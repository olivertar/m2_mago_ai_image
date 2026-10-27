<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Unit\Model;

use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Orangecat\MagoAiImage\Api\Data\GenerationInterface;
use Orangecat\MagoAiImage\Api\Data\ImageBinaryInterfaceFactory;
use Orangecat\MagoAiImage\Api\Data\ImageRequestInterface;
use Orangecat\MagoAiImage\Api\Data\ImageRequestInterfaceFactory;
use Orangecat\MagoAiImage\Api\GenerationRepositoryInterface;
use Orangecat\MagoAiImage\Api\ImageGeneratorInterface;
use Orangecat\MagoAiImage\Api\ImageProviderInterface;
use Orangecat\MagoAiImage\Api\ProviderPoolInterface;
use Orangecat\MagoAiImage\Exception\ProviderException;
use Orangecat\MagoAiImage\Model\Config;
use Orangecat\MagoAiImage\Model\Data\ImageBinary;
use Orangecat\MagoAiImage\Model\Data\ImageRequest;
use Orangecat\MagoAiImage\Model\Data\ImageResult;
use Orangecat\MagoAiImage\Model\Data\ProviderCapabilities;
use Orangecat\MagoAiImage\Model\Generation;
use Orangecat\MagoAiImage\Model\GenerationFactory;
use Orangecat\MagoAiImage\Model\Image\ImageInspector;
use Orangecat\MagoAiImage\Model\Image\PreviewStorage;
use Orangecat\MagoAiImage\Model\Image\ReferenceImageLoader;
use Orangecat\MagoAiImage\Model\ImageGenerator;
use Orangecat\MagoAiImage\Test\Unit\Model\Image\ImageInspectorTest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class ImageGeneratorTest extends TestCase
{
    /**
     * @var bool
     */
    private bool $enabled = true;

    /**
     * @var ImageRequestInterface|null
     */
    private ?ImageRequestInterface $sentRequest = null;

    /**
     * @var ImageProviderInterface|MockObject
     */
    private $provider;

    /**
     * @var ProductInterface&Stub
     */
    private $product;

    /**
     * @var PreviewStorage|MockObject
     */
    private $previewStorage;

    /**
     * @var GenerationRepositoryInterface|MockObject
     */
    private $generationRepository;

    /**
     * @var ImageGenerator
     */
    private ImageGenerator $generator;

    protected function setUp(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
        $config->method('getMaxImages')->willReturn(3);
        $config->method('getMaxReferenceBytes')->willReturn(1024 * 1024);

        $this->provider = $this->createMock(ImageProviderInterface::class);
        $this->provider->method('getCode')->willReturn('dummy');
        $this->provider->method('getLabel')->willReturn('Dummy');
        $this->provider->method('isAvailable')->willReturn(true);
        $this->provider->method('getCapabilities')->willReturn(new ProviderCapabilities(true, 1, ['1:1', '3:4'], 4));
        $pool = $this->createStub(ProviderPoolInterface::class);
        $pool->method('getDefault')->willReturn($this->provider);
        $pool->method('has')->willReturnCallback(static fn (string $code): bool => $code === 'dummy');
        $pool->method('get')->willReturn($this->provider);

        $entry = $this->createStub(ProductAttributeMediaGalleryEntryInterface::class);
        $entry->method('getId')->willReturn(7);
        $entry->method('getFile')->willReturn('/m/b/mb01.jpg');
        $entry->method('getMediaType')->willReturn('image');
        $entry->method('getTypes')->willReturn(['image']);
        $this->product = $this->createStub(ProductInterface::class);
        $this->product->method('getId')->willReturn(42);
        $this->product->method('getSku')->willReturn('MB01');
        $this->product->method('getMediaGalleryEntries')->willReturn([$entry]);
        $productRepository = $this->createStub(ProductRepositoryInterface::class);
        $productRepository->method('get')->willReturnCallback(function (string $sku) {
            if ($sku !== 'MB01') {
                throw new NoSuchEntityException(__('missing'));
            }

            return $this->product;
        });

        $binaryFactory = $this->createStub(ImageBinaryInterfaceFactory::class);
        $binaryFactory->method('create')->willReturnCallback(
            static fn (array $data): ImageBinary => new ImageBinary(...$data)
        );
        $inspector = new ImageInspector($binaryFactory);

        $loader = $this->getMockBuilder(ReferenceImageLoader::class)
            ->setConstructorArgs([
                $this->createStub(\Magento\Framework\Filesystem::class),
                $this->createStub(\Magento\Catalog\Model\Product\Media\Config::class),
                $inspector,
            ])
            ->onlyMethods(['read'])
            ->getMock();
        $loader->method('read')->willReturn(new ImageBinary(ImageInspectorTest::png(8, 8), 'image/png', 8, 8));

        $this->previewStorage = $this->createMock(PreviewStorage::class);
        $requestFactory = $this->createStub(ImageRequestInterfaceFactory::class);
        $requestFactory->method('create')->willReturnCallback(function (array $data): ImageRequest {
            $this->sentRequest = new ImageRequest(...$data);

            return $this->sentRequest;
        });

        $objectManager = new ObjectManager($this);
        $generationFactory = $this->createStub(GenerationFactory::class);
        $generationFactory->method('create')->willReturnCallback(
            static fn (): Generation => $objectManager->getObject(Generation::class)
                ?? throw new \LogicException('Could not create a generation.')
        );
        $this->generationRepository = $this->createMock(GenerationRepositoryInterface::class);

        $this->generator = new ImageGenerator(
            $config,
            $pool,
            $productRepository,
            $loader,
            $inspector,
            $this->previewStorage,
            $requestFactory,
            $generationFactory,
            $this->generationRepository,
            $this->createStub(LoggerInterface::class)
        );
    }

    public function testDisabledModuleRefuses(): void
    {
        $this->enabled = false;

        $this->expectException(LocalizedException::class);
        $this->generator->generateForProduct('MB01', 'prompt');
    }

    public function testEmptyPromptRefused(): void
    {
        $this->provider->expects($this->never())->method('generate');

        $this->expectException(LocalizedException::class);
        $this->generator->generateForProduct('MB01', '   ');
    }

    public function testCountLimitedByConfig(): void
    {
        $this->provider->expects($this->never())->method('generate');

        $this->expectExceptionMessage('between 1 and 3');
        $this->generator->generateForProduct('MB01', 'prompt', [ImageGeneratorInterface::OPTION_COUNT => 4]);
    }

    public function testUnsupportedAspectRatioRefused(): void
    {
        $this->provider->expects($this->never())->method('generate');

        $this->expectExceptionMessage('not supported');
        $this->generator->generateForProduct(
            'MB01',
            'prompt',
            [ImageGeneratorInterface::OPTION_ASPECT_RATIO => '21:9']
        );
    }

    public function testUnknownProductRefused(): void
    {
        $this->expectExceptionMessage('does not exist');
        $this->generator->generateForProduct('NOPE', 'prompt');
    }

    public function testStoresEachImageAsPreview(): void
    {
        $this->provider->expects($this->once())->method('generate')->willReturn(new ImageResult(
            [
                new ImageBinary(ImageInspectorTest::png(4, 4), 'application/octet-stream'),
                new ImageBinary(ImageInspectorTest::png(4, 4), 'image/png'),
            ],
            'dummy-gd',
            'revised',
            ['images' => 2]
        ));
        $this->previewStorage->expects($this->exactly(2))->method('save')
            ->willReturnOnConsecutiveCalls('p/1.png', 'p/2.png');
        $this->generationRepository->expects($this->exactly(2))->method('save')->willReturnArgument(0);

        $generations = $this->generator->generateForProduct(
            'MB01',
            ' a person carrying this backpack ',
            [ImageGeneratorInterface::OPTION_COUNT => 2, ImageGeneratorInterface::OPTION_ASPECT_RATIO => '3:4'],
            5
        );

        $this->assertCount(2, $generations);
        $this->assertSame('a person carrying this backpack', $this->sentRequest?->getPrompt());
        $this->assertCount(1, $this->sentRequest->getReferenceImages());
        $this->assertSame('3:4', $this->sentRequest->getAspectRatio());
        $first = $generations[0];
        $this->assertSame(42, $first->getProductId());
        $this->assertSame('/m/b/mb01.jpg', $first->getSourceFile());
        $this->assertSame('p/1.png', $first->getFile());
        $this->assertSame(GenerationInterface::STATUS_PREVIEW, $first->getStatus());
        $this->assertSame(5, $first->getAdminUserId());
        $this->assertSame(['images' => 2], $first->getUsage());
        $this->assertSame('revised', $first->getRevisedPrompt());
    }

    public function testItKeepsStoredPreviewsWhenOneImageIsBroken(): void
    {
        $this->provider->method('generate')->willReturn(new ImageResult(
            [new ImageBinary('garbage', 'image/png'), new ImageBinary(ImageInspectorTest::png(4, 4), 'image/png')],
            'm'
        ));
        $this->previewStorage->method('save')->willReturn('p/1.png');
        $this->generationRepository->method('save')->willReturnArgument(0);

        $generations = $this->generator->generateForProduct(
            'MB01',
            'prompt',
            [ImageGeneratorInterface::OPTION_COUNT => 2]
        );

        $this->assertCount(1, $generations);
        $this->assertSame('p/1.png', $generations[0]->getFile());
    }

    public function testProviderReturningGarbageStoresNothing(): void
    {
        $this->provider->method('generate')->willReturn(
            new ImageResult([new ImageBinary('garbage', 'image/png')], 'm')
        );
        $this->previewStorage->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->generator->generateForProduct('MB01', 'prompt');
    }

    public function testProviderReturningNothingFails(): void
    {
        $this->provider->method('generate')->willReturn(new ImageResult([], 'm'));

        $this->expectException(ProviderException::class);
        $this->generator->generateForProduct('MB01', 'prompt');
    }

    public function testFailedSaveRemovesPreviewFile(): void
    {
        $this->provider->method('generate')->willReturn(
            new ImageResult([new ImageBinary(ImageInspectorTest::png(4, 4), 'image/png')], 'm')
        );
        $this->previewStorage->method('save')->willReturn('p/1.png');
        $this->generationRepository->method('save')->willThrowException(new LocalizedException(__('db down')));
        $this->previewStorage->expects($this->once())->method('delete')->with('p/1.png');

        $this->expectExceptionMessage('db down');
        $this->generator->generateForProduct('MB01', 'prompt');
    }
}
