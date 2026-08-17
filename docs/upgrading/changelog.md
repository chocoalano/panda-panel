# Changelog

`CHANGELOG.md` is the record of everything that changed in `chocoalano/panel`, in the words of the
person who changed it. It is the file to read when you want to know *what happened* in a release;
[Breaking changes](breaking-changes.md) is the one to read when you want to know *what to edit*.
This page is how the file is organised, what each heading means here specifically, and how to get
from an entry to the work it implies.

## A minimal working example

The changelog is not in the installed package, so it is read from the repository:

```bash
git clone https://github.com/chocoalano/panda-panel
less panda-panel/CHANGELOG.md
```

Or from a checkout you already have:

```bash
git log --oneline -- CHANGELOG.md    # when each entry landed
git diff v0.1.1..v0.1.2 -- CHANGELOG.md
git show v0.1.2:CHANGELOG.md         # the file as it was at a tag
```

Everything below is about reading that one file.

## Where it lives, and why not in `vendor/`

`CHANGELOG.md` sits at the repository root and is marked `export-ignore` in `.gitattributes`:

```text
/CHANGELOG.md       export-ignore
```

So `vendor/chocoalano/panel/CHANGELOG.md` does not exist in a normal install, and neither do
`/docs`, `/tests`, `/examples` or the frontend toolchain. A composer dist archive carries what an
application runs — `src`, `config`, `database`, `stubs`, `resources`, `composer.json`,
`README.md`, `LICENSE.md` — and nothing about how the repository is developed.

```bash
composer show chocoalano/panel                 # the installed version
ls vendor/chocoalano/panel                      # no CHANGELOG.md here
git -C /path/to/panda-panel show v0.1.2:CHANGELOG.md
```

An install made with `--prefer-source` clones the repository instead of unpacking the archive, and
does have it. That is the exception rather than the thing to rely on.

## The format

The file states its own contract in its first lines, and both halves of it are load-bearing:

```markdown
# Changelog

All notable changes to `panda-panel` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
```

**Keep a Changelog** decides the shape: one `##` section per release, `###` category headings
inside it, newest first, and an `## [Unreleased]` section at the top for what is merged but not
tagged. **Semantic Versioning** decides the numbers, which is a separate promise with its own
page — [Versioning policy](versioning.md), including the part that matters most today: the package
is in its `0.x` series, where a minor release is allowed to break things.

## The categories

Keep a Changelog defines six. Five of them are in use here, and each means something specific:

| Heading | What goes under it | Read it when |
| --- | --- | --- |
| `### Security` | A vulnerability that was fixed, with the attack it allowed and who could reach it. | Always, and first. |
| `### Added` | New public surface — a class, a method, a command, a config key, a screen. | You are looking for a feature. |
| `### Changed` | Existing behaviour that is now different, including the ones with no edit to make. | Before an upgrade. |
| `### Fixed` | A bug. The entry names the wrong behaviour, not the commit. | When something you worked around may no longer need it. |
| `### Removed` | Surface that is gone. | Before an upgrade. |
| `### Deprecated` | Surface that still works and is going away. | Not yet used by this project. |

Two properties of the categories here are worth knowing before you scan for something.

**`Security` comes first in the section, not in alphabetical or Keep a Changelog order.** The
current `## [Unreleased]` section opens with four security entries — CSV formula injection in
exports, and three about the upload endpoint's authorization — because an entry nobody scrolls to
is an entry nobody reads.

**Category headings are not guaranteed to be unique within a section.** The current `Unreleased`
section carries two separate `### Fixed` blocks. Search the file rather than assuming the first
block you find is all of them:

```bash
grep -n '^### ' CHANGELOG.md
grep -n -i 'upload\|export\|widget' CHANGELOG.md
```

## How an entry is written

Entries are sentences about behaviour, not summaries of commits. The convention is a bolded lead
that states the user-visible change, then the reasoning — which usually means naming the failure
the change replaces, because that is the string somebody will search for:

```markdown
- **A resource that forgets `$model` says so.** PHP answered `Typed static property
  PandaPanel\Resources\Resource::$model must not be accessed before initialization`, which names
  this framework's class rather than the resource that forgot, and does not say what to write. It
  now names the resource and prints the line to add.
```

This is why grepping the changelog for an error message you are staring at is often faster than
grepping the source:

```bash
grep -n -i 'must not be accessed before initialization' CHANGELOG.md
grep -n -i 'Echo has not been configured' CHANGELOG.md
grep -n -i 'Call to undefined method' CHANGELOG.md
```

An entry with no bolded lead is a one-line fact — a publish tag added, a stub reordered — and needs
no more than the line it has.

## Entries marked breaking

An entry whose change requires an edit in an application says so inline, in bold, and names where
the fix is written out:

```markdown
**Breaking:** these throw where they previously did nothing. An application carrying one of them
has a bug today and will get an exception at schema-build time after upgrading — which is at boot
or on first render, so a test suite finds it before a user does. See `docs/upgrade.md`.
```

```markdown
**Behaviour change:** an application that broadcasts from PHP but has `BROADCAST_CONNECTION=null`
or `log` now gets no panel channel. That was already a connection no browser could subscribe to;
what changes is that the panel says so instead of failing at mount.
```

**Every such entry has a matching section in the upgrade guide.** The changelog says what changed
and why; the upgrade guide says what breaks and what to type. In this documentation set that guide
is [Breaking changes](breaking-changes.md), and the order the edits fit into is
[Upgrade guide](upgrade-guide.md).

The current `Unreleased` section maps onto it like this:

