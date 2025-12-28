<?php
/**
 * app/modules/gendoc/GendocBodyValidator.php (FINAL)
 *
 * Basit ama iş gören server-side doğrulama:
 * - lines[] zorunlu ve array olmalı
 * - totals zorunlu
 * - numeric alanlar sayı olmalı
 * - currency/unit boş olmamalı
 *
 * İstersen bunu ileride JSON Schema (draft-07) gibi daha strict hale getiririz.
 */

final class GendocBodyValidator
{
    public static function validate(array $body): array
    {
        $errors = [];

        // lines
        if (!isset($body['lines']) || !is_array($body['lines'])) {
            $errors[] = "Body.lines zorunlu ve array olmalı.";
            return [false, $errors];
        }

        // totals
        if (!isset($body['totals']) || !is_array($body['totals'])) {
            $errors[] = "Body.totals zorunlu ve object olmalı.";
        } else {
            $tot = $body['totals'];
            foreach (['sub_total','vat_total','grand_total'] as $k) {
                if (isset($tot[$k]) && !is_numeric($tot[$k])) {
                    $errors[] = "totals.$k numeric olmalı.";
                }
            }
            if (isset($tot['currency']) && trim((string)$tot['currency']) === '') {
                $errors[] = "totals.currency boş olamaz.";
            }
        }

        // validate each line
        foreach ($body['lines'] as $i => $ln) {
            if (!is_array($ln)) {
                $errors[] = "lines[$i] object olmalı.";
                continue;
            }

            // required-ish fields
            $itemCode = trim((string)($ln['item_code'] ?? ''));
            $itemName = trim((string)($ln['item_name'] ?? ''));
            if ($itemCode === '' && $itemName === '') {
                $errors[] = "lines[$i]: item_code veya item_name en az biri dolu olmalı.";
            }

            foreach (['quantity','unit_price','vat_rate','vat_amount','line_total'] as $k) {
                if (isset($ln[$k]) && $ln[$k] !== '' && !is_numeric($ln[$k])) {
                    $errors[] = "lines[$i].$k numeric olmalı.";
                }
            }

            if (isset($ln['unit']) && trim((string)$ln['unit']) === '') {
                $errors[] = "lines[$i].unit boş olamaz.";
            }
            if (isset($ln['currency']) && trim((string)$ln['currency']) === '') {
                $errors[] = "lines[$i].currency boş olamaz.";
            }
        }

        return [count($errors) === 0, $errors];
    }

    /**
     * İstersen otomatik totals hesaplama:
     * - UI’dan gelen line_total/vat_amount boşsa hesaplar
     * - totals yoksa üretir
     */
    public static function normalize(array $body): array
    {
        $currency = 'TRY';

        $sub = 0.0; $vat = 0.0; $grand = 0.0;

        if (!isset($body['lines']) || !is_array($body['lines'])) $body['lines'] = [];

        foreach ($body['lines'] as $i => $ln) {
            if (!is_array($ln)) continue;

            $qty  = (float)($ln['quantity'] ?? 0);
            $up   = (float)($ln['unit_price'] ?? 0);
            $vr   = (float)($ln['vat_rate'] ?? 0);

            if (isset($ln['currency']) && $ln['currency'] !== '') $currency = (string)$ln['currency'];

            $lineSub = $qty * $up;
            $lineVat = $lineSub * ($vr / 100.0);
            $lineTot = $lineSub + $lineVat;

            // eğer boşsa set et
            if (!isset($ln['vat_amount']) || $ln['vat_amount'] === '') $body['lines'][$i]['vat_amount'] = $lineVat;
            if (!isset($ln['line_total']) || $ln['line_total'] === '') $body['lines'][$i]['line_total'] = $lineTot;

            $sub += $lineSub;
            $vat += $lineVat;
            $grand += $lineTot;
        }

        if (!isset($body['totals']) || !is_array($body['totals'])) $body['totals'] = [];
        $body['totals']['currency'] = (string)($body['totals']['currency'] ?? $currency);
        $body['totals']['sub_total'] = $sub;
        $body['totals']['vat_total'] = $vat;
        $body['totals']['grand_total'] = $grand;

        return $body;
    }
}
