<?php

namespace cuzyapp\suneditor\widgets;

use cuzyapp\suneditor\assets\SuneditorFieldAssets;
use humhub\helpers\Html;
use humhub\modules\file\Module as FileModule;
use Yii;
use yii\bootstrap5\InputWidget;
use yii\helpers\Url;
use yii\web\View;

/**
 * SuneditorField is a form field widget that replaces a textarea with the
 * SunEditor rich-text editor.
 *
 * Bound to a model attribute:
 * ```php
 * <?= $form->field($model, 'content')->widget(SuneditorField::class) ?>
 * ```
 *
 * Or with an explicit name/value, e.g. outside an ActiveForm field:
 * ```php
 * <?= SuneditorField::widget([
 *     'name'    => 'content_text',
 *     'value'   => $lesson->content,
 *     'options' => ['id' => 'lf-content-text', 'class' => 'form-control', 'rows' => 10],
 * ]) ?>
 * ```
 */
class SuneditorField extends InputWidget
{
    /**
     * Default HTML value shown when the bound value is empty.
     */
    public string $defaultValue = '';

    /**
     * Where the editor posts uploaded files, as a route array or a URL —
     * normally the route of a controller action wiring in
     * {@see \cuzyapp\suneditor\actions\UploadAction}, e.g.
     * `['/mymodule/editor/upload', 'container' => $space]`.
     *
     * Uploads land as whatever renders them: an image, video or audio file is
     * played inline, anything else becomes a download link — see the
     * `cuzySuneditor` JS module for how a file is routed.
     *
     * Leaving this null drops the attachment button from the toolbar and makes the
     * image button fall back to SunEditor's base64 embedding, which inlines the
     * whole image into the stored content.
     *
     * @var array|string|null
     * @see \cuzyapp\suneditor\actions\UploadAction
     */
    public $uploadUrl = null;

    /**
     * SunEditor buttonList configuration.
     * `fileUpload` is dropped when {@see $uploadUrl} is not set — it has nowhere
     * to send the file.
     * @var array
     */
    public array $buttonList = [
        ['undo', 'redo'], '|',
        ['bold', 'italic', 'underline'], '|',
        ['list', 'link', 'image', 'fileUpload'], '|',
        ['blockquote', 'removeFormat'], '|',
        ['outdent', 'indent'], '|',
        ['fullScreen', 'codeView'], '|',
        ['preview', 'print'],
    ];

    /**
     * Raw SunEditor `create()` options, merged in on top of everything this
     * widget computes — an escape hatch for anything not exposed as its own
     * property, mirroring `\dosamigos\tinymce\TinyMce::$clientOptions`.
     *
     * Not named `$clientOptions`: this class extends `yii\bootstrap5\InputWidget`,
     * which already inherits an *untyped* `public $clientOptions = []` from
     * `BootstrapWidgetTrait` — its own Bootstrap JS plugin options, unrelated to
     * SunEditor. Redeclaring it here with a type is a fatal ("Type ... must not
     * be defined"); reusing the name even without a type would still silently
     * feed SunEditor options into Bootstrap's own plugin init.
     *
     * The option most consumers reach for here is `elementWhitelist`: SunEditor
     * strips any tag not in its own default set (`p|pre|blockquote|h1|...`, no
     * `script` or `style`) when converting the `codeView` source back into the
     * editable DOM — the same problem TinyMCE's `extended_valid_elements`
     * solves. No extra toolbar button is needed; `codeView` (already in
     * {@see $buttonList}) is the only way in either editor to type markup the
     * toolbar has no button for.
     * ```php
     * 'editorOptions' => ['elementWhitelist' => 'style|script'],
     * ```
     * Rendering that content back out safely is a separate concern — see
     * {@see \cuzyapp\suneditor\widgets\SuneditorContent::addNonce()}.
     */
    public array $editorOptions = [];

