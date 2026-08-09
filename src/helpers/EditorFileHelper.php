<?php

namespace cuzyapp\suneditor\helpers;

use humhub\components\ActiveRecord;
use humhub\modules\file\models\File;
use Yii;
use yii\db\ActiveRecordInterface;

/**
 * Keeps the files embedded in a {@see \cuzyapp\suneditor\widgets\SuneditorField}
 * field in sync with the record that field belongs to.
 *
 * Uploads made from inside the editor land as *unattached* File rows — see
 * {@see \cuzyapp\suneditor\actions\UploadAction} — because the record they will
 * belong to need not exist yet when an author drops an image into a brand-new
 * record. Binding them to their record is therefore deferred to {@see sync()},
 * which runs once the record is saved and, in the same pass, deletes the files
 * that have disappeared from the content.
 *
 * Reconciling at save time rather than the moment an element leaves the editor
 * is deliberate: a removal is undoable until the form is submitted, and
 * abandoning the form must not destroy files the stored content still points
 * at. Files uploaded into a form that is never saved simply stay unattached,
 * and the file module's daily cron collects them.
 */
class EditorFileHelper
{
    /**
     * Any `guid=<uuid>` in the content, which is how a HumHub file download URL
     * carries the file it serves — see {@see File::getUrl()}. Matching the bare
     * query parameter instead of the whole URL keeps this working for absolute
     * URLs, for the `index.php?r=…` form used when pretty URLs are off, and for
     * `&amp;`-encoded query strings.
     *
     * Over-matching is the safe direction: a guid that resolves to no file
     * attaches nothing, and one matched by accident merely keeps a file alive.
     */
    private const GUID_PATTERN = '~guid=([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})~i';

    /**
     * @return string[] lowercased guids of the files referenced by $html, deduplicated
     */
    public static function extractGuids(?string $html): array
    {
        if ($html === null || $html === '') {
            return [];
        }

        preg_match_all(self::GUID_PATTERN, $html, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * Reconciles the files owned by $record with the ones $html still references:
     * pending uploads become owned by the record, and files the content dropped
     * are deleted from the server.
     *
     * @param ActiveRecord $record the saved record the editor field belongs to
     * @param string|null $html the editor content as it was just stored
     * @param string[] $keepGuids guids of files this record owns outside the
     *        editor field (e.g. a separately-uploaded thumbnail or attachment) —
     *        they are never referenced by the content, so without this they
     *        would be deleted as strays
     */
    public static function sync(ActiveRecord $record, ?string $html, array $keepGuids = []): void
    {
        $referenced = self::extractGuids($html);
        $keep       = array_map('strtolower', array_filter($keepGuids));

        foreach ($referenced as $guid) {
            $file = File::findOne(['guid' => $guid]);

            if ($file === null || $file->isAssigned()) {
                continue;
            }

            // Only the current user's own pending uploads: the content is
            // author-editable HTML, so a guid pasted into the code view must not
            // be able to pull an unrelated file into this record.
            if ((int) $file->created_by !== (int) Yii::$app->user->id) {
                continue;
            }

            $record->fileManager->attach($file);
        }

        foreach ($record->fileManager->findAll() as $file) {
            $guid = strtolower((string) $file->guid);

            if (in_array($guid, $referenced, true) || in_array($guid, $keep, true)) {
                continue;
            }

            $file->delete();
        }
    }

    /**
     * Gives $owner its own copy of every file embedded in $html and returns the
     * content rewritten to point at those copies.
     *
     * Used when duplicating a record that has a SuneditorField field: the copy
     * must not reference the original's File rows, for the same reason
     * {@see FileDuplicator} exists.
     *
     * $owner must already be saved — attaching a file needs its primary key.
     */
    public static function duplicateEmbeddedFiles(?string $html, ActiveRecordInterface $owner): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        foreach (self::extractGuids($html) as $guid) {
            $newGuid = FileDuplicator::duplicate($guid, $owner);

            if ($newGuid !== null) {
                $html = str_ireplace($guid, $newGuid, $html);
            }
        }

        return $html;
    }
}
