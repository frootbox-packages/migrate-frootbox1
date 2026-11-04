<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Source;

/**
 * A block in a block area of a page.
 */
final readonly class Block
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public int $id,
        /** The field of the area on the page, e.g. block-content. */
        public string $area,
        /** The block, e.g. TextMain or Teaser1. */
        public string $type,
        /** The class when it comes from a library, e.g. …\BlocksLibrary\Blocks\Teaser\Block. */
        public string $className,
        public ?int $orderId,
        public bool $visible,
        public array $config,
        public Content $content,
    ) {}

    /**
     * The extension a library block comes from, e.g. HerrUndFrauPixelExt/BlocksLibrary.
     */
    public function library(): string
    {
        return 1 === preg_match('/^Frootbox\\\\Ext\\\\([^\\\\]+)\\\\([^\\\\]+)\\\\Blocks\\\\/', $this->className, $match) ? $match[1] . '/' . $match[2] : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function variables(): array
    {
        return Row::map(Row::path($this->config, 'template', 'variables'));
    }
}
