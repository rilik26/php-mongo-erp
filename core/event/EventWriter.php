<?php
/**
 * core/event/EventWriter.php (FINAL)
 *
 * - Event insert helper
 * - ✅ target meta auto-fill: doc_no / doc_title / status
 *   Fallback sırası:
 *   1) target içindekiler
 *   2) data.summary (doc_no/title/status)
 *   3) refs.snapshot_id üzerinden SNAP01E.target
 */

final class EventWriter
{
  public static function emit(
    string $eventCode,
    array $data,
    array $target,
    array $ctx,
    array $refs = []
  ): array {

    $eventCode = trim($eventCode);
    if ($eventCode === '') $eventCode = 'UNKNOWN.EVENT';

    $target = self::normalizeTargetMeta($target, $data, $refs);

    // target_key üret (eventleri aynı evrak altında gruplayabilmek için)
    $targetKey = self::buildTargetKey($target, $ctx);

    $nowMs = (int) floor(microtime(true) * 1000);
    $nowUtc = new MongoDB\BSON\UTCDateTime($nowMs);

    // request_id yoksa üret (debug için)
    if (!isset($refs['request_id']) || !is_string($refs['request_id']) || trim($refs['request_id']) === '') {
      $refs['request_id'] = bin2hex(random_bytes(8));
    }

    $doc = [
      'event_code' => $eventCode,
      'created_at' => $nowUtc,

      'context' => [
        'username'     => $ctx['username'] ?? null,
        'UDEF01_id'    => $ctx['UDEF01_id'] ?? null,
        'CDEF01_id'    => $ctx['CDEF01_id'] ?? null,
        'period_id'    => $ctx['period_id'] ?? null,
        'facility_id'  => $ctx['facility_id'] ?? null,
        'session_id'   => $ctx['session_id'] ?? ($_SESSION['sid'] ?? null),
      ],

      'target' => [
        'module'    => $target['module'] ?? null,
        'doc_type'  => $target['doc_type'] ?? null,
        'doc_id'    => $target['doc_id'] ?? null,

        // ✅ target meta (timeline kartları için)
        'doc_no'    => $target['doc_no'] ?? null,
        'doc_title' => $target['doc_title'] ?? null,
        'status'    => $target['status'] ?? null,
      ],

      'target_key' => $targetKey,

      'refs' => $refs,
      'data' => $data,
    ];

    // boş stringleri null'a çevir (index/unique çakışmalarını azaltır)
    $doc = self::emptyToNullDeep($doc);

    $ins = MongoManager::collection('EVENT01E')->insertOne($doc);
    $id = $ins->getInsertedId();
    if ($id instanceof MongoDB\BSON\ObjectId) $id = (string)$id;

    return [
      'ok' => true,
      'event_id' => $id,
      'target_key' => $targetKey,
    ];
  }

  /**
   * ✅ target.meta auto fill
   */
  private static function normalizeTargetMeta(array $target, array $data, array $refs): array
  {
    $docNo    = (string)($target['doc_no'] ?? '');
    $docTitle = (string)($target['doc_title'] ?? '');
    $status   = (string)($target['status'] ?? '');

    // 1) summary fallback
    $sum = $data['summary'] ?? null;
    if (is_array($sum)) {
      if ($docNo === '')    $docNo = (string)($sum['doc_no'] ?? '');
      if ($docTitle === '') $docTitle = (string)($sum['title'] ?? ($sum['doc_title'] ?? ''));
      if ($status === '')   $status = (string)($sum['status'] ?? '');
    }

    // 2) snapshot fallback
    if (($docNo === '' || $docTitle === '' || $status === '') && !empty($refs['snapshot_id'])) {
      $snapTarget = self::loadSnapshotTarget((string)$refs['snapshot_id']);
      if (is_array($snapTarget)) {
        if ($docNo === '')    $docNo = (string)($snapTarget['doc_no'] ?? '');
        if ($docTitle === '') $docTitle = (string)($snapTarget['doc_title'] ?? '');
        if ($status === '')   $status = (string)($snapTarget['status'] ?? '');
      }
    }

    if ($docNo !== '')    $target['doc_no'] = $docNo;
    if ($docTitle !== '') $target['doc_title'] = $docTitle;
    if ($status !== '')   $target['status'] = $status;

    return $target;
  }

  private static function loadSnapshotTarget(string $snapshotId): ?array
  {
    $snapshotId = trim($snapshotId);
    if ($snapshotId === '') return null;

    try {
      $oid = new MongoDB\BSON\ObjectId($snapshotId);
    } catch (Throwable $e) {
      return null;
    }

    $doc = MongoManager::collection('SNAP01E')->findOne(
      ['_id' => $oid],
      ['projection' => ['target' => 1]]
    );

    if (!$doc) return null;

    if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();
    $doc = self::bsonToArray($doc);

    $t = $doc['target'] ?? null;
    return is_array($t) ? $t : null;
  }

  private static function buildTargetKey(array $target, array $ctx): string
  {
    $cdef     = (string)($ctx['CDEF01_id'] ?? '');
    $period   = (string)($ctx['period_id'] ?? '');
    $facility = (string)($ctx['facility_id'] ?? '');

    $module   = (string)($target['module'] ?? '');
    $docType  = (string)($target['doc_type'] ?? '');
    $docId    = (string)($target['doc_id'] ?? '');

    // aynı formatı snapshot/lock tarafında da kullanıyorsan burada da uyumlu kalır
    return implode('|', [$cdef, $period, $facility, $module, $docType, $docId]);
  }

  private static function bsonToArray($v)
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
      foreach ($v as $k => $vv) $out[$k] = self::bsonToArray($vv);
      return $out;
    }
    return $v;
  }

  private static function emptyToNullDeep($v)
  {
    if (is_string($v)) {
      $t = trim($v);
      return ($t === '') ? null : $v;
    }
    if (is_array($v)) {
      $out = [];
      foreach ($v as $k => $vv) {
        $out[$k] = self::emptyToNullDeep($vv);
      }
      return $out;
    }
    return $v;
  }
}
