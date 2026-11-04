<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Source;

/**
 * Texts and files that hang on one owner (a page, block, plugin or
 * item) through their UIDs.
 */
final class Content
{
    /** @var array<string, array<string, Text>> field => language => text */
    public array $texts = [];

    /** @var array<string, list<File>> field => files */
    public array $files = [];

    /** @var array<string, list<Item>> field => items, e.g. the teasers of a block */
    public array $items = [];

    public function text(string $field, string $language): ?Text
    {
        return $this->texts[$field][$language] ?? null;
    }

    public function file(string $field): ?File
    {
        return $this->files[$field][0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        $fields = array_keys($this->texts + $this->files + $this->items);
        sort($fields);

        return array_map(strval(...), $fields);
    }
}
