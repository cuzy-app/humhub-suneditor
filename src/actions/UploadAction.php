<?php

namespace cuzyapp\suneditor\actions;

use humhub\modules\file\libs\ImageHelper;
use humhub\modules\file\models\FileUpload;
use Yii;
use yii\base\Action;
use yii\web\HttpException;
use yii\web\Response;
use yii\web\UploadedFile;

/**
 * Upload endpoint for {@see \cuzyapp\suneditor\widgets\SuneditorField}.
 *
 * SunEditor's image/video/audio/fileUpload plugins POST here and expect their
 * own JSON shape back — `{"result": [{"url", "name", "size"}]}` on success,
 * `{"errorMessage": …}` on failure, both with a 200 status — which is why this
 * does not reuse core's {@see \humhub\modules\file\actions\UploadAction}.
 * Validation is still core's: {@see FileUpload} enforces the extension
 * allow-list and maximum file size configured in the administration area.
 *
 * Wire it into any controller:
 * ```php
 * public function actions(): array
 * {
 *     return ['upload' => ['class' => \cuzyapp\suneditor\actions\UploadAction::class]];
 * }
 * ```
 * Access control is the host controller's job — this action only checks that
 * the request is a POST, nothing about who is allowed to make it.
 *
 * Files are stored **unattached**. The record the content belongs to may not
 * exist yet (e.g. a brand-new record still being filled in), and attaching a
 * file to a record that never gets saved would leak it, so ownership is
 * settled once that record is saved, by
 * {@see \cuzyapp\suneditor\helpers\EditorFileHelper::sync()}. Until then a file
 * is only viewable by its uploader, and core's file module cron removes the
 * ones that never made it into saved content.
 */
class UploadAction extends Action
{
    /**
     * @return array the JSON response
     */
    public function run(): array
    {
        if (Yii::$app->request->method !== 'POST') {
            throw new HttpException(405, 'This endpoint only accepts POST requests.');
        }

        Yii::$app->response->format = Response::FORMAT_JSON;

        $uploads = self::getUploadedFiles();
        if ($uploads === []) {
            return ['errorMessage' => 'No file was uploaded.'];
        }

        $result = [];
        foreach ($uploads as $uploadedFile) {
            $file = new FileUpload();
            $file->setUploadedFile($uploadedFile);

            if (!$file->save()) {
                return ['errorMessage' => implode(' ', $file->getErrorSummary(true))];
            }

            // Applies the administration area's maximum image dimensions, exactly
            // as core's UploadAction does for uploads made through the file widgets.
            ImageHelper::downscaleImage($file);

            $result[] = [
                // Relative on purpose: the URL is stored inside rich-text content,
                // which must survive the site changing hostname.
                'url'  => $file->getUrl([], false),
                'name' => $file->file_name,
                'size' => (int) $file->size,
            ];
        }

        return ['result' => $result];
    }

    /**
     * SunEditor's FileManager posts its selection as `file-0`, `file-1`, … rather
     * than one repeated field name, so there is no single name for
     * `UploadedFile::getInstancesByName()` to collect.
     *
     * @return UploadedFile[]
     */
    private static function getUploadedFiles(): array
    {
        $files = [];

        for ($i = 0; ($file = UploadedFile::getInstanceByName('file-' . $i)) !== null; $i++) {
            $files[] = $file;
        }

        return $files;
    }
}
