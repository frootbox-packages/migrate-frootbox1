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
