# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.1] - 2026-08-31

### Fixed

- Content whose first element was a `<script>` or `<style>` block gained an
  empty paragraph in front of it — visible in the code view as `<p><br>` on its
  own line, and stored that way. It was the placeholder
  `guardLeadingRawTextElement()` prepended in 1.2.2 to force the HTML parser out
  of "in head" insertion mode; the claim in that entry that "SunEditor's own
  cleanup removes empty format lines a moment later" was wrong — the empty
  paragraph survives every round trip.
- The same leading block was still lost outright when the *editor was
  initialised* on already-saved content, a path that workaround never covered
  (it hooked `viewer.codeView` only, and `html.clean()` also runs on init). The
  placeholder had been hiding this: content saved with it no longer *starts*
  with the raw-text element, so nothing was dropped on reopening. A field saved
  by any other route lost its leading `<style>` the next time the form opened.

  Both are now fixed at the root instead: a field that allows `script`/`style`
  through `allowedExtraTags` gets `autoStyleify: []`, which removes the
  full-document `DOMParser` round trip those blocks were disappearing into, so
  no placeholder is needed anywhere. `guardLeadingRawTextElement()` is gone.
  What such a field gives up is `autoStyleify`'s own service — giving a
  `<span style="font-weight: bold">` a nested `<strong>` — which changes nothing
  visually, since the span keeps its inline style. Set `autoStyleify` in
  `editorOptions` to override. Verified against the vendored `suneditor.min.js`
  under headless Chrome, on both paths: form reopened on stored content starting
  with `<style>`, and a leading `<style>` typed into the code view then
  submitted.
- Reopening a form flattened a stored `<script>`/`<style>` block onto one line,
  and saving again persisted that — a hand-indented stylesheet lost its
  formatting just by being looked at. `guardRawTextWhitespace()` (1.2.2) could
  only be installed from the `onload` event, while `create()` cleans the initial
  value before that, so the block was already through the unguarded
  `html.compress()` by the time the guard existed. A value containing a raw-text
  element is now held back from `create()` and applied in `onload` instead, via
  `html.set()` — the same `clean(html, {forceFormat: true})` `create()` would
  have run, only with the compress patch in place — followed by
  `history.reset()`, without which the author's first Ctrl+Z would empty the
  field back to what the editor was created with.
- An empty `<p><br></p>` could still come back at the top of the stored content
  after an undo: SunEditor's normalization inserts a format line in front of a
  leading raw-text element, since content starting with `<style>` gives the caret
  nowhere to sit. That line is reasonable in the editable DOM — it is what lets
  an author click above the block — but it has no business being saved, so it is
  now trimmed from the value handed to the textarea. Only ever a leading empty
  line *immediately before* a `<script>`/`<style>`: an empty first line anywhere
  else is left alone, on the assumption the author meant it.

## [1.3.0] - 2026-08-31

### Security

