<?php
/**
 * core/lock/LockUIHelper.php
 *
 * Lock icon html helper (V1)
 */

final class LockUIHelper
{
    public static function iconHtml(?array $lock): string
    {
        if (empty($lock)) return '';

        $status = (string)($lock['status'] ?? 'editing');
        $user = (string)($lock['username'] ?? '');
        $ttl = isset($lock['ttl_left_sec']) ? (int)$lock['ttl_left_sec'] : null;

        $ttlTxt = '';
        if ($ttl !== null) {
            $m = (int) floor($ttl / 60);
            $s = $ttl % 60;
            $ttlTxt = " | TTL: {$m}m {$s}s";
        }

        $title = trim(($user ? "Kim: {$user}" : '') . " | Durum: {$status}" . $ttlTxt);

        $emoji = '🔒';
        if ($status === 'viewing') $emoji = '👁️';
        if ($status === 'approving') $emoji = '✅';

        return '<span title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" style="cursor:help;">' . $emoji . '</span>';
    }
}
