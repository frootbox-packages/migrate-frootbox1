<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Source;

/**
 * A row of files: an upload below files/<installation>/ attached to a
 * field by its UID.
 */
final readonly class File
{
    public function __construct(
        public int $id,
        public string $field,
        public string $name,
        public string $mime,
        public int $size,
        /** Relative to the folder of the installation, e.g. uploads/2023-06/…jpeg. */
        public string $path,
        public string $copyright,
        public string $title,
        public string $caption,
    ) {}

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
