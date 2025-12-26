<?php
/**
 * core/snapshot/SnapshotDiff.php
 *
 * - diffAssoc: genel array diff (nested)
 * - diffLangRows: lang rows özel diff
 * - summarizeLangDiff: event summary
 */

final class SnapshotDiff
{
    public static function diffAssoc(array $old, array $new): array
    {
        $added = [];
        $removed = [];
        $changed = [];

        $oldKeys = array_keys($old);
        $newKeys = array_keys($new);

        foreach (array_diff($newKeys, $oldKeys) as $k) {
            $added[$k] = self::normalizeValue($new[$k]);
        }
        foreach (array_diff($oldKeys, $newKeys) as $k) {
            $removed[$k] = self::normalizeValue($old[$k]);
        }

        foreach (array_intersect($oldKeys, $newKeys) as $k) {
            $a = self::normalizeValue($old[$k]);
            $b = self::normalizeValue($new[$k]);

            if (is_array($a) && is_array($b)) {
                $sub = self::diffAssoc($a, $b);
                if (!empty($sub['added']) || !empty($sub['removed']) || !empty($sub['changed'])) {
                    $changed[$k] = $sub;
                }
            } else {
                if ($a !== $b) {
                    $changed[$k] = ['from' => $a, 'to' => $b];
                }
            }
        }

        return [
            'added'   => $added,
            'removed' => $removed,
            'changed' => $changed,
        ];
    }

    private static function normalizeValue($v)
    {
        if ($v instanceof MongoDB\Model\BSONDocument || $v instanceof MongoDB\Model\BSONArray) {
            $v = $v->getArrayCopy();
        }
        if ($v instanceof MongoDB\BSON\UTCDateTime) {
            return $v->toDateTime()->format('c');
        }
        if ($v instanceof MongoDB\BSON\ObjectId) {
            return (string)$v;
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $vv) {
                $out[$k] = self::normalizeValue($vv);
            }
            return $out;
        }
        return $v;
    }

    // LANG: rows[key][tr|en] odaklı diff
    public static function diffLangRows(array $oldRows, array $newRows): array
    {
        $oldKeys = array_keys($oldRows);
        $newKeys = array_keys($newRows);

        $addedKeys = array_values(array_diff($newKeys, $oldKeys));
        $removedKeys = array_values(array_diff($oldKeys, $newKeys));
        $changedKeys = [];

        foreach (array_intersect($oldKeys, $newKeys) as $k) {
            $o = (array)($oldRows[$k] ?? []);
            $n = (array)($newRows[$k] ?? []);

            $chg = [];
            foreach (['tr', 'en'] as $lc) {
                $from = (string)($o[$lc] ?? '');
                $to   = (string)($n[$lc] ?? '');
                if ($from !== $to) {
                    $chg[$lc] = ['from' => $from, 'to' => $to];
                }
            }

            if (!empty($chg)) $changedKeys[$k] = $chg;
        }

        return [
            'added_keys'   => $addedKeys,
            'removed_keys' => $removedKeys,
            'changed_keys' => $changedKeys,
        ];
    }

    public static function summarizeLangDiff(array $diff, int $sample = 8): array
    {
        $added = $diff['added_keys'] ?? [];
        $removed = $diff['removed_keys'] ?? [];
        $changed = array_keys($diff['changed_keys'] ?? []);

        return [
            'added_keys_count'    => count($added),
            'removed_keys_count'  => count($removed),
            'changed_keys_count'  => count($changed),
            'added_keys_sample'   => array_slice($added, 0, $sample),
            'removed_keys_sample' => array_slice($removed, 0, $sample),
            'changed_keys_sample' => array_slice($changed, 0, $sample),
        ];
    }
}
