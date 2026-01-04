<?php
/**
 * public/api/stok_save.php (FINAL)
 *
 * - Context zorunlu
 * - Validation (kod zorunlu)
 * - Repository save
 * - ActionLogger + EventWriter + SnapshotWriter + Webhook
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';
require_once __DIR__ . '/../../core/error/api_response.php';

require_once __DIR__ . '/../../core/action/ActionLogger.php';
require_once __DIR__ . '/../../core/event/EventWriter.php';
require_once __DIR__ . '/../../core/snapshot/SnapshotWriter.php';
require_once __DIR__ . '/../../core/webhook/WebhookService.php';

require_once __DIR__ . '/../../app/modules/stok/STOK01Repository.php';

SessionManager::start();

try { Context::bootFromSession(); }
catch (Throwable $e) {
    api_err('AUTH_REQUIRED', ['msg' => 'login_required']);
}

$ctx = Context::get();

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];

    $id = trim((string)($data['id'] ?? ''));

    // geriye uyum: eski key geldiyse yeniye map’le
    if (!isset($data['kod']) && isset($data['stok_kodu'])) $data['kod'] = $data['stok_kodu'];
    if (!isset($data['name']) && isset($data['stok_adi'])) $data['name'] = $data['stok_adi'];
    if (!isset($data['unit']) && isset($data['birim'])) $data['unit'] = $data['birim'];

    $kod = trim((string)($data['kod'] ?? ''));
    if ($kod === '') api_err('STOK_VALIDATION', ['fields' => ['kod'], 'detail' => 'kod_required']);

    // aktif/pasif
    if (array_key_exists('is_active', $data)) {
        $data['is_active'] = (bool)$data['is_active'];
    }

    $stat = STOK01Repository::save($data, $ctx, ($id !== '' ? $id : null));

    // LOG
    $logId = ActionLogger::success('STOK.SAVE', [
        'source'   => 'public/api/stok_save.php',
        'kod'      => $stat['kod'] ?? null,
        'STOK01_id'=> $stat['STOK01_id'] ?? null,
        'is_active'=> $stat['is_active'] ?? null,
        'version'  => $stat['version'] ?? null,
    ], $ctx);

    // EVENT (SAVE)
    EventWriter::emit(
        'STOK.SAVE',
        [
            'source' => 'public/api/stok_save.php',
            'summary' => [
                'kod'       => $stat['kod'] ?? null,
                'name'      => $stat['name'] ?? null,
                'is_active' => $stat['is_active'] ?? null,
                'version'   => $stat['version'] ?? null,
            ],
        ],
        [
            'module'    => 'stok',
            'doc_type'  => 'STOK01E',
            'doc_id'    => $stat['STOK01_id'] ?? null,
            'doc_no'    => $stat['kod'] ?? null,
            'doc_title' => $stat['name'] ?? 'Stock',
            'status'    => ($stat['is_active'] ?? true) ? 'ACTIVE' : 'PASSIVE',
        ],
        $ctx,
        ['log_id' => $logId]
    );

    // SNAPSHOT
    $dump = STOK01Repository::dumpFull((string)$stat['STOK01_id']);
    $snap = SnapshotWriter::capture(
        [
            'module'    => 'stok',
            'doc_type'  => 'STOK01E',
            'doc_id'    => $stat['STOK01_id'],
            'doc_no'    => $stat['kod'],
            'doc_title' => $stat['name'] ?? 'Stock',
            'status'    => ($stat['is_active'] ?? true) ? 'ACTIVE' : 'PASSIVE',
        ],
        $dump,
        [
            'reason' => 'save',
            'changed_fields' => array_keys($data),
            'version' => $stat['version'] ?? null,
        ]
    );

    EventWriter::emit(
        'STOK.SNAPSHOT',
        [
            'source' => 'public/api/stok_save.php',
            'summary' => [
                'snapshot_id' => $snap['snapshot_id'] ?? null,
            ],
        ],
        [
            'module'    => 'stok',
            'doc_type'  => 'STOK01E',
            'doc_id'    => $stat['STOK01_id'],
            'doc_no'    => $stat['kod'],
            'doc_title' => $stat['name'] ?? 'Stock',
            'status'    => ($stat['is_active'] ?? true) ? 'ACTIVE' : 'PASSIVE',
        ],
        $ctx,
        [
            'log_id' => $logId,
            'snapshot_id' => $snap['snapshot_id'] ?? null,
            'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
        ]
    );

    // WEBHOOK
    WebhookService::dispatch('STOK.SAVE', [
        'STOK01_id' => $stat['STOK01_id'],
        'kod'       => $stat['kod'],
        'name'      => $stat['name'] ?? null,
        'name2'     => $stat['name2'] ?? null,
        'unit'      => $stat['unit'] ?? null,
        'is_active' => $stat['is_active'] ?? null,
        'version'   => $stat['version'] ?? null,
    ], $ctx);

    WebhookService::dispatch('STOK.SNAPSHOT', [
        'STOK01_id' => $stat['STOK01_id'],
        'kod'       => $stat['kod'],
        'snapshot_id'      => $snap['snapshot_id'] ?? null,
        'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
    ], $ctx);

    api_ok([
        'STOK01_id' => $stat['STOK01_id'],
        'kod'       => $stat['kod'],
        'name'      => $stat['name'] ?? null,
        'name2'     => $stat['name2'] ?? null,
        'unit'      => $stat['unit'] ?? null,
        'is_active' => $stat['is_active'] ?? null,
        'version'   => $stat['version'] ?? null,
        'msg'       => 'saved',
    ]);

} catch (InvalidArgumentException $e) {
    // kod_not_unique vs kod_required
    $detail = $e->getMessage();

    if ($detail === 'kod_not_unique') {
        api_err('STOK_VALIDATION', [
            'fields' => ['kod'],
            'detail' => 'kod_not_unique'
        ]);
    }

    if ($detail === 'kod_required') {
        api_err('STOK_VALIDATION', [
            'fields' => ['kod'],
            'detail' => 'kod_required'
        ]);
    }

    api_err('STOK_VALIDATION', [
        'detail' => $detail,
        'msg' => 'validation_error'
    ]);

} catch (Throwable $e) {
    api_err('STOK_SAVE_FAILED', [
        'msg' => 'save_failed',
        'detail' => $e->getMessage()
    ]);
}
