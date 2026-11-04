<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Analysis;

/**
 * How often a block, plugin or layout is used, with the fields and
 * options found in the stored content.
 */
final class Usage
{
    public int $count = 0;

    /** @var array<string, array<string, int>> field => kind => count */
    public array $fields = [];

    /** @var array<string, array<string, int>> option => value => count */
    public array $options = [];

    /** @var array<int, true> pages it is on */
    public array $pages = [];

    public function __construct(
        public readonly string $key,
        /** Where it comes from, e.g. HerrUndFrauPixelExt/BlocksLibrary or the project. */
        public readonly string $origin = '',
    ) {}

    public function field(string $field, string $kind): void
    {
        $this->fields[$field][$kind] = ($this->fields[$field][$kind] ?? 0) + 1;
    }

    public function option(string $option, mixed $value): void
    {
        $text = \is_scalar($value) ? (string) $value : (string) json_encode($value);
        $this->options[$option][$text] = ($this->options[$option][$text] ?? 0) + 1;
    }

    /**
     * @return list<string> e.g. "title (headline)"
     */
    public function describeFields(): array
    {
        $fields = [];
        ksort($this->fields);

        foreach ($this->fields as $field => $kinds) {
            $fields[] = $field . ' (' . implode(', ', array_keys($kinds)) . ')';
        }

        return $fields;
    }
}
