<?php

declare(strict_types=1);

final class HomeController
{
    public static function index(): void
    {
        $employee = EmployeeAuth::employee();
        $db = Database::connection();
        $employeeId = (int) $employee['id'];
        $today = date('Y-m-d');

        $stmt = $db->prepare("
            SELECT
                p.*
            FROM placements p
            WHERE p.employee_id = :employee_id
        ");

        $stmt->execute([
            'employee_id' => $employeeId,
        ]);

        $employee['placement'] = $stmt->fetchObject();

        $stmt = $db->prepare("
            SELECT
                id,
                attendance_date,
                record_type,
                start_at,
                end_at,
                status,
                notes
            FROM attendances
            WHERE employee_id = :employee_id AND attendance_date = :today
        ");

        $stmt->execute([
            'employee_id' => $employeeId,
            'today' => $today,
        ]);

        $attendanceToday = $stmt->fetchObject();

        $stmt = $db->prepare("
            SELECT
                id,
                attendance_date,
                record_type,
                start_at,
                end_at,
                status,
                notes
            FROM attendances
            WHERE  record_type = 'presence' AND employee_id = :employee_id AND start_at IS NOT NULL AND end_at IS NOT NULL
            ORDER BY created_at DESC
            LIMIT 5
        ");

        $stmt->execute([
            'employee_id' => $employeeId
        ]);

        $attendances = $stmt->fetchAll();

        Response::json(200, 200, 'Data beranda berhasil diambil', [
            'today' => $today,
            'employee' => $employee,
            'attendanceToday' => $attendanceToday,
            'attendances' => $attendances,
            // 'locations' => array_values($locations),
            // 'schedules' => $schedules,
        ]);
    }
}
