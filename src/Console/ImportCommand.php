<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Console;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Frootbox\Core\Component\Components;
use Frootbox\MigrateFrootbox1\Import\Importer;
use Frootbox\MigrateFrootbox1\Mapping\Mapping;
use Frootbox\MigrateFrootbox1\Mapping\MappingException;
use Frootbox\MigrateFrootbox1\Source\SiteReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes a website of Frootbox 1 as a seed for this website (decisions
 * 0064, 0256): site.yaml with all steps, the images in media/ and
 * report.md with what stayed behind. Nothing is written to a database;
 * the seed runs on an empty website, e.g. after a fresh install:
 *
 *     php bin/frootbox fb1:import pdo-mysql://user:secret@localhost/old \
 *         --files ../old/files/project --mapping migration/mapping.yaml --out migration/seed --publish
 *     php bin/frootbox seed migration/seed/site.yaml
 */
#[AsCommand('fb1:import', 'Writes a website of Frootbox 1 as a seed, by the mapping')]
final class ImportCommand extends Command
{
    public function __construct(private readonly Components $components)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('database', InputArgument::REQUIRED, 'Address of the database of Frootbox 1, e.g. pdo-mysql://user:secret@localhost/old?charset=utf8mb4')
            ->addOption('files', null, InputOption::VALUE_REQUIRED, 'The folder of the installation below files/, with uploads/')
            ->addOption('mapping', null, InputOption::VALUE_REQUIRED, 'The mapping file', 'migration/mapping.yaml')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'The folder for the seed, written anew', 'migration/seed')
            ->addOption('publish', null, InputOption::VALUE_NONE, 'Publish pages, navigations, forms and fields at the end, instead of leaving drafts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $database = $input->getArgument('database');
        $files = $input->getOption('files');
        $mappingFile = $input->getOption('mapping');
        $out = $input->getOption('out');

        if (!\is_string($files) || !is_dir($files)) {
            $io->error('Name the folder with the uploads of Frootbox 1 with --files.');

            return Command::FAILURE;
        }

        try {
            $mapping = Mapping::fromFile(\is_string($mappingFile) ? $mappingFile : '');
        } catch (MappingException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        $connection = DriverManager::getConnection(new DsnParser()->parse(\is_string($database) ? $database : ''));
        $site = new SiteReader($connection)->read();
        $connection->close();

        $plan = new Importer($site, $mapping, $this->components, $files)->plan(true === $input->getOption('publish'));
        $folder = rtrim(\is_string($out) ? $out : 'migration/seed', '/');
        self::clear($folder . '/media');

        foreach ($plan->files as $target => $source) {
            if (is_file($source)) {
                copy($source, $folder . '/' . $target);
            }
        }

        file_put_contents($folder . '/site.yaml', $plan->yaml(self::hidePassword(\is_string($database) ? $database : '')));
        file_put_contents($folder . '/report.md', $plan->markdown());

        $io->success(\sprintf('Wrote %d step(s) and %d file(s) to %s. Run it with: bin/frootbox seed %s/site.yaml', \count($plan->steps), \count($plan->files), $folder, $folder));

        if ([] !== $plan->report) {
            $io->warning(\sprintf('%d point(s) for a person, see %s/report.md:', \count($plan->report), $folder));
            $io->listing($plan->report);
        }

        return Command::SUCCESS;
    }

    /**
     * An empty folder, the files of an earlier run removed.
     */
    private static function clear(string $folder): void
    {
        if (is_dir($folder)) {
            foreach (scandir($folder) as $name) {
                if (is_file($folder . '/' . $name)) {
                    unlink($folder . '/' . $name);
                }
            }
        } else {
            mkdir($folder, 0o775, true);
        }
    }

    private static function hidePassword(string $database): string
    {
        return (string) preg_replace('#//([^:/@]+):[^@]*@#', '//$1:…@', $database);
    }
}
