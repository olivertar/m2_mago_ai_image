<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Cron;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Orangecat\MagoAiImage\Api\Data\GenerationInterface;
use Orangecat\MagoAiImage\Api\GenerationManagementInterface;
use Orangecat\MagoAiImage\Api\GenerationRepositoryInterface;
use Orangecat\MagoAiImage\Model\Config;
use Orangecat\MagoAiImage\Model\Image\OrphanedPreviews;
use Orangecat\MagoAiImage\Model\Image\PreviewStorage;
use Orangecat\MagoAiImage\Model\ResourceModel\Generation\Collection;
use Orangecat\MagoAiImage\Model\ResourceModel\Generation\CollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Removes preview files older than the retention period.
 *
 * Previews nobody attached are discarded. Attached ones only lose the preview file (the product
 * gallery has its own copy); until then the Mago chat history keeps showing them. Files no row
 * points to any more, such as those of a deleted product, are removed as well.
 */
class CleanupPreviews
{
    private const BATCH_SIZE = 200;

    /**
     * @param Config $config
     * @param CollectionFactory $collectionFactory
     * @param GenerationManagementInterface $generationManagement
     * @param GenerationRepositoryInterface $generationRepository
     * @param PreviewStorage $previewStorage
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param OrphanedPreviews $orphanedPreviews
     */
    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $collectionFactory,
        private readonly GenerationManagementInterface $generationManagement,
        private readonly GenerationRepositoryInterface $generationRepository,
        private readonly PreviewStorage $previewStorage,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly OrphanedPreviews $orphanedPreviews
    ) {
    }

    /**
     * Run the cleanup.
     *
     * @return int Number of preview files removed
     */
    public function execute(): int
    {
        $cutoff = $this->dateTime->gmtTimestamp() - $this->config->getPreviewRetentionDays() * 86400;
        $threshold = (string) $this->dateTime->gmtDate('Y-m-d H:i:s', $cutoff);

        return $this->discardUnattached($threshold) + $this->clearAttached($threshold) + $this->removeOrphans($cutoff);
    }

    /**
     * Delete preview files no generation row points to any more.
     *
     * @param int $cutoff Unix time
     * @return int
     */
    private function removeOrphans(int $cutoff): int
    {
        try {
            return $this->orphanedPreviews->removeModifiedBefore($cutoff);
        } catch (LocalizedException $e) {
            $this->logger->warning('Orangecat_MagoAiImage: could not remove orphaned previews', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Discard previews nobody attached.
     *
     * @param string $threshold
     * @return int
     */
    private function discardUnattached(string $threshold): int
    {
        return $this->processInBatches(
            static fn (Collection $collection): Collection => $collection
                ->addFieldToFilter(GenerationInterface::STATUS, GenerationInterface::STATUS_PREVIEW)
                ->addFieldToFilter(GenerationInterface::CREATED_AT, ['lt' => $threshold]),
            function (GenerationInterface $generation): void {
                $this->generationManagement->discard((int) $generation->getGenerationId());
            },
            'Orangecat_MagoAiImage: could not discard preview'
        );
    }

    /**
     * Remove the preview file of attached generations; an empty file marks it as removed.
     *
     * @param string $threshold
     * @return int
     */
    private function clearAttached(string $threshold): int
    {
        return $this->processInBatches(
            static fn (Collection $collection): Collection => $collection
                ->addFieldToFilter(GenerationInterface::STATUS, GenerationInterface::STATUS_ATTACHED)
                ->addFieldToFilter(GenerationInterface::FILE, ['neq' => ''])
                ->addFieldToFilter(GenerationInterface::CREATED_AT, ['lt' => $threshold]),
            function (GenerationInterface $generation): void {
                $this->previewStorage->delete($generation->getFile());
                $this->generationRepository->save($generation->setFile(''));
            },
            'Orangecat_MagoAiImage: could not remove attached preview'
        );
    }

    /**
     * Handle every matching generation, a batch at a time, in id order.
     *
     * The cursor moves past each row whether it succeeded or not, so a backlog is worked off in
     * one run and a row that keeps failing is logged and skipped instead of filling every batch.
     *
     * @param callable $applyFilters Adds the filters of one cleanup step to a fresh collection
     * @param callable $process Cleans up one generation; throws LocalizedException on failure
     * @param string $failureMessage
     * @return int Number of generations handled without error
     */
    private function processInBatches(callable $applyFilters, callable $process, string $failureMessage): int
    {
        $handled = 0;
        $lastId = 0;
        do {
            $batch = $applyFilters($this->collectionFactory->create())
                ->addFieldToFilter(GenerationInterface::GENERATION_ID, ['gt' => $lastId])
                ->setOrder(GenerationInterface::GENERATION_ID, Collection::SORT_ORDER_ASC)
                ->setPageSize(self::BATCH_SIZE);
            $batchSize = 0;
            foreach ($batch as $generation) {
                /** @var GenerationInterface $generation */
                $batchSize++;
                $lastId = (int) $generation->getGenerationId();
                $handled += $this->tryProcess($generation, $process, $failureMessage) ? 1 : 0;
            }
        } while ($batchSize === self::BATCH_SIZE);

        return $handled;
    }

    /**
     * Run one cleanup step, logging a failure instead of stopping the run.
     *
     * @param GenerationInterface $generation
     * @param callable $process
     * @param string $failureMessage
     * @return bool
     */
    private function tryProcess(GenerationInterface $generation, callable $process, string $failureMessage): bool
    {
        try {
            $process($generation);

            return true;
        } catch (LocalizedException $e) {
            $this->logger->warning($failureMessage, [
                'generation_id' => $generation->getGenerationId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
