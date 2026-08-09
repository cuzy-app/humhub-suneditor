<?php

namespace cuzyapp\suneditor\widgets;

use cuzyapp\suneditor\assets\SuneditorContentAssets;
use HTMLPurifier_Config;
use humhub\components\Widget;
use humhub\helpers\Html;
use yii\helpers\HtmlPurifier;

/**
 * Renders HTML authored in a {@see SuneditorField} back to a reader.
 *
 * Every screen that displays such content must go through this widget rather
 * than echoing the column, because it does three things that have to happen
 * together:
 *
 * - **Purifies.** The content is arbitrary HTML typed by whoever has access to
 *   the field — including through the editor's code view — so it is never
 *   trusted markup.
 * - **Keeps SunEditor's structure.** {@see purify()} teaches HTMLPurifier the
 *   HTML5 elements the editor wraps images, video, audio and attachments in.
 * - **Loads the matching stylesheet** and applies the class it is scoped to, so
 *   image alignment, captions and lists look the way the author left them.
 *
 * Usage:
 *   <?= SuneditorContent::widget(['content' => $model->description]) ?>
 */
class SuneditorContent extends Widget
{
    /**
     * The stored editor content. Renders nothing when empty, so callers do not
     * need to guard the call.
     */
    public ?string $content = null;

    /**
     * HTML options for the wrapper. The classes this widget's stylesheet is
     * scoped to are always added on top of whatever is passed here.
     */
    public array $options = [];

    public function run(): string
    {
        if (trim((string) $this->content) === '') {
            return '';
        }

        SuneditorContentAssets::register($this->view);

        Html::addCssClass($this->options, ['sun-editor-editable', 'suneditor-content']);

        return Html::tag('div', self::purify($this->content), $this->options);
    }

    /**
     * Purifies editor content while preserving the markup SunEditor relies on.
     *
     * HTMLPurifier's doctype is HTML 4.01, so none of the HTML5 elements the
     * editor emits are known to it. Left alone it drops every one of them:
     * `figure`/`figcaption` take the figure-based alignment with them, so a
     * centred image silently reverts to a plain block, and `video`/`audio`
     * disappear entirely — an uploaded clip would render as nothing at all.
     *
     * The media definitions mirror HumHub core's own
     * `humhub\modules\content\widgets\richtext\converter\RichTextToHtmlConverter`,
     * which solves the same problem for HumHub's own rich text.
     *
     * Everything else stays at HTMLPurifier's defaults on purpose: `class` and
     * validated inline `style` are already allowed, which covers the alignment
     * classes and the width the resize handles write.
     */
    public static function purify(string $html): string
    {
        return HtmlPurifier::process($html, static function (HTMLPurifier_Config $config): void {
            $definition = $config->getHTMLDefinition(true);

            $definition->addElement('figure', 'Block', 'Flow', 'Common');
            $definition->addElement('figcaption', 'Block', 'Flow', 'Common');

            $mediaAttributes = [
                'src'      => 'URI',
                'title'    => 'Text',
                'controls' => 'Bool',
                'preload'  => 'Text',
                'autoplay' => 'Bool',
                'muted'    => 'Bool',
                'loop'     => 'Bool',
            ];

            $definition->addElement('video', 'Block', 'Optional: (source, Flow) | Flow', 'Common', array_merge(
                $mediaAttributes,
                ['width' => 'Length', 'height' => 'Length', 'poster' => 'URI'],
            ));
            $definition->addElement('audio', 'Block', 'Optional: (source, Flow) | Flow', 'Common', $mediaAttributes);
        });
    }
}
