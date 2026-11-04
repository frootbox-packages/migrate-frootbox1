<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Analysis;

use Frootbox\Core\Component\Component;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes the first version of the mapping file (decision 0063): every
 * layout, block, plugin and navigation the old website uses, with the
 * fields found in the content. A block or layout of the same name in
 * Frootbox 2 is filled in, everything else stays ~ for a person (or the
 * AI) to choose. Comments tell how often each is used.
 */
final class MappingProposal
{
    /**
     * @param array<string, Component> $blocks  of the Frootbox 2 project
     * @param array<string, Component> $layouts of the Frootbox 2 project
     */
    public static function yaml(Analysis $analysis, array $blocks = [], array $layouts = []): string
    {
        $lines = [
            '# Mapping from Frootbox 1 to Frootbox 2 (decision 0063), proposed by fb1:analyze.',
            '# Fill in every ~ with a name of Frootbox 2, or false to leave it out.',
            '',
            'languages:',
        ];

        foreach ($analysis->site->languages as $language) {
            $lines[] = '  ' . $language . ': ' . strtolower(substr($language, 0, 2));
        }

        $lines[] = '';
        $lines[] = 'layouts:';

        foreach ($analysis->layouts as $usage) {
            $lines[] = \sprintf('  %s: %s # %d page(s)', $usage->key, self::guess($usage->key, $layouts), $usage->count);
        }

        $lines[] = '';
        $lines[] = 'blocks:';

        foreach ($analysis->blocks as $usage) {
            $target = self::find($usage->key, $blocks);
            $lines[] = \sprintf('  %s: # %d time(s), %s', $usage->key, $usage->count, $usage->origin);
            $lines[] = '    block: ' . (null === $target ? '~' : $target->name);
            $lines = [...$lines, ...self::fields($usage, $target)];
        }

        $lines[] = '';
        $lines[] = 'plugins:';

        foreach ($analysis->plugins as $usage) {
            $target = self::find(explode('/', explode('@', $usage->key)[0])[2] ?? '', $blocks);
            $lines[] = \sprintf('  %s: # %d time(s)', Yaml::dump($usage->key), $usage->count);
            $lines[] = '    block: ' . (null === $target ? '~' : $target->name);
            $lines = [...$lines, ...self::fields($usage, $target)];
        }

        if ([] !== $analysis->items) {
            $lines[] = '';
            $lines[] = '# Objects of plugins and blocks, as entries of a list field or of a module.';
            $lines[] = 'items:';

            foreach ($analysis->items as $usage) {
                $lines[] = \sprintf('  %s: # %d time(s), %s', $usage->key, $usage->count, $usage->origin);
                $lines = [...$lines, ...self::fields($usage, null, '    ')];
            }
        }

        $lines[] = '';
        $lines[] = 'navigations:';

        foreach ($analysis->site->navigations as $navigation) {
            $lines[] = \sprintf('  %s: ~ # %s, %d item(s)', $navigation->key, $navigation->title, \count($navigation->items));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return list<string>
     */
    private static function fields(Usage $usage, ?Component $target, string $indent = '    '): array
    {
        if ([] === $usage->fields) {
            return [];
        }

        $lines = [$indent . 'fields:'];
        ksort($usage->fields);

        foreach ($usage->fields as $field => $kinds) {
            $name = str_replace('-#', '', $field);
            $guess = null !== $target && isset($target->fields[$name]) ? $name : '~';
            $lines[] = \sprintf('%s  %s: %s # %s', $indent, Yaml::dump($field), $guess, implode(', ', array_keys($kinds)));
        }

        return $lines;
    }

    /**
     * @param array<string, Component> $components
     */
    private static function guess(string $name, array $components): string
    {
        return self::find($name, $components)->name ?? '~';
    }

    /**
     * A component whose own name (after the vendor) matches, ignoring case and digits at the end.
     *
     * @param array<string, Component> $components
     */
    private static function find(string $name, array $components): ?Component
    {
        $wanted = strtolower((string) preg_replace('/\d+$/', '', $name));

        if ('' === $wanted) {
            return null;
        }

        foreach ($components as $component) {
            $parts = explode('/', $component->name);

            if (strtolower(end($parts)) === $wanted) {
                return $component;
            }
        }

        return null;
    }
}
