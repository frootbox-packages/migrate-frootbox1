<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Tests;

use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Field\Field;
use Frootbox\MigrateFrootbox1\Import\Importer;
use Frootbox\MigrateFrootbox1\Import\Plan;
use Frootbox\MigrateFrootbox1\Mapping\Mapping;
use Frootbox\MigrateFrootbox1\Mapping\MappingException;
use Frootbox\MigrateFrootbox1\Mapping\Rule;
use Frootbox\MigrateFrootbox1\Mapping\Target;
use Frootbox\MigrateFrootbox1\Source\SiteReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Importer::class)]
#[CoversClass(Plan::class)]
#[CoversClass(Mapping::class)]
#[CoversClass(Rule::class)]
#[CoversClass(Target::class)]
final class ImporterTest extends TestCase
{
    private string $files;

    protected function setUp(): void
    {
        $this->files = sys_get_temp_dir() . '/fb1-import-' . bin2hex(random_bytes(4));
        mkdir($this->files . '/uploads', 0o775, true);
        file_put_contents($this->files . '/uploads/team.jpg', 'jpeg');
    }

    protected function tearDown(): void
    {
        unlink($this->files . '/uploads/team.jpg');
        rmdir($this->files . '/uploads');
        rmdir($this->files);
    }

    public function testWritesTheWebsiteAsStepsOfASeed(): void
    {
        $plan = $this->plan(true);
        $steps = array_map(static fn(array $step): string => (string) array_key_first($step), $plan->steps);

        self::assertSame([
            'upload', 'media.create',
            'page.create', 'page.create',
            'page.update', 'page.update',
            'navigation.update', 'site.update', 'redirect.create',
            'page.publish', 'navigation.publish', 'site.publish',
        ], $steps, 'The redirect page gets no page of its own.');
        self::assertSame(['media/1-team.jpg' => $this->files . '/uploads/team.jpg'], $plan->files);
        self::assertSame(['upload' => '${u1.token}', 'copyright' => '© Jane'], $plan->steps[1]['media.create']);
        self::assertSame('m1', $plan->steps[1]['as']);
        self::assertSame(['layout' => 'acme/site/Page', 'parentId' => '${p1.id}', 'translations' => ['de' => ['title' => 'Über uns', 'slug' => 'ueber-uns']]], $plan->steps[3]['page.create']);
    }

