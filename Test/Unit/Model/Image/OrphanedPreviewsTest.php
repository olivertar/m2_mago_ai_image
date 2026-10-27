<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Unit\Model\Image;

use Orangecat\MagoAiImage\Model\Image\OrphanedPreviews;
use Orangecat\MagoAiImage\Model\Image\PreviewStorage;
use Orangecat\MagoAiImage\Model\ResourceModel\Generation as GenerationResource;
use PHPUnit\Framework\TestCase;

class OrphanedPreviewsTest extends TestCase
{
    public function testItDeletesOnlyFilesNoGenerationPointsTo(): void
    {
        $deleted = [];
        $previewStorage = $this->createStub(PreviewStorage::class);
        $previewStorage->method('getFilesModifiedBefore')->willReturn([
            'mago_ai_image/preview/a/b/kept.png',
            'mago_ai_image/preview/c/d/deleted-product.png',
            'mago_ai_image/preview/e/f/deleted-row.png',
        ]);
        $previewStorage->method('delete')->willReturnCallback(
            static function (string $file) use (&$deleted): void {
                $deleted[] = $file;
            }
        );
        $resource = $this->createStub(GenerationResource::class);
        $resource->method('getReferencedFiles')->willReturn(['mago_ai_image/preview/a/b/kept.png']);

        $removed = (new OrphanedPreviews($previewStorage, $resource))->removeModifiedBefore(1_000_000_000);

        $this->assertSame(2, $removed);
        $this->assertSame(
            ['mago_ai_image/preview/c/d/deleted-product.png', 'mago_ai_image/preview/e/f/deleted-row.png'],
            $deleted
        );
    }

    public function testItDoesNothingWithoutOldFiles(): void
    {
        $previewStorage = $this->createStub(PreviewStorage::class);
        $previewStorage->method('getFilesModifiedBefore')->willReturn([]);

        $removed = (new OrphanedPreviews($previewStorage, $this->createStub(GenerationResource::class)))
            ->removeModifiedBefore(1_000_000_000);

        $this->assertSame(0, $removed);
    }
}
