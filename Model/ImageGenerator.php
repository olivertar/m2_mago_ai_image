<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Orangecat\MagoAiImage\Api\Data\GenerationInterface;
use Orangecat\MagoAiImage\Api\Data\ImageBinaryInterface;
use Orangecat\MagoAiImage\Api\Data\ImageRequestInterfaceFactory;
use Orangecat\MagoAiImage\Api\Data\ImageResultInterface;
use Orangecat\MagoAiImage\Api\GenerationRepositoryInterface;
use Orangecat\MagoAiImage\Api\ImageGeneratorInterface;
use Orangecat\MagoAiImage\Api\ImageProviderInterface;
use Orangecat\MagoAiImage\Api\ProviderPoolInterface;
use Orangecat\MagoAiImage\Exception\ProviderException;
use Orangecat\MagoAiImage\Exception\ProviderUnavailableException;
use Orangecat\MagoAiImage\Model\Image\ImageInspector;
use Orangecat\MagoAiImage\Model\Image\PreviewStorage;
use Orangecat\MagoAiImage\Model\Image\ReferenceImageLoader;
use Psr\Log\LoggerInterface;

/**
 * Generates previews for a product. Nothing touches the product until a preview is attached.
 */
class ImageGenerator implements ImageGeneratorInterface
{
    public const MAX_PROMPT_LENGTH = 4000;

