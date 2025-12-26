<?php
/**
 * core/lock/LockUI.php
 *
 * Amaç:
 * - Evrak listelerinde “kim düzenliyor” ikonunu standart üretmek
 * - Lock durumunu UI-friendly şekilde göstermek (tooltip + badge)
 *
 * Kullanım (örnek):
 *   echo LockUI::iconHtml($lock); // $lock LOCK01E doc'u (array)
 *
 * Not:
 * - Bu helper sadece HTML üretir.
 * - Lock'u almak için lock_status endpointi veya LockRepository kullanılır.
 */

final class LockUI
{
    public static function esc($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Duruma göre küçük renkli badge
     */
    public static function statusBadge(string $status): string
    {
        $status = $status ?: 'editing';

        $map = [
            'editing'   => ['#E3F2FD', '#1565C0', 'EDITING'],
            'viewing'   => ['#F1F8E9', '#2E7D32', 'VIEWING'],
            'approving' => ['#FFF3E0', '#EF6C00', 'APPROVING'],
        ];

        $cfg = $map[$status] ?? $map['editing'];
        [$bg, $fg, $label] = $cfg;

        return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;background:' .
            $bg . ';color:' . $fg . ';font-weight:700;letter-spacing:.2px;">' . self::esc($label) . '</span>';
    }

    /**
     * Lock icon HTML:
     * - kilit varsa: kullanıcı + status + ttl tooltip
     * - yoksa: boş string
     */
    public static function iconHtml(?array $lock): string
    {
        if (!$lock) return '';

        $status = (string)($lock['status'] ?? 'editing');
        $ctx = (array)($lock['context'] ?? []);
        $t   = (array)($lock['target'] ?? []);

        $username = (string)($ctx['username'] ?? '');
        $docNo    = (string)($t['doc_no'] ?? '');
        $title    = (string)($t['doc_title'] ?? '');

        $expiresAt = $lock['expires_at'] ?? null;
        $ttlText = '';
        if ($expiresAt instanceof MongoDB\BSON\UTCDateTime) {
            $dt = $expiresAt->toDateTime();
            $ttlText = $dt->format('c');
        } else if ($expiresAt) {
            $ttlText = (string)$expiresAt;
        }

        $tip = 'Locked';
        if ($username !== '') $tip .= ' by ' . $username;
        $tip .= ' (' . $status . ')';
        if ($docNo !== '') $tip .= ' - ' . $docNo;
        if ($title !== '') $tip .= ' / ' . $title;
        if ($ttlText !== '') $tip .= ' | expires: ' . $ttlText;

        // basit kilit ikonu (unicode)
        $icon = '🔒';

        return '<span title="' . self::esc($tip) . '" style="cursor:help;">' .
            $icon . '&nbsp;' . self::statusBadge($status) .
        '</span>';
    }
}
