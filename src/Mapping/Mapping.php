<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Mapping;

use Frootbox\MigrateFrootbox1\Source\Row;
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
 *         widgets: { Core/Images/Thumbnail: image }
 *     plugins:
 *       Core/Navigation/Teaser@Index06: { block: …, fields: { items.1.background: image } }
 *       Core/ContactForms/FormTeaser@Index01: { block: frootbox/forms/Form, form: true }
 *     items:
 *       Teaser: { fields: { title: title, text: text, link: { to: link, text: More } } }
 *     navigations: { BannerMain: main }
 *     site:
 *       fields: { brand: Example, contact: { target: '{page:5}', text: Contact } }
 *     media:                              # by the file name in Frootbox 1
 *       team.jpg: { alt: 'The team in front of the practice', copyright: 'Jane Doe' }
 *
 * "{page:5}" stands for the new page of page 5 in Frootbox 1, "{config.x}"
 * for a value in the configuration of the block or plugin.
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
    ) {}

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

        foreach (Row::map($data['navigations'] ?? []) as $old => $new) {
            $navigations[$old] = false === $new ? null : self::name($new, 'navigations.' . $old);
        }

        $domains = array_values(array_filter(\is_array($data['domains'] ?? null) ? $data['domains'] : [], is_string(...)));

        $media = [];

        foreach (Row::map($data['media'] ?? []) as $name => $texts) {
            $media[$name] = array_filter(
                array_intersect_key(array_map(static fn(mixed $text): string => \is_string($text) ? trim($text) : '', Row::map($texts)), ['alt' => true, 'copyright' => true]),
                static fn(string $text): bool => '' !== $text,
            );
        }

        return new self($languages, $domains, $layouts, $blocks, $plugins, $items, $navigations, Row::map(Row::map($data['site'] ?? [])['fields'] ?? []), $media);
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
            $widgets[$type] = false === $field ? null : self::name($field, $where . '.widgets.' . $type);
        }

        return new Rule(
            self::name($rule['block'] ?? null, $where . '.block'),
            \is_string($rule['variant'] ?? null) ? $rule['variant'] : null,
            Row::map($rule['options'] ?? []),
            $fields,
            Row::map($rule['set'] ?? []),
            $widgets,
            true === ($rule['form'] ?? false),
        );
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
