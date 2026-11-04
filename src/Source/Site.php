<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Source;

/**
 * Everything read from one installation of Frootbox 1.
 */
final readonly class Site
{
    /**
     * @param array<int, Page>       $pages       by id, in the order of the tree
     * @param list<Navigation>       $navigations
     * @param list<Alias>            $aliases
     * @param array<int, Item>       $items       all objects by id, e.g. forms
     * @param array<int, File>       $files       by id
     * @param list<string>           $languages
     * @param array<int, Widget>     $widgets     by id
     */
    public function __construct(
        public array $pages,
        public array $navigations,
        public array $aliases,
        public array $items,
        public array $files,
        public array $languages,
        public array $widgets,
    ) {}

    public function page(int $id): ?Page
    {
        return $this->pages[$id] ?? null;
    }

    public function root(): ?Page
    {
        foreach ($this->pages as $page) {
            if ($page->isRoot()) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @return list<Page>
     */
    public function children(int $parentId): array
    {
        return array_values(array_filter($this->pages, static fn(Page $page): bool => $page->parentId === $parentId));
    }

    /**
     * How deep a page lies, the root is 0.
     */
    public function depth(Page $page): int
    {
        $depth = 0;

        for ($parent = $this->page($page->parentId); null !== $parent && $depth < 50; $parent = $this->page($parent->parentId)) {
            ++$depth;
        }

        return $depth;
    }
}
