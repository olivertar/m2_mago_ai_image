<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Model\ResourceModel;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Generation resource model.
 */
class Generation extends AbstractDb
{
    public const TABLE = 'mago_ai_image_generation';

    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(self::TABLE, 'generation_id');
    }

    /**
     * Which of these preview paths a generation row still points to.
     *
     * @param string[] $files
     * @return string[]
     * @throws LocalizedException When the database can't be read, so no caller mistakes every file for unreferenced
     */
    public function getReferencedFiles(array $files): array
    {
        if ($files === []) {
            return [];
        }
        $connection = $this->getConnection();
        if ($connection === false) {
            throw new LocalizedException(__('The database connection is not available.'));
        }
        $select = $connection->select()
            ->distinct()
            ->from($this->getMainTable(), ['file'])
            ->where('file IN (?)', $files);

        return array_map('strval', $connection->fetchCol($select));
    }
}
