<?php
/**
 * app/services/DocumentStateService.php (FINAL)
 *
 * - Tek API: transition($docType, $docId, $action, $payload)
 * - İçeride:
 *   1) doc getir
 *   2) state check
 *   3) yetki check (ABAC hook: Policy::allow)
 *   4) update state
 *   5) Event + Log + Snapshot
 */

require_once __DIR__ . '/../../core/state/StateMachine.php';

final class DocumentStateService
{
    public static function transition(string $docType, string $docId, string $action, array $payload, array $ctx): array
    {
        // 1) Koleksiyon
        $col = MongoManager::collection($docType);

        // 2) doc getir
        $doc = $col->findOne(['_id' => new MongoDB\BSON\ObjectId($docId)]);
        if (!$doc) {
            return ['ok' => false, 'error' => 'doc_not_found'];
        }
        if ($doc instanceof MongoDB\Model\BSONDocument) $doc = $doc->getArrayCopy();

        $fromState = (string)($doc['state'] ?? 'draft');

        // 3) geçiş var mı?
        if (!StateMachine::can($docType, $fromState, $action)) {
            return ['ok' => false, 'error' => 'transition_not_allowed', 'from' => $fromState, 'action' => $action];
        }

        $toState = StateMachine::next($docType, $fromState, $action);
        if (!$toState) {
            return ['ok' => false, 'error' => 'transition_map_missing'];
        }

        // 4) ABAC Hook (Phase-3’te Policy implement edeceğiz)
        if (class_exists('Policy') && method_exists('Policy', 'allow')) {
            $ok = Policy::allow($ctx, [
                'action' => 'doc.transition',
                'doc_type' => $docType,
                'doc_id' => $docId,
                'from_state' => $fromState,
                'to_state' => $toState,
            ], $doc);

            if (!$ok) return ['ok' => false, 'error' => 'forbidden'];
        }

        // 5) update state (+ version opsiyon)
        $now = new MongoDB\BSON\UTCDateTime((int)floor(microtime(true)*1000));

        $col->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($docId)],
            [
                '$set' => [
                    'state' => $toState,
                    'state_updated_at' => $now,
                ],
                '$inc' => ['version' => 1],
            ]
        );

        // 6) Log
        $logId = ActionLogger::success('DOC.STATE.TRANSITION', [
            'doc_type' => $docType,
            'doc_id'   => $docId,
            'action'   => $action,
            'from'     => $fromState,
            'to'       => $toState,
        ], $ctx);

        // 7) Snapshot (state değişimi kritik -> önerim: her geçişte al)
        $snap = SnapshotWriter::capture(
            [
                'module' => 'doc',
                'doc_type' => $docType,
                'doc_id' => $docId,
                'doc_no' => (string)($doc['doc_no'] ?? ''),
                'doc_title' => (string)($doc['title'] ?? $docType),
            ],
            [
                'before' => ['state' => $fromState, 'doc' => $doc],
                'after'  => ['state' => $toState],
                'payload'=> $payload,
            ],
            [
                'reason' => 'state_transition',
                'changed_fields' => ['state'],
                'from' => $fromState,
                'to'   => $toState,
                'action' => $action,
            ]
        );

        // 8) Event
        EventWriter::emit(
            'DOC.STATE.TRANSITION',
            [
                'summary' => [
                    'action' => $action,
                    'from' => $fromState,
                    'to' => $toState,
                ],
                'payload' => $payload,
            ],
            [
                'module' => 'doc',
                'doc_type' => $docType,
                'doc_id' => $docId,
                'doc_no' => (string)($doc['doc_no'] ?? ''),
                'doc_title' => (string)($doc['title'] ?? $docType),
            ],
            $ctx,
            [
                'log_id' => $logId,
                'snapshot_id' => $snap['snapshot_id'] ?? null,
                'prev_snapshot_id' => $snap['prev_snapshot_id'] ?? null,
            ]
        );

        return ['ok' => true, 'from' => $fromState, 'to' => $toState];
    }
}
