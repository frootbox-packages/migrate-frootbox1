<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Import;

use Frootbox\Core\Component\Components;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Page\PageEditor;
use Frootbox\Core\Page\Reach;
use Frootbox\MigrateFrootbox1\Mapping\Mapping;
use Frootbox\MigrateFrootbox1\Mapping\Rule;
use Frootbox\MigrateFrootbox1\Mapping\Target;
use Frootbox\MigrateFrootbox1\Source\Block;
use Frootbox\MigrateFrootbox1\Source\Content;
use Frootbox\MigrateFrootbox1\Source\Element;
use Frootbox\MigrateFrootbox1\Source\File;
use Frootbox\MigrateFrootbox1\Source\Item;
use Frootbox\MigrateFrootbox1\Source\Page;
use Frootbox\MigrateFrootbox1\Source\Row;
use Frootbox\MigrateFrootbox1\Source\Site;
use Frootbox\MigrateFrootbox1\Source\Text;
use Frootbox\MigrateFrootbox1\Source\Widget;
use Frootbox\RichText\HtmlReader;

/**
 * Turns a website of Frootbox 1 into a seed for an empty website of
 * Frootbox 2 (decisions 0063, 0064, 0256), following the mapping:
 *
 * 1. every image a mapped field uses goes into the media,
 * 2. the pages in the order of the tree, without content,
 * 3. forms that plugins show,
 * 4. the content of each page, blocks and plugins in the order of
 *    Frootbox 1 in one area,
 * 5. navigations, fields of the website and a 301 for every old address
 *    that has no page of the same path,
 * 6. on request publishing everything.
 *
 * Pages exist before their content, so links in texts and fields can
 * point to any page. All writing is done by the seed, through actions.
 * What has no place in the mapping lands in the report.
 */
final class Importer
{
    /** The text of a consent in Frootbox 1 without its own, by language. */
    private const array PRIVACY_TEXTS = [
        'de' => 'Ich habe die [Datenschutzerklärung] gelesen und akzeptiere diese.',
        'en' => 'I have read and accept the [privacy policy].',
    ];

    /** @var list<array<string, mixed>> */
    private array $media = [];

    /** @var array<string, string> */
    private array $files = [];

    /** @var array<int, string> Id of a file of Frootbox 1 → name of its medium in the seed */
    private array $mediaNames = [];

    /** @var list<array<string, mixed>> */
    private array $forms = [];

    /** @var array<int, string> */
    private array $formNames = [];

    /** @var list<string> */
    private array $report = [];

    /** @var array<string, int> Path of Frootbox 1 → page, also old addresses and redirects */
    private array $paths = [];

    /** @var array<int, true> Pages created in Frootbox 2 */
    private array $created = [];

    /** @var array<int, string> Page of Frootbox 1 → the plugin of Frootbox 2 it shows */
    private array $plugins = [];

    /** @var array<int, array<string, mixed>> Page of Frootbox 1 → the view of its plugin with fields and options */
    private array $views = [];

    private string $language = '';

    private string $code = '';

    private string $where = '';

    /** The page of Frootbox 1 whose content is converted, for "{page}" in names of fields. */
    private int $page = 0;

    /** The plugin whose fields are converted, for its title. */
    private ?Element $element = null;

    public function __construct(
        private readonly Site $site,
        private readonly Mapping $mapping,
        private readonly Components $components,
        private readonly string $folder,
    ) {}

    public function plan(bool $publish = false): Plan
    {
        $this->language = (string) array_key_first($this->mapping->languages);
        $this->code = $this->mapping->languages[$this->language];

        foreach ($this->site->languages as $language) {
            if (!isset($this->mapping->languages[$language])) {
                $this->note(\sprintf('Pages in %s are left out: the mapping has no language for them.', $language));
            } elseif ($language !== $this->language) {
                $this->note(\sprintf('Pages in %s are left out: the import takes one language so far.', $language));
            }
        }

        $pages = array_values(array_filter($this->site->pages, fn(Page $page): bool => $page->language === $this->language));
        $this->paths($pages);

        $create = [];
        $content = [];

        foreach ($pages as $page) {
            if ($page->isRedirect()) {
                continue;
            }

            if (!$page->isRoot() && !isset($this->created[$page->parentId])) {
                $this->note(\sprintf('Page %d "%s" is left out, its parent is.', $page->id, $page->title));

                continue;
            }

            $layout = $this->mapping->layouts['' === $page->view() ? 'Default' : $page->view()] ?? null;

            if (null === $layout || null === $this->components->layout($layout['layout'])) {
                $this->note(\sprintf('Page %d "%s" is left out: the mapping has no layout of this website for "%s".', $page->id, $page->title, '' === $page->view() ? 'Default' : $page->view()));

                continue;
            }

            $translation = ['title' => $this->mapping->titles[$page->id] ?? $page->title];

            if (!$page->isRoot()) {
                $translation['slug'] = $page->slug();
            }

            $create[] = [
                'page.create' => array_filter([
                    'layout' => $layout['layout'],
                    'parentId' => $page->isRoot() ? null : $this->ref($page->parentId),
                    'translations' => [$this->code => $translation],
                ], static fn(mixed $value): bool => null !== $value),
                'as' => 'p' . $page->id,
            ];
            $this->created[$page->id] = true;
        }

        foreach ($pages as $page) {
            if (isset($this->created[$page->id])) {
                $layout = $this->mapping->layouts['' === $page->view() ? 'Default' : $page->view()];
                $translation = [];

                // A website can come over page by page: the others keep their place in the tree.
                if ($this->mapping->takesContentOf($page->id)) {
                    $translation['content'] = ['areas' => $this->content($page, $layout['area'])];
                } else {
                    $this->note(\sprintf('Page %d "%s" comes without content: the mapping takes it only from the pages under "content".', $page->id, $page->title));
                }

                if (isset($this->views[$page->id])) {
                    $translation['content']['plugin'] = $this->views[$page->id];
                }

                [$seoTitle, $description] = $this->seo($page);

                if ('' !== $description) {
                    $translation['description'] = $description;
                }

                if ('' !== $seoTitle) {
                    $translation['seoTitle'] = $seoTitle;
                }

                $update = array_filter([
                    'id' => $this->ref($page->id),
                    'excludeFromSearchEngines' => $page->excludedFromSearchEngines() ? Reach::Page->value : null,
                    'excludeFromSiteSearch' => $page->excludedFromSiteSearch() ? Reach::Page->value : null,
                    'translations' => [] === $translation ? null : [$this->code => $translation],
                ], static fn(mixed $value): bool => null !== $value);

                if (\count($update) > 1) {
                    $content[] = ['page.update' => $update];
                }
            }
        }

        $steps = [...$this->media, ...$create, ...$this->forms, ...$content, ...$this->assignments(), ...$this->navigations(), ...$this->siteFields(), ...$this->redirects($pages)];

        if ($publish) {
            $steps = [...$steps, ...$this->publish()];
        }

        return new Plan($steps, $this->files, $this->report);
    }

