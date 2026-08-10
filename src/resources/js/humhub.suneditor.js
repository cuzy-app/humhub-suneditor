/**
 * Rich-text editor bootstrap (Suneditor v3) for the SuneditorField widget (cuzyapp\suneditor\widgets\SuneditorField).
 *
 * The widget renders a textarea and hands its Suneditor options over to create()
 * below. Beyond wiring the two together, this module owns the one behaviour
 * Suneditor has no option for: deciding what an uploaded file becomes.
 *
 * Suneditor has four upload-capable plugins and each renders exactly one thing —
 * `image` an <img>, `video` a <video>, `audio` an <audio>, `fileUpload` a
 * download link. Nothing dispatches between them, so whatever the attachment
 * button uploads becomes a link, a JPEG included. What follows sends each file
 * to the plugin that renders it inline and leaves only real attachments as links.
 *
 * Routing happens per upload route, because Suneditor treats them differently:
 *
 *  - Toolbar button — Suneditor's own button hands everything to `fileUpload`,
 *    so takeOverAttachmentButton() replaces the picker with one that routes.
 *  - Paste and drop — Suneditor already offers the file to every plugin's
 *    paste-and-drop hook, so media is inserted correctly on its own. The only
 *    thing to fix is `fileUpload` claiming the same file a second time, which
 *    dropMediaFromAttachments() cancels.
 */
