<?php
/**
 * core/auth/Policy.php (FINAL)
 *
 * allow($ctx, $request, $resource)
 * - ctx: session context (user, company, period, role ...)
 * - request: action + doc_type + facility + field + state ...
 * - resource: doc itself (row / document)
 */

final class Policy
{
    public static function allow(array $ctx, array $req, array $resource = []): bool
    {
        // 1) login guard
        if (empty($ctx['username'])) return false;

        // 2) firmaya göre izolasyon (en kritik)
        $ctxCompany = (string)($ctx['CDEF01_id'] ?? '');
        $resCompany = (string)($resource['CDEF01_id'] ?? ($req['CDEF01_id'] ?? ''));
        if ($resCompany !== '' && $ctxCompany !== '' && $resCompany !== $ctxCompany) {
            return false;
        }

        // 3) dönem izolasyonu (çoğu ekranda şart)
        $ctxPeriod = (string)($ctx['period_id'] ?? '');
        $resPeriod = (string)($resource['period_id'] ?? ($req['period_id'] ?? ''));
        if ($resPeriod !== '' && $ctxPeriod !== '' && $resPeriod !== $ctxPeriod) {
            return false;
        }

        // 4) rol bazlı baseline (RBAC)
        $role = (string)($ctx['role'] ?? '');
        $action = (string)($req['action'] ?? '');

        // admin: full
        if ($role === 'admin') return true;

        // 5) action bazlı izin örneği (kademeli genişlet)
        $matrix = [
            'user' => [
                'doc.view' => true,
                'doc.edit' => true,
                'doc.transition' => false, // onay / state geçişi yok
            ],
            'approver' => [
                'doc.view' => true,
                'doc.edit' => false,
                'doc.transition' => true,
            ],
        ];

        if (isset($matrix[$role][$action])) return (bool)$matrix[$role][$action];

        return false;
    }
}
