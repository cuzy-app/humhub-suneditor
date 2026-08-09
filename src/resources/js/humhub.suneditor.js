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
                syncBeforeSubmit(params.$, textarea);

                // Only when the field was given somewhere to upload to; without
                // it there is no attachment button and no upload to route.
                if (options.fileUpload) {
                    takeOverAttachmentButton(params.$, accept);
                }
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
