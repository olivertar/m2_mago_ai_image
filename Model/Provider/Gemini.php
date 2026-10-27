<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Model\Provider;

use Orangecat\MagoAiImage\Api\Data\ImageBinaryInterface;
use Orangecat\MagoAiImage\Api\Data\ImageBinaryInterfaceFactory;
use Orangecat\MagoAiImage\Api\Data\ImageRequestInterface;
use Orangecat\MagoAiImage\Api\Data\ImageResultInterface;
use Orangecat\MagoAiImage\Api\Data\ImageResultInterfaceFactory;
use Orangecat\MagoAiImage\Api\Data\ProviderCapabilitiesInterface;
use Orangecat\MagoAiImage\Api\Data\ProviderCapabilitiesInterfaceFactory;
use Orangecat\MagoAiImage\Api\ImageProviderInterface;
use Orangecat\MagoAiImage\Api\ProviderConfigInterface;
use Orangecat\MagoAiImage\Exception\ContentRejectedException;
use Orangecat\MagoAiImage\Exception\ProviderException;
use Orangecat\MagoAiImage\Exception\ProviderUnavailableException;
use Orangecat\MagoAiImage\Model\Config;
use Orangecat\MagoAiImage\Model\Platform\PlatformFactory;
use Orangecat\MagoAiImage\Model\Platform\ResponseReader;
use Symfony\AI\Platform\Bridge\Gemini\Gemini as GeminiModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformExceptionInterface;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Result\ChoiceResult;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;

/**
 * Google Gemini image models ("Nano Banana") through `generateContent`, with the Symfony AI
 * Gemini bridge.
 *
 * One call returns one image, so a request for N images makes N calls.
 */
class Gemini implements ImageProviderInterface
{
    public const CODE = 'gemini';
    public const DEFAULT_MODEL = 'gemini-nano-banana-2.1';
    public const FIELD_IMAGE_SIZE = 'image_size';
    public const FIELD_BASE_URL = 'base_url';

    private const LABEL = 'Google Gemini';

    private const ASPECT_RATIOS = ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'];
    private const IMAGE_SIZES = ['1K', '2K', '4K'];
    private const MAX_REFERENCE_IMAGES = 14;
    private const MAX_IMAGES_PER_REQUEST = 4;
    private const MAX_ERROR_LENGTH = 300;

    /**
     * Finish and block reasons meaning the provider refused the content.
     */
    private const REJECTION_REASONS = [
        'SAFETY',
        'IMAGE_SAFETY',
        'PROHIBITED_CONTENT',
        'IMAGE_PROHIBITED_CONTENT',
        'BLOCKLIST',
        'SPII',
        'RECITATION',
        'IMAGE_RECITATION',
    ];

    /**
     * @param PlatformFactory $platformFactory
     * @param ResponseReader $responseReader
     * @param ProviderConfigInterface $providerConfig
     * @param Config $config
     * @param ProviderCapabilitiesInterfaceFactory $capabilitiesFactory
     * @param ImageBinaryInterfaceFactory $imageBinaryFactory
     * @param ImageResultInterfaceFactory $imageResultFactory
     */
    public function __construct(
        private readonly PlatformFactory $platformFactory,
        private readonly ResponseReader $responseReader,
        private readonly ProviderConfigInterface $providerConfig,
        private readonly Config $config,
        private readonly ProviderCapabilitiesInterfaceFactory $capabilitiesFactory,
        private readonly ImageBinaryInterfaceFactory $imageBinaryFactory,
        private readonly ImageResultInterfaceFactory $imageResultFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return (string) __('Google Gemini (Nano Banana)');
    }

