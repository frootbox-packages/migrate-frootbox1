# Migration from Frootbox 1

Moves a website from Frootbox 1 to Frootbox 2. The tool reads the
database and the `files/` folder of the old installation and changes
nothing there. It is an extension for the development of a website and
never part of the standard bundle.

## Analyse

```bash
bin/frootbox fb1:analyze 'pdo-mysql://user:secret@localhost/old?charset=utf8mb4' \
    --files=/path/to/frootbox/files/site \
    --mapping=migration/frootbox1.yaml
```

The report lists the pages with their blocks and plugins, every block
type with the fields found in its stored content, plugins with their
layout and options, objects such as teasers, widgets inside texts,
navigations and redirects. Problems are listed at the end: files missing
on disk, widgets whose row is gone, redirects to pages that do not exist.
The command exits with 1 if there are any.

With `--mapping` it writes a first mapping file, never over an existing
one. Blocks and layouts of the same name in the Frootbox 2 project are
filled in; everything else stays `~` to be chosen.

Frootbox 1 keeps texts and files by UID, e.g.
`Frootbox-Persistence-Content-Blocks-Repositories-Blocks:4:title`, so the
fields of a block are what is stored, not what the template declares.
Numbered fields count as one (`text-1`, `text-2` → `text-#`).

## Mapping

The mapping says what becomes of each thing in Frootbox 1. `false`
leaves it out; the report of the import lists it.

```yaml
languages: { de-DE: de }
domains: [www.example.com]          # links to these count as internal
layouts:
  Default: acme/theme/Content       # or { layout: …, area: main }
blocks:
  Text:                             # the type of the block in Frootbox 1
    block: acme/theme/Text
    fields:
      title: title                  # field of Frootbox 1: field of Frootbox 2
      title.supertitle: eyebrow     # a value of the headline
      text-#: columns.#.body        # numbered fields into a list
  Gallery: { block: acme/theme/Gallery, fields: { images: items.#.image } }  # each file an entry
    widgets: { Core/Images/Thumbnail: image }  # the first image widget in the text
  BreakerImage: false
plugins:
  Core/Navigation/Teaser@Index06: { block: acme/theme/Stage, fields: { items.1.background: image } }
  Core/ContactForms/FormTeaser@Index01: { block: frootbox/forms/Form, form: true }
  145: { block: frootbox/theme-base/VideoEmbed }  # one plugin by its ID, before the rule of its type
  7: { block: acme/theme/Stage, needs: items, fields: { 'files-{page}': items.#.image } }  # images by page
  Core/Navigation/SubNavigation@Index05: { block: acme/theme/Cards, fields: { title: title, children: items } }
  Core/FileManager/CopyrightFilesList@Index01: { block: frootbox/theme-base/ImageCredits }
  Core/HelpAndSupport/ContactPersons@Index01: { block: acme/theme/Team, fields: { persons: people } }
  Core/News/Archive@Index01: { plugin: frootbox/news/article }  # the page shows the plugin
  Acme/Rental/Unit@Index01:            # one plugin, two blocks: "then" adds blocks right after it
    block: acme/rental/Calendar
    options: { unit: '{config.unitId}' }
    then: [{ block: acme/rental/Prices, options: { unit: '{config.unitId}' } }]
  Frootbox\Persistence\Content\Elements\Grid@Index01:   # a grid of columns, e.g. "6-6"
    block: frootbox/theme-base/Section
    variant: Two
    areas: [first, second]          # the area of the block for each column, in order
items:
  Teaser: { fields: { title: title, text: text, link: { to: link, text: More } } }
  Contact: { fields: { title: name, position: role, phone: phone, email: email, image: image } }
  Page: { fields: { title: title, image: image, teaser-text: text, link: link } }  # pages below, see children
page:                               # fields of the page itself, as a block on top
  block: acme/theme/Banner
  area: head                        # optional: an area of its own instead of the top of main
  fields: { background: image }
  options:
    height: { value: '{config.variables.bannerStyle}', map: { big-header: large, small-header: small } }
navigations:
  BannerMain: main                  # a navigation of Frootbox 1: one of Frootbox 2
  footer:                           # or the items of one of Frootbox 2, e.g. where
    - { type: target, target: '{page:2}' }   # Frootbox 1 showed the page tree
    - { type: pages, target: '{page:3}', depth: 1 }
pages: { 12: { title: My account } }  # a new title by the ID in Frootbox 1, the address stays
content: [1]                        # only these pages get their content so far
site:
  fields: { brand: Example, contact: { target: '{page:5}', text: Contact } }
media:                              # by the file name in Frootbox 1
  team.jpg: { alt: 'The team in front of the practice', copyright: 'Jane Doe' }
```