- `EditorFileHelper::duplicateEmbeddedFiles()` copied *any* file whose guid
  appeared in the content, without checking whether the user duplicating the
  record was allowed to see it. The guids come from author-editable HTML and
  `GUID_PATTERN` matches a bare `guid=<uuid>` anywhere in it — including inside
  a link's URL, so the code view wasn't even needed — while the copy is a new
  `File` row attached to the *new* record, whose visibility the author controls.
  Any user who could author such a field and duplicate the record could
  therefore read the contents of any file in the installation (another space's
  private attachments, another user's uploads) by pasting its guid in first.
  Now only files the acting user may view are copied, using `File::canView()` —
  core's own predicate for "may download this file", so nothing survives the
  check that the user could not already have fetched directly. A guid that
  doesn't survive is left pointing at the original: a broken embed in the copy,
  and a download that stays refused. `duplicateEmbeddedFiles()` takes an
  optional third `$user` argument for duplication outside a web request.
  `EditorFileHelper::sync()` already had the equivalent guard; this is the same
  threat model on the duplication path.
- `UploadAction` now refuses requests from guests. Which users may upload is
  still the host controller's job — this only rules out the one answer that is
  never the intended one, so a host that forgets its access rules no longer
  exposes an unauthenticated endpoint writing into the site's file storage.
  (The rows it created were unusable anyway: an unattached `File` with no
  creator is viewable by nobody, so the exposure was storage abuse.)

### Docs

- `SuneditorContent::addNonce()` documents the ordering its safety depends on:
  it nonces every `<script>` present when it runs and cannot tell which of them
  the caller's trust decision was about, so it must be applied to the stored
  content *before* any templating pass that interpolates values into the string.
  Applied afterwards, whoever controls an interpolated value (a profile field, a
  site setting, an imported feed) gets a `<script>` tag carrying a valid CSP
  nonce — the injection the nonce exists to refuse.

## [1.2.3] - 2026-08-10

### Docs

- Documented `strictMode.formatFilter: false` for preserving arbitrary
  pasted/code-view structure — a wrapping `<div>` SunEditor would otherwise
  drop, or an `<img>` nested inside a heading that SunEditor would otherwise
  pull out into a managed component — on both paste and `codeView` exit.
  Verified against the vendored `suneditor.min.js`: toolbar-driven typing,
  Enter, and image insertion are unaffected, since they build correct
  component markup directly rather than through this pass. Not on by
  default; it is a real tradeoff (an image pasted/typed as plain markup
  loses the resize handles a toolbar-inserted one gets), documented in
  `SuneditorField::$editorOptions` and the README.

## [1.2.2] - 2026-08-10

### Fixed

Two SunEditor bugs affecting `<script>`/`<style>` content, unrelated to any
option this package sets and to each other:

- Leaving the code view dropped a `<script>`/`<style>` block entirely when it
  was the first real content — nothing before it but whitespace or an HTML
  comment. Root cause: an internal `autoStyleify` step round-trips the code
  through a *full document* `DOMParser` parse, and the HTML5 parsing
  algorithm only switches from "in head" to "in body" insertion mode once it
  sees content that isn't valid inside `<head>` — `<script>`/`<style>`/HTML
  comments all are, so nothing forces the switch and they land in `<head>`
  instead, invisible to `.body.innerHTML`. Fixed by momentarily prepending an
  empty `<p></p>` before such a block right before code view closes; it
  forces the mode switch and is itself removed moments later by SunEditor's
  own empty-line cleanup.
- `html.compress()` — called at the start of every `html.clean()`: leaving
  code view, every paste, any programmatic HTML insert — strips every
  newline in the whole string and collapses whitespace between any two tags,
  with no concept of a raw-text element. A carefully indented stylesheet or
  script survives the paste itself untouched, then loses all its formatting
  the moment the editor leaves code view. Fixed by pulling
  `<script>`/`<style>` blocks out before `compress()` runs and splicing the
  originals back in after.

## [1.2.1] - 2026-08-10

### Fixed

- A file embedded through the editor's own inline upload (image, video, audio,
  attachment) also showed up a second time in anything that renders the
  record's generic file list (e.g. a wall entry's file footer). `show_in_stream`
  defaults to `true` on `File` and `FileManager::attach()` never touches it, so
  the embed was both rendered inline by the editor's own HTML and listed again
  as a separate attachment. `UploadAction` now sets `show_in_stream = false` on
  every file it creates.

## [1.2.0] - 2026-08-09

### Added

- `blockStyle` (paragraph/heading dropdown: P, blockquote, H1-H6) and `align`
  (left/center/right/justify) buttons to the default `$buttonList`.
- `SuneditorField::$excludeButtons` to drop specific buttons (e.g. `codeView`)
  without having to restate the whole `$buttonList`. A button left alone in its
  own group takes that now-empty group with it, and a stray `'|'` separator
  left next to another by a removed group collapses too.

## [1.1.4] - 2026-08-09

### Fixed

- SunEditor's own code-view serializer (`_convertToCode()`) HTML-entity-escapes
  every text node, including inside `<script>`/`<style>`, which HTML5 defines
  as raw text browsers never entity-decode there — corrupting real JS/CSS the
  moment the code view was opened, and persisting on save once the editor left
  code view again (including via the `syncBeforeSubmit` fix from 1.1.2, which
  forces exactly that before every submit). Hooked the documented
  `onToggleCodeView` event to reverse the escaping over `<script>`/`<style>`
  block contents using SunEditor's own inverse function
  (`helper.converter.entityToHTML`) on entering code view.

