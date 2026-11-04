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
    widgets: { Core/Images/Thumbnail: image }  # the first image widget in the text
  BreakerImage: false
plugins:
  Core/Navigation/Teaser@Index06: { block: acme/theme/Stage, fields: { items.1.background: image } }
  Core/ContactForms/FormTeaser@Index01: { block: frootbox/forms/Form, form: true }
  Core/FileManager/CopyrightFilesList@Index01: { block: frootbox/theme-base/ImageCredits }
items:
  Teaser: { fields: { title: title, text: text, link: { to: link, text: More } } }
navigations: { BannerMain: main }
site:
  fields: { brand: Example, contact: { target: '{page:5}', text: Contact } }
media:                              # by the file name in Frootbox 1
  team.jpg: { alt: 'The team in front of the practice', copyright: 'Jane Doe' }
```

`{page:5}` is the new page of page 5 in Frootbox 1, `{config.x}` a value
in the configuration of the old block. Each value is converted by the type
of the target field: HTML becomes rich text, links to old pages and
aliases become links to the new pages, files become media.
Under `media` the mapping fills in an alt text or a copyright that a file
lacks in Frootbox 1.

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
