<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Source;

use Doctrine\DBAL\Connection;

/**
 * Reads an installation of Frootbox 1 straight from its database and
 * changes nothing there (decision 0064).
 *
 * Texts and files hang on their owners by UID. Owners besides pages,
 * blocks, plugins and widgets are objects in assets, whatever their
 * repository is called.
 */
final readonly class SiteReader
{
    private const array OWNERS = ['Pages', 'Blocks', 'ContentElements', 'Widgets'];

    public function __construct(private Connection $connection) {}

    public function read(): Site
    {
        $contents = [];
        $content = static function (string $key) use (&$contents): Content {
            return $contents[$key] ??= new Content();
        };

        foreach ($this->rows('SELECT uid, language, text, config FROM content_texts ORDER BY id') as $row) {
            $uid = Uid::parse(Row::string($row, 'uid'));

            if (null !== $uid) {
                $content(self::key($uid))->texts[$uid->field][Row::string($row, 'language')] = new Text(
                    $uid->field,
                    Row::string($row, 'language'),
                    null === ($row['text'] ?? null) ? null : Row::string($row, 'text'),
                    Row::json($row, 'config'),
                );
            }
        }

        $files = [];

        foreach ($this->rows('SELECT * FROM files ORDER BY orderId, id') as $row) {
            $uid = Uid::parse(Row::string($row, 'uid'));
            $config = Row::json($row, 'config');
            $file = new File(
                Row::int($row, 'id'),
                null === $uid ? '' : $uid->field,
                Row::string($row, 'name'),
                Row::string($row, 'type'),
                Row::int($row, 'size'),
                Row::string($row, 'path'),
                Row::string($row, 'copyright'),
                Row::string($row, 'title'),
                Row::pathString($config, 'caption'),
            );
            $files[$file->id] = $file;

            if (null !== $uid) {
                $content(self::key($uid))->files[$uid->field][] = $file;
            }
        }

        $items = [];

        foreach ($this->rows('SELECT * FROM assets ORDER BY orderId DESC, id') as $row) {
            $id = Row::int($row, 'id');
            $item = new Item(
                $id,
                Row::string($row, 'className'),
                Row::string($row, 'title'),
                Row::int($row, 'parentId'),
                Row::int($row, 'orderId'),
                Row::int($row, 'visibility') > 0,
                Row::json($row, 'config'),
                $content('asset:' . $id),
            );
            $items[$id] = $item;
            $uid = Uid::parse(Row::string($row, 'uid'));

            if (null !== $uid) {
                $content(self::key($uid))->items[$uid->field][] = $item;
            } elseif (Row::int($row, 'pluginId') > 0) {
                $content('ContentElements:' . Row::int($row, 'pluginId'))->items['items'][] = $item;
            }
        }

        $pages = [];
        $languages = [];

        foreach ($this->rows('SELECT * FROM pages ORDER BY lft, id') as $row) {
            $page = new Page(
                Row::int($row, 'id'),
                Row::int($row, 'parentId'),
                Row::int($row, 'lft'),
                Row::string($row, 'language'),
                Row::string($row, 'title'),
                trim(Row::string($row, 'alias'), '/'),
                Row::string($row, 'type'),
                Row::string($row, 'visibility'),
                Row::json($row, 'config'),
            );
            $page->content = $content('Pages:' . $page->id);
            $pages[$page->id] = $page;
            $languages[$page->language] = true;
        }

        // Frootbox 1 shows blocks by orderId, highest first; NULL comes last.
        foreach ($this->rows('SELECT * FROM blocks ORDER BY orderId IS NULL, orderId DESC, id') as $row) {
            $uid = Uid::parse(Row::string($row, 'uid'));

            if (null === $uid || 'Pages' !== $uid->owner || !isset($pages[$uid->id])) {
                continue;
            }

            $page = $pages[$uid->id];

            $id = Row::int($row, 'id');
            $page->blocks[] = new Block(
                $id,
                $uid->field,
                Row::string($row, 'blockId'),
                Row::string($row, 'className'),
                Row::nullableInt($row, 'orderId'),
                Row::int($row, 'visibility') > 0,
                Row::json($row, 'config'),
                $content('Blocks:' . $id),
            );
        }

        foreach ($this->rows('SELECT * FROM content_elements ORDER BY orderId, id') as $row) {
            $page = $pages[Row::int($row, 'pageId')] ?? null;

            if (null === $page) {
                continue;
            }

            $id = Row::int($row, 'id');
            $page->elements[] = new Element(
                $id,
                Row::string($row, 'socket'),
                Row::string($row, 'className'),
                Row::string($row, 'title'),
                Row::int($row, 'orderId'),
                Row::int($row, 'visibility') > 0,
                Row::json($row, 'config'),
                $content('ContentElements:' . $id),
            );
        }

        $widgets = [];

        foreach ($this->rows('SELECT id, className, config FROM content_widgets') as $row) {
            $id = Row::int($row, 'id');
            $widgets[$id] = new Widget($id, Row::string($row, 'className'), Row::json($row, 'config'), $content('Widgets:' . $id));
        }

        return new Site($pages, $this->navigations(), $this->aliases(), $items, $files, array_map(strval(...), array_keys($languages)), $widgets);
    }

    /**
     * @return list<Navigation>
     */
    private function navigations(): array
    {
        $items = [];

        foreach ($this->rows('SELECT * FROM navigations_items ORDER BY orderId IS NULL, orderId, id') as $row) {
            $items[Row::int($row, 'navId')][] = new NavigationItem(
                Row::int($row, 'id'),
                Row::int($row, 'parentId'),
                Row::string($row, 'title'),
                Row::string($row, 'className'),
                Row::json($row, 'config'),
                Row::int($row, 'visibility') > 0,
            );
        }

        $navigations = [];

        foreach ($this->rows('SELECT * FROM navigations ORDER BY id') as $row) {
            $navigations[] = new Navigation(Row::int($row, 'id'), Row::string($row, 'navId'), Row::string($row, 'title'), $items[Row::int($row, 'id')] ?? []);
        }

        return $navigations;
    }

    /**
     * @return list<Alias>
     */
    private function aliases(): array
    {
        $aliases = [];

        foreach ($this->rows('SELECT * FROM aliases ORDER BY id') as $row) {
            $parts = explode('\\', Row::string($row, 'itemModel'));
            $aliases[] = new Alias(
                trim(Row::string($row, 'alias'), '/'),
                Row::int($row, 'itemId'),
                end($parts),
                Row::string($row, 'language'),
                Row::int($row, 'status'),
                Row::pathString(Row::json($row, 'config'), 'target'),
            );
        }

        return $aliases;
    }

    private static function key(Uid $uid): string
    {
        return \in_array($uid->owner, self::OWNERS, true) ? $uid->key() : 'asset:' . $uid->id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        $rows = [];

        foreach ($this->connection->fetchAllAssociative($sql) as $row) {
            $rows[] = Row::map($row);
        }

        return $rows;
    }
}
