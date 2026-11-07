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
}
