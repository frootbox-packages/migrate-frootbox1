<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Mapping;

/**
 * Where a field of Frootbox 1 goes: a field of Frootbox 2, in a list as
 * "items.#.title", and for a link the text it shows.
 */
final readonly class Target
{
    public function __construct(
        public string $path,
        public ?string $text = null,
    ) {}
}
