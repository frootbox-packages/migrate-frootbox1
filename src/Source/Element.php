<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Source;

/**
 * A plugin in a socket of a page (content_elements).
 */
final readonly class Element
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public int $id,
        public string $socket,
        public string $className,
        public string $title,
        public int $orderId,
        public bool $visible,
        public array $config,
        public Content $content,
    ) {}

    /**
     * E.g. Core/Navigation/Teaser for Frootbox\Ext\Core\Navigation\Plugins\Teaser\Plugin.
     */
    public function plugin(): string
    {
        return 1 === preg_match('/^Frootbox\\\\Ext\\\\([^\\\\]+)\\\\([^\\\\]+)\\\\Plugins\\\\([^\\\\]+)\\\\/', $this->className, $match)
            ? $match[1] . '/' . $match[2] . '/' . $match[3]
            : $this->className;
    }

    public function layout(): string
    {
        $layout = Row::pathString($this->config, 'layout', 'index');

        return '' === $layout ? 'Index01' : $layout;
    }
}
