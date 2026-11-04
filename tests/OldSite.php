<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * A small made-up website of Frootbox 1 in SQLite, with the tables and
 * columns the migration reads.
 */
final class OldSite
{
    private const array TABLES = [
        'pages' => 'id INTEGER PRIMARY KEY, parentId INT, lft INT, rgt INT, language TEXT, title TEXT, alias TEXT, config TEXT, visibility TEXT, type TEXT',
        'blocks' => 'id INTEGER PRIMARY KEY, pageId INT, uid TEXT, blockId TEXT, className TEXT, orderId INT, config TEXT, title TEXT, visibility INT',
        'content_texts' => 'id INTEGER PRIMARY KEY, uid TEXT, text TEXT, config TEXT, language TEXT',
        'files' => 'id INTEGER PRIMARY KEY, folderId INT, orderId INT, title TEXT, name TEXT, type TEXT, size INT, path TEXT, uid TEXT, copyright TEXT, config TEXT',
        'assets' => 'id INTEGER PRIMARY KEY, pageId INT, pluginId INT, parentId INT, orderId INT, className TEXT, uid TEXT, title TEXT, config TEXT, visibility INT',
        'content_elements' => 'id INTEGER PRIMARY KEY, pageId INT, socket TEXT, orderId INT, className TEXT, title TEXT, config TEXT, visibility INT',
        'content_widgets' => 'id INTEGER PRIMARY KEY, className TEXT, config TEXT, textUid TEXT',
        'aliases' => 'id INTEGER PRIMARY KEY, alias TEXT, itemId INT, itemModel TEXT, language TEXT, status INT, config TEXT',
        'navigations' => 'id INTEGER PRIMARY KEY, navId TEXT, title TEXT',
        'navigations_items' => 'id INTEGER PRIMARY KEY, navId INT, parentId INT, orderId INT, title TEXT, className TEXT, config TEXT, visibility INT',
    ];

    private const string BLOCKS = 'Frootbox-Persistence-Content-Blocks-Repositories-Blocks';