    public function run(): string
    {
        SuneditorFieldAssets::register($this->view);

        // ── Compute initial editor value ───────────────────────────────────────
        $value = $this->hasModel()
            ? ($this->model->{$this->attribute} ?: $this->defaultValue)
            : ($this->value ?: $this->defaultValue);

        // ── Assemble the create() options ──────────────────────────────────────
        $options = array_merge(
            [
                'value'      => $value,
                'buttonList' => $this->getButtonList(),
            ],
            $this->getUploadOptions(),
            $this->editorOptions,
        );

        // JSON_HEX_TAG keeps a `</script>` inside the stored content — perfectly
        // legal to author in the code view — from terminating the inline script.
        $encode = static fn($value): string => json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE,
        );

        $this->view->registerJs(sprintf(
            "humhub.require('cuzySuneditor').create(%s, %s, %s);",
            $encode($this->options['id']),
            $encode($options),
            $encode($this->getPickerAccept()),
        ), View::POS_READY);

        // Not renderInputHtml('textarea'): that goes through Html::input(), which
        // emits `<input type="textarea">` — not a textarea at all, just a text
        // input the browser falls back to. It only ever looked right because
        // SunEditor hides the element and the value travels through options.value.
        return $this->hasModel()
            ? Html::activeTextarea($this->model, $this->attribute, $this->options)
            : Html::textarea($this->name, $this->value, $this->options);
    }

    /**
     * @return array the buttonList with the buttons that cannot work in this
     *         configuration removed
     */
    private function getButtonList(): array
    {
        if ($this->uploadUrl !== null) {
            return $this->buttonList;
        }

        return array_map(
            static fn($group) => is_array($group)
                ? array_values(array_diff($group, ['fileUpload']))
                : $group,
            $this->buttonList,
        );
    }

    /**
     * Upload configuration, one entry per plugin that can receive a file.
     *
     * All four are configured, not just the two with a toolbar button: what an
     * upload becomes is decided by mime type in `cuzySuneditor`, which hands
     * images to `image`, video to `video`, audio to `audio` and everything else
     * to `fileUpload`. A plugin without an upload URL cannot render its file.
     *
     * The size limit mirrors what the endpoint will accept anyway, so an
     * oversized file is refused in the browser with a clear message instead of
     * after a pointless round trip.
     *
     * @return array<string, array> keyed by plugin name, empty when uploads are off
     */
    private function getUploadOptions(): array
    {
        if ($this->uploadUrl === null) {
            return [];
        }

        $fileModule = $this->getFileModule();

        $shared = [
            'uploadUrl'     => Url::to($this->uploadUrl),
            'uploadHeaders' => [Yii::$app->request->csrfHeader => Yii::$app->request->csrfToken],
            // 0 means "no limit" to SunEditor, which is also what an empty setting means here.
            'uploadSingleSizeLimit' => (int) $fileModule->settings->get('maxFileSize'),
            'allowMultiple' => true,
        ];

        return [
            'image'      => $shared,
            'video'      => $shared,
            'audio'      => $shared,
            'fileUpload' => array_merge($shared, [
                // A plain inline link showing the file name, rather than SunEditor's
                // default boxed figure: these are attachments in a body of text.
                'as' => 'link',
                // Deliberately not the site's extension list. This governs one
                // thing only — whether `fileUpload` claims a pasted or dropped
                // file — and it has to claim every type, because that is where
                // `cuzySuneditor` cancels the duplicate link SunEditor would
                // otherwise add next to a dropped image. The picker that the
                // attachment button opens carries the real extension list.
                'acceptedFormats' => '*',
            ]),
        ];
    }

    private function getFileModule(): FileModule
    {
        /** @var FileModule $fileModule */
        $fileModule = Yii::$app->getModule('file');

        return $fileModule;
    }

    /**
     * The administration area's allowed extensions as an `accept` attribute for
     * the attachment picker, so the file dialog offers what the endpoint will
     * actually take.
     *
     * Null when the site restricts nothing — an `accept` of `*` is not a valid
     * value and some browsers read it as "no type matches".
     */
    private function getPickerAccept(): ?string
    {
        if ($this->uploadUrl === null) {
            return null;
        }

        $allowedExtensions = array_filter(
            array_map('trim', explode(',', (string) $this->getFileModule()->settings->get('allowedExtensions'))),
        );

        if ($allowedExtensions === []) {
            return null;
        }

        return implode(', ', array_map(static fn(string $extension) => '.' . $extension, $allowedExtensions));
    }
}
