<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Analysis;

use Frootbox\MigrateFrootbox1\Source\Content;
use Frootbox\MigrateFrootbox1\Source\Row;
use Frootbox\MigrateFrootbox1\Source\Site;

/**
 * Counts what a website uses and finds what will not come over by
 * itself: missing files, widgets that are gone, redirects to pages that
 * do not exist.
 */
final class Analyzer
{
    /**
     * @param string|null $files The folder of the installation below files/, to check the uploads
     */
    public static function analyze(Site $site, ?string $files = null): Analysis
    {
        $layouts = [];
        $blocks = [];
        $plugins = [];
        $items = [];
        $widgets = [];
        $problems = [];
        $own = null;

        foreach ($site->pages as $page) {
            $layout = '' === $page->view() ? 'Default' : $page->view();
            $usage = $layouts[$layout] ??= new Usage($layout);
            ++$usage->count;
            $usage->pages[$page->id] = true;

            $variables = self::flatten(Row::map($page->config['variables'] ?? []));

            if ([] !== $page->content->fields() || [] !== $variables) {
                $own ??= new Usage('page');
                ++$own->count;
                $own->pages[$page->id] = true;
                self::fields($own, $page->content, $items);

                foreach ($variables as $name => $value) {
                    $own->option('variables.' . $name, $value);
                }
            }

            foreach ($page->blocks as $block) {
                $usage = $blocks[$block->type] ??= new Usage($block->type, '' === $block->library() ? 'project' : $block->library());
                ++$usage->count;
                $usage->pages[$page->id] = true;
                self::fields($usage, $block->content, $items);

                foreach ($block->variables() as $name => $value) {
                    $usage->option($name, $value);
                }

                $problems = [...$problems, ...self::widgets($site, $block->content, \sprintf('block %d (%s) on page %d', $block->id, $block->type, $page->id), $widgets)];
            }

            foreach ($page->elements as $element) {
                $key = $element->plugin() . '@' . $element->layout();
                $usage = $plugins[$key] ??= new Usage($key);
                ++$usage->count;
                $usage->pages[$page->id] = true;
                self::fields($usage, $element->content, $items);

                foreach (self::flatten($element->config) as $name => $value) {
                    $usage->option($name, $value);
                }
            }

            if ($page->isRedirect()) {
                $target = $page->redirectTarget();

                if (1 === preg_match('#^fbx://page:(\d+)$#', $target, $match) && null === $site->page((int) $match[1])) {
                    $problems[] = \sprintf('Page %d "%s" redirects to page %s, which does not exist.', $page->id, $page->title, $match[1]);
                }
            }
        }

        if (null !== $files) {
            foreach ($site->files as $file) {
                if (!is_file(rtrim($files, '/') . '/' . $file->path)) {
                    $problems[] = \sprintf('File %d "%s" is missing in %s.', $file->id, $file->name, $file->path);
                }
            }
        }

        ksort($blocks);
        ksort($plugins);
        ksort($items);

        ksort($widgets);

        return new Analysis($site, $layouts, $blocks, $plugins, $items, $widgets, $problems, $own);
    }

    /**
     * @param array<string, Usage> $items
     */
    private static function fields(Usage $usage, Content $content, array &$items): void
    {
        foreach ($content->texts as $field => $languages) {
            foreach ($languages as $text) {
                $usage->field(self::generic($field), $text->kind());
            }
        }

        foreach ($content->files as $field => $files) {
            foreach ($files as $file) {
                $usage->field(self::generic($field), $file->isImage() ? 'image' : 'file');
            }
        }

        foreach ($content->items as $field => $list) {
            foreach ($list as $item) {
                $usage->field($field, 'items:' . $item->type());
                $nested = $items[$item->type()] ??= new Usage($item->type(), $item->className);
                ++$nested->count;
                self::fields($nested, $item->content, $items);

                if (null !== $item->link()) {
                    $nested->field('link', 'page');
                }

                // Plain values such as the position or phone of a contact person.
                foreach ($item->config as $key => $value) {
                    if (\is_string($value) && '' !== $value) {
                        $nested->field($key, 'value');
                    }
                }
            }
        }
    }

    /**
     * Numbered fields count as one: text-1, text-2 → text-#.
     */
    private static function generic(string $field): string
    {
        return (string) preg_replace('/-\d+$/', '-#', $field);
    }

    /**
     * Counts widgets in texts (figure data-id) and names those whose row is gone.
     *
     * @param array<string, Usage> $usages
     *
     * @return list<string>
     */
    private static function widgets(Site $site, Content $content, string $where, array &$usages): array
    {
        $problems = [];

        foreach ($content->texts as $languages) {
            foreach ($languages as $text) {
                preg_match_all('/<figure[^>]*data-id="(\d+)"/', (string) $text->html, $matches);

                foreach ($matches[1] as $id) {
                    $widget = $site->widgets[(int) $id] ?? null;

                    if (null === $widget) {
                        $problems[] = \sprintf('A widget %s in field "%s" of %s is gone; the text keeps an empty figure.', $id, $text->field, $where);

                        continue;
                    }

                    $usage = $usages[$widget->type()] ??= new Usage($widget->type());
                    ++$usage->count;

                    foreach ($widget->content->files as $field => $files) {
                        $usage->field($field, $files[0]->isImage() ? 'image' : 'file');
                    }

                    foreach (['width', 'align', 'layoutId'] as $option) {
                        $usage->option($option, $widget->config[$option] ?? null);
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * Options of a plugin as dotted names, without the layout itself.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function flatten(array $config, string $prefix = ''): array
    {
        $flat = [];

        foreach ($config as $key => $value) {
            if ('' === $prefix && 'layout' === $key) {
                continue;
            }

            if (\is_array($value) && [] !== $value && !array_is_list($value)) {
                $flat = [...$flat, ...self::flatten(Row::map($value), $prefix . $key . '.')];
            } else {
                $flat[$prefix . $key] = $value;
            }
        }

        return $flat;
    }
}
