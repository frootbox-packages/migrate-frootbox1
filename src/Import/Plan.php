<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Import;

use Symfony\Component\Yaml\Yaml;

/**
 * What the import does, as a seed (decision 0109): the steps, the files
 * they upload and a report of what stayed behind.
 */
final readonly class Plan
{
    /**
     * @param list<array<string, mixed>> $steps
     * @param array<string, string>      $files  Path in the seed folder → file of Frootbox 1
     * @param list<string>               $report What could not be taken over, for a person to look at
     */
    public function __construct(
        public array $steps,
        public array $files,
        public array $report,
    ) {}

    /**
     * The seed file, readable for a person.
     */
    public function yaml(string $source): string
    {
        $head = [
            '# The website from Frootbox 1 (' . $source . '), written by fb1:import.',
            '# Run it on an empty website: php bin/frootbox seed <this file>',
            '# Changes belong in the mapping, this file is written anew each time.',
            '',
        ];

        return implode("\n", $head) . Yaml::dump($this->steps, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    /**
     * The report as Markdown.
     */
    public function markdown(): string
    {
        $lines = ['# Report of the import', ''];

        if ([] === $this->report) {
            $lines[] = 'Everything was taken over.';
        }

        foreach ($this->report as $line) {
            $lines[] = '- ' . $line;
        }

        return implode("\n", $lines) . "\n";
    }
}
