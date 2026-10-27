<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Api;

use Orangecat\MagoAiImage\Exception\ProviderUnavailableException;

/**
 * Per-provider configuration under `mago_ai_image/<code>/`, plus the API key of the selected service.
 *
 * Credentials live only in MageOS_AiBase: the admin picks one service row under General > AI
 * Service, and its key is handed to the provider that serves that row's code. Providers read their
 * settings only through this interface and never resolve a key themselves.
 *
 * @api
 */
interface ProviderConfigInterface
{
    public const FIELD_MODEL = 'model';

    /**
     * Whether the selected AI service can supply a key to this provider, without throwing.
     *
     * @param ImageProviderInterface $provider
     * @param int|null $storeId
     * @return bool
     */
    public function hasApiKey(ImageProviderInterface $provider, ?int $storeId = null): bool;

    /**
     * The API key of the selected AI service.
     *
     * @param ImageProviderInterface $provider
     * @param int|null $storeId
     * @return string
     * @throws ProviderUnavailableException When no service is selected, it is gone, it belongs to
     *         another provider, or it has no key
     */
    public function getApiKey(ImageProviderInterface $provider, ?int $storeId = null): string;

    /**
     * Configured image model, or the given default when empty.
     *
     * @param ImageProviderInterface $provider
     * @param string $default
     * @param int|null $storeId
     * @return string
     */
    public function getModel(ImageProviderInterface $provider, string $default = '', ?int $storeId = null): string;

    /**
     * Any other field of the provider group.
     *
     * @param ImageProviderInterface $provider
     * @param string $field
     * @param int|null $storeId
     * @return string|null
     */
    public function getValue(ImageProviderInterface $provider, string $field, ?int $storeId = null): ?string;
}