    /**
     * @inheritDoc
     */
    public function getCapabilities(): ProviderCapabilitiesInterface
    {
        return $this->capabilitiesFactory->create([
            'supportsReference' => true,
            'maxReferenceImages' => self::MAX_REFERENCE_IMAGES,
            'supportedAspectRatios' => self::ASPECT_RATIOS,
            'maxImagesPerRequest' => self::MAX_IMAGES_PER_REQUEST,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getAiBaseServiceCodes(): array
    {
        return ['google'];
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(?int $storeId = null): bool
    {
        return $this->providerConfig->hasApiKey($this, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function generate(ImageRequestInterface $request): ImageResultInterface
    {
        $storeId = $request->getStoreId();
        $apiKey = $this->providerConfig->getApiKey($this, $storeId);
        $model = $this->getModel($storeId);
        $baseUrl = $this->providerConfig->getValue($this, self::FIELD_BASE_URL, $storeId)
            ?? PlatformFactory::GEMINI_BASE_URL;
        $timeout = $this->config->getTimeout($storeId);
        $platform = $this->platformFactory->createGemini($apiKey, $baseUrl, $timeout);
        $messages = $this->buildMessages($request);
        $options = $this->buildOptions($request, $storeId);

        $images = [];
        $texts = [];
        $usage = ['images' => 0, 'calls' => 0, 'prompt_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
        for ($i = 0, $count = max(1, $request->getCount()); $i < $count; $i++) {
            try {
                [$callImages, $callText, $metadata] = $this->invokeOnce(
                    $platform,
                    $model,
                    $messages,
                    $options,
                    $timeout
                );
            } catch (ProviderException $e) {
                if ($images === []) {
                    throw $e;
                }
                break;
            }
            $usage['calls']++;
            array_push($images, ...$callImages);
            if ($callText !== '') {
                $texts[] = $callText;
            }
            $usage['prompt_tokens'] += (int) ($metadata['promptTokenCount'] ?? 0);
            $usage['output_tokens'] += (int) ($metadata['candidatesTokenCount'] ?? 0);
            $usage['total_tokens'] += (int) ($metadata['totalTokenCount'] ?? 0);
        }
        $usage['images'] = count($images);

        return $this->imageResultFactory->create([
            'images' => $images,
            'model' => $model->getName(),
            'revisedPrompt' => $texts === [] ? null : implode("\n", array_unique($texts)),
            'usage' => $usage,
        ]);
    }

    /**
     * One generateContent call: its images, its text and its usage metadata.
     *
     * Gemini returns one image per call, so a request for several images makes several calls. A
     * failing later call must not throw away the images earlier calls already returned (and were
     * billed for); generate() stops there and returns what it has.
     *
     * @param PlatformInterface $platform
     * @param GeminiModel $model
     * @param MessageBag $messages
     * @param array<string,mixed> $options
     * @param int $timeout
     * @return array{0: ImageBinaryInterface[], 1: string, 2: array<string, mixed>}
     * @throws ProviderException
     */
    private function invokeOnce(
        PlatformInterface $platform,
        GeminiModel $model,
        MessageBag $messages,
        array $options,
        int $timeout
    ): array {
        try {
            $result = $platform->invoke($model, $messages, $options);
        } catch (PlatformExceptionInterface $e) {
            throw new ProviderException(__('Google Gemini error: %1', $e->getMessage()), $e);
        }
        $response = $this->responseReader->read($result, self::LABEL, $timeout);
        [$images, $text] = $this->parseResponse($result, $response['status'], $response['body']);
        $metadata = $response['body']['usageMetadata'] ?? [];

        return [$images, $text, is_array($metadata) ? $metadata : []];
    }

    /**
     * Configured model, validated so it cannot alter the request path.
     *
     * Built directly instead of read from the bridge catalog, so models newer than the bridge
     * release can be configured.
     *
     * @param int|null $storeId
     * @return GeminiModel
     * @throws ProviderUnavailableException
     */
    private function getModel(?int $storeId): GeminiModel
    {
        $model = $this->providerConfig->getModel($this, self::DEFAULT_MODEL, $storeId);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $model)) {
            throw new ProviderUnavailableException(__('The Gemini model name "%1" is not valid.', $model));
        }

        return new GeminiModel($model, [
            Capability::INPUT_MESSAGES,
            Capability::INPUT_IMAGE,
            Capability::OUTPUT_TEXT,
            Capability::OUTPUT_IMAGE,
        ]);
    }

    /**
     * The prompt followed by the reference images, as one user message.
     *
     * @param ImageRequestInterface $request
     * @return MessageBag
     */
    private function buildMessages(ImageRequestInterface $request): MessageBag
    {
        $content = [new Text($request->getPrompt())];
        foreach (array_slice($request->getReferenceImages(), 0, self::MAX_REFERENCE_IMAGES) as $image) {
            $content[] = new Image($image->getContent(), $image->getMimeType());
        }

        return new MessageBag(Message::ofUser(...$content));
    }

    /**
     * Options the bridge sends as `generationConfig`.
     *
     * @param ImageRequestInterface $request
     * @param int|null $storeId
     * @return mixed[]
     */
    private function buildOptions(ImageRequestInterface $request, ?int $storeId): array
    {
        $imageConfig = [];
        if ($request->getAspectRatio() !== null) {
            $imageConfig['aspectRatio'] = $request->getAspectRatio();
        }
        $imageSize = $this->providerConfig->getValue($this, self::FIELD_IMAGE_SIZE, $storeId);
        if ($imageSize !== null && in_array($imageSize, self::IMAGE_SIZES, true)) {
            $imageConfig['imageSize'] = $imageSize;
        }

        $options = ['responseModalities' => ['TEXT', 'IMAGE']];
        if ($imageConfig !== []) {
            $options['imageConfig'] = $imageConfig;
        }
        if ($request->getSeed() !== null) {
            $options['seed'] = $request->getSeed();
        }

        return $options;
    }

    /**
     * Extract images and text from one response, or turn its failure into an exception.
     *
     * @param DeferredResult $result
     * @param int $status
     * @param mixed[] $body
     * @return array{0: ImageBinaryInterface[], 1: string}
     * @throws ProviderException
     */
    private function parseResponse(DeferredResult $result, int $status, array $body): array
    {
        if ($status < 200 || $status >= 300) {
            $this->throwHttpError($status, $body);
        }

        $blockReason = (string) ($body['promptFeedback']['blockReason'] ?? '');
        if ($blockReason !== '') {
            throw new ContentRejectedException(
                __('Google Gemini refused the request (%1). Try a different prompt or image.', $blockReason)
            );
        }

        try {
            $parts = $this->flatten($result->getResult());
        } catch (PlatformExceptionInterface $e) {
            throw new ProviderException(__('Google Gemini error: %1', $e->getMessage()), $e);
        }

        $images = [];
        $texts = [];
        foreach ($parts as $part) {
            // Thought parts (drafts) arrive as ThinkingResult and are skipped.
            if ($part instanceof BinaryResult && $part->getContent() !== '') {
                $images[] = $this->imageBinaryFactory->create([
                    'content' => $part->getContent(),
                    'mimeType' => $part->getMimeType() ?? 'image/png',
                ]);
            } elseif ($part instanceof TextResult) {
                $texts[] = trim($part->getContent());
            }
        }

        $text = trim(implode("\n", array_filter($texts)));
        if ($images === []) {
            $finishReason = (string) ($body['candidates'][0]['finishReason'] ?? '');
            if (in_array($finishReason, self::REJECTION_REASONS, true)) {
                throw new ContentRejectedException(
                    __('Google Gemini refused the request (%1). Try a different prompt or image.', $finishReason)
                );
            }
            throw new ProviderException(
                __(
                    'Google Gemini returned no image (%1)%2',
                    $finishReason === '' ? 'no reason given' : $finishReason,
                    $text === '' ? '.' : ': ' . mb_substr($text, 0, self::MAX_ERROR_LENGTH)
                )
            );
        }

        return [$images, $text];
    }

    /**
     * Leaf results of a converted result (several parts, or several candidates).
     *
     * @param ResultInterface $result
     * @return ResultInterface[]
     */
    private function flatten(ResultInterface $result): array
    {
        if (!$result instanceof MultiPartResult && !$result instanceof ChoiceResult) {
            return [$result];
        }

        $leaves = [];
        foreach ($result->getContent() as $part) {
            array_push($leaves, ...$this->flatten($part));
        }

        return $leaves;
    }

    /**
     * Map an HTTP error to an exception with the provider's message.
     *
     * @param int $status
     * @param mixed[] $body
     * @return never
     * @throws ProviderException
     */
    private function throwHttpError(int $status, array $body): never
    {
        $message = mb_substr((string) ($body['error']['message'] ?? ''), 0, self::MAX_ERROR_LENGTH);
        $reason = (string) ($body['error']['status'] ?? '');
        $detail = $message !== '' ? $message : sprintf('HTTP %d', $status);

        if ($status === 401 || $status === 403 || stripos($message, 'API key') !== false) {
            throw new ProviderUnavailableException(__('Google Gemini rejected the API key: %1', $detail));
        }
        if ($status === 404) {
            throw new ProviderUnavailableException(__('Google Gemini does not know the configured model: %1', $detail));
        }
        if ($status === 429 || $reason === 'RESOURCE_EXHAUSTED') {
            throw new ProviderException(__('Google Gemini quota or rate limit reached: %1', $detail));
        }

        throw new ProviderException(__('Google Gemini error (HTTP %1): %2', $status, $detail));
    }
}
