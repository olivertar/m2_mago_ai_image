<?php

declare(strict_types=1);

namespace Orangecat\MagoAiImage\Console\Command;

use Orangecat\MagoAiImage\Api\ImageProviderInterface;
use Orangecat\MagoAiImage\Api\ProviderPoolInterface;
use Orangecat\MagoAiImage\Exception\ProviderUnavailableException;
use Orangecat\MagoAiImage\Model\Config;
use Orangecat\MagoAiImage\Model\SelectedAiService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists installed image providers, the selected AI service and whether generation can run. Never prints keys.
 */
class ProvidersCommand extends Command
{
    private const NOT_APPLICABLE = '-';

    /**
     * @param ProviderPoolInterface $providerPool
     * @param SelectedAiService $selectedAiService
     * @param Config $config
     */
    public function __construct(
        private readonly ProviderPoolInterface $providerPool,
        private readonly SelectedAiService $selectedAiService,
        private readonly Config $config
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('mago:ai-image:providers')
            ->setDescription('List AI image providers and their status');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selectedCode = $this->getSelectedProviderCode();
        $table = new Table($output);
        $table->setHeaders(['Code', 'Label', 'AI services', 'Selected', 'Available']);
        foreach ($this->providerPool->getAll() as $code => $provider) {
            $table->addRow([
                $code,
                $provider->getLabel(),
                $this->formatServiceCodes($provider),
                $code === $selectedCode ? 'yes' : '',
                $provider->isAvailable() ? 'yes' : 'no',
            ]);
        }
        $table->render();
        $output->writeln(sprintf('AI service: %s', $this->describeSelectedService()));
        $output->writeln(sprintf('Module enabled: %s', $this->config->isEnabled() ? 'yes' : 'no'));

        return Command::SUCCESS;
    }

    /**
     * Code of the provider that serves the selected service, or '' when none does.
     *
     * @return string
     */
    private function getSelectedProviderCode(): string
    {
        try {
            return $this->providerPool->getDefault()->getCode();
        } catch (ProviderUnavailableException) {
            return '';
        }
    }

    /**
     * The selected service's label and code, or why there is none.
     *
     * @return string
     */
    private function describeSelectedService(): string
    {
        try {
            $service = $this->selectedAiService->get();
        } catch (ProviderUnavailableException $e) {
            return $e->getMessage();
        }

        return sprintf('%s (%s)', $service->getLabel() ?? $service->getId(), $service->getCode());
    }

    /**
     * MageOS_AiBase codes a provider serves, comma separated.
     *
     * @param ImageProviderInterface $provider
     * @return string
     */
    private function formatServiceCodes(ImageProviderInterface $provider): string
    {
        $codes = $provider->getAiBaseServiceCodes();

        return $codes === [] ? self::NOT_APPLICABLE : implode(', ', $codes);
    }
}
