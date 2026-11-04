<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Mapping;

/**
 * How a block, plugin or object of Frootbox 1 becomes a block (or an
 * entry of a list) of Frootbox 2.
 */
final readonly class Rule
{
    /**
     * @param array<string, mixed>        $options Options of the block; "{config.x}" takes a value of the old configuration
     * @param array<string, Target|null>  $fields  Field of Frootbox 1 → target, null leaves it out
     * @param array<string, mixed>        $set     Fixed values of fields, e.g. text the old template had
     * @param array<string, string|null>  $widgets Type of a widget in the text → image field of the block
     * @param bool                        $form    The plugin shows a form of Frootbox 1, which becomes a form of frootbox/forms
     */
    public function __construct(
        public string $block,
        public ?string $variant,
        public array $options,
        public array $fields,
        public array $set,
        public array $widgets,
        public bool $form,
    ) {}
}
