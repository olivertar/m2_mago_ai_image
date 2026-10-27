<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Model;

use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use Orangecat\MagoAiImage\Exception\ProviderUnavailableException;

/**
 * The MageOS_AiBase service row chosen under General > AI Service.
 *
 * Its key authenticates the image calls and its code decides which image provider serves them.
 */
class SelectedAiService
{
    /**
     * @param Config $config
     * @param AiServiceSelectorInterface $aiServiceSelector
     */
    public function __construct(
        private readonly Config $config,
        private readonly AiServiceSelectorInterface $aiServiceSelector
    ) {
    }

    /**
     * The selected service row.
     *
     * @return AiServiceInterface
     * @throws ProviderUnavailableException When no row is selected, or the selected one was deleted or disabled
     */
    public function get(): AiServiceInterface
    {
        $serviceId = $this->config->getAiServiceId();
        if ($serviceId === '') {
            throw new ProviderUnavailableException(__('No AI service is selected for image generation.'));
        }

        return $this->aiServiceSelector->getById($serviceId) ?? throw new ProviderUnavailableException(
            __('The AI service selected for image generation no longer exists or is disabled.')
        );
    }
}
