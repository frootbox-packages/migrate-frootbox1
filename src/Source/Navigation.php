<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Source;

/**
 * A navigation with its items, e.g. BannerMain.
 */
final readonly class Navigation
{
    /**
     * @param list<NavigationItem> $items
     */
    public function __construct(
        public int $id,
        public string $key,
        public string $title,
        public array $items,
    ) {}
}
