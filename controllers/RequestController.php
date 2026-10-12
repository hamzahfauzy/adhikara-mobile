<?php

declare(strict_types=1);

final class RequestController
{
    public static function index(): void
    {
        $employee = EmployeeAuth::employee();
        $recordType = $_GET['record_type'];
        $status     = $_GET['status'];

        $sql = "
            SELECT
                id,
                placement_id,
                schedule_id,
                start_at,
                end_at,
                record_type,
                status,
                notes,
                file_url,
                reviewed_at,
                rejection_reason,
                created_at
            FROM attendance_requests
            WHERE employee_id = :employee_id
        ";

        $params = [
            'employee_id' => (int) $employee['id'],
        ];

        if ($recordType !== 'all') {
            $sql .= " AND record_type = :record_type";
            $params['record_type'] = $recordType;
        }

        if ($status !== 'all') {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY created_at DESC LIMIT 200";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        Response::json(200, 200, 'Pengajuan berhasil diambil', $stmt->fetchAll());
    }

    public static function store(): void
    {
        $employee = EmployeeAuth::employee();
        $input = Request::input();

        $recordType = $input['record_type'] ?? '';
        $startDate = $input['start_date'] ?? '';
        $endDate = $input['end_date'] ?? '';
        $notes = trim((string) ($input['notes'] ?? ''));

        if (!in_array($recordType, ['leave', 'duty'], true)) {
            Response::json(422, 422, 'record_type harus leave atau duty');
        }

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $startDate);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $endDate);

        if (
            !$start || !$end ||
            $start->format('Y-m-d') !== $startDate ||
            $end->format('Y-m-d') !== $endDate
        ) {
            Response::json(422, 422, 'Format tanggal harus YYYY-MM-DD');
        }

        if ($end < $start) {
            Response::json(422, 422, 'Tanggal selesai tidak boleh sebelum tanggal mulai');
        }

        if ($start < new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'))) {
            Response::json(422, 422, 'Tanggal pengajuan tidak boleh di masa lalu');
        }

        if ($notes === '') {
            Response::json(422, 422, 'Alasan atau keterangan wajib diisi');
        }

        if (mb_strlen($notes) > 5000) {
            Response::json(422, 422, 'Keterangan maksimal 5000 karakter');
        }

        $fileUrl = null;
        $file = Request::uploadedFile('file');

        if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                Response::json(422, 422, 'Upload lampiran gagal');
            }

            if ($file['size'] > 5 * 1024 * 1024) {
                Response::json(422, 422, 'Ukuran lampiran maksimal 5 MB');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);

            $allowed = [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
            ];

            if (!isset($allowed[$mime])) {
                Response::json(422, 422, 'Lampiran harus PDF, JPG, atau PNG');
            }

            $directory = dirname(__DIR__) . '/storage/requests';

            if (!is_dir($directory) &&
                !mkdir($directory, 0750, true) &&
                !is_dir($directory)) {
                Response::json(500, 500, 'Folder upload tidak tersedia');
            }

            $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
            $destination = $directory . '/' . $filename;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                Response::json(500, 500, 'Lampiran tidak dapat disimpan');
            }

            // Path relatif internal, bukan URL publik.
            $fileUrl = 'storage/requests/' . $filename;
        }

        $db = Database::connection();

        try {
            $db->beginTransaction();

            // Cari penempatan aktif karyawan pada rentang tanggal pengajuan.
            $stmt = $db->prepare("
                SELECT id
                FROM placements
                WHERE employee_id = :employee_id
                  AND status = 'active'
                  AND start_date <= :end_date
                  AND (end_date IS NULL OR end_date >= :start_date)
                ORDER BY start_date DESC
                LIMIT 1
            ");

            $stmt->execute([
                'employee_id' => (int) $employee['id'],
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            $placement = $stmt->fetch();

            if (!$placement) {
                $db->rollBack();
                Response::json(422, 422, 'Tidak ada penempatan aktif pada periode tersebut');
            }

            // Cegah pengajuan yang bertabrakan dengan pengajuan lain
            // yang masih pending atau sudah approved.
            $stmt = $db->prepare("
                SELECT id
                FROM attendance_requests
                WHERE employee_id = :employee_id
                  AND status IN ('pending', 'approved')
                  AND start_at <= :end_at
                  AND end_at >= :start_at
                LIMIT 1
            ");

            $stmt->execute([
                'employee_id' => (int) $employee['id'],
                'start_at' => $startDate . ' 00:00:00',
                'end_at' => $endDate . ' 23:59:59',
            ]);

            if ($stmt->fetch()) {
                $db->rollBack();
                Response::json(409, 409, 'Periode bertabrakan dengan pengajuan lain');
            }

            $stmt = $db->prepare("
                INSERT INTO attendance_requests (
                    employee_id,
                    placement_id,
                    schedule_id,
                    start_at,
                    end_at,
                    record_type,
                    status,
                    notes,
                    file_url
                ) VALUES (
                    :employee_id,
                    :placement_id,
                    NULL,
                    :start_at,
                    :end_at,
                    :record_type,
                    'pending',
                    :notes,
                    :file_url
                )
            ");

            $stmt->execute([
                'employee_id' => (int) $employee['id'],
                'placement_id' => (int) $placement['id'],
                'start_at' => $startDate . ' 00:00:00',
                'end_at' => $endDate . ' 23:59:59',
                'record_type' => $recordType,
                'notes' => $notes,
                'file_url' => $fileUrl,
            ]);

            $requestId = (int) $db->lastInsertId();

            $db->commit();

            Response::json(201, 201, 'Pengajuan berhasil dikirim', [
                'request_id' => $requestId,
                'record_type' => $recordType,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => 'pending',
            ]);

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log($e->getMessage());
            Response::json(500, 500, 'Terjadi kesalahan saat membuat pengajuan');
        }
    }

    public static function show($id)
    {
        $employee = EmployeeAuth::employee();

        $stmt = Database::connection()->prepare("
            SELECT
                id,
                placement_id,
                schedule_id,
                start_at,
                end_at,
                record_type,
                status,
                notes,
                file_url,
                reviewed_at,
                rejection_reason,
                created_at
            FROM attendance_requests
            WHERE employee_id = :employee_id AND id = :id
            ORDER BY created_at DESC
            LIMIT 200
        ");

        $stmt->execute([
            'employee_id' => (int) $employee['id'],
            'id' => (int) $id
        ]);

        Response::json(200, 200, 'Pengajuan berhasil diambil', $stmt->fetchObject());
    }

    public static function statistic()
    {
        $employee = EmployeeAuth::employee();
        $sql = "
            SELECT
                status,
                COUNT(*) AS total
            FROM attendance_requests
            WHERE employee_id = :employee_id
        ";

        $params = [
            'employee_id' => (int) $employee['id'],
        ];

        $sql .= " GROUP BY status";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        $statusAggregate = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        Response::json(200, 200, 'Pengajuan berhasil diambil', $statusAggregate);
    }
}