    /**
     * Every path of Frootbox 1 that leads to a page: its own, old ones and redirects.
     *
     * @param list<Page> $pages
     */
    private function paths(array $pages): void
    {
        foreach ($pages as $page) {
            $this->paths[$page->path] = $page->id;
        }

        foreach ($this->site->aliases as $alias) {
            $page = $alias->page();

            if ('' !== $alias->path && !isset($this->paths[$alias->path]) && null !== $page && isset($this->site->pages[$page])) {
                $this->paths[$alias->path] = $page;
            }
        }
    }

    /**
     * The page a page, redirect or address of Frootbox 1 leads to in the end.
     */
    private function resolve(int|string $target, int $depth = 0): ?int
    {
        if ($depth > 10) {
            return null;
        }

        if (\is_string($target)) {
            if (1 === preg_match('#^fbx://page:(\d+)$#', $target, $match)) {
                return $this->resolve((int) $match[1], $depth + 1);
            }

            $path = trim((string) parse_url($target, \PHP_URL_PATH), '/');

            return isset($this->paths[$path]) ? $this->resolve($this->paths[$path], $depth + 1) : null;
        }

        if (isset($this->created[$target])) {
            return $target;
        }

        $page = $this->site->page($target);

        if (null !== $page && $page->isRedirect()) {
            return $this->resolve($page->redirectTarget(), $depth + 1);
        }

        // An address that redirects to another one.
        foreach ($this->site->aliases as $alias) {
            if ('Pages' === $alias->owner && $alias->itemId === $target && $alias->isRedirect() && '' !== $alias->target) {
                return $this->resolve($alias->target, $depth + 1);
            }
        }

        return null;
    }

    /**
     * The page of the privacy policy, found as in Frootbox 1 by its address.
     */
    private function privacyPage(): ?int
    {
        foreach ($this->site->pages as $page) {
            if ($page->language === $this->language && isset($this->created[$page->id]) && 1 === preg_match('/datenschutz|privacy/i', $page->path)) {
                return $page->id;
            }
        }

        return null;
    }

    /**
     * Title and description for search engines: the ones of the address of
     * the page win, as in Frootbox 1. A title the same as the one of the page
     * is left out, Frootbox 2 then builds it with the name of the website
     * (decisions 0260, 0266).
     *
     * @return array{string, string}
     */
    private function seo(Page $page): array
    {
        $title = self::squeeze($page->seo('title'));
        $description = self::squeeze($page->seo('description'));

        foreach ($this->site->aliases as $alias) {
            if ('Pages' === $alias->owner && $alias->itemId === $page->id && !$alias->isRedirect()) {
                if ('' !== self::squeeze($alias->seoTitle)) {
                    $title = self::squeeze($alias->seoTitle);
                }

                if ('' !== self::squeeze($alias->seoDescription)) {
                    $description = self::squeeze($alias->seoDescription);
                }
            }
        }

        if ($title === $page->title) {
            $title = '';
        }

        if (mb_strlen($title) > PageEditor::SEO_TITLE_MAX) {
            $title = self::cut($title, PageEditor::SEO_TITLE_MAX);
            $this->note(\sprintf('Page %d "%s": the title for search engines is cut to %d characters.', $page->id, $page->title, PageEditor::SEO_TITLE_MAX));
        }

        if (mb_strlen($description) > PageEditor::DESCRIPTION_MAX) {
            $description = self::cut($description, PageEditor::DESCRIPTION_MAX);
            $this->note(\sprintf('Page %d "%s": the description for search engines is cut to %d characters.', $page->id, $page->title, PageEditor::DESCRIPTION_MAX));
        }

        return [$title, $description];
    }

    /**
     * At most so many characters, at a word with an ellipsis.
     */
    private static function cut(string $text, int $max): string
    {
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim(false === $space ? $cut : mb_substr($cut, 0, $space), " ,.;:-") . '…';
    }

