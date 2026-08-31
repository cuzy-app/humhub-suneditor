# humhub-suneditor

A [SunEditor](https://github.com/JiHong88/SunEditor) rich-text field for
[HumHub](https://www.humhub.com) modules, with inline image/video/audio
uploads and automatic cleanup of files a user removes from the content before
saving.

Not a HumHub module — there is no `module.json` and nothing to enable. It's a
plain Composer library: two widgets, a helper, and an upload action you wire
into your own module's controller.

## Install

```bash
composer require cuzy-app/humhub-suneditor
```

Requires the [asset-packagist.org](https://asset-packagist.org) Composer
repository to resolve `npm-asset/suneditor` (add it to your module's
`composer.json` if it isn't already there — HumHub core needs it too, so it's
usually already present at the application root):

```json
{
    "repositories": [
        {"type": "composer", "url": "https://asset-packagist.org"}
    ]
}
```

Deliberately **not** a `require` here: `yiisoft/yii2`, `yiisoft/yii2-bootstrap5` and
HumHub's own core classes. They're always already loaded by the HumHub
application that loads your module — declaring them would install a second,
separate copy inside your module's own vendor directory instead of using the
one already running, and pulls in `yiisoft/yii2-composer`, a Composer plugin
most module vendor directories don't allow-list. Same convention every HumHub
module composer.json follows for `humhub/humhub` itself.

## Wiring an upload endpoint

`UploadAction` handles the HTTP side (validating the file against the
administration area's extension/size settings, storing it, downscaling images).
Access control is your controller's job — the action itself only checks that the
request is a POST from a logged-in user, never which users are allowed to make
it:

```php
use cuzyapp\suneditor\actions\UploadAction;

class EditorController extends \humhub\components\Controller
{
    protected function getAccessRules(): array
    {
        return [['login'], ['permission' => YourManagePermission::class]];
    }

    public function actions(): array
    {
        return ['upload' => ['class' => UploadAction::class]];
    }
}
```

Uploads are stored **unattached** — see "Saving and cleanup" below for why.

## Authoring: `SuneditorField`

```php
use cuzyapp\suneditor\widgets\SuneditorField;

<?= $form->field($model, 'description')->widget(SuneditorField::class, [
    'uploadUrl' => ['/mymodule/editor/upload', 'container' => $space],
]) ?>
```

Or with an explicit name/value outside an `ActiveForm` field:

```php
<?= SuneditorField::widget([
    'name'      => 'content_text',
    'value'     => $model->content,
    'options'   => ['id' => 'my-content', 'rows' => 10],
    'uploadUrl' => ['/mymodule/editor/upload', 'container' => $space],
]) ?>
```

Leave `uploadUrl` unset to disable uploads entirely (the attachment button
disappears, and the image button falls back to SunEditor's base64 embedding).

An uploaded file becomes whatever renders it: an image, video or audio file
plays inline; anything else becomes a download link showing the file name.
This is decided client-side by mime type, in the `cuzySuneditor` JS module —
see its file for how each SunEditor upload plugin gets wired up.

### Removing a toolbar button: `excludeButtons`

```php
<?= $form->field($model, 'description')->widget(SuneditorField::class, [
    'excludeButtons' => ['codeView', 'blockStyle', 'align'],
]) ?>
```

For dropping one or two buttons from the default `buttonList` without having
to restate the rest of it — the same reason leaving `uploadUrl` unset already
drops `fileUpload` on its own. A button that was alone in its group (like
`blockStyle` or `align` above) takes its now-empty group with it, rather than
leaving a stray gap in the toolbar.

To add buttons, reorder the default ones, or configure a button's own dropdown
items (e.g. `blockStyle`'s heading levels), set `buttonList` directly — the
full button catalogue is in the [options reference](https://suneditor.com/options).

### Widening what the editor accepts: `editorOptions`

`editorOptions` is a raw passthrough to SunEditor's own `create()` call — every
option in the [full SunEditor options reference](https://suneditor.com/options)
is a valid key, not just the two below. Those two exist because they come up
for essentially every module that lets an author drop into `codeView`
(already in the default `buttonList` — the only way in SunEditor to type
markup the toolbar has no button for) and write something the toolbar can't
produce:

**`<style>`/`<script>`.** SunEditor strips both when converting the `codeView`
source back into the editable DOM, so typing one in and switching back to the
visual editor silently loses it:

```php
'editorOptions' => ['allowedExtraTags' => ['script' => true, 'style' => true]],
```

`elementWhitelist` — the option this looks like it should be — does **not**
work here on its own: `script`/`style`/`meta`/`link` are independently
blacklisted by default through `allowedExtraTags`, and that blacklist wins
regardless of what `elementWhitelist` allows.

Allowing either tag also makes the widget send `autoStyleify: []`. SunEditor's
`autoStyleify` pass — the one that gives a `<span style="font-weight: bold">` a
nested `<strong>` — parses the content as a whole HTML *document* and reads back
only its `<body>`, and a `<script>`/`<style>` that leads the content is valid
inside `<head>`, so that is where the parser puts it and it is never seen again.
Switching the pass off is what keeps such a block through both editor init and
`codeView`. The field loses only the nested `<strong>`, which changes nothing
visually — the span keeps its inline style. Pass your own `autoStyleify` in
`editorOptions` to override it.

**`id`, a custom `class`, or an inline `style` on an element.** SunEditor drops
all three by default — `id` and `style` entirely, `class` down to nothing
outside its own internal classes:

```php
'editorOptions' => ['strictMode' => [
    'attrFilter' => false, 'classFilter' => false, 'styleFilter' => false,
]],
```

All three have to go together: `attrFilter` alone still drops every attribute
whenever `styleFilter` is on, because both share the one internal pass that
rewrites an element's opening tag, and that pass keeps only what either filter
explicitly collected.

**Arbitrary structure — a `<div>` wrapping a heading, an `<img>` nested inside
one, or anything else that doesn't fit SunEditor's own line/block/component
model.** On every paste and on every `codeView` exit, SunEditor coerces
content into that model: a wrapping element it doesn't recognize as a valid
container gets dropped, and any `<img>`/`<video>`/similar gets pulled out of
its surrounding line and rebuilt as one of SunEditor's own managed,
resizable "components". For most authoring this is exactly what you want; for
a field that exists specifically so an author can paste or type a hand-built
HTML/CSS snippet and have it survive untouched, it silently rewrites the
one thing the field is for:

```php
'editorOptions' => ['strictMode' => ['formatFilter' => false]],
```

This turns that whole pass off — pasted or code-view content keeps whatever
structure it already had. Toolbar-driven editing is unaffected (typing,
Enter, and the toolbar's own image/video insert build correct component
markup directly, independent of this pass), so the tradeoff is narrow: only
content that goes through paste/`codeView`/a programmatic `html.insert()`
loses SunEditor's own restructuring — including, for an `<img>` pasted or
typed as plain markup rather than inserted via the toolbar, the resize
handles and alignment controls a managed component would have gotten. Decide
per field whether that's the right trade — it is not part of the two options
above, and is not on by default.

Rendering that content back out safely is a separate concern from authoring it
— see `SuneditorContent::addNonce()` below.

## Rendering: `SuneditorContent`

Every place that displays what `SuneditorField` produced must go through this
widget rather than echoing the column — it purifies the HTML (with HTMLPurifier,
teaching it the HTML5 elements SunEditor emits: `figure`, `figcaption`, `video`,
`audio`) and loads the matching read-only stylesheet:

```php
use cuzyapp\suneditor\widgets\SuneditorContent;

<?= SuneditorContent::widget(['content' => $model->description]) ?>
```

Renders nothing for empty content, so callers don't need to guard the call.

`purify()` never allows `script` or `style` through, on purpose and not
configurably: HTMLPurifier has no "raw text element" handling for either, so
anything inside would still be tokenized as ordinary markup, corrupting real JS
or CSS containing `<`, `>`, `&&`, or a selector like `div > p`. That makes the
`SuneditorContent` *widget* the wrong tool for content you've decided to allow
`<style>`/`<script>` in via `allowedExtraTags` on the authoring side (above) —
by the time `run()` gets to your `<script>` tag, `purify()` has already run and
removed it.

For that case — rendering content that was never purified at all, because
`script`/`style` are part of what your module intentionally allows (content
only administrators can author, say) — call `SuneditorContent::addNonce()`
directly instead of the widget. It adds HumHub's current CSP nonce to every
`<script>` opening tag, as a plain string transform with no purification
attached:

```php
// $html is rendered as-is, never passed through SuneditorContent::purify() —
// this project has already decided, on its own, that it may contain <script>
// tags, and is only reaching for the one piece of that job purification would
// otherwise have handled: the nonce.
echo SuneditorContent::addNonce($html);
```

Call it on the stored content, **before** any templating pass that interpolates
values into that string. `addNonce()` nonces every `<script>` it finds and
cannot tell which of them your trust decision was about, so running it last
hands whoever controls an interpolated value (a profile field, a site setting,
an imported feed) a `<script>` tag with a valid CSP nonce — the exact injection
the nonce exists to refuse:

```php
echo yourTemplatingPass(SuneditorContent::addNonce($html)); // ✔
echo SuneditorContent::addNonce(yourTemplatingPass($html)); // ✘
```

## Saving and cleanup: `EditorFileHelper`

Uploads land as unattached `File` rows — the record the field belongs to may
not exist yet (e.g. a brand-new record still being filled in), and attaching a
file to a record that never gets saved would leak it. Call `sync()` once your
record is saved, in the same request:

```php
use cuzyapp\suneditor\helpers\EditorFileHelper;

if ($model->save()) {
    // $keepGuids: files this record owns elsewhere (a separately-uploaded
    // thumbnail, an attached PDF) that the field's content never references,
    // so sync() doesn't delete them as strays.
    EditorFileHelper::sync($model, $model->description, $keepGuids = []);
}
```

This attaches whatever pending uploads the saved content still references, and
deletes the files the content no longer references — an image dropped from the
editor before submit is deleted from the server, not left orphaned. A file
uploaded into a form that's never saved at all simply stays unattached; core's
file module daily cron collects those.

## Duplicating a record

If your module lets users duplicate a record that has a `SuneditorField`
field, give the copy its own files rather than letting it reference the
original's — `File::canView()`/`canDelete()` delegate to whatever record a file
is attached to, so two records sharing one `File` row would mean either one's
visibility rules, or its deletion, reach into the other's data:

```php
use cuzyapp\suneditor\helpers\EditorFileHelper;
use cuzyapp\suneditor\helpers\FileDuplicator;

// A single file the record owns outside the field, e.g. a thumbnail.
$copy->thumbnail_file_guid = FileDuplicator::duplicate($source->thumbnail_file_guid, $copy);

// Everything embedded in the field's content — rewritten to point at the copies.
$copy->description = EditorFileHelper::duplicateEmbeddedFiles($source->description, $copy);
```

The two differ in what they trust. `duplicateEmbeddedFiles()` reads its guids
out of author-editable HTML, where an author can name any file on the site (in
the code view, or just inside a link's URL), so it copies only files the acting
user may view — `File::canView()`, core's own "may download this file" check —
and leaves the rest pointing at the original. `FileDuplicator::duplicate()` is
the raw primitive underneath it and checks nothing: only ever hand it a guid
from a column the record itself owns, as above.

Pass the acting user explicitly when duplicating outside a web request, where
there is no logged-in user to fall back to:

```php
EditorFileHelper::duplicateEmbeddedFiles($source->description, $copy, $job->userId);
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

AGPL-3.0-or-later — see [LICENSE](LICENSE).
