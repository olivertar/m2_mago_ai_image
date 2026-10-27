<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Setup\Patch\Data;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use MageOS\AiBase\Api\ServiceImporterInterface;

/**
 * Moves the credentials of the old per-provider settings into MageOS_AiBase.
 *
 * Before, each provider group had its own Credentials choice: an API key entered in the group, or
 * a MageOS_AiBase row. Now one General > AI Service row supplies the key and picks the provider.
 * A key entered in a group is imported as a MageOS_AiBase row, so nobody has to paste it again,
 * and the row of the provider that was selected becomes the AI Service. The old paths are then
 * removed in every scope; only default scope was ever read at runtime, so a website value never
 * took effect and nothing that worked is lost. The config cache is cleaned afterwards: setup:upgrade
 * keeps it, and the tools would otherwise report no AI service until someone flushes it.
 */
class MoveCredentialsToAiBase implements DataPatchInterface
{
    private const CONFIG_TABLE = 'core_config_data';
    private const PATH_OLD_PROVIDER = 'mago_ai_image/general/provider';
    private const PATH_AI_SERVICE = 'mago_ai_image/general/ai_service';
    private const PATH_PATTERN = 'mago_ai_image/%s/%s';
    private const OLD_FIELDS = ['credential_source', 'api_key', 'ai_service'];
    private const CREDENTIAL_SOURCE_AI_BASE = 'ai_base';

    /**
     * Old provider code => MageOS_AiBase service code and the label of an imported row.
     */
    private const PROVIDERS = [
        'gemini' => ['code' => 'google', 'label' => 'Google Gemini (Image Generation)'],
        'openai' => ['code' => 'openai', 'label' => 'OpenAI (Image Generation)'],
    ];

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param ServiceImporterInterface $serviceImporter
     * @param WriterInterface $configWriter
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ServiceImporterInterface $serviceImporter,
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();
        $values = $this->readDefaultScopeValues();
        $serviceIds = array_map(
            fn (string $provider): ?string => $this->resolveServiceId($provider, $values),
            array_combine(array_keys(self::PROVIDERS), array_keys(self::PROVIDERS))
        );
        $selectedId = $serviceIds[$values[self::PATH_OLD_PROVIDER] ?? ''] ?? null;
        if ($selectedId !== null) {
            $this->configWriter->save(self::PATH_AI_SERVICE, $selectedId);
        }
        $this->deleteOldPaths();
        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * The MageOS_AiBase row a provider used, importing its own key as a new row when it had one.
     *
     * @param string $provider
     * @param array<string,string> $values
     * @return string|null
     */
    private function resolveServiceId(string $provider, array $values): ?string
    {
        if ($this->readField($provider, 'credential_source', $values) === self::CREDENTIAL_SOURCE_AI_BASE) {
            $serviceId = $this->readField($provider, 'ai_service', $values);

            return $serviceId === '' ? null : $serviceId;
        }

        return $this->serviceImporter->importFromConfig(
            self::PROVIDERS[$provider]['code'],
            ['api_key' => sprintf(self::PATH_PATTERN, $provider, 'api_key')],
            self::PROVIDERS[$provider]['label']
        );
    }

    /**
     * A field of an old provider group at default scope.
     *
     * @param string $provider
     * @param string $field
     * @param array<string,string> $values
     * @return string
     */
    private function readField(string $provider, string $field, array $values): string
    {
        return trim($values[sprintf(self::PATH_PATTERN, $provider, $field)] ?? '');
    }

    /**
     * Old values stored at default scope, read from the table so a stale config cache cannot hide them.
     *
     * @return array<string, string>
     */
    private function readDefaultScopeValues(): array
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from($this->moduleDataSetup->getTable(self::CONFIG_TABLE), ['path', 'value'])
            ->where('scope = ?', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
            ->where('scope_id = ?', 0)
            ->where('path IN (?)', $this->getOldPaths());

        return array_map('strval', $connection->fetchPairs($select));
    }

    /**
     * Remove the old settings in every scope.
     *
     * @return void
     */
    private function deleteOldPaths(): void
    {
        $this->moduleDataSetup->getConnection()->delete(
            $this->moduleDataSetup->getTable(self::CONFIG_TABLE),
            ['path IN (?)' => $this->getOldPaths()]
        );
    }

    /**
     * Every config path the old per-provider credentials used.
     *
     * @return string[]
     */
    private function getOldPaths(): array
    {
        return [
            self::PATH_OLD_PROVIDER,
            ...array_merge(...array_map(
                static fn (string $provider): array => array_map(
                    static fn (string $field): string => sprintf(self::PATH_PATTERN, $provider, $field),
                    self::OLD_FIELDS
                ),
                array_keys(self::PROVIDERS)
            )),
        ];
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
