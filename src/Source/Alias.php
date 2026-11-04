<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Source;

/**
 * An address of the old website (aliases): a page or object, or a
 * redirect with status 301.
 */
final readonly class Alias
{
    public function __construct(
        public string $path,
        public int $itemId,
        /** The last part of the repository, e.g. Pages or Teasers. */
        public string $owner,
        public string $language,
        public int $status,
        public string $target,
    ) {}

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }
}
