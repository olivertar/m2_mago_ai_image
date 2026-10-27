<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Model;

use InvalidArgumentException;
use Magento\Framework\Exception\NoSuchEntityException;
use Orangecat\MagoAiImage\Api\ImageProviderInterface;
use Orangecat\MagoAiImage\Api\ProviderPoolInterface;
use Orangecat\MagoAiImage\Exception\ProviderUnavailableException;

/**
 * Providers registered through di.xml.
 */
class ProviderPool implements ProviderPoolInterface
{
    /**
     * @var array<string, ImageProviderInterface>
     */
    private array $providers = [];

    /**
     * @param SelectedAiService $selectedAiService
     * @param ImageProviderInterface[] $providers
     */
    public function __construct(
        private readonly SelectedAiService $selectedAiService,
        array $providers = []
    ) {
        foreach ($providers as $key => $provider) {
            if (!$provider instanceof ImageProviderInterface) {
                throw new InvalidArgumentException(
                    sprintf('Image provider "%s" must implement %s.', $key, ImageProviderInterface::class)
                );
            }
            if ($provider->getCode() !== $key) {
                throw new InvalidArgumentException(
                    sprintf('Image provider "%s" is registered under the key "%s".', $provider->getCode(), $key)
                );
            }
            $this->providers[$key] = $provider;
        }
    }

    /**
     * @inheritDoc
     */
    public function getAll(): array
    {
        return $this->providers;
    }

    /**
     * @inheritDoc
     */
    public function has(string $code): bool
    {
        return isset($this->providers[$code]);
    }

    /**
     * @inheritDoc
     */
    public function get(string $code): ImageProviderInterface
    {
        if (!isset($this->providers[$code])) {
            throw new NoSuchEntityException(__('The image provider "%1" is not installed.', $code));
        }

        return $this->providers[$code];
    }

    /**
     * @inheritDoc
     */
    public function getDefault(?int $storeId = null): ImageProviderInterface
    {
        $service = $this->selectedAiService->get();
        $provider = $this->findByServiceCode($service->getCode()) ?? throw new ProviderUnavailableException(
            __('No image provider can use the selected AI service (%1).', $service->getCode())
        );
        if (!$provider->isAvailable($storeId)) {
            throw new ProviderUnavailableException(
                __('The image provider "%1" is not configured.', $provider->getLabel())
            );
        }

        return $provider;
    }

    /**
     * The first provider serving MageOS_AiBase rows of this code.
     *
     * @param string $serviceCode
     * @return ImageProviderInterface|null
     */
    private function findByServiceCode(string $serviceCode): ?ImageProviderInterface
    {
        $matches = array_filter(
            $this->providers,
            static fn (ImageProviderInterface $provider): bool =>
                in_array($serviceCode, $provider->getAiBaseServiceCodes(), true)
        );

        return $matches === [] ? null : reset($matches);
    }
}