`{page:5}` is the new page of page 5 in Frootbox 1, `{config.x}` a value
in the configuration of the old block (or page), `{widget.x}` one of the
first image the block takes from its text, e.g. `{widget.align}` or
`{widget.width}` (of 12 columns). A widget without a file, e.g. a video,
gives only these values with `widgets: { Core/Video/Video: true }`, e.g.
`{widget.url}` as the address of a video block. `{ value: …, map: … }`
translates such a value; an option without a value is left out. Objects
give their title, their linked page and plain values such as the
`position` of a contact person; the contact persons of a plugin are the
list `persons`. A plugin with `plugin:` makes its page show that plugin
of Frootbox 2 in its default space, after the content; with `block:` as
well, its texts also become that block. When `block:` names the block
of the plugin itself (one with `plugin: true`, e.g.
`frootbox/shop/Catalog`), it becomes the view of the plugin with its
fields and options instead of a block in the area. The layout of the page needs a
place for a plugin. Of several files in one field the import takes the one
Frootbox 1 shows. A plugin marked "Inherited" in Frootbox 1 shows on its
page and every page below; `{page}` in the name of a field is the page
it shows on, e.g. `files-{page}` for a slider with images by page, and
`needs` leaves the block out where such a field stays empty. A plugin
without a heading of its own gets its title, as Frootbox 1 showed it.
`children` gives the public pages below the page as objects of type
`Page` with their title, link and fields such as `image` and
`teaser-text`, e.g. for a sub navigation. `then` lists further rules for
the same block or plugin, each a block right after the first, with the
same texts and configuration, e.g. a calendar and the prices of a holiday
apartment from one plugin of Frootbox 1. The plugins in the columns of a
grid go into the areas its rule names under `areas`, one per column in
order; without them they follow one after another where the grid was. A widget in the text fills a
list with all its files with `widgets: { Core/Images/Gallery:
images.#.image }`. Each value is converted by the type
of the target field: HTML becomes rich text, links to old pages and
aliases become links to the new pages, files become media.
Under `media` the mapping fills in an alt text or a copyright that a file
lacks in Frootbox 1. To move a website page by page, `content` lists the
IDs of the pages that get their content; every other page comes with its
title and address only, so navigations, links and redirects already hold.

## Import

```bash
bin/frootbox fb1:import 'pdo-mysql://user:secret@localhost/old?charset=utf8mb4' \
    --files=/path/to/frootbox/files/site \
    --mapping=migration/mapping.yaml --out=migration/seed --publish
bin/frootbox seed migration/seed/site.yaml
```

The import writes no database. It writes a seed: `site.yaml` with the
steps (media, pages, content, forms, navigations, fields of the website,
redirects for every old address), the images in `media/` and `report.md`
with what a person should look at. The seed runs on an empty website, so
a project builds itself anew from the old database on every run, e.g. in
a script that backs up, installs and seeds. Without `--publish` all of it
stays a draft.

Hidden blocks, plugins and entries of navigations stay behind, as do
redirect pages: their address becomes a redirect to the target.

Each page takes over its description for search engines (the one of its
address wins, as in Frootbox 1, cut to 300 characters) and whether search
engines or the site search leave it out. Frootbox 2 has no title of its
own for search engines yet, so such a title goes into the report.

## Source and issues

This package is developed in the [Frootbox monorepo](https://github.com/Frootbox/frootbox)
and mirrored here read-only. Questions, bugs and ideas go there, see the
[documentation](https://github.com/Frootbox/frootbox/tree/main/documentation).
