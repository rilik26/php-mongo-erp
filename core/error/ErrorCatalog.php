<?php
/**
 * core/error/ErrorCatalog.php (FINAL)
 *
 * - Hatalar "code" ile sabitlenir
 * - UI isterse i18n key ile çevirir
 */
final class ErrorCatalog
{
    public static function get(string $code): array
    {
        $map = [
            // auth
            'AUTH_FAILED' => ['http'=>401, 'i18n'=>'auth.login_failed', 'msg'=>'Login failed'],
            'PERIOD_REQUIRED' => ['http'=>400, 'i18n'=>'period.select', 'msg'=>'Period required'],

            // sales order
            'SORD_VALIDATION' => ['http'=>422, 'i18n'=>'sord.validation_failed', 'msg'=>'Validation failed'],
            'SORD_NOT_FOUND'  => ['http'=>404, 'i18n'=>'sord.not_found', 'msg'=>'Sales order not found'],
            'SORD_SAVE_FAIL'  => ['http'=>500, 'i18n'=>'sord.save_failed', 'msg'=>'Sales order save failed'],

            // stok
            'STOK_VALIDATION' => ['http'=>422, 'i18n'=>'stok.validation_failed', 'msg'=>'Validation failed'],
            'STOK_NOT_FOUND'  => ['http'=>404, 'i18n'=>'stok.not_found', 'msg'=>'Stock not found'],
            'STOK_SAVE_FAIL'  => ['http'=>500, 'i18n'=>'stok.save_failed', 'msg'=>'Stock save failed'],

            // generic
            'FORBIDDEN'   => ['http'=>403, 'i18n'=>'common.forbidden', 'msg'=>'Forbidden'],
            'BAD_REQUEST' => ['http'=>400, 'i18n'=>'common.bad_request', 'msg'=>'Bad request'],
            'SERVER_ERROR'=> ['http'=>500, 'i18n'=>'common.server_error', 'msg'=>'Server error'],
        ];

        return $map[$code] ?? ['http'=>500, 'i18n'=>'common.server_error', 'msg'=>'Server error'];
    }
}
