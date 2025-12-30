<?php
/**
 * core/webhook/WebhookService.php (FINAL)
 *
 * - EventWriter::emit çağrısından sonra "fire-and-forget" webhook tetikler
 * - Basit cURL; hata olsa sistemi durdurmaz
 * - İstersen retry kuyruğu (JOB) sonraki faz
 */
require_once __DIR__ . '/WebhookRepository.php';

final class WebhookService
{
    public static function dispatch(string $eventKey, array $payload, array $ctx = []): void
    {
        $hooks = WebhookRepository::listActiveByEvent($eventKey);
        if (empty($hooks)) return;

        $body = json_encode([
            'event'   => $eventKey,
            'payload' => $payload,
            'context' => [
                'username'  => $ctx['username'] ?? null,
                'CDEF01_id' => $ctx['CDEF01_id'] ?? null,
                'period_id' => $ctx['period_id'] ?? null,
                'session_id'=> $ctx['session_id'] ?? null,
            ],
            'sent_at' => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE);

        foreach ($hooks as $h) {
            $url = (string)($h['url'] ?? '');
            if ($url === '') continue;

            $secret = (string)($h['secret'] ?? '');
            $sig = '';
            if ($secret !== '') {
                $sig = hash_hmac('sha256', (string)$body, $secret);
            }

            try {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_TIMEOUT => 4,
                    CURLOPT_HTTPHEADER => array_filter([
                        'Content-Type: application/json; charset=utf-8',
                        'X-Event-Key: ' . $eventKey,
                        $sig !== '' ? ('X-Signature: ' . $sig) : null,
                    ]),
                ]);
                @curl_exec($ch);
                @curl_close($ch);
            } catch (Throwable $e) {
                // sessiz geç: webhook fail sistemi düşürmesin
            }
        }
    }
}
