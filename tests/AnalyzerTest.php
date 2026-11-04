<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Tests;

use Frootbox\MigrateFrootbox1\Analysis\Analyzer;
use Frootbox\MigrateFrootbox1\Analysis\MappingProposal;
use Frootbox\MigrateFrootbox1\Analysis\Usage;
use Frootbox\MigrateFrootbox1\Source\Block;
use Frootbox\MigrateFrootbox1\Source\SiteReader;
use Frootbox\MigrateFrootbox1\Source\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(SiteReader::class)]
#[CoversClass(Analyzer::class)]
#[CoversClass(MappingProposal::class)]
#[CoversClass(Usage::class)]
#[CoversClass(Uid::class)]
final class AnalyzerTest extends TestCase
{
    public function testReadsTheSiteWithItsContent(): void
    {
        $site = new SiteReader(OldSite::create())->read();

        self::assertSame(['de-DE'], $site->languages);
        self::assertSame([1, 2, 3], array_keys($site->pages));
        self::assertSame('ueber-uns', $site->pages[2]->path);
        self::assertTrue($site->pages[3]->isRedirect());

        $start = $site->pages[1];
        self::assertSame(['Teaser1', 'Text'], array_map(static fn(Block $block): string => $block->type, $start->blocks), 'Highest orderId first.');
        self::assertSame('Acme/Library', $start->blocks[0]->library());
        self::assertSame(['Eins', 'Zwei'], array_map(static fn($item): string => $item->title, $start->blocks[0]->content->items['teasers']));
        self::assertSame(2, $start->blocks[0]->content->items['teasers'][0]->linkedPage());
        self::assertSame('eins.jpg', $start->blocks[0]->content->items['teasers'][0]->content->file('background')?->name);
        self::assertSame('headline', $start->blocks[1]->content->text('title', 'de-DE')?->kind());
        self::assertSame(['columns' => '2'], $start->blocks[1]->variables());

        self::assertSame('Core/Navigation/Teaser', $start->elements[0]->plugin());
        self::assertSame('Index06', $start->elements[0]->layout());
        self::assertSame(['Slide'], array_map(static fn($item): string => $item->title, $start->elements[0]->content->items['items']));

        self::assertSame('Core/Images/Thumbnail', $site->widgets[1]->type());
        self::assertSame('© Jane', $site->widgets[1]->content->file('image')?->copyright);
        self::assertTrue($site->aliases[1]->isRedirect());
        self::assertSame('Page', $site->navigations[0]->items[0]->type());
        self::assertSame([true, false], array_map(static fn($item): bool => $item->visible, $site->navigations[0]->items));
    }

    public function testFindsWhatNeedsAHand(): void
    {
        $analysis = Analyzer::analyze(new SiteReader(OldSite::create())->read(), sys_get_temp_dir() . '/no-such-folder');

        self::assertSame(2, $analysis->blocks['Text']->count);
        self::assertEquals(['text-#' => ['html' => 2], 'title' => ['headline' => 1]], $analysis->blocks['Text']->fields);
        self::assertSame('Acme/Library', $analysis->blocks['Teaser1']->origin);
        self::assertSame(3, $analysis->items['Teaser']->count);
        self::assertSame(1, $analysis->widgets['Core/Images/Thumbnail']->count);
        self::assertSame(1, $analysis->plugins['Core/Navigation/Teaser@Index06']->count);
        self::assertContains('Page 3 "Alt" redirects to page 9, which does not exist.', $analysis->problems);
        self::assertContains('A widget 7 in field "text-1" of block 3 (Text) on page 2 is gone; the text keeps an empty figure.', $analysis->problems);
        self::assertContains('File 1 "team.jpg" is missing in uploads/team.jpg.', $analysis->problems);
    }

    public function testProposesAMappingToFillIn(): void
    {
        $yaml = MappingProposal::yaml(Analyzer::analyze(new SiteReader(OldSite::create())->read()));
        $mapping = Yaml::parse($yaml);

        self::assertIsArray($mapping);
        self::assertSame(['de-DE' => 'de'], $mapping['languages']);
        self::assertSame(['Default' => null], $mapping['layouts']);
        self::assertIsArray($mapping['blocks']);
        self::assertSame(['block' => null, 'fields' => ['text-#' => null, 'title' => null]], $mapping['blocks']['Text']);
        self::assertIsArray($mapping['plugins']);
        self::assertArrayHasKey('Core/Navigation/Teaser@Index06', $mapping['plugins']);
        self::assertSame(['Main' => null], $mapping['navigations']);
        self::assertStringContainsString('Text: # 2 time(s), project', $yaml);
    }
}
