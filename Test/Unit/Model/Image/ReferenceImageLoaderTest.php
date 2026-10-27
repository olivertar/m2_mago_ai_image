<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Unit\Model\Image;

use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Orangecat\MagoAiImage\Api\Data\ImageBinaryInterfaceFactory;
use Orangecat\MagoAiImage\Model\Data\ImageBinary;
use Orangecat\MagoAiImage\Model\Image\ImageInspector;
use Orangecat\MagoAiImage\Model\Image\ReferenceImageLoader;
use PHPUnit\Framework\TestCase;

class ReferenceImageLoaderTest extends TestCase
{
    /**
     * @var array<string, string> Media path => file contents
     */
    private array $files = [];

    /**
     * @var ReferenceImageLoader
     */
    private ReferenceImageLoader $loader;

    protected function setUp(): void
    {
        $directory = $this->createStub(ReadInterface::class);
        $directory->method('isFile')->willReturnCallback(fn (string $path): bool => isset($this->files[$path]));
        $directory->method('stat')->willReturnCallback(
            fn (string $path): array => ['size' => strlen($this->files[$path])]
        );
        $directory->method('readFile')->willReturnCallback(fn (string $path): string => $this->files[$path]);
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($directory);

        $mediaConfig = $this->createStub(MediaConfig::class);
        $mediaConfig->method('getMediaPath')->willReturnCallback(
            static fn (string $file): string => 'catalog/product' . $file
        );

        $binaries = $this->createStub(ImageBinaryInterfaceFactory::class);
        $binaries->method('create')->willReturnCallback(
            static fn (array $data): ImageBinary => new ImageBinary(...$data)
        );

        $this->loader = new ReferenceImageLoader($filesystem, $mediaConfig, new ImageInspector($binaries));
    }

    public function testItPrefersTheBaseImage(): void
    {
        $product = $this->product([
            $this->entry(1, '/v/i/video.jpg', [], 'external-video'),
            $this->entry(2, '/s/i/side.jpg', ['small_image']),
            $this->entry(3, '/f/r/front.jpg', ['image', 'thumbnail']),
        ]);

        $entry = $this->loader->selectEntry($product);

        $this->assertSame(3, $entry?->getId());
    }

    public function testItFallsBackToTheFirstEnabledImageWithoutABaseImage(): void
    {
        $product = $this->product([
            $this->entry(1, '/h/i/hidden.jpg', [], 'image', true),
            $this->entry(2, '/s/i/side.jpg', []),
        ]);

        $entry = $this->loader->selectEntry($product);

        $this->assertSame(2, $entry?->getId());
    }

    public function testItReturnsNullForAProductWithoutImages(): void
    {
        $entry = $this->loader->selectEntry($this->product([$this->entry(1, '/v/i/video.jpg', [], 'external-video')]));

        $this->assertNull($entry);
    }

    public function testItUsesTheRequestedImage(): void
    {
        $product = $this->product([$this->entry(2, '/s/i/side.jpg', []), $this->entry(3, '/f/r/front.jpg', ['image'])]);

        $entry = $this->loader->selectEntry($product, 2);

        $this->assertSame(2, $entry?->getId());
    }

    public function testItRefusesAnImageOfAnotherProduct(): void
    {
        $product = $this->product([$this->entry(3, '/f/r/front.jpg', ['image'])]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('does not belong to the product "WJ12"');
        $this->loader->selectEntry($product, 99);
    }

    public function testItReadsAndInspectsTheFile(): void
    {
        $this->files['catalog/product/f/r/front.png'] = ImageInspectorTest::png(4, 3);

        $image = $this->loader->read('/f/r/front.png', 1024 * 1024);

        $this->assertSame('image/png', $image->getMimeType());
        $this->assertSame(4, $image->getWidth());
    }

    public function testItRefusesAFileOverTheSizeLimit(): void
    {
        $this->files['catalog/product/f/r/front.png'] = ImageInspectorTest::png(4, 3);

        $this->expectExceptionMessage('The product image is too large');
        $this->loader->read('/f/r/front.png', 10);
    }

    public function testItReportsAMissingFile(): void
    {
        $this->expectExceptionMessage('The product image file "/f/r/gone.png" is missing.');
        $this->loader->read('/f/r/gone.png', 1024 * 1024);
    }

    /**
     * @param ProductAttributeMediaGalleryEntryInterface[] $entries
     */
    private function product(array $entries): ProductInterface
    {
        $product = $this->createStub(ProductInterface::class);
        $product->method('getMediaGalleryEntries')->willReturn($entries);
        $product->method('getSku')->willReturn('WJ12');

        return $product;
    }

    /**
     * @param string[] $types
     */
    private function entry(
        int $id,
        string $file,
        array $types,
        string $mediaType = 'image',
        bool $isDisabled = false
    ): ProductAttributeMediaGalleryEntryInterface {
        $entry = $this->createStub(ProductAttributeMediaGalleryEntryInterface::class);
        $entry->method('getId')->willReturn($id);
        $entry->method('getFile')->willReturn($file);
        $entry->method('getTypes')->willReturn($types);
        $entry->method('getMediaType')->willReturn($mediaType);
        $entry->method('isDisabled')->willReturn($isDisabled);

        return $entry;
    }
}
