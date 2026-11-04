<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Source;

/**
 * A page of the tree (pages, nested set).
 */
final class Page
{
    /** @var list<Block> in the order Frootbox 1 shows them */
    public array $blocks = [];

    /** @var list<Element> */
    public array $elements = [];

    public Content $content;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly int $id,
        public readonly int $parentId,
        public readonly int $lft,
        public readonly string $language,
        public readonly string $title,
        /** The whole path without slashes around it, e.g. kompetenzen/prophylaxe. */
        public readonly string $path,
        public readonly string $type,
        public readonly string $visibility,
        public readonly array $config,
    ) {
        $this->content = new Content();
    }

    public function isRoot(): bool
    {
        return 0 === $this->parentId;
    }

    /**
     * The last part of the path.
     */
    public function slug(): string
    {
        $parts = explode('/', $this->path);

        return end($parts);
    }

    /**
     * The page a redirect page points to, e.g. 3 for fbx://page:3.
     */
    public function redirectTarget(): string
    {
        return Row::pathString($this->config, 'redirect', 'target');
    }

    public function isRedirect(): bool
    {
        return 'Redirect' === $this->type || 'Redirect' === Row::pathString($this->config, 'pageType');
    }

    /**
     * The template of the page, if not the default.
     */
    public function view(): string
    {
        return Row::pathString($this->config, 'view');
    }

    /**
     * @return list<Block>
     */
    public function blocksIn(string $area): array
    {
        return array_values(array_filter($this->blocks, static fn(Block $block): bool => $block->area === $area));
    }
}
