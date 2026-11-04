<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Console;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Components;
use Frootbox\MigrateFrootbox1\Analysis\Analysis;
use Frootbox\MigrateFrootbox1\Analysis\Analyzer;
use Frootbox\MigrateFrootbox1\Analysis\MappingProposal;
use Frootbox\MigrateFrootbox1\Source\Page;
use Frootbox\MigrateFrootbox1\Source\SiteReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reads a website of Frootbox 1 and lists what it uses: pages, blocks,
 * plugins, objects, navigations, redirects and problems (decision 0063).
 * With --mapping it writes the first version of the mapping file.
 *
 *     bin/frootbox fb1:analyze 'pdo-mysql://user:secret@localhost/old?charset=utf8mb4' \
 *         --files=/path/to/frootbox/files/site --mapping=migration/frootbox1.yaml
 */
#[AsCommand(name: 'fb1:analyze', description: 'Lists what a website of Frootbox 1 uses and proposes a mapping')]
final class AnalyzeCommand extends Command
{
    public function __construct(private readonly Components $components)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('database', InputArgument::REQUIRED, 'The database of Frootbox 1, e.g. pdo-mysql://user:secret@localhost/old?charset=utf8mb4')
            ->addOption('files', null, InputOption::VALUE_REQUIRED, 'The folder of the installation below files/, to check the uploads')
            ->addOption('mapping', null, InputOption::VALUE_REQUIRED, 'Write a proposed mapping to this file (never overwrites)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $database = $input->getArgument('database');
        $files = $input->getOption('files');
        $mapping = $input->getOption('mapping');

        $connection = DriverManager::getConnection(new DsnParser()->parse(\is_string($database) ? $database : ''));
        $site = new SiteReader($connection)->read();
        $analysis = Analyzer::analyze($site, \is_string($files) ? $files : null);
        $connection->close();

        $this->report($io, $analysis);

        if (\is_string($mapping) && '' !== $mapping) {
            if (is_file($mapping)) {
                $io->warning(\sprintf('%s exists and stays as it is.', $mapping));
            } else {
                if (!is_dir(\dirname($mapping))) {
                    mkdir(\dirname($mapping), 0o775, true);
                }

                file_put_contents($mapping, MappingProposal::yaml($analysis, $this->components->all(ComponentKind::Block), $this->components->all(ComponentKind::Layout)));
                $io->success(\sprintf('Proposed mapping written to %s.', $mapping));
            }
        }

        return [] === $analysis->problems ? Command::SUCCESS : Command::FAILURE;
    }

    private function report(SymfonyStyle $io, Analysis $analysis): void
    {
        $site = $analysis->site;
        $blocks = array_sum(array_map(static fn(Page $page): int => \count($page->blocks), $site->pages));
        $elements = array_sum(array_map(static fn(Page $page): int => \count($page->elements), $site->pages));

        $io->title('Frootbox 1');
        $io->definitionList(
            ['Languages' => implode(', ', $site->languages)],
            ['Pages' => (string) \count($site->pages)],
            ['Blocks' => (string) $blocks],
            ['Plugins' => (string) $elements],
            ['Files' => (string) \count($site->files)],
            ['Navigations' => (string) \count($site->navigations)],
            ['Redirects' => (string) \count(array_filter($site->aliases, static fn($alias): bool => $alias->isRedirect()))],
        );

        $io->section('Pages');
        $rows = [];

        foreach ($site->pages as $page) {
            $parts = [];

            foreach ($page->elements as $element) {
                $parts[] = $element->socket . ': ' . $element->plugin() . '@' . $element->layout();
            }

            foreach ($page->blocks as $block) {
                $parts[] = $block->type;
            }

            $rows[] = [
                $page->id,
                str_repeat('  ', $site->depth($page)) . $page->title,
                '/' . $page->path,
                $page->isRedirect() ? 'redirect ' . $page->redirectTarget() : ('Public' === $page->visibility ? '' : strtolower($page->visibility)),
                implode(', ', $parts),
            ];
        }

        $io->table(['#', 'Title', 'Path', 'Note', 'Content'], $rows);

        $io->section('Blocks');
        $io->table(['Block', 'From', 'Uses', 'Fields', 'Options'], array_map(static fn($usage): array => [
            $usage->key,
            $usage->origin,
            $usage->count,
            implode("\n", $usage->describeFields()),
            self::options($usage->options),
        ], array_values($analysis->blocks)));

        $io->section('Plugins');
        $io->table(['Plugin@Layout', 'Uses', 'Fields', 'Options'], array_map(static fn($usage): array => [
            $usage->key,
            $usage->count,
            implode("\n", $usage->describeFields()),
            self::options($usage->options),
        ], array_values($analysis->plugins)));

        if ([] !== $analysis->items) {
            $io->section('Objects');
            $io->table(['Object', 'Uses', 'Fields'], array_map(static fn($usage): array => [
                $usage->key,
                $usage->count,
                implode("\n", $usage->describeFields()),
            ], array_values($analysis->items)));
        }

        if ([] !== $analysis->widgets) {
            $io->section('Widgets in texts');
            $io->table(['Widget', 'Uses', 'Fields', 'Options'], array_map(static fn($usage): array => [
                $usage->key,
                $usage->count,
                implode("\n", $usage->describeFields()),
                self::options($usage->options),
            ], array_values($analysis->widgets)));
        }

        $io->section('Navigations');
        $rows = [];

        foreach ($site->navigations as $navigation) {
            $items = [];

            foreach ($navigation->items as $item) {
                $items[] = $item->title . ' (' . $item->type() . (null === $item->pageId() ? '' : ' ' . $item->pageId()) . ')';
            }

            $rows[] = [$navigation->key, $navigation->title, implode(', ', $items)];
        }

        $io->table(['Key', 'Title', 'Items'], $rows);

        if ([] === $analysis->problems) {
            $io->success('Nothing found that needs a hand.');
        } else {
            $io->section('Problems');
            $io->listing($analysis->problems);
        }
    }

    /**
     * Options with the values they had, without the prefix of a layout's variables.
     *
     * @param array<string, array<string, int>> $options
     */
    private static function options(array $options): string
    {
        $lines = [];

        foreach ($options as $name => $values) {
            $values = array_filter(array_keys($values), static fn(string $value): bool => '' !== $value);

            if ([] !== $values) {
                $lines[] = preg_replace('/^variables\.index\./', '', $name) . ': ' . implode(' | ', \array_slice($values, 0, 4));
            }
        }

        return implode("\n", $lines);
    }
}
