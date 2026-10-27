<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Unit\Model\Image;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Orangecat\MagoAiImage\Api\Data\ImageBinaryInterfaceFactory;
use Orangecat\MagoAiImage\Model\Data\ImageBinary;
use Orangecat\MagoAiImage\Model\Image\ImageInspector;
use Orangecat\MagoAiImage\Model\Image\PreviewStorage;
use PHPUnit\Framework\TestCase;

class PreviewStorageTest extends TestCase
{
    /**
     * @var array<string, array{content: string, mtime: int}> Media path => file
     */
    private array $files = [];

    /**
     * @var PreviewStorage
     */
    private PreviewStorage $storage;

    protected function setUp(): void
    {
        $write = $this->createStub(WriteInterface::class);
        $write->method('isFile')->willReturnCallback(fn (string $path): bool => isset($this->files[$path]));
        $write->method('readFile')->willReturnCallback(fn (string $path): string => $this->files[$path]['content']);
        $write->method('writeFile')->willReturnCallback(function (string $path, string $content): int {
            $this->files[$path] = ['content' => $content, 'mtime' => 2_000_000_000];

            return strlen($content);
        });
        $write->method('delete')->willReturnCallback(function (string $path): bool {
            unset($this->files[$path]);

            return true;
        });

        $read = $this->createStub(ReadInterface::class);
        $read->method('isDirectory')->willReturn(true);
        $read->method('search')->willReturnCallback(fn (): array => array_keys($this->files));
        $read->method('isFile')->willReturnCallback(fn (string $path): bool => isset($this->files[$path]));
        $read->method('stat')->willReturnCallback(
            fn (string $path): array => ['mtime' => $this->files[$path]['mtime']]
        );

        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($write);
        $filesystem->method('getDirectoryRead')->willReturn($read);

        $binaries = $this->createStub(ImageBinaryInterfaceFactory::class);
        $binaries->method('create')->willReturnCallback(
            static fn (array $data): ImageBinary => new ImageBinary(...$data)
        );

        $this->storage = new PreviewStorage(
            $filesystem,
            $this->createStub(StoreManagerInterface::class),
            new ImageInspector($binaries)
        );
    }

    public function testItStoresAPreviewInsideThePreviewFolder(): void
    {
        $path = $this->storage->save(new ImageBinary('png-bytes', 'image/png'));

        $this->assertMatchesRegularExpression('#^mago_ai_image/preview/[0-9a-f]/[0-9a-f]/[0-9a-f]{32}\.png$#', $path);
        $this->assertSame('png-bytes', $this->storage->read($path));
    }

    public function testItNeverDeletesAFileOutsideThePreviewFolder(): void
    {
        $this->files['catalog/product/m/b/mb01.jpg'] = ['content' => 'product image', 'mtime' => 1];

        $this->storage->delete('catalog/product/m/b/mb01.jpg');
        $this->storage->delete('mago_ai_image/preview/../../catalog/product/m/b/mb01.jpg');

        $this->assertArrayHasKey('catalog/product/m/b/mb01.jpg', $this->files);
    }

    public function testItRefusesToReadAFileOutsideThePreviewFolder(): void
    {
        $this->files['app/etc/env.php'] = ['content' => 'secrets', 'mtime' => 1];

        $this->expectException(LocalizedException::class);
        $this->storage->read('mago_ai_image/preview/../../../app/etc/env.php');
    }

    public function testItListsOnlyFilesOlderThanTheGivenMoment(): void
    {
        $this->files['mago_ai_image/preview/a/b/old.png'] = ['content' => 'x', 'mtime' => 100];
        $this->files['mago_ai_image/preview/c/d/new.png'] = ['content' => 'x', 'mtime' => 300];

        $files = $this->storage->getFilesModifiedBefore(200);

        $this->assertSame(['mago_ai_image/preview/a/b/old.png'], $files);
    }
}
