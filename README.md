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
Access control is your controller's job — the action only checks that the
request is POST:

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

### Allowing `<style>`/`<script>` in the editor

SunEditor strips any tag not in its own default element set (`p|pre|blockquote
|h1|...`, no `script` or `style`) when converting the `codeView` source back
into the editable DOM — so by default, typing a `<style>` block in code view
and switching back to the visual editor silently loses it. No extra toolbar
button unlocks this; `codeView` (already in the default `buttonList`) is the
only way in SunEditor to type markup the toolbar has no button for, same as
any other tag. Widen the whitelist through the raw options passthrough:

```php
'clientOptions' => ['elementWhitelist' => 'style|script'],
```

Rendering that content back out safely is a separate concern from authoring it
— see `SuneditorContent::$nonce` below.

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
`<style>`/`<script>` in via `elementWhitelist` on the authoring side (above) —
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

## License

AGPL-3.0-or-later — see [LICENSE](LICENSE).
