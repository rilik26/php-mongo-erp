<?php
/**
 * core/snapshot/SnapshotDiff.php
 *
 * - diffAssoc: genel array diff (nested, tree output)
 * - diffAssocPaths: genel array diff (flattened path output)
 * - summarizeGenericDiff: event summary (generic)
 * - diffLangRows: lang rows özel diff
 * - summarizeLangDiff: event summary (lang)
 */

final class SnapshotDiff
{
    /**
     * NESTED DIFF (TREE)
     * Output:
     * [
     *   'added' => [k => value, ...],
     *   'removed' => [k => value, ...],
     *   'changed' => [
     *        k => ['from'=>..., 'to'=>...] OR subtree
     *   ]
     * ]
     */
    public static function diffAssoc(array $old, array $new): array
    {
        $added = [];
        $removed = [];
        $changed = [];

        $old = self::normalizeValue($old);
        $new = self::normalizeValue($new);

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

    /**
     * FLATTEN DIFF (PATHS)
     * Output:
     * [
     *   'added_keys' => ['a.b', ...],
     *   'removed_keys' => ['x.y', ...],
     *   'changed_keys' => [
     *      'k.path' => ['from'=>..., 'to'=>...],
     *   ]
     * ]
     */
    public static function diffAssocPaths(array $old, array $new, string $prefix = ''): array
    {
        $old = self::normalizeValue($old);
        $new = self::normalizeValue($new);

        $added = [];
        $removed = [];
        $changed = [];

        // removed keys
        foreach ($old as $k => $ov) {
            if (!array_key_exists($k, $new)) {
                $removed[] = self::joinPath($prefix, (string)$k);
            }
        }

        // added & changed
        foreach ($new as $k => $nv) {
            $path = self::joinPath($prefix, (string)$k);

            if (!array_key_exists($k, $old)) {
                $added[] = $path;
                continue;
            }

            $ov = $old[$k];

            // array - array
            if (is_array($ov) && is_array($nv)) {
                // list compare (numeric arrays) -> compare JSON directly
                if (self::isList($ov) || self::isList($nv)) {
                    if (!self::sameScalarOrJson($ov, $nv)) {
                        $changed[$path] = ['from' => $ov, 'to' => $nv];
                    }
                    continue;
                }

                // assoc -> recurse
                $sub = self::diffAssocPaths($ov, $nv, $path);

                $added = array_merge($added, $sub['added_keys']);
                $removed = array_merge($removed, $sub['removed_keys']);
                $changed = array_merge($changed, $sub['changed_keys']);
                continue;
            }

            // scalar compare
            if (!self::sameScalarOrJson($ov, $nv)) {
                $changed[$path] = ['from' => $ov, 'to' => $nv];
            }
        }

        return [
            'added_keys' => array_values($added),
            'removed_keys' => array_values($removed),
            'changed_keys' => $changed,
        ];
    }

    /**
     * Summary for GENERIC diff (paths).
     */
    public static function summarizeGenericDiff(array $diff, int $sample = 8): array
    {
        $added = $diff['added_keys'] ?? [];
        $removed = $diff['removed_keys'] ?? [];
        $changed = $diff['changed_keys'] ?? [];

        $changedKeys = is_array($changed) ? array_keys($changed) : [];

        return [
            'mode' => 'generic',
            'added_keys_count' => count($added),
            'removed_keys_count' => count($removed),
            'changed_keys_count' => count($changedKeys),

            'added_keys_sample' => array_slice(array_values($added), 0, $sample),
            'removed_keys_sample' => array_slice(array_values($removed), 0, $sample),
            'changed_keys_sample' => array_slice(array_values($changedKeys), 0, $sample),
        ];
    }

    /**
     * BSON & nested normalize
     */
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
            'mode' => 'lang',
            'added_keys_count'    => count($added),
            'removed_keys_count'  => count($removed),
            'changed_keys_count'  => count($changed),
            'added_keys_sample'   => array_slice($added, 0, $sample),
            'removed_keys_sample' => array_slice($removed, 0, $sample),
            'changed_keys_sample' => array_slice($changed, 0, $sample),
        ];
    }

    // ---- helpers ----

    private static function joinPath(string $prefix, string $key): string
    {
        if ($prefix === '') return $key;
        return $prefix . '.' . $key;
    }

    private static function isList(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $v) {
            if ($k !== $i) return false;
            $i++;
        }
        return true;
    }

    private static function sameScalarOrJson($a, $b): bool
    {
        if (!is_array($a) && !is_array($b)) return $a === $b;

        $aj = json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $bj = json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $aj === $bj;
    }
}
