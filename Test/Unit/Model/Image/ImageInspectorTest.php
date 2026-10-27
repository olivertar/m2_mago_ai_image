<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Unit\Model\Image;

use Magento\Framework\Exception\LocalizedException;
use Orangecat\MagoAiImage\Api\Data\ImageBinaryInterfaceFactory;
use Orangecat\MagoAiImage\Model\Data\ImageBinary;
use Orangecat\MagoAiImage\Model\Image\ImageInspector;
use PHPUnit\Framework\TestCase;

class ImageInspectorTest extends TestCase
{
    /**
     * @var ImageInspector
     */
    private ImageInspector $inspector;

    protected function setUp(): void
    {
        $factory = $this->createStub(ImageBinaryInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $data): ImageBinary => new ImageBinary(...$data)
        );
        $this->inspector = new ImageInspector($factory);
    }

    public function testDetectsPngFromBytesIgnoringDeclaredType(): void
    {
        $image = $this->inspector->inspect(self::png(3, 2));

        $this->assertSame('image/png', $image->getMimeType());
        $this->assertSame(3, $image->getWidth());
        $this->assertSame(2, $image->getHeight());
        $this->assertSame('png', $this->inspector->getExtension($image->getMimeType()));
    }

    public function testRejectsNonImage(): void
    {
        $this->expectException(LocalizedException::class);
        $this->inspector->inspect('<?php echo "not an image";');
    }

    public function testRejectsUnsupportedFormat(): void
    {
        $gd = imagecreatetruecolor(2, 2);
        ob_start();
        imagegif($gd);
        $gif = (string) ob_get_clean();

        $this->expectException(LocalizedException::class);
        $this->inspector->inspect($gif);
    }

    public function testRejectsOversizedImageFromHeader(): void
    {
        // Only the header is read: a PNG signature and an IHDR chunk claiming 9000x10 pixels.
        $header = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 9000, 10) . "\x08\x02\x00\x00\x00"
            . pack('N', 0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('9000x10');
        $this->inspector->inspect($header);
    }

    public static function png(int $width, int $height): string
    {
        $gd = imagecreatetruecolor(max(1, $width), max(1, $height));
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }
}
