<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Analysis;

use Frootbox\MigrateFrootbox1\Source\Site;

/**
 * What a website of Frootbox 1 uses, as the base of the mapping
 * (decision 0063).
 */
final readonly class Analysis
{
    /**
     * @param array<string, Usage> $layouts
     * @param array<string, Usage> $blocks
     * @param array<string, Usage> $plugins     plugin@layout
     * @param array<string, Usage> $items       objects of plugins and blocks by class
     * @param array<string, Usage> $widgets     elements inside texts
     * @param list<string>         $problems
     * @param Usage|null           $page        fields and variables of the pages themselves, e.g. a background image
     */
    public function __construct(
        public Site $site,
        public array $layouts,
        public array $blocks,
        public array $plugins,
        public array $items,
        public array $widgets,
        public array $problems,
        public ?Usage $page = null,
    ) {}
}
