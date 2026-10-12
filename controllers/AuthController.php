<?php

declare(strict_types=1);

final class AuthController
{
    public static function login(): void
    {
        $input = Request::input();

        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($username === '' || $password === '') {
            Response::json(422, 422, 'Username dan password wajib diisi');
        }

        $db = Database::connection();

        $stmt = $db->prepare("
            SELECT id, code, name, username, phone, status, password_hash
            FROM employees
            WHERE username = :username
            LIMIT 1
        ");

        $stmt->execute(['username' => $username]);
        $employee = $stmt->fetch();

        if (
            !$employee ||
            $employee['status'] !== 'active' ||
            !password_verify($password, $employee['password_hash'])
        ) {
            Response::json(401, 401, 'Username atau password salah');
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        // Token aktif sebelumnya tidak otomatis dicabut.
        // Perangkat lain dapat tetap login sampai token dicabut/kedaluwarsa.
        $stmt = $db->prepare("
            INSERT INTO employee_tokens (
                employee_id,
                token_hash,
                expires_at
            ) VALUES (
                :employee_id,
                :token_hash,
                DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 DAY)
            )
        ");

        $stmt->execute([
            'employee_id' => $employee['id'],
            'token_hash' => $tokenHash,
        ]);

        $stmt = $db->prepare("
            UPDATE employees
            SET last_login_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $stmt->execute(['id' => $employee['id']]);

        unset($employee['password_hash']);

        Response::json(200, 200, 'Login berhasil', [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 2592000,
            'employee' => $employee,
        ]);
    }

    public static function logout(): void
    {
        $token = Request::bearerToken();

        if (!$token) {
            Response::json(401, 401, 'Token autentikasi diperlukan');
        }

        $stmt = Database::connection()->prepare("
            UPDATE employee_tokens
            SET revoked_at = CURRENT_TIMESTAMP
            WHERE token_hash = :token_hash
              AND revoked_at IS NULL
        ");

        $stmt->execute([
            'token_hash' => hash('sha256', $token),
        ]);

        Response::json(200, 200, 'Logout berhasil');
    }

    public static function me(): void
    {
        Response::json(200, 200, 'Profil berhasil diambil', EmployeeAuth::employee());
    }
}
