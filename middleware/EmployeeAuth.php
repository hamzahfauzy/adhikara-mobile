<?php

declare(strict_types=1);

final class EmployeeAuth
{
    private static ?array $employee = null;

    public static function handle(): void
    {
        if (self::$employee !== null) {
            return;
        }

        $token = Request::bearerToken();

        if (!$token) {
            Response::json(401, 401, 'Token autentikasi diperlukan');
        }

        $hash = hash('sha256', $token);
        $db = Database::connection();

        $stmt = $db->prepare("
            SELECT
                e.id,
                e.code,
                e.name,
                e.username,
                e.phone,
                e.status,
                t.id AS token_id
            FROM employee_tokens t
            INNER JOIN employees e ON e.id = t.employee_id
            WHERE t.token_hash = :token_hash
              AND t.revoked_at IS NULL
              AND t.expires_at > CURRENT_TIMESTAMP
              AND e.status = 'active'
            LIMIT 1
        ");

        $stmt->execute(['token_hash' => $hash]);
        $employee = $stmt->fetch();

        if (!$employee) {
            Response::json(401, 401, 'Token tidak valid atau sudah kedaluwarsa');
        }

        $update = $db->prepare("
            UPDATE employee_tokens
            SET last_used_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $update->execute(['id' => $employee['token_id']]);

        unset($employee['token_id']);

        self::$employee = $employee;
    }

    public static function employee(): array
    {
        if (self::$employee === null) {
            Response::json(401, 401, 'Autentikasi diperlukan');
        }

        return self::$employee;
    }
}