    public static function create(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (self::TABLES as $table => $columns) {
            $connection->executeStatement("CREATE TABLE {$table} ({$columns})");
        }

        $page = 'Frootbox-Persistence-Repositories-Pages';
        $connection->insert('pages', ['id' => 1, 'parentId' => 0, 'lft' => 1, 'rgt' => 6, 'language' => 'de-DE', 'title' => 'Start', 'alias' => '', 'visibility' => 'Public', 'type' => 'Default']);
        $connection->insert('pages', ['id' => 2, 'parentId' => 1, 'lft' => 2, 'rgt' => 3, 'language' => 'de-DE', 'title' => 'Über uns', 'alias' => 'ueber-uns', 'config' => '{"view":null}', 'visibility' => 'Public', 'type' => 'Default']);
        $connection->insert('pages', ['id' => 3, 'parentId' => 1, 'lft' => 4, 'rgt' => 5, 'language' => 'de-DE', 'title' => 'Alt', 'alias' => 'alt', 'config' => '{"pageType":"Redirect","redirect":{"target":"fbx:\/\/page:9"}}', 'visibility' => 'Public', 'type' => 'Redirect']);

        // Two blocks on the start page: the one with the higher orderId comes first.
        $connection->insert('blocks', ['id' => 1, 'pageId' => 1, 'uid' => $page . ':1:block-content', 'blockId' => 'Text', 'orderId' => 1, 'config' => '{"template":{"variables":{"columns":"2"}}}', 'visibility' => 2]);
        $connection->insert('blocks', ['id' => 2, 'pageId' => 1, 'uid' => $page . ':1:block-content', 'blockId' => 'Teaser1', 'className' => 'Frootbox\Ext\Acme\Library\Blocks\Teaser\Block', 'orderId' => 2, 'visibility' => 2]);
        $connection->insert('blocks', ['id' => 3, 'pageId' => 2, 'uid' => $page . ':2:block-content', 'blockId' => 'Text', 'visibility' => 2]);

        $connection->insert('content_texts', ['uid' => self::BLOCKS . ':1:title', 'text' => '<span class="head">Willkommen</span>', 'config' => '{"headline":"Willkommen","level":"h2"}', 'language' => 'de-DE']);
        $connection->insert('content_texts', ['uid' => self::BLOCKS . ':1:text-1', 'text' => '<figure data-id="1"></figure><p>Hallo</p>', 'language' => 'de-DE']);
        $connection->insert('content_texts', ['uid' => self::BLOCKS . ':3:text-1', 'text' => '<figure data-id="7"></figure><p>Wir</p>', 'language' => 'de-DE']);
        $connection->insert('content_widgets', ['id' => 1, 'className' => 'Frootbox\Ext\Core\Images\Widgets\Thumbnail\Widget', 'config' => '{"width":"6","align":"right"}', 'textUid' => self::BLOCKS . ':1:text-1']);
        $connection->insert('files', ['id' => 1, 'name' => 'team.jpg', 'type' => 'image/jpeg', 'size' => 10, 'path' => 'uploads/team.jpg', 'uid' => 'Frootbox-Persistence-Content-Repositories-Widgets:1:image', 'copyright' => '© Jane']);

        $teasers = 'Frootbox-Ext-Core-Navigation-Plugins-Teaser-Persistence-Repositories-Teasers';
        $teaser = 'Frootbox\Ext\Core\Navigation\Plugins\Teaser\Persistence\Teaser';
        $connection->insert('assets', ['id' => 1, 'pageId' => 1, 'pluginId' => 0, 'orderId' => 0, 'className' => $teaser, 'uid' => self::BLOCKS . ':2:teasers', 'title' => 'Eins', 'config' => '{"redirect":{"pageId":"2"}}', 'visibility' => 2]);
        $connection->insert('assets', ['id' => 2, 'pageId' => 1, 'pluginId' => 0, 'orderId' => 0, 'className' => $teaser, 'uid' => self::BLOCKS . ':2:teasers', 'title' => 'Zwei', 'visibility' => 2]);
        $connection->insert('assets', ['id' => 3, 'pageId' => 1, 'pluginId' => 1, 'orderId' => 0, 'className' => $teaser, 'title' => 'Slide', 'visibility' => 2]);
        $connection->insert('content_texts', ['uid' => $teasers . ':1:title', 'text' => 'Eins', 'config' => '{"headline":"Eins"}', 'language' => 'de-DE']);
        $connection->insert('files', ['id' => 2, 'name' => 'eins.jpg', 'type' => 'image/jpeg', 'size' => 10, 'path' => 'uploads/eins.jpg', 'uid' => $teasers . ':1:background']);

        $connection->insert('content_elements', ['id' => 1, 'pageId' => 1, 'socket' => 'Emotional', 'orderId' => 1, 'className' => 'Frootbox\Ext\Core\Navigation\Plugins\Teaser\Plugin', 'title' => 'Teaser', 'config' => '{"layout":{"index":"Index06"},"variables":{"index":{"crossFade":"on"}}}', 'visibility' => 2]);

        $connection->insert('aliases', ['alias' => 'ueber-uns', 'itemId' => 2, 'itemModel' => 'Frootbox\Persistence\Repositories\Pages', 'language' => 'de-DE', 'status' => 200, 'config' => '[]']);
        $connection->insert('aliases', ['alias' => 'about', 'itemId' => 2, 'itemModel' => 'Frootbox\Persistence\Repositories\Pages', 'language' => 'de-DE', 'status' => 301, 'config' => '{"target":"ueber-uns"}']);

        $connection->insert('navigations', ['id' => 1, 'navId' => 'Main', 'title' => 'Hauptnavigation']);
        $connection->insert('navigations_items', ['id' => 1, 'navId' => 1, 'parentId' => 0, 'title' => 'Über uns', 'className' => 'Frootbox\Ext\Core\Navigation\Navigations\Items\Page\Item', 'config' => '{"pageId":"2"}', 'visibility' => 2]);
        $connection->insert('navigations_items', ['id' => 2, 'navId' => 1, 'parentId' => 0, 'title' => 'Versteckt', 'className' => 'Frootbox\Ext\Core\Navigation\Navigations\Items\Page\Item', 'config' => '{"pageId":"1"}', 'visibility' => 0]);

        return $connection;
    }
}
