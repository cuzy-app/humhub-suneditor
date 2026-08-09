<?php

namespace cuzyapp\suneditor\assets;

use Composer\InstalledVersions;
use humhub\components\assets\AssetBundle;
use Yii;

/**
 * SunEditor's *content* stylesheet — the rules that style what the editor
 * produced, without any of the editor chrome in {@see SuneditorFieldAssets} —
 * plus this package's own small overrides for read-only rendering.
 *
 * Registered by {@see \cuzyapp\suneditor\widgets\SuneditorContent}, which is
 * what puts the `.sun-editor-editable` class these rules are scoped to on the
 * wrapper. Every rule in SunEditor's own file sits under that class, and the
 * ones that set text and background colour use `inherit`, so it stays inside
 * its wrapper and follows the active theme.
 */
class SuneditorContentAssets extends AssetBundle
{
    public $sourcePath = __DIR__ . '/../resources';

    public $css = [
        'css/content.css',
    ];

    public function init()
    {
        parent::init();

        $suneditorDist = InstalledVersions::getInstallPath('npm-asset/suneditor') . '/dist';
        [, $suneditorUrl] = Yii::$app->assetManager->publish($suneditorDist);

        // Prepended: content.css's overrides need SunEditor's own rules loaded first.
        array_unshift($this->css, $suneditorUrl . '/suneditor-contents.min.css');
    }
}
