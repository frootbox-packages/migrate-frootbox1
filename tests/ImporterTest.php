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
        file_put_contents($this->files . '/uploads/kopf.jpg', 'jpeg');
    }

    protected function tearDown(): void
    {
        unlink($this->files . '/uploads/team.jpg');
        unlink($this->files . '/uploads/kopf.jpg');
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

    public function testAPluginOfFrootbox1BecomesThePluginOfItsPage(): void
    {
        $steps = $this->plan(true, teaser: ['plugin' => 'acme/site/topic'])->steps;
        $assign = array_values(array_filter($steps, static fn(array $step): bool => isset($step['plugin.assign'])));
        $updates = array_keys(array_filter($steps, static fn(array $step): bool => isset($step['page.update'])));

        self::assertSame([['plugin.assign' => ['id' => '${p1.id}', 'type' => 'acme/site/topic']]], $assign);
        self::assertNotSame([], $updates);
        self::assertGreaterThan(max($updates), array_search($assign[0], $steps, true), 'After the content: the view of the plugin joins it.');
        self::assertSame(['acme/site/Topics', 'acme/site/Text'], array_column(self::main($steps[4]), 'block'), 'Without "block" the plugin is no block in the area, the topics are the block Teaser1.');

        $both = $this->plan(teaser: ['block' => 'acme/site/Topics', 'plugin' => 'acme/site/topic'])->steps;

        self::assertSame(['acme/site/Topics', 'acme/site/Topics', 'acme/site/Text'], array_column(self::main($both[4]), 'block'));
        self::assertContains(['plugin.assign' => ['id' => '${p1.id}', 'type' => 'acme/site/topic']], $both);
    }

    public function testTheBlockOfThePluginBecomesItsView(): void
    {
        $steps = $this->plan(teaser: ['block' => 'acme/site/TopicView', 'plugin' => 'acme/site/topic', 'fields' => ['title' => 'title'], 'set' => ['title' => 'Willkommen'], 'options' => ['tag' => 'startseite']])->steps;

        self::assertSame(['acme/site/Topics', 'acme/site/Text'], array_column(self::main($steps[4]), 'block'), 'The view is no block in the area.');
        $view = self::at($steps[4], 'page.update', 'translations', 'de', 'content', 'plugin');
        self::assertIsArray($view);
        self::assertSame('acme/site/TopicView', $view['block']);
        self::assertSame(['tag' => 'startseite'], $view['options']);
        self::assertSame('Willkommen', self::at($view, 'fields', 'title'));
        self::assertContains(['plugin.assign' => ['id' => '${p1.id}', 'type' => 'acme/site/topic']], $steps);
    }

    public function testAPluginNeedsAPlaceInTheLayout(): void
    {
        $plan = $this->plan(teaser: ['plugin' => 'acme/site/topic'], pluginPlace: false);

        self::assertSame([], array_filter($plan->steps, static fn(array $step): bool => isset($step['plugin.assign'])));
        self::assertStringContainsString('cannot show the plugin acme/site/topic, its layout acme/site/Page has no place for one', $plan->markdown());
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
                'translations' => ['de' => ['content' => ['areas' => ['main' => $main]], 'description' => 'Wer wir sind und was wir tun.', 'seoTitle' => 'Wer wir sind']],
            ]],
            $this->plan()->steps[5],
            'Title and description of the address win, on one line without tags.',
        );
    }

    public function testReportsWhatStaysBehind(): void
    {
        $plan = $this->plan();

        self::assertSame([
            'Page 1 "Start": the field "background" of the page is left out, the mapping has no "page".',
            'The image team.jpg has no description (alt text) yet; the mapping can give it one under media.',
            'Block 3 (Text) on page 2 "Über uns": a widget 7 in the text is left out.',
            'Navigation "Main": the entry "Versteckt" is hidden and left out.',
            'The old address /alt leads to no page and gets no redirect.',
        ], $plan->report);
        self::assertStringContainsString("- Navigation \"Main\": the entry \"Versteckt\" is hidden and left out.\n", $plan->markdown());
        self::assertStringStartsWith('# The website from Frootbox 1 (old), written by fb1:import.', $plan->yaml('old'));
    }

    public function testPutsABlockFromThePageItselfOnTop(): void
    {
        $plan = $this->plan(page: [
            'block' => 'acme/site/Banner',
            'fields' => ['background' => 'image'],
            'options' => [
                'height' => ['value' => '{config.variables.bannerStyle}', 'map' => ['big-header' => 'large', 'small-header' => 'small']],
                'tone' => '{config.variables.tone}',
            ],
        ]);
        $start = self::main($plan->steps[6]);
        $about = self::main($plan->steps[7]);

        self::assertSame(['block' => 'acme/site/Banner', 'options' => ['height' => 'large'], 'fields' => ['image' => ['media' => '${m3.id}']]], $start[0], 'A placeholder without a value leaves its option out.');
        self::assertSame('acme/site/Topics', self::at($start, 1, 'block'));
        self::assertSame(['block' => 'acme/site/Banner', 'fields' => []], $about[0], 'A page without variables gets the defaults of the block.');
        self::assertSame(['media.create' => ['upload' => '${u3.token}'], 'as' => 'm3'], $plan->steps[1], 'The image of the page comes first.');
        self::assertNotContains('Page 1 "Start": the field "background" of the page is left out, the mapping has no "page".', $plan->report);
    }

    public function testGivesAPageANewTitleAndKeepsItsAddress(): void
    {
        $plan = $this->plan(pages: [2 => ['title' => 'Wir']]);

        self::assertSame(['title' => 'Wir', 'slug' => 'ueber-uns'], self::at($plan->steps[3], 'page.create', 'translations', 'de'));
    }

    public function testPutsTheBlockFromThePageIntoItsOwnArea(): void
    {
        $plan = $this->plan(page: ['block' => 'acme/site/Banner', 'area' => 'head']);
        $start = array_values(array_filter($plan->steps, static fn(array $step): bool => '${p1.id}' === self::at($step, 'page.update', 'id')))[0];
        $areas = self::at($start, 'page.update', 'translations', 'de', 'content', 'areas');

        self::assertIsArray($areas);
        self::assertSame(['head', 'main'], array_keys($areas));
        self::assertSame([['block' => 'acme/site/Banner', 'fields' => []]], $areas['head']);
        self::assertSame('acme/site/Topics', self::at($areas, 'main', 0, 'block'));
    }

    public function testTheOldAddressOfAnObjectLeadsToItsPage(): void
    {
        $redirects = array_values(array_filter($this->plan(persons: true)->steps, static fn(array $step): bool => isset($step['redirect.create'])));

        self::assertContains(['redirect.create' => ['path' => '/team/max-muster', 'target' => 'page:${p1.id}']], $redirects, 'Not the page with the id of the contact person.');
    }

    public function testTakesPlainValuesOfAnObject(): void
    {
        $plan = $this->plan(persons: true);
        $start = self::main(array_values(array_filter($plan->steps, static fn(array $step): bool => isset($step['page.update'])))[0]);

        self::assertSame(['block' => 'acme/site/Topics', 'fields' => ['items' => [['title' => 'Leitung'], ['title' => 'Lager']]]], $start[0], 'The position of each visible contact person.');
    }

    public function testTakesTheImagesOfAGalleryOneByOne(): void
    {
        $plan = $this->plan(gallery: true);
        $about = self::main(array_values(array_filter($plan->steps, static fn(array $step): bool => '${p2.id}' === self::at($step, 'page.update', 'id')))[0]);

        self::assertSame(['block' => 'acme/site/Gallery', 'fields' => ['items' => [['image' => ['media' => '${m5.id}']], ['image' => ['media' => '${m4.id}']]]]], $about[0], 'Highest orderId first, as Frootbox 1 shows them.');
    }

    public function testAnOptionTakesAValueOfTheImageInTheText(): void
    {
        $plan = $this->plan(textOptions: ['image' => ['value' => '{widget.align}', 'map' => ['right' => 'aside', 'justify' => 'below']], 'imageWidth' => '{widget.width}']);
        $text = self::main($plan->steps[4])[1] ?? null;

        self::assertSame(['image' => 'aside', 'imageWidth' => 6], self::at($text, 'options'), 'The widget sits on the right with 6 of 12 columns.');
    }

    public function testTheMappingFillsInWhatAFileLacks(): void
    {
        $plan = $this->plan(media: ['team.jpg' => ['alt' => 'Das Team vor der Praxis', 'copyright' => 'Max Muster']]);

        self::assertSame(['upload' => '${u1.token}', 'copyright' => '© Jane', 'texts' => ['de' => ['alt' => 'Das Team vor der Praxis']]], $plan->steps[1]['media.create'], 'The copyright of Frootbox 1 wins, the alt text comes from the mapping.');
        self::assertNotContains('The image team.jpg has no description (alt text) yet; the mapping can give it one under media.', $plan->report);
    }

    public function testRejectsAMappingThatIsNotFilledIn(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('blocks.Text.block is not filled in.');

        Mapping::fromArray(['languages' => ['de-DE' => 'de'], 'blocks' => ['Text' => null]]);
    }

    /**
     * The blocks of the area "main" in a step page.update.
     *
     * @param array<string, mixed> $step
     *
     * @return array<mixed>
     */
    private static function main(array $step): array
    {
        $main = self::at($step, 'page.update', 'translations', 'de', 'content', 'areas', 'main');
        self::assertIsArray($main);

        return $main;
    }

    private static function at(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!\is_array($value)) {
                return null;
            }

            $value = $value[$key] ?? null;
        }

        return $value;
    }

    /**
     * @param array<string, array<string, string>> $media
     * @param array<string, mixed>|null           $page
     * @param array<int, array<string, string>>   $pages
     * @param array<string, mixed>                $textOptions
     * @param array<string, mixed>|null           $teaser      The rule for the plugin Teaser instead of the usual one
     */
    private function plan(bool $publish = false, array $media = [], ?array $page = null, array $pages = [], bool $persons = false, bool $gallery = false, array $textOptions = [], ?array $teaser = null, bool $pluginPlace = true): Plan
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
        $banner = self::block('acme/site/Banner', ['image' => new Field('image', 'image', 'Image', optional: true)]);
        $view = new Component(ComponentKind::Block, 'acme/site/TopicView', '/tmp', 'Topics', '', ['title' => new Field('title', 'headline', 'Title', optional: true)], [], [], plugin: true);
        $images = self::block('acme/site/Gallery', ['items' => new Field('items', 'list', 'Images', fields: ['image' => new Field('image', 'image', 'Image')])]);
        $components = new Components(
            blocks: ['acme/site/Text' => $text, 'acme/site/Topics' => $topics, 'acme/site/Banner' => $banner, 'acme/site/Gallery' => $images, 'acme/site/TopicView' => $view],
            layouts: ['acme/site/Page' => new Component(ComponentKind::Layout, 'acme/site/Page', '/tmp', 'Page', '', [], [], [], plugin: $pluginPlace)],
        );
        $mapping = Mapping::fromArray([
            'languages' => ['de-DE' => 'de'],
            'domains' => ['www.example.com'],
            'layouts' => ['Default' => 'acme/site/Page'],
            'blocks' => [
                'Text' => ['block' => 'acme/site/Text', 'fields' => ['title' => 'title', 'text-#' => 'columns.#.body'], 'widgets' => ['Core/Images/Thumbnail' => 'image'], 'options' => $textOptions],
                'Teaser1' => ['block' => 'acme/site/Topics', 'fields' => ['title' => 'title', 'teasers' => 'items']],
                'Gallery' => $gallery ? ['block' => 'acme/site/Gallery', 'fields' => ['images' => 'items.#.image']] : false,
            ],
            'plugins' => ['Core/Navigation/Teaser@Index06' => $teaser ?? ($persons ? ['block' => 'acme/site/Topics', 'fields' => ['persons' => 'items', 'items' => false]] : false)],
            'items' => [
                'Teaser' => ['fields' => ['title' => 'title', 'link' => ['to' => 'link', 'text' => 'Mehr'], 'background' => false]],
                'Contact' => ['fields' => ['position' => 'title', 'image' => false]],
            ],
            'navigations' => ['Main' => 'main'],
            'site' => ['fields' => ['brand' => 'Acme', 'contact' => ['target' => '{page:2}', 'text' => 'Kontakt']]],
            'media' => $media,
            ...(null === $page ? [] : ['page' => $page]),
            ...([] === $pages ? [] : ['pages' => $pages]),
        ]);

        $old = OldSite::create();

        if ($persons) {
            OldSite::persons($old);
        }

        return new Importer(new SiteReader($old)->read(), $mapping, $components, $this->files)->plan($publish);
    }

    /**
     * @param array<string, Field> $fields
     */
    private static function block(string $name, array $fields): Component
    {
        return new Component(ComponentKind::Block, $name, '/tmp', $name, '', $fields, [], []);
    }
}
