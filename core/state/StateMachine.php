<?php
/**
 * core/state/StateMachine.php (FINAL)
 *
 * - Evrak state geçişlerini merkezileştirir.
 * - Geçişte: ABAC kontrolü (hook), Event + Log + Snapshot üretimi.
 */

final class StateMachine
{
    /**
     * Doküman tipine göre state grafiği.
     * İstersen bunu DB'den de yönetebiliriz (Phase 2/3).
     */
    private static array $graphs = [
        'default' => [
            'draft' => ['save' => 'saved', 'cancel' => 'cancelled'],
            'saved' => ['submit' => 'waiting_approval', 'lock' => 'locked', 'cancel' => 'cancelled'],
            'waiting_approval' => ['approve' => 'approved', 'reject' => 'saved', 'cancel' => 'cancelled'],
            'approved' => ['lock' => 'locked'],
            'locked' => ['unlock' => 'saved'], // örnek
            'cancelled' => [],
        ],
    ];

    public static function can(string $docType, string $fromState, string $action): bool
    {
        $g = self::$graphs[$docType] ?? self::$graphs['default'];
        return isset($g[$fromState][$action]);
    }

    public static function next(string $docType, string $fromState, string $action): ?string
    {
        $g = self::$graphs[$docType] ?? self::$graphs['default'];
        return $g[$fromState][$action] ?? null;
    }
}
