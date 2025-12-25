<?php
/**
 * app/modules/lang/LANG01TRepository.php
 *
 * AMAÇ:
 * - LANG01T (translations) veri erişimi
 * - Admin pivot listesi
 * - Bulk upsert
 * - Snapshot için final dictionary dump (tr/en)
 *
 * ŞEMA (LANG01T):
 * {
 *   lang_code: "tr"|"en",
 *   module: "auth",
 *   key: "auth.login",
 *   text: "Giriş"
 * }
 */

final class LANG01TRepository
{
    /**
     * Var olan helper: tek dil için dictionary (key => text)
     */
    public static function loadDictionary(string $langCode): array
    {
        $col = MongoManager::collection('LANG01T');

        $cursor = $col->find(
            ['lang_code' => $langCode],
            ['projection' => ['_id' => 0, 'key' => 1, 'text' => 1]]
        );

        $out = [];
        foreach ($cursor as $doc) {
            $k = (string)($doc['key'] ?? '');
            if ($k === '') continue;
            $out[$k] = (string)($doc['text'] ?? '');
        }

        ksort($out);
        return $out;
    }

    /**
     * ✅ Snapshot için FINAL STATE DUMP
     * tek dil için: key => [module,key,text]
     */
    public static function dumpAll(string $langCode): array
    {
        $col = MongoManager::collection('LANG01T');

        $cursor = $col->find(
            ['lang_code' => $langCode],
            ['projection' => ['_id' => 0, 'module' => 1, 'key' => 1, 'text' => 1]]
        );

        $out = [];
        foreach ($cursor as $doc) {
            $key = (string)($doc['key'] ?? '');
            if ($key === '') continue;

            $out[$key] = [
                'module' => (string)($doc['module'] ?? 'common'),
                'key'    => $key,
                'text'   => (string)($doc['text'] ?? ''),
            ];
        }

        ksort($out);
        return $out;
    }

    /**
     * Admin ekranı için pivot: key bazlı (TR & EN yan yana)
     * $langCodes = ['tr','en']
     */
    public static function listPivot(array $langCodes, string $q = '', int $limit = 800): array
    {
        $col = MongoManager::collection('LANG01T');

        $filter = ['lang_code' => ['$in' => array_values($langCodes)]];
        if ($q !== '') {
            $filter['$or'] = [
                ['key'    => ['$regex' => $q, '$options' => 'i']],
                ['text'   => ['$regex' => $q, '$options' => 'i']],
                ['module' => ['$regex' => $q, '$options' => 'i']],
            ];
        }

        $cursor = $col->find(
            $filter,
            [
                'projection' => ['_id' => 0, 'lang_code' => 1, 'module' => 1, 'key' => 1, 'text' => 1],
                'limit' => $limit,
                'sort'  => ['module' => 1, 'key' => 1]
            ]
        );

        // pivot: key => ['module'=>..., 'tr'=>..., 'en'=>...]
        $pivot = [];
        foreach ($cursor as $doc) {
            $key = (string)($doc['key'] ?? '');
            if ($key === '') continue;

            $lang = (string)($doc['lang_code'] ?? '');
            $module = (string)($doc['module'] ?? 'common');
            $text = (string)($doc['text'] ?? '');

            if (!isset($pivot[$key])) {
                $pivot[$key] = [
                    'module' => $module,
                ];
            } elseif (($pivot[$key]['module'] ?? '') === '') {
                $pivot[$key]['module'] = $module;
            }

            // lang sütunları
            if ($lang === 'tr') $pivot[$key]['tr'] = $text;
            if ($lang === 'en') $pivot[$key]['en'] = $text;
        }

        ksort($pivot);
        return $pivot;
    }

    /**
     * Bulk upsert (pivot rows -> LANG01T docs)
     * rows[key] = ['module'=>..., 'key'=>..., 'tr'=>..., 'en'=>...]
     */
    public static function bulkUpsertPivot(array $rows, array $langCodes = ['tr','en']): void
    {
        $col = MongoManager::collection('LANG01T');

        foreach ($rows as $key => $row) {
            $k = (string)($row['key'] ?? $key);
            if ($k === '') continue;

            $module = (string)($row['module'] ?? 'common');

            foreach ($langCodes as $lang) {
                $val = (string)($row[$lang] ?? '');

                $col->updateOne(
                    ['lang_code' => $lang, 'key' => $k],
                    ['$set' => [
                        'lang_code' => $lang,
                        'module'    => $module,
                        'key'       => $k,
                        'text'      => $val,
                    ]],
                    ['upsert' => true]
                );
            }
        }
    }
}
