<?php
require_once __DIR__ . '/ErrorCatalog.php';

function api_ok(array $data = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok'=>true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function api_err(string $code, array $extra = []): void
{
    $e = ErrorCatalog::get($code);
    http_response_code((int)$e['http']);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(array_merge([
        'ok'   => false,
        'code' => $code,
        'i18n' => $e['i18n'],
        'msg'  => $e['msg'], // debug için; prod’da istersen kaldırırız
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
