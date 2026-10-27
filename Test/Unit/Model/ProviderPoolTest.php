<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Unit\Model;

use InvalidArgumentException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use Orangecat\MagoAiImage\Api\ImageProviderInterface;
use Orangecat\MagoAiImage\Exception\ProviderUnavailableException;
use Orangecat\MagoAiImage\Model\Config;
use Orangecat\MagoAiImage\Model\ProviderPool;
use Orangecat\MagoAiImage\Model\SelectedAiService;
use PHPUnit\Framework\TestCase;

class ProviderPoolTest extends TestCase
{
    public function testRejectsProviderRegisteredUnderAnotherKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ProviderPool($this->selected(null), ['other' => $this->provider('gemini', ['google'], true)]);
    }

    public function testGetUnknownProviderThrows(): void
    {
        $pool = new ProviderPool($this->selected(null), []);

        $this->assertFalse($pool->has('gemini'));
        $this->expectException(NoSuchEntityException::class);
        $pool->get('gemini');
    }

    public function testDefaultRequiresASelectedService(): void
    {
        $pool = new ProviderPool($this->selected(null), ['gemini' => $this->provider('gemini', ['google'], true)]);

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionMessage('No AI service is selected');
        $pool->getDefault();
    }

    public function testDefaultRequiresAProviderForTheServiceCode(): void
    {
        $pool = new ProviderPool(
            $this->selected('anthropic'),
            ['gemini' => $this->provider('gemini', ['google'], true)]
        );

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionMessage('No image provider can use the selected AI service (anthropic)');
        $pool->getDefault();
    }

    public function testDefaultRequiresAvailableProvider(): void
    {
        $pool = new ProviderPool($this->selected('google'), ['gemini' => $this->provider('gemini', ['google'], false)]);

        $this->expectException(ProviderUnavailableException::class);
        $pool->getDefault();
    }

    public function testDefaultIsTheProviderServingTheSelectedServiceCode(): void
    {
        $openAi = $this->provider('openai', ['openai'], true);
        $pool = new ProviderPool(
            $this->selected('openai'),
            [
                'dummy' => $this->provider('dummy', [], true),
                'gemini' => $this->provider('gemini', ['google'], true),
                'openai' => $openAi,
            ]
        );

        $default = $pool->getDefault();

        $this->assertSame($openAi, $default);
        $this->assertSame(['dummy', 'gemini', 'openai'], array_keys($pool->getAll()));
    }

    /**
     * @param string[] $serviceCodes
     */
    private function provider(string $code, array $serviceCodes, bool $available): ImageProviderInterface
    {
        $provider = $this->createStub(ImageProviderInterface::class);
        $provider->method('getCode')->willReturn($code);
        $provider->method('getLabel')->willReturn(ucfirst($code));
        $provider->method('getAiBaseServiceCodes')->willReturn($serviceCodes);
        $provider->method('isAvailable')->willReturn($available);

        return $provider;
    }

    private function selected(?string $serviceCode): SelectedAiService
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($serviceCode === null ? '' : 'row1');

        $service = $this->createStub(AiServiceInterface::class);
        $service->method('getCode')->willReturn((string) $serviceCode);

        $selector = $this->createStub(AiServiceSelectorInterface::class);
        $selector->method('getById')->willReturn($serviceCode === null ? null : $service);

        return new SelectedAiService(new Config($scopeConfig), $selector);
    }
}
