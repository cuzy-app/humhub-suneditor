# CLAUDE.md — cuzy-app/humhub-suneditor

Context for future Claude sessions working on this repo. For how to *use* the
package, read [README.md](README.md) first — it documents the public API and
every non-obvious option in depth. This file is about maintaining the package
itself: architecture, why things are shaped the way they are, how to verify a
change, how to release, and which real modules depend on it.

## What this is

A standalone Composer library (no `module.json`, not a HumHub module) that
gives HumHub modules a SunEditor rich-text field with inline file uploads and
automatic cleanup of files removed from the content before saving. Four
classes, one JS bootstrap file, one stylesheet:

```
src/
├── widgets/SuneditorField.php      # authoring: renders the editor
├── widgets/SuneditorContent.php    # rendering: purifies + displays stored HTML
├── actions/UploadAction.php        # generic upload endpoint, no access control
├── helpers/EditorFileHelper.php    # attach/orphan-cleanup at save time
├── helpers/FileDuplicator.php      # independent file copy for record duplication
├── assets/SuneditorFieldAssets.php / SuneditorContentAssets.php
└── resources/js/humhub.suneditor.js, resources/css/content.css
```

`npm-asset/suneditor` (vendored via asset-packagist) is the actual SunEditor
v3 JS/CSS; this package only wires it into HumHub's widget/asset/form
conventions and adds the HumHub-specific behavior (upload routing, purification,
file lifecycle).

## Design decisions worth knowing before touching the code

These are the ones that cost real debugging time to find — each is documented
in full at its point of use (docblocks in the class, or the linked
CHANGELOG.md entry); this is only the index so you know where to look:

