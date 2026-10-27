<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Model\Image;

use Magento\Framework\Exception\LocalizedException;
use Orangecat\MagoAiImage\Model\ResourceModel\Generation as GenerationResource;

/**
 * Preview files no generation row points to any more.
 *
 * Deleting a product removes its generation rows through the foreign key, and a row can be
 * deleted through the repository, but neither touches the file. Without this sweep those files
 * would stay in pub/media forever, since every other cleanup starts from a row.
 */
class OrphanedPreviews
{
    private const CHUNK_SIZE = 500;

    /**
     * @param PreviewStorage $previewStorage
     * @param GenerationResource $generationResource
     */
    public function __construct(
        private readonly PreviewStorage $previewStorage,
        private readonly GenerationResource $generationResource
    ) {
    }

    /**
     * Delete unreferenced preview files last modified before a moment.
     *
     * Only older files are considered: a preview is written before its row is saved, so a fresh
     * file without a row may belong to a generation that is still being stored.
     *
     * @param int $timestamp Unix time
     * @return int Number of files deleted
     * @throws LocalizedException
     */
    public function removeModifiedBefore(int $timestamp): int
    {
        return array_sum(array_map(
            fn (array $files): int => $this->removeUnreferenced($files),
            array_chunk($this->previewStorage->getFilesModifiedBefore($timestamp), self::CHUNK_SIZE)
        ));
    }

    /**
     * Delete the files of a chunk that no row points to.
     *
     * @param string[] $files
     * @return int
     * @throws LocalizedException
     */
    private function removeUnreferenced(array $files): int
    {
        $orphans = array_diff($files, $this->generationResource->getReferencedFiles($files));
        foreach ($orphans as $file) {
            $this->previewStorage->delete($file);
        }

        return count($orphans);
    }
}
