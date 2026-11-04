<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Source;

/**
 * Where Frootbox 1 keeps a text or a file: the repository of the owner,
 * its id and the name of the field, e.g.
 * "Frootbox-Persistence-Content-Blocks-Repositories-Blocks:4:title".
 */
final readonly class Uid
{
    public function __construct(
        /** The last part of the repository, e.g. Blocks, Pages, Teasers. */
        public string $owner,
        public int $id,
        public string $field,
    ) {}

    public static function parse(string $uid): ?self
    {
        if (1 !== preg_match('/^([A-Za-z0-9-]+):(\d+):(.+)$/', $uid, $match)) {
            return null;
        }

        $parts = explode('-', $match[1]);

        return new self(end($parts), (int) $match[2], $match[3]);
    }

    public function key(): string
    {
        return $this->owner . ':' . $this->id;
    }
}