- **`allowedExtraTags`, not `elementWhitelist`**, is what keeps `<script>`/
  `<style>` through the code view. `elementWhitelist` looks like the right
  option and does nothing here — `allowedExtraTags` independently blacklists
  those tags and wins regardless. See `SuneditorField::$editorOptions` docblock,
  [CHANGELOG.md#1.1.2](CHANGELOG.md).
- **`strictMode`'s `attrFilter` and `styleFilter` must be set together.** They
  share one internal SunEditor pass that rebuilds an element's opening tag; if
  either is left at its default, that pass keeps only what the enabled one
  collected — so `attrFilter: false` alone still silently drops everything,
  `id`/`class` included, unless `styleFilter` is also `false`. Verified
  empirically (see "Verifying a fix" below), not from documentation — SunEditor
  doesn't document this interaction. See [CHANGELOG.md#1.1.3](CHANGELOG.md).
- **`SuneditorField::$editorOptions` is not named `$clientOptions`.**
  `yii\bootstrap5\InputWidget` (via `BootstrapWidgetTrait`) already declares an
  *untyped* `public $clientOptions` for its own Bootstrap JS plugin config.
  Redeclaring it with a type is a PHP fatal; even without a type it would
  silently leak SunEditor options into Bootstrap's init. See
  [CHANGELOG.md#1.1.1](CHANGELOG.md).
- **SunEditor's own code-view serializer entity-escapes `<script>`/`<style>`
  content**, corrupting real JS/CSS the moment the code view is merely opened
  (HTML5 defines both as raw-text elements; SunEditor's `_convertToCode()`
  doesn't special-case them). Fixed by reversing the escaping on the
  `onToggleCodeView` event using SunEditor's own inverse function
  (`helper.converter.entityToHTML`). See [CHANGELOG.md#1.1.4](CHANGELOG.md).
- **`SuneditorContent::purify()` never allows `script`/`style`, and this is not
  configurable.** HTMLPurifier has no raw-text-element handling for either —
  content would be tokenized as ordinary markup and corrupted. A module that
  has already decided (on its own, outside this package) that a field may
  contain trusted `<script>` content must bypass `purify()`/the widget
  entirely and call `SuneditorContent::addNonce()` directly. See the
  `purify()` docblock — it explains this at length, don't re-litigate it as a
  bug report.
- **Files embedded inline via the editor get `show_in_stream = false`.**
  `File::show_in_stream` defaults to `true`, and `FileManager::attach()` never
  touches it — so without this, a file rendered inline by the editor's own
  HTML would *also* show up in anything that lists a record's attached files
  generically (e.g. a wall entry's file footer). Set in `UploadAction`, not in
  `EditorFileHelper::sync()`, since it has to happen once, at upload time —
  not be recomputed every sync. See [CHANGELOG.md#1.2.1](CHANGELOG.md).
- **`EditorFileHelper::sync()`'s `$keepGuids` contract is easy to get wrong.**
  It must be *every* guid the record owns outside the editor field, not just
  "whatever this request's form happened to submit" — a caller that passes an
  incomplete list will have `sync()` delete files it shouldn't. The correct
  way to compute it when a record also has its own separate file-attachment
  feature (a dropzone, a thumbnail) is: everything currently attached, minus
  whatever the *previous* value of the field referenced (i.e., only guids that
  could actually be an inline embed the user just removed are eligible for
  deletion). See the Web Feed consumer note below for a worked example — this
  bit the module's own `afterSave()`, not the package, but it's exactly the
  kind of caller mistake this contract invites.

## Verifying a fix: real SunEditor, no server needed

Several of the fixes above were originally shipped "textbook correct" from
reading SunEditor's docs/source, and turned out wrong in practice — the docs
describe intent, not always the actual code path. The pattern that caught this
every time: drive the **real vendored** `suneditor.min.js`/`.css` (from
`npm-asset/suneditor`, e.g. via `Composer\InstalledVersions::getInstallPath()`
or just the copy in a consuming module's `vendor/`) in a standalone HTML page
under headless Chrome via Playwright — no HumHub server, no login, no
database. Load the page, call `SUNEDITOR.create()` with the options under
test, drive the DOM (type into code view, toggle buttons, read
`.getContents()`), and compare against what the option was supposed to do.

This is worth doing again for any change to `editorOptions` handling,
`buttonList`/`excludeButtons` logic, or anything touching the code-view
round-trip — those are exactly the areas where SunEditor's actual behavior has
previously diverged from its documentation.

## Release process

No `module.json`, so there's no version field to keep in sync — the package is
versioned purely by git tag, resolved by Composer/Packagist directly.

1. Land the change on `main` with a descriptive commit message (the commit
   message is the primary record of *why*, since there's no PR history to
   fall back on for a solo-maintained repo).
2. Decide the version bump by SemVer: breaking change to the public API →
   major; new option/feature → minor; bug fix → patch. Every actual release so
   far has been a patch or minor bump (see CHANGELOG.md) — nothing has broken
   the public API yet.
3. Add a `## [x.y.z] - YYYY-MM-DD` section to the top of `CHANGELOG.md`
   ([Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format), and a
   compare-link reference at the bottom (`[x.y.z]: .../compare/vPREV...vNEW`).
4. `git tag vX.Y.Z && git push origin main && git push origin vX.Y.Z`.
5. Create a matching GitHub Release with `gh release create vX.Y.Z --title
   X.Y.Z --notes-file <the CHANGELOG section body>` — keep the two in sync;
   CHANGELOG.md is the source of truth (it ships in the Composer `vendor/`
   copy, so it's readable offline/in an IDE without hitting GitHub), the
   Release is a mirror of it for GitHub's UI.
6. In each consuming module: `composer update cuzy-app/humhub-suneditor`
   (check the module's version constraint first — a major bump needs the
   constraint widened there too).

## Known consumers

All four are separate HumHub module repos, not part of this one — check their
own `composer.json` for the exact version constraint in use. Listed because
the trust model and file-lifecycle wiring differ meaningfully between them;
useful context before assuming a change is safe everywhere:

- **LMS** (`modules_cuzy/lms`) — the module this package was originally
  extracted from. `uploadUrl` set on both the course description and lesson
  content fields; full `EditorFileHelper::sync()` lifecycle wired in
  controller traits (`CourseManagementTrait`, `CurriculumManagementTrait`).
  `editorOptions` widens `allowedExtraTags`/`strictMode` for `<style>` support.
- **Homepage** (`modules_cuzy/homepage`) — admin-authored space/user homepage
  content. `uploadUrl` set, but **never calls `EditorFileHelper::sync()`** —
  uploaded files stay permanently unattached (relies on core's file-module
  cron for anything abandoned). Renders via `SuneditorContent::addNonce()`,
  **not** the `SuneditorContent` widget/`purify()` — this content type
  intentionally allows `<script>`/`<style>` (admin-only, by design), which
  `purify()` would strip.
- **Inforisque** (`modules_cuzy_partners/inforisque`) — advertiser-authored
  product/post/event fields. No `uploadUrl` (inline uploads are off; only the
  toolbar/formatting features are used). `excludeButtons: ['codeView',
  'blockStyle', 'align']` to keep its pre-existing toolbar shape. Content is
  never rendered inside HumHub — it's sent outbound to a WordPress API, where
  it's purified at the point of building that payload (`WpApiPostModel`/
  `WpApiEventModel`), not via the `SuneditorContent` widget.
- **Web Feed** (`modules_cuzy/web-feed`) — post description on content
  retrieved from an external feed, editable by anyone who can manage the
  feed. `uploadUrl` set; also has its own independent "Attach Files" dropzone
  feature (unrelated to this package, pre-existing) attaching to the same
  `fileManager`. Its `afterSave()` is the worked example for the
  `EditorFileHelper::sync()` `$keepGuids` gotcha above — it originally passed
  only the current request's newly-uploaded dropzone guids as `$keepGuids`,
  which silently deleted untouched pre-existing dropzone attachments on every
  save that didn't touch the dropzone widget. Rendering goes through the
  `SuneditorContent` widget (full `purify()`) — feed content is external and
  untrusted, unlike Homepage's admin-authored content.
