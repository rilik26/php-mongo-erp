<?php
final class ContextFactory
{
    public static function create(array $user): array
    {
        return [
            'UDEF01_id'  => $user['UDEF01_id'],
            'username'   => $user['username'] ?? null,   // ✅ EKLE
            'CDEF01_id'  => $user['CDEF01_id'],
            'period_id'  => $user['period_id'],
            'role'       => $user['role'],
            'session_id' => session_id(),

            'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];
    }
}
