<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Api;

use Orangecat\MagoAiImage\Api\Data\ImageRequestInterface;
use Orangecat\MagoAiImage\Api\Data\ImageResultInterface;
use Orangecat\MagoAiImage\Api\Data\ProviderCapabilitiesInterface;
use Orangecat\MagoAiImage\Exception\ProviderException;

/**
 * An image generation backend. Provider modules implement this and add it to the
 * `providers` argument of {@see ProviderPoolInterface} in di.xml.
 *
 * @api
 */
interface ImageProviderInterface
{
    /**
     * Machine code, also the config group id under `mago_ai_image/<code>/`.
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Human readable name.
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * What the provider supports.
     *
     * @return ProviderCapabilitiesInterface
     */
    public function getCapabilities(): ProviderCapabilitiesInterface;

    /**
     * MageOS_AiBase service codes this provider serves.
     *
     * Selecting a row of one of these codes under General > AI Service makes this the provider
     * and supplies its API key. Add the codes to the `serviceCodes` argument of the
     * `Orangecat\MagoAiImage\Model\Config\Source\ImageAiService` virtual type too, or the
     * dropdown will not offer them. Empty for a provider without credentials, which can then only
     * be called by code (`mago:ai-image:generate --provider=<code>`).
     *
     * @return string[]
     */
    public function getAiBaseServiceCodes(): array;

    /**
     * Whether the provider is configured and can be called in the given store.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isAvailable(?int $storeId = null): bool;

    /**
     * Generate images.
     *
     * @param ImageRequestInterface $request
     * @return ImageResultInterface
     * @throws ProviderException
     */
    public function generate(ImageRequestInterface $request): ImageResultInterface;
}
