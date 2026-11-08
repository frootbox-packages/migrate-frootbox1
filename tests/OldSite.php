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
        'content_elements' => 'id INTEGER PRIMARY KEY, pageId INT, socket TEXT, orderId INT, className TEXT, title TEXT, config TEXT, visibility INT, inheritance TEXT',
        'content_widgets' => 'id INTEGER PRIMARY KEY, className TEXT, config TEXT, textUid TEXT',
        'aliases' => 'id INTEGER PRIMARY KEY, alias TEXT, pageId INT, itemId INT, itemModel TEXT, language TEXT, status INT, config TEXT',
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
        $connection->insert('pages', ['id' => 1, 'parentId' => 0, 'lft' => 1, 'rgt' => 6, 'language' => 'de-DE', 'title' => 'Start', 'alias' => '', 'config' => '{"variables":{"bannerStyle":"big-header"}}', 'visibility' => 'Public', 'type' => 'Default']);
        // The background image of the start page belongs to the page itself.
        $connection->insert('files', ['id' => 3, 'name' => 'kopf.jpg', 'type' => 'image/jpeg', 'size' => 10, 'path' => 'uploads/kopf.jpg', 'uid' => $page . ':1:background']);
        $connection->insert('pages', ['id' => 2, 'parentId' => 1, 'lft' => 2, 'rgt' => 3, 'language' => 'de-DE', 'title' => 'Über uns', 'alias' => 'ueber-uns', 'config' => '{"view":null,"seo":{"title":"Wer wir sind","description":"Alt.","preventIndexing":null},"search":{"preventIndexing":"1"}}', 'visibility' => 'Public', 'type' => 'Default']);
        $connection->insert('pages', ['id' => 3, 'parentId' => 1, 'lft' => 4, 'rgt' => 5, 'language' => 'de-DE', 'title' => 'Alt', 'alias' => 'alt', 'config' => '{"pageType":"Redirect","redirect":{"target":"fbx:\/\/page:9"}}', 'visibility' => 'Public', 'type' => 'Redirect']);

        // Two blocks on the start page: the one with the higher orderId comes first.
        $connection->insert('blocks', ['id' => 1, 'pageId' => 1, 'uid' => $page . ':1:block-content', 'blockId' => 'Text', 'orderId' => 1, 'config' => '{"template":{"variables":{"columns":"2"}}}', 'visibility' => 2]);
        $connection->insert('blocks', ['id' => 2, 'pageId' => 1, 'uid' => $page . ':1:block-content', 'blockId' => 'Teaser1', 'className' => 'Frootbox\Ext\Acme\Library\Blocks\Teaser\Block', 'orderId' => 2, 'visibility' => 2]);
        $connection->insert('blocks', ['id' => 3, 'pageId' => 2, 'uid' => $page . ':2:block-content', 'blockId' => 'Text', 'visibility' => 2]);
        $connection->insert('blocks', ['id' => 4, 'pageId' => 2, 'uid' => $page . ':2:block-content', 'blockId' => 'Gallery', 'orderId' => 1, 'visibility' => 2]);
        // The images of a gallery, as Frootbox 1 shows them: highest orderId first.
        $connection->insert('files', ['id' => 4, 'orderId' => 1, 'name' => 'zwei.jpg', 'type' => 'image/jpeg', 'size' => 10, 'path' => 'uploads/team.jpg', 'uid' => self::BLOCKS . ':4:images']);
        $connection->insert('files', ['id' => 5, 'orderId' => 2, 'name' => 'eins.jpg', 'type' => 'image/jpeg', 'size' => 10, 'path' => 'uploads/kopf.jpg', 'uid' => self::BLOCKS . ':4:images']);

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

        $connection->insert('aliases', ['alias' => 'ueber-uns', 'itemId' => 2, 'itemModel' => 'Frootbox\Persistence\Repositories\Pages', 'language' => 'de-DE', 'status' => 200, 'config' => '{"seo":{"description":"Wer wir sind  und <b>was</b> wir tun."}}']);
        $connection->insert('aliases', ['alias' => 'about', 'itemId' => 2, 'itemModel' => 'Frootbox\Persistence\Repositories\Pages', 'language' => 'de-DE', 'status' => 301, 'config' => '{"target":"ueber-uns"}']);

        $connection->insert('navigations', ['id' => 1, 'navId' => 'Main', 'title' => 'Hauptnavigation']);
        $connection->insert('navigations_items', ['id' => 1, 'navId' => 1, 'parentId' => 0, 'title' => 'Über uns', 'className' => 'Frootbox\Ext\Core\Navigation\Navigations\Items\Page\Item', 'config' => '{"pageId":"2"}', 'visibility' => 2]);
        $connection->insert('navigations_items', ['id' => 2, 'navId' => 1, 'parentId' => 0, 'title' => 'Versteckt', 'className' => 'Frootbox\Ext\Core\Navigation\Navigations\Items\Page\Item', 'config' => '{"pageId":"1"}', 'visibility' => 0]);

        return $connection;
    }

    /**
     * On the start page a slider shown on every page below with images by
     * page (none for the start page) and a sub navigation without a heading
     * of its own; in the text of page 2 a gallery widget with two images;
     * page 2 has an image and a teaser of its own.
     */
    public static function tree(Connection $connection): void
    {
        $connection->insert('content_elements', ['id' => 2, 'pageId' => 1, 'socket' => 'Emotional', 'orderId' => 1, 'className' => 'Frootbox\Ext\Core\Images\Plugins\Slider\Plugin', 'title' => 'Bilder', 'visibility' => 2, 'inheritance' => 'Inherited']);
        $connection->insert('content_elements', ['id' => 3, 'pageId' => 1, 'socket' => 'MainContent', 'orderId' => 1, 'className' => 'Frootbox\Ext\Core\Navigation\Plugins\SubNavigation\Plugin', 'title' => 'Unsere Seiten', 'visibility' => 2, 'inheritance' => 'None']);
        $elements = 'Frootbox-Persistence-Repositories-ContentElements';
        $connection->insert('files', ['id' => 20, 'orderId' => 1, 'name' => 'hinten.jpg', 'type' => 'image/jpeg', 'size' => 10, 'path' => 'uploads/kopf.jpg', 'uid' => $elements . ':2:files-2']);
        $connection->insert('files', ['id' => 21, 'orderId' => 2, 'name' => 'vorne.jpg', 'type' => 'image/jpeg', 'size' => 10, 'path' => 'uploads/team.jpg', 'uid' => $elements . ':2:files-2']);
        $connection->insert('content_widgets', ['id' => 7, 'className' => 'Frootbox\Ext\Core\Images\Widgets\Gallery\Widget', 'config' => '{"imageColumns":"4"}', 'textUid' => self::BLOCKS . ':3:text-1']);
        $widgets = 'Frootbox-Persistence-Content-Repositories-Widgets';
        $connection->insert('files', ['id' => 22, 'orderId' => 1, 'name' => 'b.jpg', 'type' => 'image/jpeg', 'size' => 10, 'path' => 'uploads/kopf.jpg', 'uid' => $widgets . ':7:images']);
        $connection->insert('files', ['id' => 23, 'orderId' => 2, 'name' => 'a.jpg', 'type' => 'image/jpeg', 'size' => 10, 'path' => 'uploads/team.jpg', 'uid' => $widgets . ':7:images']);
        $pages = 'Frootbox-Persistence-Repositories-Pages';
        $connection->insert('files', ['id' => 24, 'name' => 'seite.jpg', 'type' => 'image/jpeg', 'size' => 10, 'path' => 'uploads/kopf.jpg', 'uid' => $pages . ':2:image']);
        $connection->insert('content_texts', ['uid' => $pages . ':2:teaser-text', 'text' => '<p>Wer wir sind</p>', 'language' => 'de-DE']);
    }

    /**
     * A grid "6-6" on the start page between two texts of its own: a text
     * in each column (sockets MainContent_30_0 and MainContent_30_1) and a
     * text below the grid.
     */
    public static function grid(Connection $connection): void
    {
        $text = 'Frootbox\\Ext\\Core\\Editing\\Plugins\\Text\\Plugin';
        $connection->insert('content_elements', ['id' => 30, 'pageId' => 1, 'socket' => 'MainContent', 'orderId' => 5, 'className' => 'Frootbox\\Persistence\\Content\\Elements\\Grid', 'config' => '{"columns":"6-6"}', 'visibility' => 2, 'inheritance' => 'None']);
        $connection->insert('content_elements', ['id' => 31, 'pageId' => 1, 'socket' => 'MainContent_30_0', 'orderId' => 1, 'className' => $text, 'title' => 'Links', 'visibility' => 2, 'inheritance' => 'None']);
        $connection->insert('content_elements', ['id' => 32, 'pageId' => 1, 'socket' => 'MainContent_30_1', 'orderId' => 1, 'className' => $text, 'title' => 'Rechts', 'visibility' => 2, 'inheritance' => 'None']);
        $connection->insert('content_elements', ['id' => 33, 'pageId' => 1, 'socket' => 'MainContent', 'orderId' => 1, 'className' => $text, 'title' => 'Unten', 'visibility' => 2, 'inheritance' => 'None']);
        // An address hidden by the mail escape of Pixel-Fabrik: info@example.test.
        $connection->insert('content_texts', ['uid' => 'Frootbox-Persistence-Repositories-ContentElements:33:text', 'text' => '<p>Per <a data-xtg="1_eM.fzLA8bf@WfvW" href="/page/Ext/PixelFabrik/BaseTemplate/SaveMailEscape/send/1_eM.fzLA8bf@WfvW">E-Mail</a></p>', 'language' => 'de-DE']);
    }

    /**
     * Contact persons for the teaser plugin (id 1): two visible, one hidden,
     * the first with an older and a newer image.
     */
    public static function persons(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE persons (id INTEGER PRIMARY KEY, pluginId INT, pageId INT, orderId INT, className TEXT, config TEXT, gender TEXT, title TEXT, firstName TEXT, lastName TEXT, phone TEXT, email TEXT, position TEXT, visibility INT)');
        $contact = 'Frootbox\\Ext\\Core\\HelpAndSupport\\Persistence\\Contact';
        $connection->insert('persons', ['id' => 1, 'pluginId' => 1, 'pageId' => 1, 'orderId' => 2, 'className' => $contact, 'firstName' => 'Erika', 'lastName' => 'Muster', 'phone' => '0123 45', 'email' => 'erika@example.test', 'position' => 'Leitung', 'title' => '', 'visibility' => 2]);
        $connection->insert('persons', ['id' => 2, 'pluginId' => 1, 'pageId' => 1, 'orderId' => 1, 'className' => $contact, 'firstName' => 'Max', 'lastName' => 'Muster', 'position' => 'Lager', 'visibility' => 2]);
        $connection->insert('persons', ['id' => 3, 'pluginId' => 1, 'pageId' => 1, 'orderId' => 0, 'className' => $contact, 'firstName' => 'Versteckt', 'visibility' => 0]);
        // The address of Max shows him on the start page; his id is also the one of a page.
        $connection->insert('aliases', ['alias' => 'team/max-muster', 'pageId' => 1, 'itemId' => 2, 'itemModel' => 'Frootbox\Ext\Core\HelpAndSupport\Persistence\Repositories\Contacts', 'language' => 'de-DE', 'status' => 200, 'config' => '[]']);
        $contacts = 'Frootbox-Ext-Core-HelpAndSupport-Persistence-Repositories-Contacts';
        $connection->insert('files', ['id' => 10, 'orderId' => 2, 'name' => 'alt.jpg', 'type' => 'image/jpeg', 'size' => 10, 'path' => 'uploads/alt.jpg', 'uid' => $contacts . ':1:image']);
        $connection->insert('files', ['id' => 11, 'orderId' => 3, 'name' => 'neu.jpg', 'type' => 'image/jpeg', 'size' => 10, 'path' => 'uploads/neu.jpg', 'uid' => $contacts . ':1:image']);
    }
}
