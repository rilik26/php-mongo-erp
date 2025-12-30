<?php
/**
 * public/api/sord_save.php (FINAL)
 *
 * POST JSON:
 * {
 *   "header": { "evrakno":"SO-0001", ... },
 *   "lines":  [ {..}, {..} ]
 * }
 *
 * ÇIKTI:
 * - ok:true, sord_id, evrakno
 * - ayrıca Event + Snapshot + Webhook tetikler
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
require_once __DIR__ . '/../../app/modules/salesorder/SORDRepository.php';

SessionManager::start();

try {
    Context::bootFromSession();
} catch (ContextException $e) {
    api_err('AUTH_FAILED');
}

$ctx = Context::get();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_err('BAD_REQUEST', ['detail'=>'POST required']);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    api_err('BAD_REQUEST', ['detail'=>'invalid_json']);
}

$header = $data['header'] ?? [];
$lines  = $data['lines'] ?? [];
if (!is_array($header) || !is_array($lines)) {
    api_err('BAD_REQUEST', ['detail'=>'header_or_lines_invalid']);
}

try {
    // validation minimal
    $evrakno = trim((string)($header['evrakno'] ?? ''));
    if ($evrakno === '') api_err('SORD_VALIDATION', ['fields'=>['evrakno']]);

    $stat = SORDRepository::save($header, $lines, $ctx);

    // LOG
    $logId = ActionLogger::success('SORD.SAVE', [
        'source' => 'public/api/sord_save.php',
        'evrakno' => $stat['evrakno'] ?? null,
        'SORD01_id' => $stat['SORD01_id'] ?? null,
        'lines_count' => $stat['lines_count'] ?? 0,
    ], $ctx);

    // EVENT
    EventWriter::emit(
        'SORD.SAVE',
        [
            'source' => 'public/api/sord_save.php',
            'summary' => [
                'evrakno' => $stat['evrakno'] ?? null,
                'lines_count' => $stat['lines_count'] ?? 0,
            ],
        ],
        [
            'module' => 'sales',
            'doc_type' => 'SORD01E',
            'doc_id' => $stat['SORD01_id'] ?? null,
            'doc_no' => $stat['evrakno'] ?? null,
            'doc_title' => 'Sales Order',
        ],
        $ctx,
        ['log_id' => $logId]
    );

    // SNAPSHOT (final dump)
    $dump = SORDRepository::dumpFull((string)$stat['SORD01_id']);

    $snap = SnapshotWriter::capture(
        [
            'module' => 'sales',
            'doc_type' => 'SORD01E',
            'doc_id' => $stat['SORD01_id'],
            'doc_no' => $stat['evrakno'],
            'doc_title' => 'Sales Order',
        ],
        $dump,
        [
            'reason' => 'save',
            'changed_fields' => ['header','lines'],
            'lines_count' => $stat['lines_count'] ?? 0,
        ]
    );

    // EVENT (snapshot)
    EventWriter::emit(
        'SORD.SNAPSHOT',
        [
            'source' => 'public/api/sord_save.php',
            'summary' => [
                'snapshot_id' => $snap['snapshot_id'] ?? null,
            ],
        ],
        [
            'module' => 'sales',
            'doc_type' => 'SORD01E',
            'doc_id' => $stat['SORD01_id'],
            'doc_no' => $stat['evrakno'],
            'doc_title' => 'Sales Order',
        ],
        $ctx,
        [
            'log_id' => $logId,
            'snapshot_id' => $snap['snapshot_id'] ?? null,
            'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
        ]
    );

    // WEBHOOK (save + snapshot)
    WebhookService::dispatch('SORD.SAVE', [
        'SORD01_id' => $stat['SORD01_id'],
        'evrakno'   => $stat['evrakno'],
        'lines_count' => $stat['lines_count'] ?? 0,
    ], $ctx);

    WebhookService::dispatch('SORD.SNAPSHOT', [
        'SORD01_id' => $stat['SORD01_id'],
        'evrakno'   => $stat['evrakno'],
        'snapshot_id' => $snap['snapshot_id'] ?? null,
        'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
    ], $ctx);

    api_ok([
        'sord_id' => $stat['SORD01_id'],
        'evrakno' => $stat['evrakno'],
        'snapshot_id' => $snap['snapshot_id'] ?? null,
    ]);

} catch (InvalidArgumentException $e) {
    api_err('SORD_VALIDATION', ['detail'=>$e->getMessage()]);
} catch (Throwable $e) {
    ActionLogger::fail('SORD.SAVE.FAIL', [
        'source' => 'public/api/sord_save.php',
        'error' => $e->getMessage(),
    ], $ctx);

    api_err('SORD_SAVE_FAIL');
}
