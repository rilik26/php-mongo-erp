<?php
/**
 * public/api/snapshot_diff.php
 *
 * Query:
 * - snapshot_id=...  (preferred)
 * - target_key=...
 * - module + doc_type + doc_id (+period_id optional)
 *
 * Output:
 * - ok, mode, summary, diff, prev/latest info
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/bootstrap.php';

require_once __DIR__ . '/../../core/auth/SessionManager.php';
require_once __DIR__ . '/../../core/base/Context.php';
require_once __DIR__ . '/../../core/base/ContextException.php';

require_once __DIR__ . '/../../core/snapshot/SnapshotRepository.php';
require_once __DIR__ . '/../../core/snapshot/SnapshotDiff.php';

SessionManager::start();

try {
    Context::bootFromSession();
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ctx = Context::get();

$snapshotId = trim($_GET['snapshot_id'] ?? '');
$targetKey  = trim($_GET['target_key'] ?? '');

$module  = trim($_GET['module'] ?? '');
$docType = trim($_GET['doc_type'] ?? '');
$docId   = trim($_GET['doc_id'] ?? '');
$periodOverride = trim($_GET['period_id'] ?? ''); // opsiyonel

try {
    // 1) snapshot_id varsa: onu latest kabul et, prev'i bağla
    if ($snapshotId !== '') {
        $latest = SnapshotRepository::findById($snapshotId);
        if (!$latest) {
            echo json_encode(['ok' => false, 'error' => 'snapshot_not_found'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $targetKey = (string)($latest['target_key'] ?? '');
        $prev = SnapshotRepository::findPrevOfSnapshot($latest);

        $result = buildDiffResponse($prev, $latest);
        $result['ok'] = true;
        $result['target_key'] = $targetKey;

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2) module/doc_type/doc_id ile targetKey üret
    if ($targetKey === '' && $module !== '' && $docType !== '' && $docId !== '') {
        $targetKey = SnapshotRepository::buildTargetKey(
            $module,
            $docType,
            $docId,
            $ctx,
            $periodOverride !== '' ? $periodOverride : null
        );
    }

    if ($targetKey === '') {
        echo json_encode([
            'ok' => false,
            'error' => 'missing_param',
            'hint' => 'use snapshot_id OR target_key OR module+doc_type+doc_id'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3) latest + prev (latest.version-1)
    $latest = SnapshotRepository::findLatest($targetKey);
    if (!$latest) {
        echo json_encode(['ok' => false, 'error' => 'no_snapshot_for_target_key', 'target_key' => $targetKey], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $prev = SnapshotRepository::findPrevOfSnapshot($latest);

    $result = buildDiffResponse($prev, $latest);
    $result['ok'] = true;
    $result['target_key'] = $targetKey;

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function buildDiffResponse(?array $prev, array $latest): array
{
    $mode = 'generic';
    $diff = null;
    $summary = null;
    $note = null;

    $latestId = (string)($latest['_id'] ?? '');
    $latestVer = (int)($latest['version'] ?? 0);

    if (!$prev) {
        $note = 'no_prev_snapshot';
        return [
            'prev'   => null,
            'latest' => ['id' => $latestId, 'version' => $latestVer],
            'mode'   => $mode,
            'diff'   => ['added' => [], 'removed' => [], 'changed' => []],
            'summary'=> [
                'added_count' => 0,
                'removed_count' => 0,
                'changed_count' => 0,
                'note' => $note
            ],
            'note' => $note,
        ];
    }

    $prevId = (string)($prev['_id'] ?? '');
    $prevVer = (int)($prev['version'] ?? 0);

    $oldData = $prev['data'] ?? [];
    $newData = $latest['data'] ?? [];

    // LANG mode (LANG01T dictionary)
    $docType = (string)($latest['target']['doc_type'] ?? '');
    if ($docType === 'LANG01T') {
        $mode = 'lang';
        $oldRows = (array)($oldData['rows'] ?? []);
        $newRows = (array)($newData['rows'] ?? []);
        $diff = SnapshotDiff::diffLangRows($oldRows, $newRows);
        $summary = SnapshotDiff::summarizeLang($diff, 12);
    } else {
        $mode = 'generic';
        $diff = SnapshotDiff::diffAssoc($oldData, $newData);
        $summary = SnapshotDiff::summarize($diff, 12);
    }

    return [
        'prev'   => ['id' => $prevId, 'version' => $prevVer],
        'latest' => ['id' => $latestId, 'version' => $latestVer],
        'mode'   => $mode,
        'diff'   => $diff,
        'summary'=> $summary,
        'note'   => null,
    ];
}
