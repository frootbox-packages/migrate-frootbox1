<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Source;

final readonly class NavigationItem
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public int $id,
        public int $parentId,
        public string $title,
        public string $className,
        public array $config,
    ) {}

    /**
     * E.g. Page, Children, Url or CookieLink.
     */
    public function type(): string
    {
        return 1 === preg_match('/Items\\\\([^\\\\]+)\\\\Item$/', $this->className, $match) ? $match[1] : $this->className;
    }

    public function pageId(): ?int
    {
        $id = Row::pathString($this->config, 'pageId');

        return ctype_digit($id) ? (int) $id : null;
    }
}
