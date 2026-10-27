<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Api;

use Magento\Framework\Exception\NoSuchEntityException;
use Orangecat\MagoAiImage\Exception\ProviderUnavailableException;

/**
 * Registered image providers.
 *
 * @api
 */
interface ProviderPoolInterface
{
    /**
     * All registered providers, keyed by code.
     *
     * @return array<string, ImageProviderInterface>
     */
    public function getAll(): array;

    /**
     * Whether a provider with this code is registered.
     *
     * @param string $code
     * @return bool
     */
    public function has(string $code): bool;

    /**
     * Provider by code.
     *
     * @param string $code
     * @return ImageProviderInterface
     * @throws NoSuchEntityException
     */
    public function get(string $code): ImageProviderInterface;

    /**
     * The provider serving the AI service selected in configuration, if registered and available.
     *
     * @param int|null $storeId
     * @return ImageProviderInterface
     * @throws ProviderUnavailableException
     */
    public function getDefault(?int $storeId = null): ImageProviderInterface;
}
