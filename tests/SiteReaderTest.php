<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Tests;

use Frootbox\MigrateFrootbox1\Source\Item;
use Frootbox\MigrateFrootbox1\Source\SiteReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SiteReader::class)]
final class SiteReaderTest extends TestCase
{
    public function testReadsContactPersonsAsAListOfTheirPlugin(): void
    {
        $connection = OldSite::create();
        OldSite::persons($connection);

        $site = new SiteReader($connection)->read();
        $persons = $site->pages[1]->elements[0]->content->items['persons'];

        self::assertSame(['Erika Muster', 'Max Muster', 'Versteckt'], array_map(static fn(Item $item): string => $item->title, $persons), 'Highest orderId first, as Frootbox 1 shows them.');
        self::assertSame('Contact', $persons[0]->type());
        self::assertSame(['firstName' => 'Erika', 'lastName' => 'Muster', 'phone' => '0123 45', 'email' => 'erika@example.test', 'position' => 'Leitung'], $persons[0]->config);
        self::assertFalse($persons[2]->visible);
        self::assertSame('neu.jpg', $persons[0]->content->file('image')?->name, 'Of two images Frootbox 1 shows the one with the higher orderId.');
        self::assertArrayNotHasKey('image', $site->items[1]->content->files, 'Files of a contact do not land on the object with the same id.');
    }

    public function testOrdersNavigationsAsFrootbox1(): void
    {
        $connection = OldSite::create();
        $item = 'Frootbox\\Ext\\Core\\Navigation\\Navigations\\Items\\Page\\Item';
        $connection->insert('navigations_items', ['id' => 3, 'navId' => 1, 'parentId' => 0, 'orderId' => 1, 'title' => 'Kontakt', 'className' => $item, 'config' => '{"pageId":"2"}', 'visibility' => 2]);
        $connection->insert('navigations_items', ['id' => 4, 'navId' => 1, 'parentId' => 0, 'orderId' => 2, 'title' => 'Start', 'className' => $item, 'config' => '{"pageId":"1"}', 'visibility' => 2]);

        $titles = array_map(static fn($item): string => $item->title, new SiteReader($connection)->read()->navigations[0]->items);

        self::assertSame(['Start', 'Kontakt', 'Über uns', 'Versteckt'], $titles, 'Highest orderId first, without one last.');
    }

    public function testAPageKeepsTheAddressItHadLive(): void
    {
        $connection = OldSite::create();
        OldSite::suffixed($connection);
        $connection->insert('pages', ['id' => 4, 'parentId' => 1, 'lft' => 6, 'rgt' => 7, 'language' => 'de-DE', 'title' => 'Kurs 2', 'alias' => 'kurs-2', 'visibility' => 'Public', 'type' => 'Default']);
        $connection->insert('aliases', ['alias' => 'kurs', 'pageId' => 4, 'itemId' => 4, 'itemModel' => 'Frootbox\\Persistence\\Repositories\\Pages', 'language' => 'de-DE', 'status' => 301, 'config' => '{"target":"kurs-2"}']);

        $pages = new SiteReader($connection)->read()->pages;

        self::assertSame('ueber-uns', $pages[2]->path, 'Not "ueber-uns-1" from pages.alias: "ueber-uns" still leads to the page.');
        self::assertSame('', $pages[1]->path, 'Not "-2": the start page had no path.');
        self::assertSame('kurs-2', $pages[4]->path, 'A number of its own stays when the address without it only redirects.');
    }

    public function testOnlyRowsVisitorsSeeAreVisible(): void
    {
        $connection = OldSite::create();
        $link = 'Frootbox\\Ext\\Core\\HelpAndSupport\\Plugins\\Links\\Persistence\\Link';
        $connection->insert('assets', ['id' => 10, 'pageId' => 1, 'pluginId' => 1, 'orderId' => 2, 'className' => $link, 'title' => 'Öffentlich', 'visibility' => 2]);
        $connection->insert('assets', ['id' => 11, 'pageId' => 1, 'pluginId' => 1, 'orderId' => 1, 'className' => $link, 'title' => 'Nur Redaktion', 'visibility' => 1]);

        $items = new SiteReader($connection)->read()->items;

        self::assertTrue($items[10]->visible);
        self::assertFalse($items[11]->visible, 'Frootbox 1 shows visibility 1 only to editors who are signed in.');
    }
}