humhub.module('cuzySuneditor', function (module, require, $) {
    'use strict';

    // Which plugin renders which upload. Anything unmatched is an attachment.
    var MEDIA_PLUGINS = [
        {plugin: 'image', pattern: /^image\//},
        {plugin: 'video', pattern: /^video\//},
        {plugin: 'audio', pattern: /^audio\//},
    ];

    /**
     * Name of the plugin that renders this file inline, or null when the file is
     * an attachment. The browser's own mime type decides — the server validates
     * the upload again anyway, so this only has to be right about presentation.
     */
    var mediaPluginFor = function (file) {
        for (var i = 0; i < MEDIA_PLUGINS.length; i++) {
            if (MEDIA_PLUGINS[i].pattern.test(file.type || '')) {
                return MEDIA_PLUGINS[i].plugin;
            }
        }

        return null;
    };

    /**
     * Sends every file to the plugin that renders it, attachments included.
     *
     * Each plugin uploads through its own FileManager, so what lands in the
     * document is a real editor component — resizable, alignable, removable —
     * rather than markup pasted in from outside.
     */
    var route = function (deps, files) {
        var attachments = [];

        files.forEach(function (file) {
            var plugin = mediaPluginFor(file);
            if (plugin) {
                deps.plugins[plugin].submitFile([file]);
            } else {
                attachments.push(file);
            }
        });

        if (attachments.length) {
            deps.plugins.fileUpload.submitFile(attachments);
        }
    };

    /**
     * Points the toolbar's attachment button at our own file picker.
     *
     * Suneditor's button feeds its selection straight to `fileUpload`, which only
     * knows how to make links. Its command dispatcher looks `action` up on the
     * live plugin instance on every click, so replacing the method is enough to
     * get the picked files into route() instead.
     */
    var takeOverAttachmentButton = function (deps, accept) {
        var fileUpload = deps.plugins.fileUpload;
        if (!fileUpload) {
            return;
        }

        var picker = document.createElement('input');
        picker.type = 'file';
        picker.multiple = true;
        if (accept) {
            picker.accept = accept;
        }

        picker.addEventListener('change', function () {
            route(deps, Array.prototype.slice.call(picker.files));
            // Reset, so picking the same file twice in a row still fires change.
            picker.value = '';
        });

        fileUpload.action = function () {
            // What Suneditor's own action() does first: hold the caret while the
            // file dialog takes focus, so the upload lands where the cursor was.
            deps.store.set('_preventBlur', true);
            picker.click();
        };
    };

    /**
     * Drops media from a batch on its way into `fileUpload`.
     *
     * A pasted or dropped file is offered to every plugin's paste-and-drop hook
     * in turn, so by the time `fileUpload` asks to turn an image into a link the
     * `image` plugin has already inserted it. Returning false cancels that;
     * returning the trimmed info keeps the real attachments of a mixed drop.
     *
     * Returning undefined would cancel the upload too, so the pass-through case
     * has to return the info object explicitly.
     */
    var dropMediaFromAttachments = function (params) {
        var attachments = Array.prototype.slice.call(params.info.files).filter(function (file) {
            return !mediaPluginFor(file);
        });

        if (!attachments.length) {
            return false;
        }

        params.info.files = attachments;

        return params.info;
    };

    // <script>/<style> content, in the code view's own textarea.
    var RAW_TEXT_ELEMENTS = /(<(script|style)\b[^>]*>)([\s\S]*?)(<\/\2>)/gi;

    /**
     * Undoes a SunEditor bug: whenever the code view is (re-)populated from the
     * WYSIWYG DOM — entering code view, or `fullScreen`/`showBlocks` refreshing
     * it — SunEditor HTML-entity-escapes the text content of *every* element,
     * `<script>`/`<style>` included. Per the HTML5 spec those two are "raw text"
     * elements: browsers never entity-decode their contents, so escaping is
     * simply wrong there — `alert("x")` becomes the code view showing
     * `alert(&quot;x&quot;)`, which is no longer valid JS, and if the editor
     * ever leaves code view again (including our own {@see syncBeforeSubmit},
     * which does exactly that before every submit) that corrupted text is what
     * gets re-parsed back into the DOM and saved.
     *
     * SunEditor ships the exact inverse of the function that caused this
     * (`helper.converter.entityToHTML`, the counterpart to `htmlToEntity`, which
     * is what over-escapes here) — this only has to find each raw-text
     * element's content and run that.
     */
    var fixRawTextElements = function (code) {
        return code.replace(RAW_TEXT_ELEMENTS, function (match, open, tag, inner, close) {
            return open + SUNEDITOR.helper.converter.entityToHTML(inner) + close;
        });
    };

    /**
     * Undoes a third SunEditor bug, in the same family as
     * {@see fixRawTextElements} and {@see guardLeadingRawTextElement}:
     * `html.compress()` — called at the very start of *every* `html.clean()`,
     * i.e. on leaving code view, on every paste, and on any programmatic HTML
     * insert — is two blind regexes with no concept of a raw-text element:
     * `.replace(/>\s+</g, '> <')` collapses whitespace between *any* adjacent
     * tags, and `.replace(/\n/g, '')` removes *every* newline in the string —
     * including ones inside a `<script>`/`<style>` block's own JS/CSS. A
     * carefully indented stylesheet typed or pasted into the code view is
     * untouched by the paste itself (a `<textarea>` never reformats anything);
     * it comes out on one line, indentation gone, the moment the editor
     * leaves code view.
     *
     * Collapsing whitespace between ordinary tags is normal, intentional
     * behavior for a WYSIWYG editor — insignificant whitespace has no visual
     * meaning in HTML, and no SunEditor option changes that — so this leaves
     * it alone. Only the part that reaches *inside* a raw-text element, where
     * whitespace means exactly what it means in a `.css`/`.js` file, is a bug.
     *
     * Fixed by pulling every `<script>`/`<style>` block out before compress()
     * runs and splicing the untouched originals back in after, on the
     * `html.compress()` method every internal caller already goes through —
     * so this covers paste and programmatic insert as well as leaving code
     * view, not just the one path {@see guardLeadingRawTextElement} guards.
     */
    var guardRawTextWhitespace = function (deps) {
        var originalCompress = deps.html.compress.bind(deps.html);

        deps.html.compress = function (html) {
            var blocks = [];
            var placeholderHtml = html.replace(RAW_TEXT_ELEMENTS, function (block) {
                blocks.push(block);
                return '\x00' + (blocks.length - 1) + '\x00';
            });

            return originalCompress(placeholderHtml).replace(/\x00(\d+)\x00/g, function (match, index) {
                return blocks[+index];
            });
        };
    };

    // A leading `<script>`/`<style>` in the code view, ignoring any HTML
    // comment or whitespace before it — the only shapes that trigger the bug
    // guardLeadingRawTextElement() works around. Once *any* other content
    // (even just text or a <br>) precedes it, the bug does not occur.
    var LEADING_RAW_TEXT_ELEMENT = /^(?:\s|<!--[\s\S]*?-->)*<(?:script|style)\b/i;

    /**
     * Undoes another SunEditor bug, distinct from {@see fixRawTextElements}:
     * leaving the code view drops a `<script>`/`<style>` block entirely when
     * it is the first real content — nothing else precedes it except
     * whitespace or an HTML comment.
     *
     * Root cause is in a SunEditor-internal step unrelated to any option this
     * package sets (`autoStyleify`, on by default, which auto-converts
     * bold/underline/italic/strike markup on every code-view exit): it
     * round-trips the code through `DOMParser.parseFromString(html,
     * 'text/html')` and reads back `.body.innerHTML`. That is a *full
     * document* parse, not a fragment parse — and per the HTML5 parsing
     * algorithm, a document parser starts in "in head" insertion mode and
     * only switches to "in body" once it sees content that isn't valid inside
     * `<head>`. `<script>`/`<style>`/HTML comments are all valid there, so
     * nothing forces the switch; the parser inserts them into `<head>`
     * instead, and `.body.innerHTML` no longer contains them. Any other
     * top-level content (text, `<br>`, `<p>`, …) is not valid in `<head>` and
     * forces the switch immediately, which is why the bug never shows up once
     * something else precedes the block.
     *
     * Fixed by prepending an empty `<p></p>` before such a block right before
     * the code view closes — enough to force "in body" mode during that one
     * internal parse. The placeholder never reaches the saved content:
     * SunEditor's own cleanup removes empty format lines a moment later, in
     * the same pass. Verified against the vendored suneditor.min.js, both
     * directly (`html.clean()`) and through the real `codeView(false)`
     * toggle, including a leading HTML comment and a `<script>` followed by a
     * `<style>` with nothing else before either.
     */
    var guardLeadingRawTextElement = function (deps) {
        var originalCodeView = deps.viewer.codeView.bind(deps.viewer);

        deps.viewer.codeView = function (value) {
            var frameContext = deps.frameContext;
            var isCodeView = frameContext.get('isCodeView');
            var targetValue = value === undefined ? !isCodeView : value;
            var leavingCodeView = isCodeView && !targetValue;
            var codeArea = leavingCodeView ? frameContext.get('code') : null;

            if (codeArea && LEADING_RAW_TEXT_ELEMENT.test(codeArea.value)) {
                codeArea.value = '<p></p>' + codeArea.value;
            }

            return originalCodeView(value);
        };
    };

    /**
     * Forces the editor out of code view before its form's submit button is
     * clicked, so an edit typed directly into the code view — never toggled
     * back to the visual editor — is not silently dropped.
     *
     * `onChange` only fires from a history push, and Suneditor does not push
     * history for keystrokes made *inside* the code view textarea — only when
     * the editor leaves it (`viewer.codeView(false)`, which re-parses the code
     * view's text into the WYSIWYG DOM and pushes history from there). Without
     * this, `textarea.value` — what the surrounding <form> actually submits —
     * still holds whatever the content was before code view was opened.
     *
     * Listens in the capture phase, not on the form's `submit` event: HumHub's
     * own modal dialogs submit via an AJAX click handler on the submit button
     * (`data-action-click`) that calls `preventDefault()` on the click itself,
     * so the browser's native form submission — and the `submit` event with it
     * — never fires at all. A capture-phase listener runs before any of that:
     * capture travels root-to-target before the click reaches the button, which
     * is strictly before HumHub's own bubble-phase handler reads the form's
     * current field values, regardless of which handler was registered first.
     */
    var syncBeforeSubmit = function (deps, textarea) {
        var form = textarea.closest('form');
        if (!form) {
            return;
        }

        form.addEventListener('click', function (evt) {
            if (evt.target.closest('[type="submit"]')) {
                deps.viewer.codeView(false);
            }
        }, true);
    };

    /**
     * Replaces a textarea with a Suneditor instance.
     *
     * @param {string} id id of the textarea to replace
     * @param {Object} options Suneditor create() options, built by SuneditorField
     * @param {string|null} accept `accept` for the attachment picker, mirroring
     *        the extensions the site allows
     */
    var create = function (id, options, accept) {
        var textarea = document.getElementById(id);
        if (!textarea) {
            return;
        }

        options.plugins = SUNEDITOR.plugins;
        options.events = {
            onChange: function (content) {
                textarea.value = content.data;
            },
            onload: function (params) {
                guardRawTextWhitespace(params.$);
                guardLeadingRawTextElement(params.$);
                syncBeforeSubmit(params.$, textarea);

                // Only when the field was given somewhere to upload to; without
                // it there is no attachment button and no upload to route.
                if (options.fileUpload) {
                    takeOverAttachmentButton(params.$, accept);
                }
            },
            onToggleCodeView: function (params) {
                if (!params.is) {
                    return;
                }

                var codeArea = params.frameContext.get('code');
                codeArea.value = fixRawTextElements(codeArea.value);
            },
        };

        if (options.fileUpload) {
            options.events.onFileUploadBefore = dropMediaFromAttachments;
        }

        SUNEDITOR.create(textarea, options);
    };

    module.export({
        create: create,
    });
});
