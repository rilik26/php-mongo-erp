<?php
/**
 * core/lock/LockGuard.php (FINAL)
 *
 * Server-side lock doğrulama:
 * - lock sende değilse save engellenir (UI bypass edilemez)
 *
 * Varsayım: LOCK01E collection
 * Doküman örnek alanlar:
 * - target.module, target.doc_type, target.doc_id
 * - context.username
 * - expires_at (UTCDateTime) veya expires_at ISO string
 */

final class LockGuard
{
  public static function isMine(string $module, string $docType, string $docId, array $ctx): bool
  {
    $username = (string)($ctx['username'] ?? '');
    if ($username === '') return false;

    $lock = MongoManager::collection('LOCK01E')->findOne([
      'target.module'   => $module,
      'target.doc_type' => $docType,
      'target.doc_id'   => $docId,
      'status'          => 'editing',
    ]);

    if (!$lock) return false;

    if ($lock instanceof MongoDB\Model\BSONDocument) $lock = $lock->getArrayCopy();

    $lu = (string)($lock['context']['username'] ?? '');
    if ($lu !== $username) return false;

    // expires kontrol (varsa)
    $exp = $lock['expires_at'] ?? null;

    try {
      if ($exp instanceof MongoDB\BSON\UTCDateTime) {
        $expDt = $exp->toDateTime();
        $now = new DateTime('now', new DateTimeZone('UTC'));
        return $expDt > $now;
      }

      if (is_string($exp) && $exp !== '') {
        $expDt = new DateTime($exp);
        $now = new DateTime('now', new DateTimeZone('UTC'));
        return $expDt > $now;
      }
    } catch(Throwable $e) {
      // expire parse hatası varsa "mine" sayma
      return false;
    }

    // expires yoksa, sahibi aynıysa mine kabul
    return true;
  }

  public static function requireMine(string $module, string $docType, string $docId, array $ctx): void
  {
    if (!self::isMine($module, $docType, $docId, $ctx)) {
      throw new RuntimeException('Lock sende değil (server-side).');
    }
  }
}
