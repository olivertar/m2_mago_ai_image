<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use Orangecat\MagoAiImage\Api\ImageProviderInterface;
use Orangecat\MagoAiImage\Api\ProviderConfigInterface;
use Orangecat\MagoAiImage\Exception\ProviderUnavailableException;

/**
 * Reads `mago_ai_image/<code>/*` and hands out the key of the selected MageOS_AiBase service.
 *
 * AiServiceSelectorInterface returns rows already decrypted, so no key is decrypted here.
 */
class ProviderConfig implements ProviderConfigInterface
{
    private const XML_PATH_PATTERN = 'mago_ai_image/%s/%s';
    private const AI_BASE_API_KEY = 'api_key';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param SelectedAiService $selectedAiService
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SelectedAiService $selectedAiService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function hasApiKey(ImageProviderInterface $provider, ?int $storeId = null): bool
    {
        try {
            return $this->getApiKey($provider, $storeId) !== '';
        } catch (ProviderUnavailableException) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getApiKey(ImageProviderInterface $provider, ?int $storeId = null): string
    {
        $apiKey = $this->readApiKey($this->getServiceFor($provider));
        if ($apiKey === '') {
            throw new ProviderUnavailableException(
                __('The AI service selected for image generation has no API key.')
            );
        }

        return $apiKey;
    }

    /**
     * @inheritDoc
     */
    public function getModel(ImageProviderInterface $provider, string $default = '', ?int $storeId = null): string
    {
        $model = $this->read($provider, self::FIELD_MODEL, $storeId);

        return $model !== '' ? $model : $default;
    }

    /**
     * @inheritDoc
     */
    public function getValue(ImageProviderInterface $provider, string $field, ?int $storeId = null): ?string
    {
        $value = $this->read($provider, $field, $storeId);

        return $value === '' ? null : $value;
    }

    /**
     * The selected service, provided it is one this provider serves.
     *
     * @param ImageProviderInterface $provider
     * @return AiServiceInterface
     * @throws ProviderUnavailableException
     */
    private function getServiceFor(ImageProviderInterface $provider): AiServiceInterface
    {
        $service = $this->selectedAiService->get();
        if (!in_array($service->getCode(), $provider->getAiBaseServiceCodes(), true)) {
            throw new ProviderUnavailableException(
                __('The AI service selected for image generation is not a %1 service.', $provider->getLabel())
            );
        }

        return $service;
    }

    /**
     * The service row's key, trimmed.
     *
     * @param AiServiceInterface $service
     * @return string
     */
    private function readApiKey(AiServiceInterface $service): string
    {
        $apiKey = $service->getConfiguration()[self::AI_BASE_API_KEY] ?? '';

        return is_string($apiKey) ? trim($apiKey) : '';
    }

    /**
     * Read a provider field.
     *
     * @param ImageProviderInterface $provider
     * @param string $field
     * @param int|null $storeId
     * @return string
     */
    private function read(ImageProviderInterface $provider, string $field, ?int $storeId): string
    {
        $value = $this->scopeConfig->getValue(
            sprintf(self::XML_PATH_PATTERN, $provider->getCode(), $field),
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
