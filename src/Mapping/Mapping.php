<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Mapping;

use Frootbox\MigrateFrootbox1\Source\Row;
use Normalizer;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The mapping file of a project (decision 0063), as fb1:analyze proposes
 * it and a person completes it:
 *
 *     languages: { de-DE: de }
 *     domains: [www.example.com]          # links to these count as internal
 *     layouts:
 *       Default: acme/theme/Content       # or { layout: …, area: main }
 *     blocks:
 *       Text:                             # the type of the block in Frootbox 1
 *         block: acme/theme/Text          # false leaves it out
 *         variant: Wide
 *         options: { coordinates: '{config.lng}, {config.lat}' }
 *         fields:
 *           title: title                  # field of Frootbox 1: field of Frootbox 2
 *           title.supertitle: eyebrow     # a value of the headline
 *           text-#: columns.#.body        # numbered fields into a list
 *           image: false
 *         set: { signature: 'Dr. Example' }
 *         widgets: { Core/Images/Thumbnail: image, Core/Video/Video: true }   # true: only for "{widget.x}"
 *     plugins:
 *       Core/Navigation/Teaser@Index06: { block: …, fields: { items.1.background: image } }
 *       Core/ContactForms/FormTeaser@Index01: { block: frootbox/forms/Form, form: true }
 *       145: { block: acme/theme/Video }     # this one plugin by its ID, before the rule of its type
 *       Core/News/Archive@Index01: { plugin: frootbox/news/article }   # the page shows the plugin
 *     items:
 *       Teaser: { fields: { title: title, text: text, link: { to: link, text: More } } }
 *     navigations:
 *       BannerMain: main                  # a navigation of Frootbox 1: one of Frootbox 2
 *       footer:                           # or the items of a navigation of Frootbox 2
 *         - { type: target, target: '{page:2}' }
 *         - { type: pages, target: '{page:3}', depth: 1 }
 *     pages: { 12: { title: My account } }  # a new title by the ID in Frootbox 1, the address stays
 *     content: [1, 5]                     # only these pages get their content, the others come empty
 *     site:
 *       fields: { brand: Example, contact: { target: '{page:5}', text: Contact } }
 *     media:                              # by the file name in Frootbox 1
 *       team.jpg: { alt: 'The team in front of the practice', copyright: 'Jane Doe' }
 *     page:                               # fields of the page itself, as a block at its top
 *       block: acme/theme/Banner
 *       area: head                        # optional: its own area of the layout instead of the top of main
 *       fields: { background: image }
 *       options:
 *         height: { value: '{config.variables.bannerStyle}', map: { big-header: large, small-header: small } }
 *
 * "{page:5}" stands for the new page of page 5 in Frootbox 1, "{config.x}"
 * for a value in the configuration of the block or plugin (of the page
 * for "page"). An option that is only a placeholder without a value is
 * left out, so the default of the block counts; `{ value, map }`
 * translates the value and leaves the option out for a value the map
 * does not name.
 */
