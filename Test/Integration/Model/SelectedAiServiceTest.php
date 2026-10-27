<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Integration\Model;

use Magento\Framework\App\Config as AppConfig;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Api\ServiceImporterInterface;
use Orangecat\MagoAiImage\Api\ProviderConfigInterface;
use Orangecat\MagoAiImage\Api\ProviderPoolInterface;
use PHPUnit\Framework\TestCase;

/**
 * A service row saved through MageOS_AiBase, encrypted like the admin form saves it, picks the
 * provider and hands it its key.
 */
class SelectedAiServiceTest extends TestCase
{
    private const SERVICES_PATH = 'mageos_ai/services/configuration';
    private const AI_SERVICE_PATH = 'mago_ai_image/general/ai_service';

    /**
     * @var ObjectManagerInterface
     */
    private ObjectManagerInterface $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
    }

    protected function tearDown(): void
    {
        $writer = $this->objectManager->get(WriterInterface::class);
        $writer->delete(self::SERVICES_PATH);
        $writer->delete(self::AI_SERVICE_PATH);
        $this->objectManager->get(AppConfig::class)->clean();
    }

    public function testAGoogleServiceMakesGeminiTheProviderWithItsKey(): void
    {
        $rowId = $this->objectManager->get(ServiceImporterInterface::class)
            ->import('google', ['api_key' => 'test-gemini-key']);
        $this->objectManager->get(WriterInterface::class)->save(self::AI_SERVICE_PATH, $rowId);
        $this->objectManager->get(AppConfig::class)->clean();

        $provider = $this->objectManager->create(ProviderPoolInterface::class)->getDefault();

        $this->assertSame('gemini', $provider->getCode());
        $this->assertSame(
            'test-gemini-key',
            $this->objectManager->create(ProviderConfigInterface::class)->getApiKey($provider)
        );
    }
}