    public function testFillsTheBlocksByTheMapping(): void
    {
        $main = [
            [
                'block' => 'acme/site/Topics',
                'fields' => ['items' => [
                    ['title' => 'Eins', 'link' => ['target' => 'page:${p2.id}', 'text' => 'Mehr']],
                    ['title' => 'Zwei'],
                ]],
            ],
            [
                'block' => 'acme/site/Text',
                'fields' => [
                    'title' => 'Willkommen',
                    'columns' => [['body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hallo']]]]]]],
                    'image' => ['media' => '${m1.id}'],
                ],
            ],
        ];

        self::assertSame(
            ['page.update' => ['id' => '${p1.id}', 'translations' => ['de' => ['content' => ['areas' => ['main' => $main]]]]]],
            $this->plan()->steps[4],
            'Highest orderId first; the widget in the text becomes the image of the block.',
        );
    }

    public function testTakesOverNavigationsFieldsAndOldAddresses(): void
    {
        $plan = $this->plan();
        $byName = [];

        foreach ($plan->steps as $step) {
            foreach ($step as $action => $input) {
                $byName[$action] = $input;
            }
        }

        self::assertSame(['name' => 'main', 'items' => ['de' => [['type' => 'target', 'target' => 'page:${p2.id}']]]], $byName['navigation.update']);
        self::assertSame(['language' => 'de', 'fields' => ['brand' => 'Acme', 'contact' => ['target' => 'page:${p2.id}', 'text' => 'Kontakt']]], $byName['site.update']);
        self::assertSame(['path' => '/about', 'target' => 'page:${p2.id}'], $byName['redirect.create']);
        self::assertArrayNotHasKey('page.publish', $byName, 'Without --publish everything stays a draft.');
    }

    public function testTakesOverTheSettingsForSearchEngines(): void
    {
        $body = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Wir']]]]];
        $main = [['block' => 'acme/site/Text', 'fields' => ['columns' => [['body' => $body]]]]];

        self::assertSame(
            ['page.update' => [
                'id' => '${p2.id}',
                'excludeFromSiteSearch' => 'page',
                'translations' => ['de' => ['content' => ['areas' => ['main' => $main]], 'description' => 'Wer wir sind und was wir tun.']],
            ]],
            $this->plan()->steps[5],
            'The description of the address wins, on one line without tags.',
        );
    }

    public function testReportsWhatStaysBehind(): void
    {
        $plan = $this->plan();

        self::assertSame([
            'The image team.jpg has no description (alt text) yet.',
            'Block 3 (Text) on page 2 "Über uns": a widget 7 in the text is left out.',
            'Page 2 "Über uns" has its own title for search engines, "Wer wir sind"; Frootbox 2 has no field for it yet.',
            'Navigation "Main": the entry "Versteckt" is hidden and left out.',
            'The old address /alt leads to no page and gets no redirect.',
        ], $plan->report);
        self::assertStringContainsString("- Navigation \"Main\": the entry \"Versteckt\" is hidden and left out.\n", $plan->markdown());
        self::assertStringStartsWith('# The website from Frootbox 1 (old), written by fb1:import.', $plan->yaml('old'));
    }

    public function testRejectsAMappingThatIsNotFilledIn(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('blocks.Text.block is not filled in.');

        Mapping::fromArray(['languages' => ['de-DE' => 'de'], 'blocks' => ['Text' => null]]);
    }

    private function plan(bool $publish = false): Plan
    {
        $text = self::block('acme/site/Text', [
            'title' => new Field('title', 'headline', 'Title', optional: true),
            'columns' => new Field('columns', 'list', 'Columns', fields: ['body' => new Field('body', 'richtext', 'Text')]),
            'image' => new Field('image', 'image', 'Image', optional: true),
        ]);
        $topics = self::block('acme/site/Topics', [
            'title' => new Field('title', 'headline', 'Title', optional: true),
            'items' => new Field('items', 'list', 'Topics', fields: [
                'title' => new Field('title', 'headline', 'Title'),
                'link' => new Field('link', 'link', 'Link', optional: true),
            ]),
        ]);
        $components = new Components(
            blocks: ['acme/site/Text' => $text, 'acme/site/Topics' => $topics],
            layouts: ['acme/site/Page' => new Component(ComponentKind::Layout, 'acme/site/Page', '/tmp', 'Page', '', [], [], [])],
        );
        $mapping = Mapping::fromArray([
            'languages' => ['de-DE' => 'de'],
            'domains' => ['www.example.com'],
            'layouts' => ['Default' => 'acme/site/Page'],
            'blocks' => [
                'Text' => ['block' => 'acme/site/Text', 'fields' => ['title' => 'title', 'text-#' => 'columns.#.body'], 'widgets' => ['Core/Images/Thumbnail' => 'image']],
                'Teaser1' => ['block' => 'acme/site/Topics', 'fields' => ['title' => 'title', 'teasers' => 'items']],
            ],
            'plugins' => ['Core/Navigation/Teaser@Index06' => false],
            'items' => ['Teaser' => ['fields' => ['title' => 'title', 'link' => ['to' => 'link', 'text' => 'Mehr'], 'background' => false]]],
            'navigations' => ['Main' => 'main'],
            'site' => ['fields' => ['brand' => 'Acme', 'contact' => ['target' => '{page:2}', 'text' => 'Kontakt']]],
        ]);

        return new Importer(new SiteReader(OldSite::create())->read(), $mapping, $components, $this->files)->plan($publish);
    }

    /**
     * @param array<string, Field> $fields
     */
    private static function block(string $name, array $fields): Component
    {
        return new Component(ComponentKind::Block, $name, '/tmp', $name, '', $fields, [], []);
    }
}
