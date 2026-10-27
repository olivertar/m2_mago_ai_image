<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Integration\Setup\Patch\Data;

use Magento\Framework\App\Config as AppConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use Orangecat\MagoAiImage\Setup\Patch\Data\MoveCredentialsToAiBase;
use PHPUnit\Framework\TestCase;

/**
 * Upgrading from per-provider credentials keeps a store working without anyone re-entering a key.
 */
class MoveCredentialsToAiBaseTest extends TestCase
{
    private const SERVICES_PATH = 'mageos_ai/services/configuration';
    private const OLD_PATHS = [
        'mago_ai_image/general/provider',
        'mago_ai_image/gemini/credential_source',
        'mago_ai_image/gemini/api_key',
        'mago_ai_image/openai/credential_source',
        'mago_ai_image/openai/ai_service',
    ];

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
        foreach ([...self::OLD_PATHS, self::SERVICES_PATH, 'mago_ai_image/general/ai_service'] as $path) {
            $writer->delete($path);
        }
        $this->objectManager->get(AppConfig::class)->clean();
    }

    public function testAKeyEnteredForTheSelectedProviderBecomesTheSelectedAiService(): void
    {
        $writer = $this->objectManager->get(WriterInterface::class);
        $writer->save('mago_ai_image/general/provider', 'gemini');
        $writer->save('mago_ai_image/gemini/credential_source', 'own');
        $writer->save(
            'mago_ai_image/gemini/api_key',
            $this->objectManager->get(EncryptorInterface::class)->encrypt('migrated-gemini-key')
        );
        $writer->save('mago_ai_image/openai/credential_source', 'ai_base');
        $writer->save('mago_ai_image/openai/ai_service', '_unused');
        $this->objectManager->get(AppConfig::class)->clean();

        $this->objectManager->create(MoveCredentialsToAiBase::class)->apply();

        $scopeConfig = $this->objectManager->get(ScopeConfigInterface::class);
        $service = $this->objectManager->get(AiServiceSelectorInterface::class)
            ->getById((string) $scopeConfig->getValue('mago_ai_image/general/ai_service'));
        $this->assertSame('google', $service?->getCode());
        $this->assertSame('migrated-gemini-key', $service->getConfiguration()['api_key'] ?? null);
        foreach (self::OLD_PATHS as $path) {
            $this->assertNull($scopeConfig->getValue($path), $path . ' should be removed.');
        }
    }
}
