<?php

namespace cuzyapp\suneditor\assets;

use Composer\InstalledVersions;
use humhub\components\assets\AssetBundle;
use Yii;

/**
 * Everything {@see \cuzyapp\suneditor\widgets\SuneditorField} needs: the
 * SunEditor library itself and this package's own bootstrap, which creates the
 * editor instance and routes each upload to the plugin that renders it.
 *
 * The library and the bootstrap live in two different directories — SunEditor
 * under the sibling `npm-asset/suneditor` Composer package, the bootstrap under
 * this package's own `src/resources` — so `$sourcePath` alone can only publish
 * one of them. `init()` locates and publishes the library separately (via
 * Composer's `InstalledVersions`, so it works regardless of where the consuming
 * app's vendor directory puts it) and appends its URLs.
 */
class SuneditorFieldAssets extends AssetBundle
{
    public $sourcePath = __DIR__ . '/../resources';

    public $js = [
        'js/humhub.suneditor.js',
    ];

    public function init()
    {
        parent::init();

        $suneditorDist = InstalledVersions::getInstallPath('npm-asset/suneditor') . '/dist';
        [, $suneditorUrl] = Yii::$app->assetManager->publish($suneditorDist);

        // Prepended: the bootstrap in $js above calls SUNEDITOR.create() at
        // registration time, so the library it refers to must already exist.
        array_unshift($this->js, $suneditorUrl . '/suneditor.min.js');
        array_unshift($this->css, $suneditorUrl . '/suneditor.min.css');
    }
}
