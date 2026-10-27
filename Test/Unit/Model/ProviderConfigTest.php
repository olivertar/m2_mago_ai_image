<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use Orangecat\MagoAiImage\Api\ImageProviderInterface;
use Orangecat\MagoAiImage\Exception\ProviderUnavailableException;
use Orangecat\MagoAiImage\Model\Config;
use Orangecat\MagoAiImage\Model\ProviderConfig;
use Orangecat\MagoAiImage\Model\SelectedAiService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ProviderConfigTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private array $values = [];

    /**
     * @var array<string, AiServiceInterface>
     */
    private array $services = [];

    /**
     * @var ProviderConfig
     */
    private ProviderConfig $providerConfig;

    protected function setUp(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn (string $path) => $this->values[$path] ?? null);

        $selector = $this->createStub(AiServiceSelectorInterface::class);
        $selector->method('getById')->willReturnCallback(fn (string $id) => $this->services[$id] ?? null);

        $this->providerConfig = new ProviderConfig(
            $scopeConfig,
            new SelectedAiService(new Config($scopeConfig), $selector)
        );
    }

    public function testItReturnsTheKeyOfTheSelectedService(): void
    {
        $this->values['mago_ai_image/general/ai_service'] = 'row1';
        $this->services['row1'] = $this->service('google', ['api_key' => ' row-key ', 'model' => 'gemini-pro']);

        $apiKey = $this->providerConfig->getApiKey($this->provider(['google']));

        $this->assertSame('row-key', $apiKey);
        $this->assertTrue($this->providerConfig->hasApiKey($this->provider(['google'])));
    }

    public function testItRefusesAServiceOfAnotherProvider(): void
    {
        $this->values['mago_ai_image/general/ai_service'] = 'row1';
        $this->services['row1'] = $this->service('openai', ['api_key' => 'openai-key']);

        $hasApiKey = $this->providerConfig->hasApiKey($this->provider(['google']));

        $this->assertFalse($hasApiKey);
        $this->expectExceptionMessage('is not a Gemini service');
        $this->providerConfig->getApiKey($this->provider(['google']));
    }

    public function testItReportsThatNoServiceIsSelected(): void
    {
        $hasApiKey = $this->providerConfig->hasApiKey($this->provider(['google']));

        $this->assertFalse($hasApiKey);
        $this->expectExceptionMessage('No AI service is selected');
        $this->providerConfig->getApiKey($this->provider(['google']));
    }

    public function testItReportsADeletedOrDisabledService(): void
    {
        $this->values['mago_ai_image/general/ai_service'] = 'gone';

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionMessage('no longer exists or is disabled');
        $this->providerConfig->getApiKey($this->provider(['google']));
    }

    public function testItReportsAServiceWithoutKey(): void
    {
        $this->values['mago_ai_image/general/ai_service'] = 'row1';
        $this->services['row1'] = $this->service('google', ['api_key' => '  ']);

        $this->expectExceptionMessage('has no API key');
        $this->providerConfig->getApiKey($this->provider(['google']));
    }

    public function testItNeverHandsAKeyToAProviderWithoutAiBaseCodes(): void
    {
        $this->values['mago_ai_image/general/ai_service'] = 'row1';
        $this->services['row1'] = $this->service('google', ['api_key' => 'row-key']);

        $hasApiKey = $this->providerConfig->hasApiKey($this->provider([]));

        $this->assertFalse($hasApiKey);
    }

    public function testItReadsGroupFields(): void
    {
        $this->values['mago_ai_image/gemini/base_url'] = 'https://example.test';

        $baseUrl = $this->providerConfig->getValue($this->provider([]), 'base_url');

        $this->assertSame('https://example.test', $baseUrl);
        $this->assertNull($this->providerConfig->getValue($this->provider([]), 'image_size'));
    }

    public function testModelFallsBackToDefault(): void
    {
        $provider = $this->provider([]);
        $this->assertSame('default-model', $this->providerConfig->getModel($provider, 'default-model'));

        $this->values['mago_ai_image/gemini/model'] = ' custom-model ';
        $this->assertSame('custom-model', $this->providerConfig->getModel($provider, 'default-model'));
    }

    /**
     * @param string[] $aiBaseCodes
     */
    private function provider(array $aiBaseCodes): ImageProviderInterface&Stub
    {
        $provider = $this->createStub(ImageProviderInterface::class);
        $provider->method('getCode')->willReturn('gemini');
        $provider->method('getLabel')->willReturn('Gemini');
        $provider->method('getAiBaseServiceCodes')->willReturn($aiBaseCodes);

        return $provider;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function service(string $code, array $configuration): AiServiceInterface
    {
        $service = $this->createStub(AiServiceInterface::class);
        $service->method('getCode')->willReturn($code);
        $service->method('getConfiguration')->willReturn($configuration);

        return $service;
    }
}
