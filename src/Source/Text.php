<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Source;

/**
 * A row of content_texts: HTML and, for headlines and icons, its config.
 */
final readonly class Text
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $field,
        public string $language,
        public ?string $html,
        public array $config,
    ) {}

    /**
     * What the field holds, judged from what is stored: a headline keeps
     * its parts in the config, an icon its name.
     */
    public function kind(): string
    {
        return match (true) {
            \array_key_exists('headline', $this->config) => 'headline',
            \array_key_exists('icon', $this->config) => 'icon',
            default => 'html',
        };
    }
}
