<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Model\Image;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Orangecat\MagoAiImage\Api\Data\ImageBinaryInterface;

/**
 * Preview files under pub/media/mago_ai_image/preview.
 *
 * Previews live on the store's own media URL because the Mago chat drops images from other hosts.
 */
class PreviewStorage
{
    public const BASE_PATH = 'mago_ai_image/preview';

    /**
     * Glob of the files save() writes below BASE_PATH: two single-character folders, then the file.
     */
    private const PREVIEW_FILES = '*/*/*';

    /**
     * @var WriteInterface|null
     */
    private ?WriteInterface $mediaDirectory = null;

    /**
     * @param Filesystem $filesystem
     * @param StoreManagerInterface $storeManager
     * @param ImageInspector $imageInspector
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly StoreManagerInterface $storeManager,
        private readonly ImageInspector $imageInspector
    ) {
    }

    /**
     * Store an image and return its path relative to pub/media.
     *
     * @param ImageBinaryInterface $image
     * @return string
     * @throws LocalizedException
     */
    public function save(ImageBinaryInterface $image): string
    {
        $hash = bin2hex(random_bytes(16));
        $path = sprintf(
            '%s/%s/%s/%s.%s',
            self::BASE_PATH,
            $hash[0],
            $hash[1],
            $hash,
            $this->imageInspector->getExtension($image->getMimeType())
        );
        try {
            $this->getMediaDirectory()->writeFile($path, $image->getContent());
        } catch (FileSystemException $e) {
            throw new LocalizedException(__('Could not store the generated image.'), $e);
        }

        return $path;
    }

    /**
     * Read a stored preview.
     *
     * @param string $path
     * @return string
     * @throws LocalizedException
     */
    public function read(string $path): string
    {
        $this->assertPreviewPath($path);
        try {
            return $this->getMediaDirectory()->readFile($path);
        } catch (FileSystemException $e) {
            throw new LocalizedException(__('The generated image file is missing.'), $e);
        }
    }

    /**
     * Whether a stored preview exists.
     *
     * @param string $path
     * @return bool
     */
    public function exists(string $path): bool
    {
        return $this->isPreviewPath($path) && $this->getMediaDirectory()->isFile($path);
    }

    /**
     * Delete a stored preview. Missing files are ignored.
     *
     * @param string $path
     * @return void
     * @throws LocalizedException
     */
    public function delete(string $path): void
    {
        if (!$this->exists($path)) {
            return;
        }
        try {
            $this->getMediaDirectory()->delete($path);
        } catch (FileSystemException $e) {
            throw new LocalizedException(__('Could not delete the generated image file.'), $e);
        }
    }

    /**
     * Preview files last modified before a moment, as paths relative to pub/media.
     *
     * @param int $timestamp Unix time
     * @return string[]
     * @throws LocalizedException
     */
    public function getFilesModifiedBefore(int $timestamp): array
    {
        $directory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        try {
            if (!$directory->isDirectory(self::BASE_PATH)) {
                return [];
            }

            return array_values(array_filter(
                $directory->search(self::PREVIEW_FILES, self::BASE_PATH),
                static fn (string $path): bool => $directory->isFile($path)
                    && (int) ($directory->stat($path)['mtime'] ?? $timestamp) < $timestamp
            ));
        } catch (FileSystemException $e) {
            throw new LocalizedException(__('Could not read the generated image folder.'), $e);
        }
    }

    /**
     * Public URL of a stored preview.
     *
     * @param string $path
     * @return string
     */
    public function getUrl(string $path): string
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore();
        $baseUrl = (string) $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);

        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Absolute filesystem path of a stored preview.
     *
     * @param string $path
     * @return string
     */
    public function getAbsolutePath(string $path): string
    {
        return $this->getMediaDirectory()->getAbsolutePath($path);
    }

    /**
     * Refuse paths outside the preview folder (e.g. a tampered record pointing at another media file).
     *
     * @param string $path
     * @return void
     * @throws LocalizedException
     */
    private function assertPreviewPath(string $path): void
    {
        if (!$this->isPreviewPath($path)) {
            throw new LocalizedException(__('Invalid generated image path.'));
        }
    }

    /**
     * Whether a path points inside the preview folder.
     *
     * @param string $path
     * @return bool
     */
    private function isPreviewPath(string $path): bool
    {
        return str_starts_with($path, self::BASE_PATH . '/') && !str_contains($path, '..');
    }

    /**
     * The media directory, opened lazily.
     *
     * @return WriteInterface
     */
    private function getMediaDirectory(): WriteInterface
    {
        if ($this->mediaDirectory === null) {
            $this->mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        }

        return $this->mediaDirectory;
    }
}
