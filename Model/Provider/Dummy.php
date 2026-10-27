<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Model\Provider;

use GdImage;
use Magento\Framework\App\State;
use Orangecat\MagoAiImage\Api\Data\ImageBinaryInterfaceFactory;
use Orangecat\MagoAiImage\Api\Data\ImageRequestInterface;
use Orangecat\MagoAiImage\Api\Data\ImageResultInterface;
use Orangecat\MagoAiImage\Api\Data\ImageResultInterfaceFactory;
use Orangecat\MagoAiImage\Api\Data\ProviderCapabilitiesInterface;
use Orangecat\MagoAiImage\Api\Data\ProviderCapabilitiesInterfaceFactory;
use Orangecat\MagoAiImage\Api\ImageProviderInterface;
use Orangecat\MagoAiImage\Exception\ProviderException;

/**
 * Offline provider for development and tests: tints the reference image locally with GD.
 *
 * Only available in developer mode, so it can never be selected by accident in production.
 */
class Dummy implements ImageProviderInterface
{
    public const CODE = 'dummy';

    private const SIZE = 512;
    private const ASPECT_RATIOS = ['1:1', '3:4', '4:3', '9:16', '16:9'];

    /**
     * @param State $appState
     * @param ProviderCapabilitiesInterfaceFactory $capabilitiesFactory
     * @param ImageBinaryInterfaceFactory $imageBinaryFactory
     * @param ImageResultInterfaceFactory $imageResultFactory
     */
    public function __construct(
        private readonly State $appState,
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
        return (string) __('Dummy (developer mode only)');
    }

    /**
     * @inheritDoc
     */
    public function getCapabilities(): ProviderCapabilitiesInterface
    {
        return $this->capabilitiesFactory->create([
            'supportsReference' => true,
            'maxReferenceImages' => 1,
            'supportedAspectRatios' => self::ASPECT_RATIOS,
            'maxImagesPerRequest' => 4,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getAiBaseServiceCodes(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(?int $storeId = null): bool
    {
        return $this->appState->getMode() === State::MODE_DEVELOPER && function_exists('imagecreatetruecolor');
    }

    /**
     * @inheritDoc
     */
    public function generate(ImageRequestInterface $request): ImageResultInterface
    {
        if (!$this->isAvailable($request->getStoreId())) {
            throw new ProviderException(__('The dummy image provider only works in developer mode.'));
        }

        $reference = $request->getReferenceImages()[0] ?? null;
        $images = [];
        $count = $request->getCount();
        for ($i = 0; $i < $count; $i++) {
            $canvas = $this->createCanvas($request->getAspectRatio() ?? '1:1');
            $source = $reference === null ? false : imagecreatefromstring($reference->getContent());
            if ($source instanceof GdImage) {
                imagecopyresampled(
                    $canvas,
                    $source,
                    0,
                    0,
                    0,
                    0,
                    imagesx($canvas),
                    imagesy($canvas),
                    imagesx($source),
                    imagesy($source)
                );
            }
            // A different tint per prompt and index, so previews are told apart at a glance.
            $hash = crc32($request->getPrompt() . '#' . $i);
            imagefilter($canvas, IMG_FILTER_COLORIZE, $hash & 0x7F, ($hash >> 8) & 0x7F, ($hash >> 16) & 0x7F);
            $images[] = $this->imageBinaryFactory->create([
                'content' => $this->toPng($canvas),
                'mimeType' => 'image/png',
                'width' => imagesx($canvas),
                'height' => imagesy($canvas),
            ]);
        }

        return $this->imageResultFactory->create([
            'images' => $images,
            'model' => 'dummy-gd',
            'revisedPrompt' => null,
            'usage' => ['images' => count($images)],
        ]);
    }

    /**
     * Blank canvas of the requested aspect ratio, long side SIZE px.
     *
     * @param string $aspectRatio
     * @return GdImage
     * @throws ProviderException
     */
    private function createCanvas(string $aspectRatio): GdImage
    {
        [$w, $h] = array_map('intval', explode(':', $aspectRatio) + [1, 1]);
        $w = max(1, $w);
        $h = max(1, $h);
        $width = max(1, $w >= $h ? self::SIZE : (int) round(self::SIZE * $w / $h));
        $height = max(1, $h >= $w ? self::SIZE : (int) round(self::SIZE * $h / $w));
        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas instanceof GdImage) {
            throw new ProviderException(__('Could not create the image.'));
        }
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 230, 230, 230));

        return $canvas;
    }

    /**
     * Encode as PNG.
     *
     * @param GdImage $image
     * @return string
     */
    private function toPng(GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
