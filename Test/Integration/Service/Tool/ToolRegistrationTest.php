<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Integration\Service\Tool;

use Magento\Framework\App\Config as AppConfig;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Api\ServiceImporterInterface;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use Orangecat\MagoAiImage\Service\Tool\AttachImage;
use Orangecat\MagoAiImage\Service\Tool\GenerateImage;
use Orangecat\MagoAiImage\Service\Tool\ListImages;
use PHPUnit\Framework\TestCase;

/**
 * Mago offers what di.xml registers in its tool registry, minus tools that say they can't run.
 */
class ToolRegistrationTest extends TestCase
{
    private const TOOLS = [
        'mago_product_image_list' => ListImages::class,
        'mago_product_image_generate' => GenerateImage::class,
        'mago_product_image_attach' => AttachImage::class,
    ];
    private const PATHS = [
        'mago_ai_image/general/enabled',
        'mago_ai_image/general/ai_service',
        'mageos_ai/services/configuration',
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
        foreach (self::PATHS as $path) {
            $writer->delete($path);
        }
        $this->objectManager->get(AppConfig::class)->clean();
    }

    public function testMagoOffersTheImageToolsOnceGenerationIsSetUp(): void
    {
        $writer = $this->objectManager->get(WriterInterface::class);
        $writer->save(
            'mago_ai_image/general/ai_service',
            $this->objectManager->get(ServiceImporterInterface::class)->import('openai', ['api_key' => 'sk-test'])
        );
        $writer->save('mago_ai_image/general/enabled', '1');
        $this->objectManager->get(AppConfig::class)->clean();

        $registry = $this->objectManager->create(ToolRegistry::class);

        foreach (self::TOOLS as $name => $class) {
            $this->assertInstanceOf($class, $registry->getToolByName($name), $name);
        }
    }

    public function testMagoHidesTheImageToolsWhileGenerationIsDisabled(): void
    {
        $registry = $this->objectManager->create(ToolRegistry::class);

        foreach (array_keys(self::TOOLS) as $name) {
            $this->assertNull($registry->getToolByName($name), $name);
        }
    }
}
