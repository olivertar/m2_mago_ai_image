<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Unit\Service\Tool;

use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Orangecat\MagoAiImage\Api\ImageProviderInterface;
use Orangecat\MagoAiImage\Api\ProviderPoolInterface;
use Orangecat\MagoAiImage\Exception\ProviderUnavailableException;
use Orangecat\MagoAiImage\Model\Availability;
use Orangecat\MagoAiImage\Model\Config;
use Orangecat\MagoAiImage\Model\Data\ProviderCapabilities;
use Orangecat\MagoAiImage\Model\Image\ReferenceImageLoader;
use Orangecat\MagoAiImage\Model\ProductImageReader;
use Orangecat\MagoAiImage\Service\Tool\ListImages;
use PHPUnit\Framework\TestCase;

class ListImagesTest extends TestCase
{
    public function testItAsksForASku(): void
    {
        $result = $this->tool($this->createStub(ProviderPoolInterface::class))->execute(['sku' => '  ']);

        $this->assertSame(['error' => 'The SKU is required.'], $result);
    }

    public function testItReportsWhyGenerationIsNotPossible(): void
    {
        $providerPool = $this->createStub(ProviderPoolInterface::class);
        $providerPool->method('getDefault')->willThrowException(
            new ProviderUnavailableException(__('No AI service is selected for image generation.'))
        );

        $result = $this->tool($providerPool)->execute(['sku' => 'WJ12']);

        $this->assertSame(['error' => 'No AI service is selected for image generation.'], $result);
    }

    public function testItDescribesTheImagesAndWhatCanBeGenerated(): void
    {
        $provider = $this->createStub(ImageProviderInterface::class);
        $provider->method('getLabel')->willReturn('OpenAI (GPT Image)');
        $provider->method('getCapabilities')->willReturn(new ProviderCapabilities(true, 1, ['1:1', '3:2'], 10));
        $providerPool = $this->createStub(ProviderPoolInterface::class);
        $providerPool->method('getDefault')->willReturn($provider);

        $result = $this->tool($providerPool)->execute(['sku' => 'WJ12']);

        $this->assertSame('WJ12', $result['sku']);
        $this->assertSame(42, $result['product_id']);
        $this->assertSame(7, $result['default_reference_id']);
        $this->assertSame([['id' => 7]], $result['images']);
        $this->assertSame([], $result['previews']);
        $this->assertSame(
            ['provider' => 'OpenAI (GPT Image)', 'aspect_ratios' => ['1:1', '3:2'], 'max_images' => 4],
            $result['generation']
        );
    }

    private function tool(ProviderPoolInterface $providerPool): ListImages
    {
        $product = $this->createStub(ProductInterface::class);
        $product->method('getSku')->willReturn('WJ12');
        $product->method('getId')->willReturn(42);
        $product->method('getName')->willReturn('Jacket');
        $reader = $this->createStub(ProductImageReader::class);
        $reader->method('getProduct')->willReturn($product);
        $reader->method('getImages')->willReturn([['id' => 7]]);
        $reader->method('getPreviews')->willReturn([]);

        $entry = $this->createStub(ProductAttributeMediaGalleryEntryInterface::class);
        $entry->method('getId')->willReturn(7);
        $loader = $this->createStub(ReferenceImageLoader::class);
        $loader->method('selectEntry')->willReturn($entry);

        $config = $this->createStub(Config::class);
        $config->method('getMaxImages')->willReturn(4);

        return new ListImages($reader, $loader, $providerPool, $config, $this->createStub(Availability::class));
    }
}
