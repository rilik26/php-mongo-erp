<?php
/**
 * core/snapshot/SnapshotDiff.php
 *
 * Snapshot diff helper’ları (V1)
 * - Genel assoc diff (array)
 * - Lang sözlüğü için özel diff + summary
 */

final class SnapshotDiff
{
    /**
     * Genel array diff (recursive)
     * Dönen yapı:
     * [
     *   'added_keys'   => [...],
     *   'removed_keys' => [...],
     *   'changed_keys' => [ key => ['from'=>..., 'to'=>... ] OR nested ],
     * ]
     */
    public static function diffAssoc(array $old, array $new): array
    {
        $added = array_values(array_diff(array_keys($new), array_keys($old)));
        $removed = array_values(array_diff(array_keys($old), array_keys($new)));

        $changed = [];
        $commonKeys = array_intersect(array_keys($old), array_keys($new));

        foreach ($commonKeys as $k) {
            $ov = $old[$k];
            $nv = $new[$k];

            if (is_array($ov) && is_array($nv)) {
                $d = self::diffAssoc($ov, $nv);
                if (!empty($d['added_keys']) || !empty($d['removed_keys']) || !empty($d['changed_keys'])) {
                    $changed[$k] = $d;
                }
            } else {
                if ($ov !== $nv) {
                    $changed[$k] = [
                        'from' => $ov,
                        'to'   => $nv,
                    ];
                }
            }
        }

        return [
            'added_keys'   => $added,
            'removed_keys' => $removed,
            'changed_keys' => $changed,
        ];
    }

    /**
     * LANG sözlüğü için diff:
     * $oldRows/$newRows formatı:
     * rows[key] = ['module'=>..,'key'=>..,'tr'=>..,'en'=>..]
     *
     * Dönen yapı:
     * [
     *   'added_keys'   => [...],
     *   'removed_keys' => [...],
     *   'changed_keys' => [
     *      'auth.login' => [
     *         'tr' => ['from'=>'..','to'=>'..'],
     *         'en' => ['from'=>'..','to'=>'..'],
     *         'module' => ['from'=>'..','to'=>'..'],
     *      ],
     *   ]
     * ]
     */
    public static function diffLangRows(array $oldRows, array $newRows): array
    {
        $oldKeys = array_keys($oldRows);
        $newKeys = array_keys($newRows);

        $added = array_values(array_diff($newKeys, $oldKeys));
        $removed = array_values(array_diff($oldKeys, $newKeys));

        $changed = [];
        $common = array_intersect($oldKeys, $newKeys);

        foreach ($common as $k) {
            $o = (array)$oldRows[$k];
            $n = (array)$newRows[$k];

            $fields = ['module', 'tr', 'en'];
            $fieldDiffs = [];

            foreach ($fields as $f) {
                $ov = $o[$f] ?? '';
                $nv = $n[$f] ?? '';
                if ($ov !== $nv) {
                    $fieldDiffs[$f] = ['from' => $ov, 'to' => $nv];
                }
            }

            if (!empty($fieldDiffs)) {
                $changed[$k] = $fieldDiffs;
            }
        }

        return [
            'added_keys'   => $added,
            'removed_keys' => $removed,
            'changed_keys' => $changed,
        ];
    }

    /**
     * ✅ SENDE EKSİK OLAN METOT: summarizeLangDiff()
     *
     * Kısa özet çıkarır (Event.refs.summary içine yazmak için)
     */
    public static function summarizeLangDiff(array $diff, int $sampleLimit = 8): array
    {
        $added = $diff['added_keys'] ?? [];
        $removed = $diff['removed_keys'] ?? [];
        $changed = $diff['changed_keys'] ?? [];

        $changedKeys = array_keys($changed);

        return [
            'added_keys_count'   => count($added),
            'removed_keys_count' => count($removed),
            'changed_keys_count' => count($changedKeys),

            'added_keys_sample'   => array_slice(array_values($added), 0, $sampleLimit),
            'removed_keys_sample' => array_slice(array_values($removed), 0, $sampleLimit),
            'changed_keys_sample' => array_slice(array_values($changedKeys), 0, $sampleLimit),
        ];
    }
}
