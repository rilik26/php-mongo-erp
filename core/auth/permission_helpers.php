<?php
/**
 * permission_helpers.php
 *
 * can('perm.code') -> bool
 * require_perm('perm.code') -> 403 + exit (PERM.DENY loglar)
 */

require_once __DIR__ . '/SessionManager.php';
require_once __DIR__ . '/PermissionManager.php';
require_once __DIR__ . '/../action/ActionLogger.php';

function can(string $perm): bool
{
    return PermissionManager::can($perm);
}

function require_perm(string $perm): void
{
    SessionManager::start();

    if (can($perm)) {
        return;
    }

    // context topla (session context yeterli; ActionLogger zaten fallback yapıyor)
    $ctx = (isset($_SESSION['context']) && is_array($_SESSION['context']))
        ? $_SESSION['context']
        : [];

    // deny log
    ActionLogger::deny('PERM.DENY', [
        'perm'   => $perm,
        'path'   => $_SERVER['REQUEST_URI'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'source' => 'require_perm',
    ], array_merge($ctx, [
        'session_id' => session_id(),
    ]));

    http_response_code(403);
    echo "Forbidden";
    exit;
}
