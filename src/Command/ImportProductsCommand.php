<?php

declare(strict_types=1);

namespace App\Command;

use App\Product\ProductImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:products:import', description: 'Importe les références ULTRAPOP sans écraser les produits déjà administrés.')]
final class ImportProductsCommand extends Command
{
    public function __construct(private readonly ProductImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('images-only', null, InputOption::VALUE_NONE, 'Complète uniquement les images des produits existants.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->importer->import((bool) $input->getOption('images-only'));
        (new SymfonyStyle($input, $output))->success(sprintf('%d produits créés, %d produits conservés, %d images ajoutées.', $result['created'], $result['skipped'], $result['images']));

        return Command::SUCCESS;
    }
}
