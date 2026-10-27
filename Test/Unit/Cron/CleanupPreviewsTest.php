<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Unit\Cron;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Orangecat\MagoAiImage\Api\Data\GenerationInterface;
use Orangecat\MagoAiImage\Api\GenerationManagementInterface;
use Orangecat\MagoAiImage\Api\GenerationRepositoryInterface;
use Orangecat\MagoAiImage\Cron\CleanupPreviews;
use Orangecat\MagoAiImage\Model\Config;
use Orangecat\MagoAiImage\Model\Generation;
use Orangecat\MagoAiImage\Model\Image\OrphanedPreviews;
use Orangecat\MagoAiImage\Model\Image\PreviewStorage;
use Orangecat\MagoAiImage\Model\ResourceModel\Generation\Collection;
use Orangecat\MagoAiImage\Model\ResourceModel\Generation\CollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class CleanupPreviewsTest extends TestCase
{
    public function testDiscardsUnattachedAndClearsAttachedPreviews(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getPreviewRetentionDays')->willReturn(7);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_000_000_000);
        $dateTime->method('gmtDate')->willReturn('2001-09-02 01:46:40');

        $preview = $this->generation(5, GenerationInterface::STATUS_PREVIEW, 'mago_ai_image/preview/a/b/p.png');
        $attached = $this->generation(9, GenerationInterface::STATUS_ATTACHED, 'mago_ai_image/preview/c/d/a.png');

        $filters = [];
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls(
            $this->collection([$preview], $filters),
            $this->collection([$attached], $filters)
        );

        $management = $this->createMock(GenerationManagementInterface::class);
        $management->expects($this->once())->method('discard')->with(5);
        $previewStorage = $this->createMock(PreviewStorage::class);
        $previewStorage->expects($this->once())->method('delete')->with('mago_ai_image/preview/c/d/a.png');
        $repository = $this->createMock(GenerationRepositoryInterface::class);
        $repository->expects($this->once())->method('save')
            ->with($this->callback(static fn (GenerationInterface $g): bool => $g->getFile() === ''))
            ->willReturnArgument(0);

        $cron = new CleanupPreviews(
            $config,
            $factory,
            $management,
            $repository,
            $previewStorage,
            $dateTime,
            $this->createStub(LoggerInterface::class),
            $this->createStub(OrphanedPreviews::class)
        );

        $this->assertSame(2, $cron->execute());
        $this->assertContains(['status', GenerationInterface::STATUS_ATTACHED], $filters);
        $this->assertContains(['file', ['neq' => '']], $filters);
        $this->assertContains(['created_at', ['lt' => '2001-09-02 01:46:40']], $filters);
    }

    public function testItWorksThroughMoreThanOneBatchAndSkipsRowsThatFail(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getPreviewRetentionDays')->willReturn(7);
        $previews = array_map(
            fn (int $id): Generation => $this->generation($id, GenerationInterface::STATUS_PREVIEW, 'p.png'),
            range(1, 201)
        );
        $filters = [];
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls(
            $this->collection(array_slice($previews, 0, 200), $filters),
            $this->collection(array_slice($previews, 200), $filters),
            $this->collection([], $filters)
        );
        $discarded = [];
        $management = $this->createStub(GenerationManagementInterface::class);
        $management->method('discard')->willReturnCallback(
            function (int $id) use (&$discarded, $previews): Generation {
                if ($id === 3) {
                    throw new LocalizedException(__('File is locked'));
                }
                $discarded[] = $id;

                return $previews[$id - 1];
            }
        );
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2001-09-02 01:46:40');
        $cron = new CleanupPreviews(
            $config,
            $factory,
            $management,
            $this->createStub(GenerationRepositoryInterface::class),
            $this->createStub(PreviewStorage::class),
            $dateTime,
            $this->createStub(LoggerInterface::class),
            $this->createStub(OrphanedPreviews::class)
        );

        $handled = $cron->execute();

        $this->assertSame(200, $handled);
        $this->assertContains(201, $discarded);
        $this->assertNotContains(3, $discarded);
        $this->assertContains(['generation_id', ['gt' => 200]], $filters);
    }

    /**
     * @param int $id
     * @param string $status
     * @param string $file
     * @return Generation
     */
    private function generation(int $id, string $status, string $file): Generation
    {
        $generation = $this->getMockBuilder(Generation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getGenerationId'])
            ->getMock();
        $generation->method('getGenerationId')->willReturn($id);
        $generation->setData(GenerationInterface::STATUS, $status);
        $generation->setData(GenerationInterface::FILE, $file);

        return $generation;
    }

    /**
     * @param Generation[] $items
     * @param mixed[] $filters Receives every filter as [field, condition]
     * @return Collection
     */
    private function collection(array $items, array &$filters): Collection
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function ($field, $condition) use ($collection, &$filters) {
                $filters[] = [$field, $condition];

                return $collection;
            }
        );
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));

        return $collection;
    }
}
