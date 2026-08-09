<?php

namespace cuzyapp\suneditor\helpers;

use humhub\modules\file\models\File;
use Yii;
use yii\db\ActiveRecordInterface;

/**
 * Gives a file its own independent copy, attached to a different owner.
 *
 * Used when duplicating a record that has files attached — directly, or
 * embedded in a {@see \cuzyapp\suneditor\widgets\SuneditorField} field via
 * {@see EditorFileHelper::duplicateEmbeddedFiles()}. A new {@see File} row is
 * required rather than reusing the source's guid: `File::canView()`/`canDelete()`
 * delegate to whatever record a file is attached to, so letting two records
 * share one File would mean either record's visibility rules — or deletion —
 * reach into the other's data.
 */
class FileDuplicator
{
    /**
     * Creates a new {@see File} record with the same content as the file
     * identified by $sourceGuid, attached to $owner via
     * `setPolymorphicRelation()`.
     *
     * @return string|null the new file's guid, or null when the source file no
     *         longer exists or could not be duplicated
     */
    public static function duplicate(string $sourceGuid, ActiveRecordInterface $owner): ?string
    {
        $sourceFile = File::findOne(['guid' => $sourceGuid]);
        if ($sourceFile === null) {
            return null;
        }

        $newFile            = new File();
        $newFile->file_name = $sourceFile->file_name;
        $newFile->title     = $sourceFile->title;
        $newFile->mime_type = $sourceFile->mime_type;

        if (!$newFile->save()) {
            Yii::error(['message' => 'Could not save duplicated file record.', 'errors' => $newFile->getErrors()], 'suneditor');

            return null;
        }

        $newFile->setStoredFileContent($sourceFile->store->getContent());
        $newFile->setPolymorphicRelation($owner);

        if (!$newFile->save()) {
            Yii::error(['message' => 'Duplicated file but could not attach it to its owner.', 'errors' => $newFile->getErrors()], 'suneditor');

            return null;
        }

        return $newFile->guid;
    }
}
