<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Integration\Model\Config\Source;

use Magento\Framework\App\Config as AppConfig;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The AI Service dropdown is a virtual type over MageOS_AiBase's option source. Only a real
 * object manager shows whether di.xml hands it the serviceCodes filter.
 */
class ImageAiServiceTest extends TestCase
{
    private const SERVICES_PATH = 'mageos_ai/services/configuration';
    private const SOURCE = 'Orangecat\MagoAiImage\Model\Config\Source\ImageAiService';

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
        $this->objectManager->get(WriterInterface::class)->delete(self::SERVICES_PATH);
        $this->objectManager->get(AppConfig::class)->clean();
    }

    public function testItOffersOnlyGoogleAndOpenAiServices(): void
    {
        $this->objectManager->get(WriterInterface::class)->save(self::SERVICES_PATH, json_encode([
            '_google' => ['google' => ['model' => 'gemini-flash-latest']],
            '_openai' => ['openai' => ['model' => 'gpt-5.5']],
            '_compatible' => ['openai_compatible' => ['model' => 'kimi']],
            '_anthropic' => ['anthropic' => ['model' => 'claude']],
        ], JSON_THROW_ON_ERROR));
        $this->objectManager->get(AppConfig::class)->clean();
        $source = $this->objectManager->create(self::SOURCE);
        $this->assertInstanceOf(OptionSourceInterface::class, $source);

        $values = array_column($source->toOptionArray(), 'value');

        $this->assertSame(['_google', '_openai'], $values);
    }
}