final readonly class Mapping
{
    /**
     * @param array<string, string>      $languages   Language of Frootbox 1 → code of Frootbox 2
     * @param list<string>               $domains
     * @param array<string, array{layout: string, area: string}> $layouts
     * @param array<string, Rule|null>   $blocks      null leaves the block out
     * @param array<string, Rule|null>   $plugins
     * @param array<string, Rule>        $items
     * @param array<string, string|null> $navigations
     * @param array<string, mixed>       $site        Fields of the website
     * @param array<string, array{alt?: string, copyright?: string}> $media Texts of files by their name in Frootbox 1, where they lack them
     * @param Rule|null                  $page        A block at the top of every page from the fields of the page itself, e.g. its background image
     * @param string|null                $pageArea    The area of the layout for that block, null for the top of the main area
     * @param array<int, string>         $titles      New titles of pages by their ID in Frootbox 1, the address stays
     * @param list<int>|null             $content     The pages by their ID in Frootbox 1 that get their content, null for all; the others come with title and address only
     * @param array<string, list<array<string, mixed>>> $menus Items of navigations of Frootbox 2 by their name, for those Frootbox 1 built from the page tree
     */
    public function __construct(
        public array $languages,
        public array $domains,
        public array $layouts,
        public array $blocks,
        public array $plugins,
        public array $items,
        public array $navigations,
        public array $site,
        public array $media = [],
        public ?Rule $page = null,
        public ?string $pageArea = null,
        public array $titles = [],
        public ?array $content = null,
        public array $menus = [],
    ) {}

    /**
     * The texts the mapping gives a file of Frootbox 1, by its name in any
     * form of Unicode: names uploaded from a Mac keep "ä" as "a" and two dots.
     *
     * @return array{alt?: string, copyright?: string}
     */
    public function textsOf(string $name): array
    {
        return $this->media[self::normalized($name)] ?? [];
    }

    /**
     * Whether the page of Frootbox 1 gets its content, by "content".
     */
    public function takesContentOf(int $page): bool
    {
        return null === $this->content || \in_array($page, $this->content, true);
    }

    /**
     * @throws MappingException
     */
    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            throw new MappingException(\sprintf('The mapping %s does not exist. fb1:analyze --mapping proposes one.', $file));
        }

        try {
            $data = Yaml::parseFile($file);
        } catch (ParseException $exception) {
            throw new MappingException(\sprintf('The mapping %s is no valid YAML: %s', $file, $exception->getMessage()), 0, $exception);
        }

        return self::fromArray(Row::map($data));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws MappingException
     */
    public static function fromArray(array $data): self
    {
        $languages = [];

        foreach (Row::map($data['languages'] ?? []) as $old => $new) {
            $languages[$old] = self::name($new, 'languages.' . $old);
        }

        if ([] === $languages) {
            throw new MappingException('The mapping names no language, e.g. "languages: { de-DE: de }".');
        }

        $layouts = [];

        foreach (Row::map($data['layouts'] ?? []) as $old => $layout) {
            $layouts[$old] = \is_array($layout)
                ? ['layout' => self::name($layout['layout'] ?? null, 'layouts.' . $old . '.layout'), 'area' => \is_string($layout['area'] ?? null) ? $layout['area'] : 'main']
                : ['layout' => self::name($layout, 'layouts.' . $old), 'area' => 'main'];
        }

        $blocks = [];

        foreach (Row::map($data['blocks'] ?? []) as $old => $rule) {
            $blocks[$old] = self::rule($rule, 'blocks.' . $old);
        }

        $plugins = [];

        foreach (Row::map($data['plugins'] ?? []) as $old => $rule) {
            $plugins[$old] = self::rule($rule, 'plugins.' . $old);
        }

        $items = [];

        foreach (Row::map($data['items'] ?? []) as $old => $rule) {
            $items[$old] = self::rule(['block' => '-', ...Row::map($rule)], 'items.' . $old) ?? throw new MappingException(\sprintf('items.%s cannot be false.', $old));
        }

        $navigations = [];

        $menus = [];

        foreach (Row::map($data['navigations'] ?? []) as $old => $new) {
            // A list is no navigation of Frootbox 1 but the items of one of Frootbox 2.
            if (\is_array($new) && array_is_list($new)) {
                $menus[$old] = array_map(Row::map(...), $new);

                continue;
            }

            $navigations[$old] = false === $new ? null : self::name($new, 'navigations.' . $old);
        }

        $domains = array_values(array_filter(\is_array($data['domains'] ?? null) ? $data['domains'] : [], is_string(...)));

        $media = [];

        foreach (Row::map($data['media'] ?? []) as $name => $texts) {
            $media[self::normalized($name)] = array_filter(
                array_intersect_key(array_map(static fn(mixed $text): string => \is_string($text) ? trim($text) : '', Row::map($texts)), ['alt' => true, 'copyright' => true]),
                static fn(string $text): bool => '' !== $text,
            );
        }

        $page = isset($data['page']) ? self::rule($data['page'], 'page') : null;
        $pageArea = Row::map($data['page'] ?? [])['area'] ?? null;

        if (null !== $pageArea && (!\is_string($pageArea) || '' === $pageArea)) {
            throw new MappingException('page.area must be the name of an area of the layouts, e.g. "head".');
        }

        $titles = [];

        $pages = $data['pages'] ?? [];

        // PHP keeps numeric keys as integers.
        foreach (\is_array($pages) ? $pages : [] as $id => $settings) {
            $title = Row::map($settings)['title'] ?? null;

            if (!\is_int($id) || $id < 1 || !\is_string($title) || '' === trim($title)) {
                throw new MappingException(\sprintf('pages.%s needs the ID of a page in Frootbox 1 and a title, e.g. "pages: { 12: { title: My account } }".', $id));
            }

            $titles[$id] = trim($title);
        }

        $content = null;

        if (\array_key_exists('content', $data)) {
            $ids = \is_array($data['content']) && array_is_list($data['content']) ? $data['content'] : [null];
            $content = [];

            foreach ($ids as $id) {
                if (!\is_int($id) || $id < 1) {
                    throw new MappingException('content must list the IDs of the pages in Frootbox 1 that get their content, e.g. "content: [1, 5]".');
                }

                $content[$id] = $id;
            }

            $content = array_values($content);
        }

        return new self($languages, $domains, $layouts, $blocks, $plugins, $items, $navigations, Row::map(Row::map($data['site'] ?? [])['fields'] ?? []), $media, $page, $pageArea, $titles, $content, $menus);
    }

    /**
     * @throws MappingException
     */
    private static function rule(mixed $rule, string $where): ?Rule
    {
        if (false === $rule) {
            return null;
        }

        $rule = Row::map($rule);

        if (false === ($rule['block'] ?? null)) {
            return null;
        }

        $plugin = $rule['plugin'] ?? null;

        if (null !== $plugin && (!\is_string($plugin) || '' === $plugin)) {
            throw new MappingException(\sprintf('%s.plugin must be the type of a plugin of Frootbox 2, e.g. "frootbox/news/article".', $where));
        }

        $fields = [];

        foreach (Row::map($rule['fields'] ?? []) as $old => $new) {
            $fields[$old] = match (true) {
                false === $new => null,
                \is_string($new) => new Target($new),
                \is_array($new) => new Target(self::name($new['to'] ?? null, $where . '.fields.' . $old . '.to'), \is_string($new['text'] ?? null) ? $new['text'] : null),
                default => throw new MappingException(\sprintf('%s.fields.%s has no target. Write a field of Frootbox 2 or false.', $where, $old)),
            };
        }

        $widgets = [];

        foreach (Row::map($rule['widgets'] ?? []) as $type => $field) {
            // true takes a widget without a file, e.g. a video, only for "{widget.x}".
            $widgets[$type] = match ($field) {
                false => null,
                true => '',
                default => self::name($field, $where . '.widgets.' . $type),
            };
        }

        $needs = [];

        foreach (\is_string($rule['needs'] ?? null) ? [$rule['needs']] : Row::map($rule['needs'] ?? []) as $name) {
            $needs[] = self::name($name, $where . '.needs');
        }

        $then = [];

        foreach (Row::map($rule['then'] ?? []) as $number => $next) {
            $next = self::rule($next, $where . '.then.' . $number);

            if (null !== $next) {
                $then[] = $next;
            }
        }

        return new Rule(
            null !== $plugin && !\array_key_exists('block', $rule) ? '' : self::name($rule['block'] ?? null, $where . '.block'),
            \is_string($rule['variant'] ?? null) ? $rule['variant'] : null,
            Row::map($rule['options'] ?? []),
            $fields,
            Row::map($rule['set'] ?? []),
            $widgets,
            true === ($rule['form'] ?? false),
            $plugin,
            $needs,
            $then,
        );
    }

    private static function normalized(string $name): string
    {
        $normalized = Normalizer::normalize($name, Normalizer::FORM_C);

        return \is_string($normalized) ? $normalized : $name;
    }

    /**
     * @throws MappingException
     */
    private static function name(mixed $value, string $where): string
    {
        if (!\is_string($value) || '' === $value) {
            throw new MappingException(\sprintf('%s is not filled in. Write a name of Frootbox 2 or false.', $where));
        }

        return $value;
    }
}