## [1.1.3] - 2026-08-09

### Docs

- Linked the full [SunEditor options reference](https://suneditor.com/options)
  from the README and `SuneditorField::$editorOptions`.
- Documented `strictMode: {attrFilter, classFilter, styleFilter}` (all three
  `false`) as what actually preserves an `id`, a custom `class`, or an inline
  `style` typed directly into the code view — SunEditor drops all three by
  default, and `attrFilter` alone is not enough since it and `styleFilter`
  share one internal pass that keeps only what either filter explicitly
  collects.

## [1.1.2] - 2026-08-09

### Fixed

- `script`/`style`/`meta`/`link` are independently blacklisted by default via
  `allowedExtraTags` (`{script: false, style: false, ...}`), and that
  blacklist wins over `elementWhitelist` regardless of what the latter allows
  — `elementWhitelist` alone never worked for this and was a documentation
  bug. Corrected the docblock and README to `allowedExtraTags`.
- An edit typed directly into the code view, then submitted without toggling
  back to the visual editor first, was silently dropped: `onChange` only
  fires from a history push, and SunEditor does not push history for
  code-view keystrokes. `humhub.suneditor.js` now forces the editor out of
  code view on a capture-phase click on the form's submit button, before
  HumHub's own AJAX modal-dialog submit handler can read the form's fields.

## [1.1.1] - 2026-08-09

### Fixed

- Renamed `SuneditorField::$clientOptions` to `$editorOptions`:
  `yii\bootstrap5\InputWidget` already inherits an untyped
  `public $clientOptions = []` from `BootstrapWidgetTrait` for its own
  Bootstrap JS plugin options. Redeclaring it with a type is a PHP fatal —
  a child class cannot add a type to a property the parent declared without
  one.

## [1.1.0] - 2026-08-09

### Added

- `SuneditorField::$clientOptions`, a raw `create()` options passthrough
  (mirrors `dosamigos\tinymce\TinyMce::$clientOptions`) — renamed to
  `$editorOptions` in 1.1.1.
- `SuneditorContent::addNonce()`, a standalone string transform that adds
  HumHub's CSP nonce to `<script>` opening tags, for a caller that renders
  script-carrying content outside `purify()`'s pipeline entirely. Not wired
  into `purify()`: HTMLPurifier has no raw-text-element handling for
  `<script>`/`<style>`, so their contents would be tokenized as ordinary
  markup and corrupted.

## [1.0.2] - 2026-08-09

### Fixed

- Resolved the `@web` alias `AssetManager::publish()` leaves unresolved in its
  returned URL. Without this, the published SunEditor library URL was taken
  as relative to the bundle's own `baseUrl` instead of absolute, doubling up
  into a broken path.

## [1.0.1] - 2026-08-09

### Changed

- Dropped the `yiisoft/yii2` Composer requirement — always provided by the
  host HumHub application.

## [1.0.0] - 2026-08-09

### Added

- Initial release: `SuneditorField`/`SuneditorContent` widgets, the generic
  upload `Action`, and the `EditorFileHelper` file-lifecycle helper.

[1.3.1]: https://github.com/cuzy-app/humhub-suneditor/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/cuzy-app/humhub-suneditor/compare/v1.2.3...v1.3.0
[1.2.3]: https://github.com/cuzy-app/humhub-suneditor/compare/v1.2.2...v1.2.3
[1.2.2]: https://github.com/cuzy-app/humhub-suneditor/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/cuzy-app/humhub-suneditor/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/cuzy-app/humhub-suneditor/compare/v1.1.4...v1.2.0
[1.1.4]: https://github.com/cuzy-app/humhub-suneditor/compare/v1.1.3...v1.1.4
[1.1.3]: https://github.com/cuzy-app/humhub-suneditor/compare/v1.1.2...v1.1.3
[1.1.2]: https://github.com/cuzy-app/humhub-suneditor/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/cuzy-app/humhub-suneditor/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/cuzy-app/humhub-suneditor/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/cuzy-app/humhub-suneditor/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/cuzy-app/humhub-suneditor/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/cuzy-app/humhub-suneditor/releases/tag/v1.0.0