| Changelog entry | Category there | Breaking changes |
| --- | --- | --- |
| Uploads are authorized by the form the field belongs to | `Security` | [§1](breaking-changes.md) |
| CSV exports no longer execute what somebody typed into a text field | `Security` | [§9](breaking-changes.md) |
| The `notifications` migration's `down()` no longer drops a table the application owned | `Fixed` | [§2](breaking-changes.md) |
| A schema that cannot mean what it says is now refused, loudly | `Fixed` | [§3](breaking-changes.md) |
| `PanelPlugin::publishes()` is on the contract | `Changed` | [§4](breaking-changes.md) |
| Plugin version constraints are checked again | `Fixed` | [§5](breaking-changes.md) |
| The guest redirect is registered by the service provider | `Changed` | [§6](breaking-changes.md) |
| Signing in lands in the panel | `Added` | [§7](breaking-changes.md) |
| Broadcasting no longer assumes a broadcaster | `Fixed` | [§8](breaking-changes.md) |

Two things fall out of that table. A breaking change can live under **any** category — three of the
nine are under `Fixed`, one is under `Added` — so scanning only for a `Changed` heading misses most
of them. And a changelog entry names a cause where the upgrade guide names an edit, which is why
both exist.

## `## [Unreleased]`

Everything merged and not yet tagged sits under `## [Unreleased]`, which is the top of the file and
the section that answers "is this fixed on main?".

```bash
sed -n '/## \[Unreleased\]/,/^## \[/p' CHANGELOG.md
```

At a release, that heading becomes a version heading with a date and a new empty `## [Unreleased]`
is opened above it:

```markdown
## [Unreleased]

## [0.1.3] - 2026-08-15

### Fixed

- …
```

Which is a step on the [Release checklist](release-checklist.md) rather than something a tool does.

The published tags are the other half of the record, and they are what composer resolves against:

```bash
git tag                                  # v0.1.0, v0.1.1, v0.1.2, v0.1.4 — v0.1.3 was never tagged
git log --oneline v0.1.1..v0.1.2         # what a tag actually contained
composer show chocoalano/panel --all     # every version Packagist knows about
```

A tag with no matching `## [x.y.z]` heading means the work is still filed under `Unreleased`; read
the section, not the absence of a heading.

## Reading it for an upgrade

The changelog is long because the entries explain themselves. Four passes get most of the value out
of one:

```bash
# 1. Everything under Security, always.
grep -n -A 3 '^### Security' CHANGELOG.md

# 2. Everything that needs an edit.
grep -n -i '\*\*breaking\|\*\*behaviour change' CHANGELOG.md

# 3. Anything naming a class you call.
grep -n 'FormSchema\|TableSchema\|Exporter\|Panel::' CHANGELOG.md

# 4. Anything naming a published file, which composer alone will not bring you.
grep -n '\.vue\|\.ts\b\|resources/js' CHANGELOG.md
```

That last pass is the one people miss. A `Fixed` entry about `panel/lib/grid.ts`,
`forms/uploadEndpoint.ts` or a `.vue` component describes a file **your application owns**, so
`composer update` does not deliver it:

```bash
php artisan panel:assets            # is that file behind, or did you edit it?
php artisan panel:assets --update
npm run build
```

See [Resolving asset conflicts](asset-conflicts.md) when it turns out you edited it.

## What the changelog does not cover

| Not there | Where instead |
| --- | --- |
| The edit to make for a breaking change | [Breaking changes](breaking-changes.md) |
| The order the upgrade steps happen in | [Upgrade guide](upgrade-guide.md) |
| What a version number promises | [Versioning policy](versioning.md) |
| Which PHP, Laravel, Node and npm versions are supported | [Compatibility](../getting-started/compatibility.md) |
| Which published files changed in your application | `php artisan panel:assets` |

And some entries are about the repository rather than the package: the CI matrix, the frontend
toolchain, `examples/`, `tests/` and `docs/` are all `export-ignore`d, so an entry about them
changes nothing an application installs. They are in the file because the changelog is the record
of the project, not only of its dist.

## Notes

- **Read `Security` even on a patch release.** The four security entries in the current section
  include one — CSV formula injection — whose attacker is anyone who can write a record field and
  whose victim is the administrator who opens the export.
- **A breaking change can be filed under `Fixed`.** Three of the nine currently are. Grep for
  `**Breaking:` and `**Behaviour change:`, not for a heading.
- **`### Fixed` may appear more than once in a section.** Search the file rather than reading the
  first block.
- **Entries reference `docs/upgrade.md`.** That is the master document's name for the guide; in
  this set it is [Breaking changes](breaking-changes.md), with the procedure in
  [Upgrade guide](upgrade-guide.md).
- **`CHANGELOG.md` is not installed.** `vendor/chocoalano/panel/CHANGELOG.md` does not exist unless
  you installed with `--prefer-source`.
- **The changelog is not a migration script.** An entry about a published Vue or TypeScript file
  needs `panel:assets --update` and `npm run build` before it reaches a browser.
- **There is no `Deprecated` section yet.** Nothing has been marked as going away; things have been
  removed outright, under `Removed`.

## See also

- [Breaking changes](breaking-changes.md) — every change that needs an edit, with the edit
- [Upgrade guide](upgrade-guide.md) — the order those edits fit into
- [Versioning policy](versioning.md) — what the numbers in the headings promise
- [Release checklist](release-checklist.md) — how an `Unreleased` section becomes a release
- [Package name migration](package-name-migration.md)
- [Asset manifest](asset-manifest.md), [Resolving asset conflicts](asset-conflicts.md)
- [Compatibility](../getting-started/compatibility.md), [Requirements](../getting-started/requirements.md)
- [CI matrix](../testing/ci-matrix.md)
- [`panel:assets`](../cli/panel-assets.md)
