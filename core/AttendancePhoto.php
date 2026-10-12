<?php

declare(strict_types=1);

final class AttendancePhoto
{
    private const MAX_SIZE = 5 * 1024 * 1024;

    public static function store(string $photo): array
    {
        if (trim($photo) === '') {
            throw new InvalidArgumentException(
                'Foto absensi wajib diunggah'
            );
        }

        // Memastikan input berupa Data URL gambar
        if (!preg_match(
            '/^data:image\/(jpeg|png);base64,([A-Za-z0-9+\/]*={0,2})$/',
            $photo,
            $matches
        )) {
            throw new InvalidArgumentException(
                'Format foto harus berupa JPEG atau PNG dari kamera'
            );
        }

        $extension = $matches[1] === 'jpeg' ? 'jpg' : 'png';
        $base64 = $matches[2];

        // Validasi panjang Base64 sebelum decoding
        if ($base64 === '' || strlen($base64) > ceil(self::MAX_SIZE * 4 / 3) + 4) {
            throw new InvalidArgumentException(
                'Ukuran foto maksimal 5 MB'
            );
        }

        $imageData = base64_decode($base64, true);

        if ($imageData === false || $imageData === '') {
            throw new InvalidArgumentException(
                'Data foto tidak valid'
            );
        }

        if (strlen($imageData) > self::MAX_SIZE) {
            throw new InvalidArgumentException(
                'Ukuran foto maksimal 5 MB'
            );
        }

        // Validasi MIME berdasarkan isi file, bukan ekstensi dari input
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($imageData);

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];

        if (!isset($allowed[$mime])) {
            throw new InvalidArgumentException(
                'Foto harus berupa JPEG atau PNG yang valid'
            );
        }

        // Pastikan data benar-benar dapat dibaca sebagai gambar
        $imageInfo = @getimagesizefromstring($imageData);

        if (
            $imageInfo === false ||
            $imageInfo[2] !== ($mime === 'image/jpeg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG)
        ) {
            throw new InvalidArgumentException(
                'Data foto bukan gambar yang valid'
            );
        }

        $directory = dirname(__DIR__) . '/storage/attendance';

        if (
            !is_dir($directory) &&
            !mkdir($directory, 0750, true) &&
            !is_dir($directory)
        ) {
            throw new RuntimeException(
                'Direktori penyimpanan foto tidak tersedia'
            );
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        $absolutePath = $directory . '/' . $filename;

        if (file_put_contents($absolutePath, $imageData, LOCK_EX) === false) {
            throw new RuntimeException(
                'Foto tidak dapat disimpan'
            );
        }

        @chmod($absolutePath, 0640);

        return [
            'path' => 'storage/attendance/' . $filename,
            'absolute_path' => $absolutePath,
        ];
    }
}