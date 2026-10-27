<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Test\Integration\Setup;

use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The generation table and the foreign keys the cleanup relies on.
 */
class SchemaTest extends TestCase
{
    public function testTheGenerationTableFollowsProductsAndAdminUsers(): void
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $table = $resource->getTableName('mago_ai_image_generation');

        $columns = array_keys($connection->describeTable($table));
        $foreignKeys = array_column($connection->getForeignKeys($table), 'ON_DELETE', 'REF_TABLE_NAME');

        foreach (['generation_id', 'product_id', 'file', 'status', 'admin_user_id', 'created_at'] as $column) {
            $this->assertContains($column, $columns);
        }
        $this->assertSame('CASCADE', $foreignKeys[$resource->getTableName('catalog_product_entity')] ?? null);
        $this->assertSame('SET NULL', $foreignKeys[$resource->getTableName('admin_user')] ?? null);
    }
}
