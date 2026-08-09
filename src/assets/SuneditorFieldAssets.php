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
        // AssetManager::publish() does not resolve aliases in the URL it returns
        // (only an AssetBundle's own $baseUrl gets that treatment, in its init()) —
        // HumHub's AssetManager in particular returns the asset mount's raw,
        // unresolved `@web/...` alias here. Left unresolved, it would be taken for
        // a path relative to *this* bundle's own $baseUrl instead of an absolute
        // URL, doubling up into something like `/assets/<this-hash>/@web/assets/...`.
        $suneditorUrl = Yii::getAlias($suneditorUrl);

        // Prepended: the bootstrap in $js above calls SUNEDITOR.create() at
        // registration time, so the library it refers to must already exist.
        array_unshift($this->js, $suneditorUrl . '/suneditor.min.js');
        array_unshift($this->css, $suneditorUrl . '/suneditor.min.css');
    }
}
