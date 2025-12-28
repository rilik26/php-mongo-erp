<?php
/**
 * app/modules/lang/LANG01TRepository.php (FINAL)
 *
 * LANG01T = sözlük satırları
 * {
 *   module: "common",
 *   key: "common.save",
 *   lang_code: "tr",
 *   text: "Kaydet",
 *   updated_at: UTCDateTime
 * }
 *
 * - listPivot: aktif dillerle pivot listesi
 * - bulkUpsertPivot: POST rows -> upsert (boş / NEW_ key atlanır)
 * - dumpAll: snapshot için sözlüğün tamamını dök
 */

final class LANG01TRepository
{
    private static function col(): MongoDB\Collection
    {
        return MongoManager::collection('LANG01T');
    }

    private static function nowUtc(): MongoDB\BSON\UTCDateTime
    {
        return new MongoDB\BSON\UTCDateTime((int) floor(microtime(true) * 1000));
    }

    public static function upsertOne(string $module, string $key, string $langCode, string $text): void
    {
        $module = trim($module) !== '' ? trim($module) : 'common';
        $key = trim($key);
        $langCode = strtolower(trim($langCode));

        if ($key === '' || $langCode === '') return;

        self::col()->updateOne(
            [
                'module' => $module,
                'key' => $key,
                'lang_code' => $langCode,
            ],
            [
                '$set' => [
                    'text' => (string)$text,
                    'updated_at' => self::nowUtc(),
                ],
                '$setOnInsert' => [
                    'created_at' => self::nowUtc(),
                ]
            ],
            ['upsert' => true]
        );
    }

    /**
     * HTML tablo için pivot:
     * return [
     *   "common.save" => ["module"=>"common","key"=>"common.save","tr"=>"Kaydet","en"=>"Save"]
     * ]
     */
    public static function listPivot(array $langCodes, string $q = '', int $limit = 800): array
    {
        $langCodes = array_values(array_unique(array_filter(array_map(function ($x) {
            $s = strtolower(trim((string)$x));
            return $s !== '' ? $s : null;
        }, $langCodes))));

        if (empty($langCodes)) $langCodes = ['tr'];

        $filter = [
            'lang_code' => ['$in' => $langCodes]
        ];

        if (trim($q) !== '') {
            $rx = new MongoDB\BSON\Regex(preg_quote(trim($q)), 'i');
            $filter['$or'] = [
                ['key' => $rx],
                ['text' => $rx],
                ['module' => $rx],
            ];
        }

        $cur = self::col()->find(
            $filter,
            [
                'projection' => ['module' => 1, 'key' => 1, 'lang_code' => 1, 'text' => 1],
                'sort' => ['module' => 1, 'key' => 1, 'lang_code' => 1],
                'limit' => max(10, min(5000, $limit)),
            ]
        );

        $out = [];
        foreach ($cur as $doc) {
            if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();

            $module = (string)($doc['module'] ?? 'common');
            $key = (string)($doc['key'] ?? '');
            $lc = (string)($doc['lang_code'] ?? '');
            $text = (string)($doc['text'] ?? '');

            if ($key === '' || $lc === '') continue;

            if (!isset($out[$key])) {
                $out[$key] = ['module' => $module, 'key' => $key];
                foreach ($langCodes as $x) $out[$key][$x] = '';
            }

            $out[$key]['module'] = $module ?: ($out[$key]['module'] ?? 'common');
            $out[$key][$lc] = $text;
        }

        // key sırası sabit
        ksort($out);

        return $out;
    }

    /**
     * bulk upsert:
     * rows = [
     *   "common.save" => ["module"=>"common","key"=>"common.save","tr"=>"...","en"=>"..."]
     * ]
     *
     * Kural:
     * - key boşsa veya NEW_ ile başlıyorsa -> ATLA (DB bozulmasın)
     */
    public static function bulkUpsertPivot(array $rows, array $activeLangCodes): array
    {
        $activeLangCodes = array_values(array_unique(array_filter(array_map(function ($x) {
            $s = strtolower(trim((string)$x));
            return $s !== '' ? $s : null;
        }, $activeLangCodes))));

        if (empty($activeLangCodes)) $activeLangCodes = ['tr'];

        $saved = 0;
        $skipped = 0;
        $skippedKeys = [];

        foreach ($rows as $row) {
            if (!is_array($row)) continue;

            $key = trim((string)($row['key'] ?? ''));
            $module = trim((string)($row['module'] ?? 'common'));
            if ($module === '') $module = 'common';

            if ($key === '' || str_starts_with($key, 'NEW_')) {
                $skipped++;
                $skippedKeys[] = $key !== '' ? $key : '(empty)';
                continue;
            }

            foreach ($activeLangCodes as $lc) {
                $text = (string)($row[$lc] ?? '');
                self::upsertOne($module, $key, $lc, $text);
            }

            $saved++;
        }

        return [
            'saved_rows' => $saved,
            'skipped_rows' => $skipped,
            'skipped_keys_sample' => array_slice($skippedKeys, 0, 10),
        ];
    }

    /**
     * Snapshot için tüm sözlüğü dök:
     * return [ key => ["module"=>"..","key"=>"..","text"=>".."] ]
     */
    public static function dumpAll(string $langCode): array
    {
        $langCode = strtolower(trim($langCode));
        if ($langCode === '') return [];

        $cur = self::col()->find(
            ['lang_code' => $langCode],
            [
                'projection' => ['module' => 1, 'key' => 1, 'text' => 1],
                'sort' => ['module' => 1, 'key' => 1],
                'limit' => 200000,
            ]
        );

        $out = [];
        foreach ($cur as $doc) {
            if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
            $key = (string)($doc['key'] ?? '');
            if ($key === '') continue;

            $out[$key] = [
                'module' => (string)($doc['module'] ?? 'common'),
                'key' => $key,
                'text' => (string)($doc['text'] ?? ''),
            ];
        }

        return $out;
    }
}
