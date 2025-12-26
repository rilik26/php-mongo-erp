<?php
/**
 * core/lock/lock_helpers.php
 *
 * lock badge (UI):
 * - editing: kırmızı kilit
 * - viewing: mavi göz
 * - approving: turuncu onay
 *
 * Basit kullanım:
 * echo lock_badge($lock);  // lock array varsa
 */

function lock_badge(?array $lock): string
{
    if (!$lock) return '';

    $status = $lock['status'] ?? 'editing';
    $user = $lock['context']['username'] ?? 'unknown';

    $icon = '🔒';
    $color = '#d32f2f';

    if ($status === 'viewing') { $icon='👁️'; $color='#1976d2'; }
    if ($status === 'approving') { $icon='✅'; $color='#f57c00'; }

    $title = "Locked: {$status} by {$user}";

    return '<span title="'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'" style="color:'.$color.'; font-weight:bold; margin-right:6px;">'.$icon.'</span>';
}
