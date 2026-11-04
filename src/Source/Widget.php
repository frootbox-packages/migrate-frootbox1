<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Source;

/**
 * A small element inside a text (content_widgets), placed there as
 * <figure data-id="…">, e.g. an image floating next to the text.
 */
final readonly class Widget
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public int $id,
        public string $className,
        public array $config,
        public Content $content,
    ) {}

    /**
     * E.g. Core/Images/Thumbnail.
     */
    public function type(): string
    {
        return 1 === preg_match('/^Frootbox\\\\Ext\\\\([^\\\\]+)\\\\([^\\\\]+)\\\\Widgets\\\\([^\\\\]+)\\\\/', $this->className, $match)
            ? $match[1] . '/' . $match[2] . '/' . $match[3]
            : $this->className;
    }
}
