<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Source;

/**
 * Reads typed values from a row of the old database, whose columns are
 * often NULL or JSON.
 */
final class Row
{
    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableInt(array $row, string $column): ?int
    {
        $value = $row[$column] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function json(array $row, string $column): array
    {
        return self::decode(self::string($row, $column));
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $json): array
    {
        if ('' === $json) {
            return [];
        }

        return self::map(json_decode($json, true));
    }

    /**
     * An array with string keys, or an empty one for anything else.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $data): array
    {
        if (!\is_array($data)) {
            return [];
        }

        $result = [];

        foreach ($data as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * A value deep in decoded JSON, e.g. path(config, 'layout', 'index').
     *
     * @param array<string, mixed> $data
     */
    public static function path(array $data, string ...$keys): mixed
    {
        $value = $data;

        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function pathString(array $data, string ...$keys): string
    {
        $value = self::path($data, ...$keys);

        return \is_scalar($value) ? (string) $value : '';
    }
}
