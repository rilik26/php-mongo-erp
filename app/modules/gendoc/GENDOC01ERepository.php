<?php
/**
 * app/modules/gendoc/GENDOC01ERepository.php (FINAL)
 *
 * ✅ E11000 duplicate key fix:
 * - findByTarget & upsertHeader: period sadece CURRENT (GLOBAL yok)
 * - list / audit gibi yerlerde GLOBAL göstermek istersen ayrı filtre ile yap
 *
 * ✅ facility null/missing uyumlu
 * ✅ atomic version (last_version)
 */

class GENDOC01ERepository
{
  private static function col() {
    return MongoManager::collection('GENDOC01E');
  }

  /**
   * Scope filter
   * $includeGlobal=true  -> current + GLOBAL (liste/rapor için)
   * $includeGlobal=false -> sadece current period (upsert için şart)
   */
  public static function scopeFilter(array $ctx, bool $includeGlobal = false): array
  {
    $cdef     = $ctx['CDEF01_id'] ?? null;
    $period   = $ctx['period_id'] ?? null;
    $facility = $ctx['facility_id'] ?? null;

    $f = [];
    if ($cdef) $f['context.CDEF01_id'] = $cdef;

    if ($period) {
      if ($includeGlobal) {
        $f['$or'] = [
          ['context.period_id' => $period],
          ['context.period_id' => 'GLOBAL'],
        ];
      } else {
        $f['context.period_id'] = $period;
      }
    }

    // facility null ise null + missing match
    if ($facility === null || $facility === '') {
      $f['$and'][] = [
        '$or' => [
          ['context.facility_id' => null],
          ['context.facility_id' => ['$exists' => false]],
        ]
      ];
    } else {
      $f['context.facility_id'] = $facility;
    }

    return $f;
  }

  public static function buildTargetKey(array $target, array $ctx): string
  {
    $cdef     = (string)($ctx['CDEF01_id'] ?? '');
    $period   = (string)($ctx['period_id'] ?? '');
    $facility = $ctx['facility_id'] ?? null;

    $module   = (string)($target['module'] ?? '');
    $docType  = (string)($target['doc_type'] ?? '');
    $docId    = (string)($target['doc_id'] ?? '');

    $facilityPart = ($facility === null || $facility === '') ? 'NULL' : (string)$facility;

    return implode('|', [
      $cdef,
      $period ?: 'GLOBAL',
      $facilityPart,
      $module,
      $docType,
      $docId,
    ]);
  }

  public static function findByTarget(array $target, array $ctx): ?array
  {
    // ✅ sadece current period
    $f = self::scopeFilter($ctx, false);

    $f['target.module']   = (string)($target['module'] ?? '');
    $f['target.doc_type'] = (string)($target['doc_type'] ?? '');
    $f['target.doc_id']   = (string)($target['doc_id'] ?? '');

    $doc = self::col()->findOne($f);
    if (!$doc) return null;

    if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
    return is_array($doc) ? $doc : null;
  }

  public static function upsertHeader(array $targetMeta, array $header, array $ctx): array
  {
    $target = [
      'module'   => (string)($targetMeta['module'] ?? ''),
      'doc_type' => (string)($targetMeta['doc_type'] ?? ''),
      'doc_id'   => (string)($targetMeta['doc_id'] ?? ''),
    ];

    $targetKey = self::buildTargetKey($target, $ctx);

    // ✅ upsert filter: sadece current period
    $f = self::scopeFilter($ctx, false);
    $f['target.module']   = $target['module'];
    $f['target.doc_type'] = $target['doc_type'];
    $f['target.doc_id']   = $target['doc_id'];

    $cdef     = $ctx['CDEF01_id'] ?? null;
    $period   = $ctx['period_id'] ?? null;
    $facility = $ctx['facility_id'] ?? null;

    $now = new MongoDB\BSON\UTCDateTime((int)(microtime(true) * 1000));

    $update = [
      '$set' => [
        'target' => [
          'module'    => $target['module'],
          'doc_type'  => $target['doc_type'],
          'doc_id'    => $target['doc_id'],
          'doc_no'    => $targetMeta['doc_no'] ?? null,
          'doc_title' => $targetMeta['doc_title'] ?? null,
          'status'    => $targetMeta['status'] ?? null,
        ],
        'header'     => $header,
        'target_key' => $targetKey,
        'updated_at' => $now,
      ],
      '$setOnInsert' => [
        'context' => [
          'CDEF01_id'   => $cdef,
          'period_id'   => ($period ?: 'GLOBAL'),
          'facility_id' => ($facility === '' ? null : $facility),
        ],
        'created_at'   => $now,
        'last_version' => 0,
      ],
    ];

    self::col()->updateOne($f, $update, ['upsert' => true]);

    $doc = self::col()->findOne(['target_key' => $targetKey]);
    if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
    return is_array($doc) ? $doc : ['target_key' => $targetKey];
  }

  public static function nextVersion(string $targetKey, array $ctx): int
  {
    $res = self::col()->findOneAndUpdate(
      ['target_key' => $targetKey],
      [
        '$inc' => ['last_version' => 1],
        '$set' => ['updated_at' => new MongoDB\BSON\UTCDateTime((int)(microtime(true) * 1000))]
      ],
      [
        'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        'upsert' => true,
      ]
    );

    if ($res instanceof MongoDB\Model\BSONDocument) $res = $res->getArrayCopy();
    $v = is_array($res) ? (int)($res['last_version'] ?? 0) : 0;
    return max(1, $v);
  }
}
