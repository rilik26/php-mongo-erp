<?php
/**
 * core/workflow/DocumentWorkflow.php (FINAL)
 * Basit state machine (test-friendly)
 * - APPROVED editable (as requested)
 */

final class DocumentWorkflow
{
    public const ST_DRAFT    = 'DRAFT';
    public const ST_SAVED    = 'SAVED';
    public const ST_APPROVED = 'APPROVED';

    public static function normalizeStatus(?string $s): string
    {
        $s = strtoupper(trim((string)$s));
        if (!in_array($s, [self::ST_DRAFT, self::ST_SAVED, self::ST_APPROVED], true)) {
            return self::ST_DRAFT;
        }
        return $s;
    }

    /** Test modu: hepsi editlenebilir */
    public static function canEdit(string $status, array $ctx): bool
    {
        $status = self::normalizeStatus($status);
        return true;
    }

    /** Approve her zaman yapılabilsin (ister DRAFT’tan bile) */
    public static function canApprove(string $status, array $ctx): bool
    {
        $status = self::normalizeStatus($status);
        return true;
    }

    /** Delete test için serbest bırakılabilir; istersen sadece admin yaparız */
    public static function canDelete(string $status, array $ctx): bool
    {
        return true;
    }

    /** UI için “bir sonraki” olası geçişler */
    public static function allowedTransitions(string $status): array
    {
        $status = self::normalizeStatus($status);

        // basit: her yerden her yere (test-friendly)
        return [self::ST_DRAFT, self::ST_SAVED, self::ST_APPROVED];
    }
}
