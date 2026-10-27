<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Unit\Model;

use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\Data\ImageContentInterface;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\Api\ImageContent;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Orangecat\MagoAiImage\Api\Data\GenerationInterface;
use Orangecat\MagoAiImage\Api\Data\ImageBinaryInterfaceFactory;
use Orangecat\MagoAiImage\Api\GenerationRepositoryInterface;
use Orangecat\MagoAiImage\Model\Data\ImageBinary;
use Orangecat\MagoAiImage\Model\Generation;
use Orangecat\MagoAiImage\Model\GenerationManagement;
use Orangecat\MagoAiImage\Model\Image\ImageInspector;
use Orangecat\MagoAiImage\Model\Image\PreviewStorage;
use Orangecat\MagoAiImage\Test\Unit\Model\Image\ImageInspectorTest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class GenerationManagementTest extends TestCase
{
    /**
     * @var Generation
     */
    private Generation $generation;

    /**
     * @var ProductAttributeMediaGalleryManagementInterface|MockObject
     */
    private $galleryManagement;

    /**
     * @var PreviewStorage|MockObject
     */
    private $previewStorage;

    /**
     * @var ProductAttributeMediaGalleryEntryInterface|null
     */
    private ?ProductAttributeMediaGalleryEntryInterface $sentEntry = null;

    /**
     * @var GenerationManagement
     */
    private GenerationManagement $management;

    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);
        $generation = $objectManager->getObject(Generation::class);
        $this->assertInstanceOf(Generation::class, $generation);
        $this->generation = $generation;
        $this->generation->setData(GenerationInterface::GENERATION_ID, 9);
        $this->generation->setProductId(42)
            ->setFile('mago_ai_image/preview/a/b/ab.png')
            ->setStatus(GenerationInterface::STATUS_PREVIEW);

        $repository = $this->createStub(GenerationRepositoryInterface::class);
        $repository->method('getById')->willReturn($this->generation);
        $repository->method('save')->willReturnArgument(0);

        $product = $this->createStub(ProductInterface::class);
        $product->method('getSku')->willReturn('MB 01/Blue');
        $productRepository = $this->createStub(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $this->galleryManagement = $this->createMock(ProductAttributeMediaGalleryManagementInterface::class);

        $entryFactory = $this->createStub(ProductAttributeMediaGalleryEntryInterfaceFactory::class);
        $entryFactory->method('create')->willReturnCallback(
            fn () => $objectManager->getObject(\Magento\Catalog\Model\Product\Gallery\Entry::class)
        );
        $contentFactory = $this->createStub(ImageContentInterfaceFactory::class);
        $contentFactory->method('create')->willReturnCallback(fn () => $objectManager->getObject(ImageContent::class));

        $this->previewStorage = $this->createMock(PreviewStorage::class);
        $this->previewStorage->method('read')->willReturn(ImageInspectorTest::png(4, 4));

        $binaryFactory = $this->createStub(ImageBinaryInterfaceFactory::class);
        $binaryFactory->method('create')->willReturnCallback(
            static fn (array $data): ImageBinary => new ImageBinary(...$data)
        );

        $this->management = new GenerationManagement(
            $repository,
            $productRepository,
            $this->galleryManagement,
            $entryFactory,
            $contentFactory,
            $this->previewStorage,
            new ImageInspector($binaryFactory)
        );
    }

    public function testAttachAddsGalleryEntryAndRemovesPreview(): void
    {
        $this->galleryManagement->expects($this->once())->method('create')
            ->with('MB 01/Blue', $this->callback(function (ProductAttributeMediaGalleryEntryInterface $entry): bool {
                $this->sentEntry = $entry;

                return true;
            }))
            ->willReturn(123);
        // The preview stays for the Mago chat history; the cron removes it later.
        $this->previewStorage->expects($this->never())->method('delete');

        $result = $this->management->attach(9, ['image', 'thumbnail'], ' City walk ');

        $this->assertSame(GenerationInterface::STATUS_ATTACHED, $result->getStatus());
        $this->assertSame(123, $result->getMediaGalleryEntryId());
        $this->assertSame(['image', 'thumbnail'], $this->sentEntry?->getTypes());
        $this->assertSame('City walk', $this->sentEntry->getLabel());
        $this->assertSame('image', $this->sentEntry->getMediaType());
        /** @var ImageContentInterface $content */
        $content = $this->sentEntry->getContent();
        $this->assertSame('image/png', $content->getType());
        $this->assertSame('mb-01-blue-ai-9.png', $content->getName());
        $this->assertSame(ImageInspectorTest::png(4, 4), base64_decode($content->getBase64EncodedData()));
    }

    public function testAttachRejectsUnknownRole(): void
    {
        $this->galleryManagement->expects($this->never())->method('create');

        $this->expectExceptionMessage('Unknown image role');
        $this->management->attach(9, ['hero']);
    }

    public function testAttachRejectsAlreadyAttached(): void
    {
        $this->generation->setStatus(GenerationInterface::STATUS_ATTACHED);
        $this->galleryManagement->expects($this->never())->method('create');

        $this->expectException(LocalizedException::class);
        $this->management->attach(9);
    }

    public function testDiscardDeletesFile(): void
    {
        $this->previewStorage->expects($this->once())->method('delete')->with('mago_ai_image/preview/a/b/ab.png');

        $this->assertSame(GenerationInterface::STATUS_DISCARDED, $this->management->discard(9)->getStatus());
    }
}
