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

    /**
     * Where an item links to: a page, an address of the website such as
     * "ferienwohnungen" or another website. Frootbox 1 keeps the one it
     * shows under redirect: pageId before intern before target. A link
     * switched off with "linkageDeactivated" is none.
     */
    public function link(): int|string|null
    {
        if (true === ($this->config['linkageDeactivated'] ?? false)) {
            return null;
        }

        $intern = Row::pathString($this->config, 'redirect', 'intern');
        $target = Row::pathString($this->config, 'redirect', 'target');

        return $this->linkedPage() ?? match (true) {
            '' !== $intern => '/' . ltrim($intern, '/'),
            '' !== $target => $target,
            default => null,
        };
    }
}