    /**
     * A text of Frootbox 1 on one line, without tags.
     */
    private static function squeeze(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5)));
    }

    private function ref(int $page): string
    {
        return '${p' . $page . '.id}';
    }

    /**
     * The blocks of a page by area: plugins above the content, the blocks,
     * the plugins of the content, then the blocks below. The block from the
     * page itself comes first, or into its own area when the mapping says so.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function content(Page $page, string $area): array
    {
        $areas = [];
        // The plugins in the columns of a grid go into the block of the grid, not on top.
        $cells = self::cells($page->elements);
        $loose = array_filter($page->elements, static fn(Element $element): bool => null === self::cell($element, $cells));

        $first = array_filter($loose, static fn(Element $element): bool => 'MainContent' !== $element->socket);
        $main = array_filter($loose, static fn(Element $element): bool => 'MainContent' === $element->socket);
        $above = $page->blocksIn('block-content');
        $below = array_filter($page->blocks, static fn($block): bool => 'block-content' !== $block->area);
        $blocks = [];
        $this->page = $page->id;
        $this->where = \sprintf('Page %d "%s"', $page->id, $page->title);

        if (null !== $this->mapping->page) {
            $block = $this->block($this->mapping->page, $page->content, $page->config, null);

            if (null !== $block && null !== $this->mapping->pageArea && $area !== $this->mapping->pageArea) {
                $areas[$this->mapping->pageArea] = [$block];
            } elseif (null !== $block) {
                $blocks[] = $block;
            }
        } elseif (!isset($this->mapping->items['Page'])) {
            // With "items.Page" the fields of a page go to the list of a sub navigation above it.
            foreach ($page->content->fields() as $field) {
                if ($this->filled($page->content, $field)) {
                    $this->note(\sprintf('%s: the field "%s" of the page is left out, the mapping has no "page".', $this->where, $field));
                }
            }
        }

        return $areas + [$area => [...$blocks, ...$this->parts($page, [...$first, ...$above, ...$main, ...$below], $cells)]];
    }

    /**
     * The blocks of plugins and blocks of Frootbox 1, in this order.
     *
     * @param list<Element|Block>                  $parts
     * @param array<int, array<int, list<Element>>> $cells the plugins in the columns of each grid, by its ID
     *
     * @return list<array<string, mixed>>
     */
    private function parts(Page $page, array $parts, array $cells): array
    {
        $blocks = [];

        foreach ($parts as $part) {
            $isElement = $part instanceof Element;

            if ($isElement && isset($cells[$part->id])) {
                array_push($blocks, ...$this->grid($page, $part, $cells));

                continue;
            }

            $key = $isElement ? $part->plugin() . '@' . $part->layout() : $part->type;
            $this->where = \sprintf('%s %d (%s) on page %d "%s"', $isElement ? 'Plugin' : 'Block', $part->id, $key, $page->id, $page->title);

            if (!$part->visible) {
                $this->note($this->where . ' is hidden and left out.');

                continue;
            }

            $rules = $isElement ? $this->mapping->plugins : $this->mapping->blocks;
            // The ID of one plugin or block goes before the rule of its type.
            $key = \array_key_exists((string) $part->id, $rules) ? (string) $part->id : $key;

            if (!\array_key_exists($key, $rules)) {
                $this->note($this->where . ' is left out: the mapping does not name it.');

                continue;
            }

            $rule = $rules[$key];

            if (null === $rule) {
                continue;
            }

            if (null !== $rule->plugin) {
                $this->plugin($page, $rule->plugin);
            }

            if ($rule->onlyPlugin()) {
                continue;
            }

            $block = $this->block($rule, $part->content, $part->config, $isElement ? $part : null);

            // The block of the plugin itself is its view, with fields and options (decision 0320).
            if (null !== $block && null !== $rule->plugin && true === $this->components->block($rule->block)?->plugin) {
                if (($this->plugins[$page->id] ?? null) === $rule->plugin) {
                    $this->views[$page->id] = $block;
                }

                continue;
            }

            if (null !== $block) {
                $blocks[] = $block;
            }

            foreach ($rule->then as $next) {
                $further = $this->block($next, $part->content, $part->config, $isElement ? $part : null);

                if (null !== $further) {
                    $blocks[] = $further;
                }
            }
        }

        return $blocks;
    }

    /**
     * A grid of Frootbox 1 (columns such as "6-6"): with "areas" in its rule
     * one block, each column in its area; else the columns one after
     * another where the grid was.
     *
     * @param array<int, array<int, list<Element>>> $cells
     *
     * @return list<array<string, mixed>>
     */
    private function grid(Page $page, Element $grid, array $cells): array
    {
        $where = \sprintf('Grid %d on page %d "%s"', $grid->id, $page->id, $page->title);

        if (!$grid->visible) {
            $this->note($where . ' is hidden and left out with its columns.');

            return [];
        }

        $columns = $cells[$grid->id];
        ksort($columns);
        $inner = array_map(fn(array $elements): array => $this->parts($page, $elements, $cells), $columns);
        $rules = $this->mapping->plugins;
        $key = \array_key_exists((string) $grid->id, $rules) ? (string) $grid->id : $grid->plugin() . '@' . $grid->layout();
        $rule = $rules[$key] ?? null;
        $this->where = $where;
        $block = null === $rule || [] === $rule->areas ? null : $this->block($rule, $grid->content, $grid->config, $grid);

        if (null === $block) {
            if (!\array_key_exists($key, $rules)) {
                $this->note($where . ': the mapping does not name it, its columns follow one after another.');
            }

            return array_merge(...array_values($inner));
        }

        $after = [];
        $areas = [];

        foreach ($inner as $column => $blocks) {
            $name = $rule->areas[$column] ?? null;

            if (null === $name) {
                $this->note(\sprintf('%s: column %d has no area in the mapping, its blocks follow the grid.', $where, $column + 1));
                array_push($after, ...$blocks);

                continue;
            }

            $areas[$name] = $blocks;
        }

        $block['areas'] = $areas;

        return [$block, ...$after];
    }

    /**
     * The plugins in the columns of the grids of a page, by the ID of the
     * grid and the column: Frootbox 1 names their socket after both, e.g.
     * MainContent_2_0 for the first column of grid 2.
     *
     * @param list<Element> $elements
     *
     * @return array<int, array<int, list<Element>>>
     */
    private static function cells(array $elements): array
    {
        $grids = [];

        foreach ($elements as $element) {
            if (str_ends_with($element->className, '\\Content\\Elements\\Grid')) {
                $grids[$element->id] = [];
            }
        }

        foreach ($elements as $element) {
            if (1 === preg_match('/_(\d+)_(\d+)$/', $element->socket, $match) && isset($grids[(int) $match[1]])) {
                $grids[(int) $match[1]][(int) $match[2]][] = $element;
            }
        }

        return array_filter($grids, static fn(array $columns): bool => [] !== $columns);
    }

    /**
     * @param array<int, array<int, list<Element>>> $cells
     */
    private static function cell(Element $element, array $cells): ?int
    {
        return 1 === preg_match('/_(\d+)_(\d+)$/', $element->socket, $match) && isset($cells[(int) $match[1]]) ? (int) $match[1] : null;
    }

    /**
     * Notes the plugin the page shows; one per page, in a layout with a
     * place for it.
     */
    private function plugin(Page $page, string $type): void
    {
        $current = $this->plugins[$page->id] ?? null;

        if (null !== $current) {
            if ($current !== $type) {
                $this->note(\sprintf('%s: the page shows the plugin %s already, %s is left out.', $this->where, $current, $type));
            }

            return;
        }

        $layout = $this->mapping->layouts['' === $page->view() ? 'Default' : $page->view()]['layout'] ?? '';

        if (true !== $this->components->layout($layout)?->plugin) {
            $this->note(\sprintf('%s: the page cannot show the plugin %s, its layout %s has no place for one.', $this->where, $type, $layout));

            return;
        }

        $this->plugins[$page->id] = $type;
    }

    /**
     * The pages that show a plugin, after their content: the view of the
     * plugin joins the content of the draft.
     *
     * @return list<array<string, mixed>>
     */
    private function assignments(): array
    {
        $steps = [];

        foreach ($this->plugins as $page => $type) {
            $steps[] = ['plugin.assign' => ['id' => $this->ref($page), 'type' => $type]];
        }

        return $steps;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>|null
     */
    private function block(Rule $rule, Content $content, array $config, ?Element $element): ?array
    {
        $component = $this->components->block($rule->block);

        if (null === $component) {
            $this->note(\sprintf('%s is left out: there is no block %s.', $this->where, $rule->block));

            return null;
        }

        $this->element = $element;
        $fields = $this->fields($rule, $content, $component->fields);
        $this->element = null;
        $widget = array_values($this->widgets($rule, $content))[0] ?? null;
        $options = $this->placeholders($rule->options, $config, $widget->config ?? []);

        // A number from Frootbox 1 stays a number only for an option of numbers, e.g. not the ID of a course to choose.
        foreach ($options as $name => $value) {
            $option = [] === $component->variants ? null : ($component->variant($rule->variant)->options[$name] ?? null);

            if ((\is_int($value) || \is_float($value)) && null !== $option && 'number' !== $option->type) {
                $options[$name] = (string) $value;
            }
        }

        foreach ($rule->needs as $name) {
            if (null === ($fields[$name] ?? null) || [] === $fields[$name] || '' === $fields[$name]) {
                return null;
            }
        }

        if ($rule->form && null !== $element) {
            $form = $this->form($element);

            if (null !== $form) {
                $options['form'] = $form;
            }
        }

        return array_filter([
            'block' => $rule->block,
            'variant' => $rule->variant,
            'options' => [] === $options ? null : $options,
            'fields' => $fields,
        ], static fn(mixed $value): bool => null !== $value);
    }

    /**
     * The fields of a block or entry by the rule.
     *
     * @param array<string, Field> $schema
     *
     * @return array<string, mixed>
     */
    private function fields(Rule $rule, Content $content, array $schema, ?Item $item = null): array
    {
        $fields = [];
        $used = [];

        foreach ($rule->fields as $old => $target) {
            // "files-{page}": the field of the page whose content this is, e.g. the images of an inherited slider.
            if (str_contains($old, '{page}')) {
                $pattern = '/^' . str_replace('\\{page\\}', '\\d+', preg_quote($old, '/')) . '$/';

                foreach ($content->fields() as $field) {
                    if (1 === preg_match($pattern, $field)) {
                        $used[$field] = true;
                    }
                }

                $old = str_replace('{page}', (string) $this->page, $old);
            }

            foreach ($this->sources($old, $content, $item, null !== $target && str_contains($target->path, '.#.')) as [$number, $field, $value]) {
                $used[$field] = true;

                if (null === $target) {
                    continue;
                }

                $definition = $this->definition($target->path, $schema);

                if (null === $definition) {
                    $this->note(\sprintf('%s: the block has no field "%s" for "%s".', $this->where, $target->path, $old));

                    continue;
                }

                $converted = $this->convert($value, $definition, $target, $rule);

                if (null !== $converted) {
                    self::put($fields, $target->path, $number, $converted);
                }
            }
        }

        foreach ($content->fields() as $field) {
            if (!isset($used[$field]) && $this->filled($content, $field)) {
                $this->note(\sprintf('%s: the field "%s" is left out, the mapping does not name it.', $this->where, $field));
            }
        }

        foreach ($this->widgets($rule, $content) as $field => $widget) {
            $definition = $this->definition($field, $schema);
            $files = array_values($widget->content->files)[0] ?? [];

            if (null === $definition || 'image' !== $definition->type) {
                continue;
            }

            // "images.#.image" takes every file of the widget, as in a gallery.
            foreach (str_contains($field, '.#.') ? $files : \array_slice($files, 0, 1) as $index => $file) {
                if ($file->isImage()) {
                    self::put($fields, $field, $index + 1, $this->image($file));
                }
            }
        }

        foreach ($this->placeholders($rule->set, []) as $field => $value) {
            $fields[$field] = $value;
        }

        return self::lists($fields);
    }

    /**
     * The values a field of the mapping stands for: [number, field of Frootbox 1, value].
     *
     * "text-#" gives all numbered fields, "title.supertitle" a value of the
     * headline, "items.1.background" a field of the first object of a
     * list, "title" and "link" of an object its title and linked page.
     * Into a list ("items.#.image") the files of one field go one by one,
     * as in a gallery.
     *
     * @return list<array{int, string, mixed}>
     */
    private function sources(string $old, Content $content, ?Item $item, bool $many = false): array
    {
        if ($many && isset($content->files[$old])) {
            $found = [];

            foreach ($content->files[$old] as $index => $file) {
                $found[] = [$index + 1, $old, $file];
            }

            return $found;
        }

        if (str_contains($old, '-#')) {
            $pattern = '/^' . str_replace('\-\#', '-(\d+)', preg_quote($old, '/')) . '$/';
            $found = [];

            foreach ($content->fields() as $field) {
                if (1 === preg_match($pattern, $field, $match)) {
                    $found[] = [(int) $match[1], $field, $this->value($content, $field)];
                }
            }

            usort($found, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

            return $found;
        }

        $parts = explode('.', $old, 3);

        if (isset($parts[1]) && isset($content->items[$parts[0]])) {
            $entry = array_values(array_filter($content->items[$parts[0]], static fn(Item $item): bool => $item->visible))[(int) $parts[1] - 1] ?? null;

            return null === $entry || !isset($parts[2]) ? [] : [[0, $parts[0], $this->value($entry->content, $parts[2]) ?? ('title' === $parts[2] ? $entry->title : null)]];
        }

        if (isset($parts[1])) {
            $text = $content->text($parts[0], $this->language);
            $value = null === $text ? null : Row::pathString($text->config, $parts[1]);

            return null === $value || '' === $value ? [] : [[0, $parts[0], $value]];
        }

        $value = $this->value($content, $old);

        // A plugin of Frootbox 1 shows its own title until a heading is written in its place.
        if (null === $value && null === $item && 'title' === $old && '' !== trim((string) $this->element?->title)) {
            $value = trim((string) $this->element?->title);
        }

        if (null === $value && null !== $item) {
            $value = match ($old) {
                'title' => $item->title,
                'link' => $item->link(),
                // A plain value of the object, e.g. the position of a contact person.
                default => \is_string($item->config[$old] ?? null) && '' !== $item->config[$old] ? $item->config[$old] : null,
            };
        }

        return null === $value ? [] : [[0, $old, $value]];
    }

    private function value(Content $content, string $field): mixed
    {
        if (isset($content->items[$field])) {
            return array_values(array_filter($content->items[$field], static fn(Item $item): bool => $item->visible));
        }

        // The public pages below the page, as objects of type Page, e.g. for a sub navigation with images.
        if ('children' === $field && !isset($content->texts[$field]) && !isset($content->files[$field])) {
            return array_map(
                static fn(Page $child): Item => new Item($child->id, 'Page', $child->title, $child->parentId, $child->lft, true, ['redirect' => ['pageId' => (string) $child->id]], $child->content),
                array_values(array_filter($this->site->children($this->page), static fn(Page $child): bool => 'Public' === $child->visibility)),
            );
        }

        return $content->text($field, $this->language) ?? $content->file($field);
    }

    private function filled(Content $content, string $field): bool
    {
        $value = $this->value($content, $field);

        return match (true) {
            $value instanceof Text => '' !== trim(strip_tags((string) $value->html)) || [] !== array_filter($value->config, static fn(mixed $entry): bool => \is_string($entry) && '' !== $entry),
            \is_array($value) => [] !== $value,
            default => null !== $value,
        };
    }

    /**
     * @param array<string, Field> $schema
     */
    private function definition(string $path, array $schema): ?Field
    {
        $parts = explode('.', $path);
        $field = $schema[$parts[0]] ?? null;

        if (isset($parts[2]) && null !== $field) {
            return $field->fields[$parts[2]] ?? null;
        }

        return $field;
    }

    /**
     * Puts a value at "name" or "list.#.name"; # is the number of the field of Frootbox 1.
     *
     * @param array<string, mixed> $fields
     */
    private static function put(array &$fields, string $path, int $number, mixed $value): void
    {
        $parts = explode('.', $path);

        if (!isset($parts[2])) {
            $fields[$parts[0]] = $value;

            return;
        }

        $index = '#' === $parts[1] ? max(0, $number - 1) : max(0, (int) $parts[1] - 1);
        $list = \is_array($fields[$parts[0]] ?? null) ? $fields[$parts[0]] : [];
        $entry = \is_array($list[$index] ?? null) ? $list[$index] : [];
        $entry[$parts[2]] = $value;
        $list[$index] = $entry;
        $fields[$parts[0]] = $list;
    }

    /**
     * Lists in order and without gaps.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function lists(array $fields): array
    {
        foreach ($fields as $name => $value) {
            if (\is_array($value) && !array_is_list($value) && [] === array_filter(array_keys($value), is_string(...))) {
                ksort($value);
                $fields[$name] = array_values($value);
            }
        }

        return $fields;
    }

    /**
     * A value of Frootbox 1 for a field of Frootbox 2, by the type of the field.
     */
    private function convert(mixed $value, Field $field, Target $target, Rule $rule): mixed
    {
        if ($value instanceof File) {
            return 'image' === $field->type && $value->isImage() ? $this->image($value) : $this->skip($field, 'a file');
        }

        if (\is_int($value)) {
            $page = $this->resolve($value);

            if ('link' !== $field->type || null === $page) {
                return $this->skip($field, 'a link to page ' . $value);
            }

            return ['target' => 'page:' . $this->ref($page), 'text' => $target->text ?? $this->site->pages[$page]->title];
        }

        if (\is_array($value)) {
            return 'list' === $field->type ? $this->entries(array_values(array_filter($value, static fn(mixed $entry): bool => $entry instanceof Item)), $field) : $this->skip($field, 'a list');
        }

        if ($value instanceof Text) {
            return match ($field->type) {
                'icon' => '' === Row::pathString($value->config, 'icon') ? null : Row::pathString($value->config, 'icon'),
                'headline' => self::plain($value, false),
                'text' => self::plain($value, true),
                'richtext' => $this->richText($value, $rule),
                default => $this->skip($field, 'text'),
            };
        }

        if (\is_string($value) && 'link' === $field->type) {
            $href = $this->link($value);

            if (str_starts_with($href, 'page:')) {
                $page = $this->resolve($this->paths[trim($value, '/')] ?? 0);

                return ['target' => $href, 'text' => $target->text ?? (null === $page ? $value : $this->site->pages[$page]->title)];
            }

            return 1 === preg_match('#^(https?|mailto|tel):#i', $href) ? ['url' => $href, 'text' => $target->text ?? $href] : $this->skip($field, 'a link to ' . $value);
        }

        if (\is_string($value)) {
            return match ($field->type) {
                'headline', 'text' => $value,
                'richtext' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $value]]]]],
                default => $this->skip($field, 'text'),
            };
        }

        return null;
    }

    private function skip(Field $field, string $what): null
    {
        $this->note(\sprintf('%s: the field "%s" (%s) cannot take %s.', $this->where, $field->name, $field->type, $what));

        return null;
    }

    /**
     * Objects of Frootbox 1 as entries of a list, by the mapping of their type.
     *
     * @param list<Item> $items
     *
     * @return list<array<string, mixed>>
     */
    private function entries(array $items, Field $list): array
    {
        $entries = [];

        foreach ($items as $item) {
            $rule = $this->mapping->items[$item->type()] ?? null;

            if (null === $rule) {
                $this->note(\sprintf('%s: objects of type %s are left out, the mapping has no "items.%s".', $this->where, $item->type(), $item->type()));

                return [];
            }

            $entries[] = $this->fields($rule, $item->content, $list->fields, $item);
        }

        return $entries;
    }

    /**
     * Text without markup: breaks and paragraphs become lines if wanted.
     */
    private static function plain(Text $text, bool $lines): string
    {
        $headline = Row::pathString($text->config, 'headline');

        if ('' !== $headline) {
            return $headline;
        }

        $html = (string) $text->html;

        if ($lines) {
            $html = (string) preg_replace(['#<br\s*/?>#i', '#</(p|div|li|h\d)>#i'], ["\n", "\n"], $html);
        }

        $plain = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $plain = (string) preg_replace($lines ? '/[ \t]*\n[ \t\n]*/' : '/\s+/', $lines ? "\n" : ' ', $plain);

        return trim((string) preg_replace('/[ \t]+/', ' ', $plain));
    }

    /**
     * @return array<string, mixed>
     */
    private function richText(Text $text, Rule $rule): array
    {
        // Widgets in the text go to a field of the block or into the report (decision 0120).
        $html = (string) preg_replace_callback('#<figure[^>]*data-id="(\d+)"[^>]*>.*?</figure>#is', function (array $match) use ($rule): string {
            $widget = $this->site->widgets[(int) $match[1]] ?? null;

            if (null === $widget || !\array_key_exists($widget->type(), $rule->widgets)) {
                $this->note(\sprintf('%s: a widget %s in the text is left out.', $this->where, null === $widget ? $match[1] : $widget->type()));
            }

            return '';
        }, (string) $text->html);

        $reader = new HtmlReader($this->link(...));
        $document = $reader->read($html);

        foreach ($reader->dropped() as $name) {
            $this->note(\sprintf('%s: <%s> in the text field "%s" is left out.', $this->where, $name, $text->field));
        }

        return $document;
    }

    /**
     * The first widget with an image of each kind the rule takes, by field.
     *
     * @return array<string, Widget>
     */
    private function widgets(Rule $rule, Content $content): array
    {
        $widgets = [];

        foreach ($content->texts as $languages) {
            $text = $languages[$this->language] ?? null;

            preg_match_all('/<figure[^>]*data-id="(\d+)"/', (string) $text?->html, $matches);

            foreach ($matches[1] as $id) {
                $widget = $this->site->widgets[(int) $id] ?? null;
                $field = null === $widget ? null : ($rule->widgets[$widget->type()] ?? null);
                $file = null === $widget ? null : (array_values($widget->content->files)[0][0] ?? null);

                // An image needs its file, a widget only for "{widget.x}" (field '') does not.
                if (null !== $field && (null !== $file || '' === $field) && !isset($widgets[$field])) {
                    $widgets[$field] = $widget;
                }
            }
        }

        return $widgets;
    }

    /**
     * A link in a text: to a page of the website it becomes a target, others stay.
     */
    private function link(string $href): string
    {
        // The base template of Pixel-Fabrik hid e-mail addresses from spam bots behind a link that a script turned back.
        if (1 === preg_match('#/SaveMailEscape/send/([^/?\#]+)#', $href, $match)) {
            return 'mailto:' . self::unescapeMail(rawurldecode($match[1]));
        }

        if ('' === $href || str_starts_with($href, '#') || 1 === preg_match('#^(mailto|tel):#i', $href)) {
            return $href;
        }

        if (str_starts_with($href, 'fbx://')) {
            $page = $this->resolve($href);

            return null === $page ? $this->unknown($href) : 'page:' . $this->ref($page);
        }

        $parts = parse_url($href);
        $host = \is_array($parts) ? strtolower($parts['host'] ?? '') : '';

        if ('' !== $host && !\in_array($host, array_map(strtolower(...), $this->mapping->domains), true)) {
            return $href;
        }

        $path = trim(\is_array($parts) ? ($parts['path'] ?? '') : '', '/');
        $path = (string) preg_replace('#\.html?$#', '', $path);
        $page = isset($this->paths[$path]) ? $this->resolve($this->paths[$path]) : null;

        if (null === $page) {
            return '' === $host && '' !== $path ? $this->unknown($href) : $href;
        }

        $fragment = \is_array($parts) && isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return '' === $fragment ? 'page:' . $this->ref($page) : $href;
    }

    /**
     * The address behind a link of the mail escape of Pixel-Fabrik: each
     * character 33 places further in its own alphabet of 66.
     */
    private static function unescapeMail(string $escaped): string
    {
        $alphabet = 'C7olD9ALhcnQZtR1G4rq.pU2xjVBKvOIfEyMbY-mauw_HNWTiX3Sg@856zPkJds0Fe';
        $address = '';

        foreach (str_split($escaped) as $char) {
            $position = strpos($alphabet, $char);
            $address .= false === $position ? $char : $alphabet[($position + 33) % 66];
        }

        return $address;
    }

    private function unknown(string $href): string
    {
        $this->note(\sprintf('%s: the link "%s" leads to no page and stays as it is.', $this->where, $href));

        return $href;
    }

    /**
     * An image field with the file as a medium, uploaded once.
     *
     * @return array<string, string>
     */
    private function image(File $file): array
    {
        if (!isset($this->mediaNames[$file->id])) {
            $name = 'm' . $file->id;
            $source = rtrim($this->folder, '/') . '/' . $file->path;
            $target = 'media/' . $file->id . '-' . basename($file->path);

            if (!is_file($source)) {
                $this->note(\sprintf('%s: the file %s is missing in %s.', $this->where, $file->name, $source));
            }

            // The mapping fills in what the file lacks in Frootbox 1.
            $texts = $this->mapping->textsOf($file->name);
            $alt = '' === $file->title ? $texts['alt'] ?? '' : $file->title;
            $copyright = '' === $file->copyright ? $texts['copyright'] ?? '' : $file->copyright;
            $this->files[$target] = $source;
            $this->media[] = ['upload' => ['file' => $target], 'as' => 'u' . $file->id];
            $this->media[] = [
                'media.create' => array_filter([
                    'upload' => '${u' . $file->id . '.token}',
                    'copyright' => '' === $copyright ? null : $copyright,
                    'texts' => '' === $alt ? null : [$this->code => ['alt' => $alt]],
                ], static fn(mixed $value): bool => null !== $value),
                'as' => $name,
            ];
            $this->mediaNames[$file->id] = $name;

            if ('' === $alt) {
                $this->note(\sprintf('The image %s has no description (alt text) yet; the mapping can give it one under media.', $file->name));
            }
        }

        return array_filter(['media' => '${' . $this->mediaNames[$file->id] . '.id}', 'caption' => '' === $file->caption ? null : $file->caption], static fn(?string $value): bool => null !== $value);
    }

    /**
     * The form a plugin shows, created once with its fields.
     */
    private function form(Element $element): ?string
    {
        $id = (int) Row::pathString($element->config, 'formId');
        $form = $this->site->items[$id] ?? null;

        if (null === $form || 'Form' !== $form->type()) {
            $this->note(\sprintf('%s: the form %d does not exist.', $this->where, $id));

            return null;
        }

        if (!isset($this->formNames[$id])) {
            $name = 'form' . $id;
            $title = $element->content->text('title', $this->language);
            $groups = array_keys(array_filter($this->site->items, static fn(Item $item): bool => $item->parentId === $id && 'Group' === $item->type()));
            $fields = array_values(array_filter($this->site->items, static fn(Item $item): bool => \in_array($item->parentId, [$id, ...$groups], true) && 'Field' === $item->type()));
            $button = null;
            $add = [];

            foreach ($fields as $field) {
                $type = match (Row::pathString($field->config, 'type')) {
                    'Text' => 'text',
                    'Email' => 'email',
                    'Phone' => 'phone',
                    'Textarea' => 'textarea',
                    'Privacy' => 'consent',
                    'Checkbox' => 'checkbox',
                    'Date' => 'date',
                    'Number' => 'number',
                    'Button' => 'button',
                    default => null,
                };

                if ('button' === $type) {
                    $button = $field->title;
                } elseif (null === $type) {
                    $this->note(\sprintf('%s: the field "%s" (%s) of the form is left out.', $this->where, $field->title, Row::pathString($field->config, 'type')));
                } else {
                    $label = $field->title;
                    $link = null;

                    // Frootbox 1 shows its own text with the privacy policy in brackets (decision 0259).
                    if ('consent' === $type) {
                        $privacy = $this->privacyPage();
                        $own = self::squeeze(Row::pathString($field->config, 'privacyText'));
                        $label = '' !== $own ? $own : self::PRIVACY_TEXTS[$this->code] ?? $label;
                        $link = null === $privacy ? null : 'page:' . $this->ref($privacy);

                        if (null === $privacy) {
                            $this->note(\sprintf('%s: no page of the privacy policy found for the consent of the form, the field has no link.', $this->where));
                        }
                    }

                    $add[] = ['frootbox/forms/form.field-add' => array_filter([
                        'id' => '${' . $name . '.id}',
                        'type' => $type,
                        'translations' => [$this->code => ['label' => $label]],
                        'required' => 'consent' === $type ? null : true === ($field->config['mandatory'] ?? false),
                        'link' => $link,
                    ], static fn(mixed $value): bool => null !== $value)];
                }
            }

            $split = preg_split('/[,;\s]+/', Row::pathString($form->config, 'recipients'));
            $recipients = array_values(array_filter(false === $split ? [] : $split, static fn(string $address): bool => '' !== $address));
            $this->forms[] = [
                'frootbox/forms/form.create' => [
                    'translations' => [$this->code => array_filter(['title' => null === $title ? $form->title : self::plain($title, false), 'button' => $button], static fn(?string $value): bool => null !== $value && '' !== $value)],
                    'recipients' => $recipients,
                ],
                'as' => $name,
            ];
            $this->forms = [...$this->forms, ...$add];
            $this->formNames[$id] = $name;
        }

        return '${' . $this->formNames[$id] . '.id}';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function navigations(): array
    {
        $steps = [];

        foreach ($this->site->navigations as $navigation) {
            $this->where = \sprintf('Navigation "%s"', $navigation->key);

            if (!\array_key_exists($navigation->key, $this->mapping->navigations)) {
                $this->note($this->where . ' is left out: the mapping does not name it.');

                continue;
            }

            $name = $this->mapping->navigations[$navigation->key];

            if (null === $name) {
                continue;
            }

            $items = [];

            foreach ($navigation->items as $item) {
                if (!$item->visible) {
                    $this->note(\sprintf('%s: the entry "%s" is hidden and left out.', $this->where, $item->title));

                    continue;
                }

                if (0 !== $item->parentId) {
                    $this->note(\sprintf('%s: the entry "%s" below another one is left out.', $this->where, $item->title));

                    continue;
                }

                $page = null === $item->pageId() ? null : $this->resolve($item->pageId());
                $url = Row::pathString($item->config, 'url');

                if (null !== $page && 'Children' === $item->type()) {
                    $items = [...$items, ...$this->children($page)];
                } elseif (null !== $page) {
                    $entry = ['type' => 'target', 'target' => 'page:' . $this->ref($page)];

                    if ($item->title !== $this->site->pages[$page]->title) {
                        $entry['label'] = $item->title;
                    }

                    $items[] = $entry;
                } elseif ('' !== $url) {
                    $items[] = ['type' => 'url', 'url' => $url, 'label' => $item->title];
                } else {
                    $this->note(\sprintf('%s: the entry "%s" (%s) is left out.', $this->where, $item->title, $item->type()));
                }
            }

            $steps[] = ['navigation.update' => ['name' => $name, 'items' => [$this->code => $items]]];
        }

        foreach ($this->mapping->menus as $name => $items) {
            $this->where = \sprintf('Navigation "%s" of the mapping', $name);
            $steps[] = ['navigation.update' => ['name' => $name, 'items' => [$this->code => array_values($this->placeholders(Row::map($items), []))]]];
        }

        return $steps;
    }

    /**
     * The pages below a page, as Frootbox 1 lists them. Redirects among
     * them have no page in Frootbox 2, so they stay as single entries
     * with their title; without any the entry lists the pages itself.
     *
     * @return list<array<string, mixed>>
     */
    private function children(int $parent): array
    {
        $children = $this->site->children($parent);

        if ([] === array_filter($children, static fn(Page $child): bool => $child->isRedirect())) {
            return [['type' => 'pages', 'target' => 'page:' . $this->ref($parent), 'depth' => 1]];
        }

        $items = [];

        foreach ($children as $child) {
            $page = $this->resolve($child->id);

            if (null === $page) {
                continue;
            }

            $entry = ['type' => 'target', 'target' => 'page:' . $this->ref($page)];

            if ($child->title !== $this->site->pages[$page]->title) {
                $entry['label'] = $child->title;
            }

            $items[] = $entry;
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function siteFields(): array
    {
        if ([] === $this->mapping->site) {
            return [];
        }

        $this->where = 'Fields of the website';

        return [['site.update' => ['language' => $this->code, 'fields' => $this->placeholders($this->mapping->site, [])]]];
    }

    /**
     * A 301 for every old address that is not the path of a page of the
     * new website: a redirect there would shadow the page (decision 0356).
     *
     * @param list<Page> $pages
     *
     * @return list<array<string, mixed>>
     */
    private function redirects(array $pages): array
    {
        $own = [];
        $paths = [];

        // Parents come first (by lft), so their new path is known.
        foreach ($pages as $page) {
            if (isset($this->created[$page->id])) {
                $paths[$page->id] = $page->isRoot() ? '' : ltrim(($paths[$page->parentId] ?? '') . '/' . $page->slug(), '/');
                $own[$paths[$page->id]] = true;
            }
        }

        $steps = [];
        $this->where = 'Redirects';

        foreach ($this->paths as $path => $id) {
            if (isset($own[$path]) || '' === $path) {
                continue;
            }

            $page = $this->resolve($id);

            if (null === $page) {
                $this->note(\sprintf('The old address /%s leads to no page and gets no redirect.', $path));

                continue;
            }

            $steps[] = ['redirect.create' => ['path' => '/' . $path, 'target' => 'page:' . $this->ref($page)]];
        }

        return $steps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publish(): array
    {
        $steps = [];

        foreach ($this->formNames as $name) {
            $steps[] = ['frootbox/forms/form.publish' => ['id' => '${' . $name . '.id}']];
        }

        $steps[] = ['page.publish' => ['ids' => array_map($this->ref(...), array_keys($this->created))]];
        $names = array_values(array_unique([...array_filter($this->mapping->navigations, is_string(...)), ...array_keys($this->mapping->menus)]));

        if ([] !== $names) {
            $steps[] = ['navigation.publish' => ['names' => $names]];
        }

        if ([] !== $this->mapping->site) {
            $steps[] = ['site.publish' => []];
        }

        return $steps;
    }

    /**
     * Replaces "{page:5}" with the new page, "{config.x}" with a value of the
     * old configuration and "{widget.x}" with one of the first image in the text.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $config
     * @param array<string, mixed> $widget
     *
     * @return array<string, mixed>
     */
    private function placeholders(array $values, array $config, array $widget = []): array
    {
        foreach ($values as $key => $value) {
            // { value: '{config.x}', map: { old: new } } translates a value.
            if (\is_array($value) && 2 === \count($value) && isset($value['value'], $value['map']) && \is_array($value['map'])) {
                $old = $this->placeholders(['value' => $value['value']], $config, $widget)['value'] ?? null;
                $old = \is_scalar($old) ? (string) $old : '';
                $map = Row::map($value['map']);

                if ('' === $old) {
                    unset($values[$key]);
                } elseif (\array_key_exists($old, $map)) {
                    $values[$key] = $map[$old];
                } else {
                    $this->note(\sprintf('%s: the option "%s" is left out, the map has no value "%s".', $this->where, $key, $old));
                    unset($values[$key]);
                }

                continue;
            }

            if (\is_array($value)) {
                $values[$key] = $this->placeholders(Row::map($value), $config, $widget);

                if (array_is_list($value)) {
                    $values[$key] = array_values($values[$key]);
                }
            } elseif (\is_string($value)) {
                $whole = 1 === preg_match('/^\{(config|widget)\.[a-zA-Z0-9_.]+\}$/', $value);
                $values[$key] = (string) preg_replace_callback('/\{(page:(\d+)|(config|widget)\.([a-zA-Z0-9_.]+))\}/', function (array $match) use ($config, $widget): string {
                    if ('' !== ($match[2] ?? '')) {
                        $page = $this->resolve((int) $match[2]);

                        if (null === $page) {
                            $this->note(\sprintf('%s: page %s does not exist in Frootbox 2.', $this->where, $match[2]));

                            return '';
                        }

                        return 'page:' . $this->ref($page);
                    }

                    return Row::pathString('widget' === ($match[3] ?? '') ? $widget : $config, ...explode('.', $match[4] ?? ''));
                }, $value);

                // A single placeholder without a value leaves the option out.
                if ($whole && '' === $values[$key]) {
                    unset($values[$key]);
                } elseif ($whole && is_numeric($values[$key])) {
                    // A single number stays a number, e.g. the zoom of a map.
                    $values[$key] = str_contains($values[$key], '.') ? (float) $values[$key] : (int) $values[$key];
                }
            }
        }

        return $values;
    }

    private function note(string $line): void
    {
        if (!\in_array($line, $this->report, true)) {
            $this->report[] = $line;
        }
    }
}