    /**
     * @param Config $config
     * @param ProviderPoolInterface $providerPool
     * @param ProductRepositoryInterface $productRepository
     * @param ReferenceImageLoader $referenceImageLoader
     * @param ImageInspector $imageInspector
     * @param PreviewStorage $previewStorage
     * @param ImageRequestInterfaceFactory $imageRequestFactory
     * @param GenerationFactory $generationFactory
     * @param GenerationRepositoryInterface $generationRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly ProviderPoolInterface $providerPool,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ReferenceImageLoader $referenceImageLoader,
        private readonly ImageInspector $imageInspector,
        private readonly PreviewStorage $previewStorage,
        private readonly ImageRequestInterfaceFactory $imageRequestFactory,
        private readonly GenerationFactory $generationFactory,
        private readonly GenerationRepositoryInterface $generationRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function generateForProduct(
        string $sku,
        string $prompt,
        array $options = [],
        ?int $adminUserId = null
    ): array {
        $storeId = isset($options[self::OPTION_STORE_ID]) ? (int) $options[self::OPTION_STORE_ID] : null;
        if (!$this->config->isEnabled($storeId)) {
            throw new LocalizedException(__('AI image generation is disabled.'));
        }

        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new LocalizedException(__('The prompt is empty.'));
        }
        if (mb_strlen($prompt) > self::MAX_PROMPT_LENGTH) {
            throw new LocalizedException(
                __('The prompt is too long (maximum %1 characters).', self::MAX_PROMPT_LENGTH)
            );
        }

        $provider = $this->resolveProvider($options, $storeId);
        $capabilities = $provider->getCapabilities();

        $count = (int) ($options[self::OPTION_COUNT] ?? 1);
        $maxCount = min($this->config->getMaxImages($storeId), max(1, $capabilities->getMaxImagesPerRequest()));
        if ($count < 1 || $count > $maxCount) {
            throw new LocalizedException(__('The number of images must be between 1 and %1.', $maxCount));
        }

        $aspectRatio = isset($options[self::OPTION_ASPECT_RATIO])
            ? trim((string) $options[self::OPTION_ASPECT_RATIO])
            : '';
        $supportedRatios = $capabilities->getSupportedAspectRatios();
        if ($aspectRatio !== '' && $supportedRatios !== [] && !in_array($aspectRatio, $supportedRatios, true)) {
            throw new LocalizedException(
                __(
                    'The aspect ratio "%1" is not supported. Use one of: %2.',
                    $aspectRatio,
                    implode(', ', $supportedRatios)
                )
            );
        }

        try {
            $product = $this->productRepository->get($sku, false, $storeId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('The product "%1" does not exist.', $sku));
        }

        $sourceFile = null;
        $references = [];
        if ($capabilities->supportsReference()) {
            $mediaEntryId = isset($options[self::OPTION_MEDIA_ENTRY_ID])
                ? (int) $options[self::OPTION_MEDIA_ENTRY_ID]
                : null;
            $entry = $this->referenceImageLoader->selectEntry($product, $mediaEntryId);
            if ($entry === null) {
                throw new LocalizedException(__('The product "%1" has no image to use as reference.', $sku));
            }
            $sourceFile = (string) $entry->getFile();
            $references[] = $this->referenceImageLoader->read(
                $sourceFile,
                $this->config->getMaxReferenceBytes($storeId)
            );
        }

        $request = $this->imageRequestFactory->create([
            'prompt' => $prompt,
            'referenceImages' => $references,
            'aspectRatio' => $aspectRatio === '' ? null : $aspectRatio,
            'count' => $count,
            'seed' => isset($options[self::OPTION_SEED]) ? (int) $options[self::OPTION_SEED] : null,
            'storeId' => $storeId,
        ]);

        try {
            $result = $provider->generate($request);
        } catch (ProviderException $e) {
            $this->logger->warning('Orangecat_MagoAiImage: provider failed', [
                'provider' => $provider->getCode(),
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $images = array_slice($result->getImages(), 0, $count);
        if ($images === []) {
            throw new ProviderException(__('The image provider returned no image.'));
        }

        $generations = [];
        $firstError = null;
        foreach ($images as $image) {
            try {
                $generations[] = $this->storePreview(
                    $image,
                    $product,
                    $sourceFile,
                    $provider,
                    $result,
                    $prompt,
                    $adminUserId
                );
            } catch (LocalizedException $e) {
                $firstError ??= $e;
                $this->logger->warning('Orangecat_MagoAiImage: could not store a generated image', [
                    'provider' => $provider->getCode(),
                    'sku' => $sku,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        if ($generations === [] && $firstError !== null) {
            throw $firstError;
        }

        return $generations;
    }

    /**
     * Inspect, write and record one generated image as a preview.
     *
     * Every image was billed by the provider, so one broken image must not cost the others: the
     * caller keeps the previews that were stored and only fails when none could be.
     *
     * @param ImageBinaryInterface $image
     * @param ProductInterface $product
     * @param string|null $sourceFile
     * @param ImageProviderInterface $provider
     * @param ImageResultInterface $result
     * @param string $prompt
     * @param int|null $adminUserId
     * @return GenerationInterface
     * @throws LocalizedException
     */
    private function storePreview(
        ImageBinaryInterface $image,
        ProductInterface $product,
        ?string $sourceFile,
        ImageProviderInterface $provider,
        ImageResultInterface $result,
        string $prompt,
        ?int $adminUserId
    ): GenerationInterface {
        $file = $this->previewStorage->save($this->imageInspector->inspect($image->getContent()));
        $generation = $this->generationFactory->create();
        $generation->setProductId((int) $product->getId())
            ->setSourceFile($sourceFile)
            ->setProvider($provider->getCode())
            ->setModel($result->getModel())
            ->setPrompt($prompt)
            ->setRevisedPrompt($result->getRevisedPrompt())
            ->setFile($file)
            ->setStatus(GenerationInterface::STATUS_PREVIEW)
            ->setAdminUserId($adminUserId)
            ->setUsage($result->getUsage());
        try {
            return $this->generationRepository->save($generation);
        } catch (LocalizedException $e) {
            $this->previewStorage->delete($file);
            throw $e;
        }
    }

    /**
     * The requested provider, or the configured default.
     *
     * @param mixed[] $options
     * @param int|null $storeId
     * @return ImageProviderInterface
     * @throws ProviderUnavailableException
     */
    private function resolveProvider(array $options, ?int $storeId): ImageProviderInterface
    {
        $code = trim((string) ($options[self::OPTION_PROVIDER] ?? ''));
        if ($code === '') {
            return $this->providerPool->getDefault($storeId);
        }
        if (!$this->providerPool->has($code)) {
            throw new ProviderUnavailableException(__('The image provider "%1" is not installed.', $code));
        }
        $provider = $this->providerPool->get($code);
        if (!$provider->isAvailable($storeId)) {
            throw new ProviderUnavailableException(
                __('The image provider "%1" is not configured.', $provider->getLabel())
            );
        }

        return $provider;
    }
}
