<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Mapping;

/**
 * How a block, plugin or object of Frootbox 1 becomes a block (or an
 * entry of a list) of Frootbox 2, and a plugin of Frootbox 1 also the
 * plugin its page shows.
 */
final readonly class Rule
{
    /**
     * @param array<string, mixed>        $options Options of the block; "{config.x}" takes a value of the old configuration
     * @param array<string, Target|null>  $fields  Field of Frootbox 1 → target, null leaves it out
     * @param array<string, mixed>        $set     Fixed values of fields, e.g. text the old template had
     * @param array<string, string|null>  $widgets Type of a widget in the text → image field of the block, or "list.#.image" for all its files
     * @param bool                        $form    The plugin shows a form of Frootbox 1, which becomes a form of frootbox/forms
     * @param string|null                 $plugin  The page shows this plugin of Frootbox 2 in its default space, e.g. "frootbox/shop/product"; "block" may then be left out
     * @param list<string>                $needs   Fields of the block that must get a value, else the block is left out, e.g. the images of a slider
     * @param list<Rule>                  $then    Further blocks from the same block or plugin, right after it, e.g. the prices beside the calendar of a holiday apartment
     * @param list<string>                $areas   For a grid of Frootbox 1: the area of the block for each of its columns, in order
     */
    public function __construct(
        public string $block,
        public ?string $variant,
        public array $options,
        public array $fields,
        public array $set,
        public array $widgets,
        public bool $form,
        public ?string $plugin = null,
        public array $needs = [],
        public array $then = [],
        public array $areas = [],
    ) {}

    /**
     * Only the plugin, no block in the area.
     */
    public function onlyPlugin(): bool
    {
        return '' === $this->block;
    }
}
