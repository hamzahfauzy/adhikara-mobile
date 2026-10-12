<?php

declare(strict_types=1);

final class AttendanceController
{
    public static function index(): void
    {
        $employee = EmployeeAuth::employee();
        $query = Request::query();

        $sql = "
            SELECT
                a.id,
                a.attendance_date,
                a.schedule_id,
                a.placement_id,
                p.name AS placement_name,
                a.record_type,
                a.start_at,
                a.end_at,
                a.start_lat,
                a.start_lng,
                a.end_lat,
                a.end_lng,
                a.status,
                a.notes
            FROM attendances a
            LEFT JOIN placements p ON p.id = a.placement_id
            WHERE a.employee_id = :employee_id AND a.start_at IS NOT NULL AND a.end_at IS NOT NULL
        ";

        $params = [
            'employee_id' => (int) $employee['id'],
        ];

        foreach ([
            'status' => ['pending', 'approved', 'rejected'],
            'record_type' => ['presence', 'leave', 'duty'],
        ] as $key => $allowed) {
            if (!empty($query[$key])) {
                if (!in_array($query[$key], $allowed, true)) {
                    Response::json(422, 422, "Filter {$key} tidak valid");
                }

                $sql .= " AND a.{$key} = :{$key}";
                $params[$key] = $query[$key];
            }
        }

        foreach (['start_date' => '>=', 'end_date' => '<='] as $key => $operator) {
            if (!empty($query[$key])) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $query[$key]);

                if (!$date || $date->format('Y-m-d') !== $query[$key]) {
                    Response::json(422, 422, "Format {$key} harus YYYY-MM-DD");
                }

                $sql .= " AND a.attendance_date {$operator} :{$key}";
                $params[$key] = $query[$key];
            }
        }

        $sql .= " ORDER BY a.attendance_date DESC, a.start_at DESC LIMIT 200";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        Response::json(200, 200, 'Riwayat absensi berhasil diambil', $stmt->fetchAll());
    }

    
    public static function store(): void
    {
        $employee = EmployeeAuth::employee();
        $input = Request::input();
        $date = date('Y-m-d');

        $action = $input['action'] ?? '';
        // $scheduleId = filter_var(
        //     $input['schedule_id'] ?? null,
        //     FILTER_VALIDATE_INT
        // );

        $lat = filter_var(
            $input['location']['lat'] ?? null,
            FILTER_VALIDATE_FLOAT
        );

        $lng = filter_var(
            $input['location']['lng'] ?? null,
            FILTER_VALIDATE_FLOAT
        );

        if (
            !in_array($action, ['check_in', 'check_out'], true) ||
            $lat === false || $lat === null ||
            $lng === false || $lng === null ||
            $lat < -90 || $lat > 90 ||
            $lng < -180 || $lng > 180
        ) {
            Response::json(422, 422, 'Action, jadwal, dan koordinat GPS wajib valid');
        }

        $db = Database::connection();
        $employeeId = (int) $employee['id'];
        $uploadedPhoto = null;

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("
                SELECT
                    s.id AS schedule_id,
                    s.date,
                    s.status AS schedule_status,
                    sh.start_time,
                    sh.end_time,
                    sh.late_tolerance_minutes,
                    p.id AS placement_id,
                    p.lat,
                    p.lng,
                    p.radius
                FROM schedules s
                INNER JOIN shifts sh ON sh.id = s.shift_id
                INNER JOIN placements p ON p.id = s.placement_id
                INNER JOIN clients c ON c.id = p.client_id
                WHERE s.date = :date
                AND s.employee_id = :employee_id
                AND s.status = 'scheduled'
                AND p.status = 'active'
                AND c.status = 'active'
                AND p.start_date <= s.date
                AND (p.end_date IS NULL OR p.end_date >= s.date)
                FOR UPDATE
            ");

            $stmt->execute([
                'date' => $date,
                'employee_id' => $employeeId,
            ]);

            $schedule = $stmt->fetch();

            if (!$schedule) {
                $db->rollBack();
                Response::json(404, 404, 'Jadwal aktif tidak ditemukan');
            }

            // Validasi radius lokasi.
            $earthRadius = 6371000;

            $lat1 = deg2rad((float) $schedule['lat']);
            $lat2 = deg2rad((float) $lat);
            $deltaLat = deg2rad((float) $lat - (float) $schedule['lat']);
            $deltaLng = deg2rad((float) $lng - (float) $schedule['lng']);

            $a = sin($deltaLat / 2) ** 2
                + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

            $distance = $earthRadius * 2 * atan2(
                sqrt($a),
                sqrt(max(0, 1 - $a))
            );

            if ($distance > (int) $schedule['radius']) {
                $db->rollBack();

                Response::json(422, 422, 'Anda berada di luar radius absensi', [
                    'distance_meters' => (int) round($distance),
                    'allowed_radius_meters' => (int) $schedule['radius'],
                ]);
            }

            $timezone = new DateTimeZone('Asia/Jakarta');

            $now = new DateTimeImmutable('now', $timezone);

            $shiftStart = new DateTimeImmutable(
                $schedule['date'] . ' ' . $schedule['start_time'],
                $timezone
            );

            $shiftEnd = new DateTimeImmutable(
                $schedule['date'] . ' ' . $schedule['end_time'],
                $timezone
            );

            // Jika jam selesai <= jam mulai, shift melewati tengah malam.
            if ($shiftEnd <= $shiftStart) {
                $shiftEnd = $shiftEnd->modify('+1 day');
            }

            $isLate = false;
            $lateMinutes = 0;

            if ($action === 'check_in') {
                // Izinkan check-in paling awal 12 jam sebelum shift.
                // Check-in ditutup saat shift berakhir.
                $earliest = $shiftStart->modify('-12 hours');

                if ($now < $earliest || $now > $shiftEnd) {
                    $db->rollBack();

                    Response::json(422, 422, 'Di luar rentang waktu check-in');
                }

                // Hitung keterlambatan berdasarkan jam mulai shift.
                $tolerance = max(
                    0,
                    (int) $schedule['late_tolerance_minutes']
                );

                $lateThreshold = $shiftStart->modify(
                    '+' . $tolerance . ' minutes'
                );

                $isLate = $now > $lateThreshold;

                $lateMinutes = $isLate
                    ? (int) ceil(
                        ($now->getTimestamp() - $shiftStart->getTimestamp()) / 60
                    )
                    : 0;

                // Foto wajib untuk check-in.
                $uploadedPhoto = AttendancePhoto::store($input['photo']);

                $stmt = $db->prepare("
                    INSERT INTO attendances (
                        employee_id,
                        placement_id,
                        schedule_id,
                        attendance_date,
                        start_at,
                        start_lat,
                        start_lng,
                        start_photo_url,
                        record_type,
                        status,
                        is_late,
                        late_minutes
                    ) VALUES (
                        :employee_id,
                        :placement_id,
                        :schedule_id,
                        :attendance_date,
                        :start_at,
                        :start_lat,
                        :start_lng,
                        :photo,
                        'presence',
                        'approved',
                        :is_late,
                        :late_minutes
                    )
                ");

                $stmt->execute([
                    'employee_id' => $employeeId,
                    'placement_id' => $schedule['placement_id'],
                    'schedule_id' => $schedule['schedule_id'],
                    'attendance_date' => $date,
                    'start_at' => $now->format('Y-m-d H:i:s'),
                    'start_lat' => $lat,
                    'start_lng' => $lng,
                    'photo' => $uploadedPhoto['path'],
                    'is_late' => $isLate ? 1 : 0,
                    'late_minutes' => $lateMinutes,
                ]);

                $attendanceId = (int) $db->lastInsertId();

            } else {
                // Check-out harus memiliki check-in yang belum selesai.
                $stmt = $db->prepare("
                    SELECT id, start_at, end_at
                    FROM attendances
                    WHERE employee_id = :employee_id
                    AND attendance_date = :date
                    AND record_type = 'presence'
                    FOR UPDATE
                ");

                $stmt->execute([
                    'employee_id' => $employeeId,
                    'date' => $date
                ]);

                $attendance = $stmt->fetch();

                if (!$attendance || !$attendance['start_at']) {
                    $db->rollBack();
                    Response::json(422, 422, 'Check-in belum dilakukan');
                }

                if ($attendance['end_at'] !== null) {
                    $db->rollBack();
                    Response::json(409, 409, 'Check-out sudah dilakukan');
                }

                if (
                    $now <= new DateTimeImmutable(
                        $attendance['start_at'],
                        $timezone
                    )
                ) {
                    $db->rollBack();
                    Response::json(422, 422, 'Waktu check-out tidak valid');
                }

                // Foto wajib untuk check-out.
                $uploadedPhoto = AttendancePhoto::store($input['photo']);

                $stmt = $db->prepare("
                    UPDATE attendances
                    SET end_at = :end_at,
                        end_lat = :end_lat,
                        end_lng = :end_lng,
                        end_photo_url = :photo
                    WHERE id = :id
                    AND end_at IS NULL
                ");

                $stmt->execute([
                    'end_at' => $now->format('Y-m-d H:i:s'),
                    'end_lat' => $lat,
                    'end_lng' => $lng,
                    'photo' => $uploadedPhoto['path'],
                    'id' => $attendance['id'],
                ]);

                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Absensi telah diperbarui oleh request lain'
                    );
                }

                $attendanceId = (int) $attendance['id'];
            }

            $db->commit();

            Response::json(200, 200, 'Absensi berhasil disimpan', [
                'attendance_id' => $attendanceId,
                'action' => $action,
                'time' => $now->format('Y-m-d H:i:s'),
                'distance_meters' => (int) round($distance),
                'is_late' => $action === 'check_in' ? $isLate : null,
                'late_minutes' => $action === 'check_in' ? $lateMinutes : null,
                'photo' => $uploadedPhoto['path'],
            ]);

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            // Hapus file jika database gagal menyimpan referensinya.
            if (
                $uploadedPhoto &&
                is_file($uploadedPhoto['absolute_path'])
            ) {
                unlink($uploadedPhoto['absolute_path']);
            }

            if ($e instanceof InvalidArgumentException) {
                Response::json(422, 422, $e->getMessage());
            }

            if ($e instanceof PDOException && $e->getCode() === '23000') {
                Response::json(409, 409, 'Absensi untuk jadwal ini sudah tercatat');
            }

            error_log($e->getMessage());

            Response::json(500, 500, 'Terjadi kesalahan saat menyimpan absensi. ' .$e->getMessage());
        }
    }

}
