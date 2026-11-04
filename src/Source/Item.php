<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Source;

/**
 * A row of assets: an object of a plugin or block such as a teaser, a
 * form or an article, told apart by its class.
 */
final readonly class Item
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public int $id,
        public string $className,
        public string $title,
        public int $parentId,
        public int $orderId,
        public bool $visible,
        public array $config,
        public Content $content,
    ) {}

    /**
     * The short class, e.g. Teaser for Frootbox\Ext\Core\Navigation\Plugins\Teaser\Persistence\Teaser.
     */
    public function type(): string
    {
        $parts = explode('\\', $this->className);

        return end($parts);
    }

    /**
     * The page an item links to, if it does.
     */
    public function linkedPage(): ?int
    {
        $id = Row::pathString($this->config, 'redirect', 'pageId');

        return ctype_digit($id) ? (int) $id : null;
    }
}
