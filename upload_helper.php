<?php
/**
 * Upload Helper - Sindesa API
 * Menangani upload file dari Android ke storage Laravel
 * Deteksi otomatis direktori upload Laravel (sindesa-app di Linux server vs sindesa di local Laragon)
 */

function get_upload_dir($subfolder = 'pengajuan') {
    $sub = trim($subfolder, '/') . '/';
    $candidates = [
        "../../storage/app/public/",        // Jika API di sindesa-app/public/api.domain.com/
        "../storage/app/public/",           // Jika API di sindesa-app/public_api/
        "../../../storage/app/public/",    // Jika API di subfolder lebih dalam
        "/home/sindesa/sindesa-app/storage/app/public/",
        "../sindesa-app/storage/app/public/",
        "../sindesa/storage/app/public/",
        "./uploads/"
    ];
    foreach ($candidates as $base) {
        if (is_dir($base)) {
            $dir = $base . $sub;
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            return $dir;
        }
    }
    $fallback = "../../storage/app/public/" . $sub;
    if (!is_dir($fallback)) @mkdir($fallback, 0777, true);
    return $fallback;
}

function process_upload($file_key, $prefix, $nik, $upload_dir) {
    if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] != 0) {
        // Log error code untuk debug
        if (isset($_FILES[$file_key]['error']) && $_FILES[$file_key]['error'] != 4) {
            // Error 4 = no file uploaded (normal), error lain perlu dicatat
            // FIX: Gunakan concatenation, bukan string interpolation untuk array
            error_log("upload_helper: \$_FILES[{$file_key}][error] = " . $_FILES[$file_key]['error'] . " untuk field $file_key");
        }
        return "";
    }

    // Validasi ukuran (max 5MB, disesuaikan dengan limit website Sindesa)
    if ($_FILES[$file_key]['size'] > 5242880) {
        die(json_encode(["success" => false, "message" => "Ukuran file " . $file_key . " terlalu besar (Max 5MB)"]));
    }

    // Validasi ukuran minimum (hindari file kosong)
    if ($_FILES[$file_key]['size'] < 100) {
        error_log("upload_helper: File $file_key terlalu kecil: " . $_FILES[$file_key]['size'] . " bytes");
        return "";
    }

    // Validasi ekstensi
    $ext = strtolower(pathinfo($_FILES[$file_key]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
        die(json_encode(["success" => false, "message" => "Format file " . $file_key . " harus JPG, PNG, atau PDF"]));
    }

    // Pastikan direktori upload ada
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            error_log("upload_helper: Gagal buat direktori $upload_dir");
            return "";
        }
    }

    // Buat nama file unik
    $filename = $prefix . "_" . $nik . "_" . time() . "_" . rand(100, 999) . "." . $ext;
    $dest = rtrim($upload_dir, '/') . '/' . $filename;

    // Log tmp file info untuk debug
    $tmp = $_FILES[$file_key]['tmp_name'];
    error_log("upload_helper: Mencoba copy $tmp → $dest");

    // Salin file dari temp
    if (!copy($tmp, $dest)) {
        // Coba move_uploaded_file sebagai alternatif
        if (!move_uploaded_file($tmp, $dest)) {
            error_log("upload_helper: Gagal copy/move file ke " . $dest . " | tmp=$tmp | exists=" . (file_exists($tmp) ? 'yes' : 'no'));
            return "";
        }
    } else {
        @unlink($tmp); // Hapus file temp setelah copy berhasil
    }

    // FIX 403 Forbidden: Set permission Windows via icacls (hanya jika di Windows dan fungsi exec aktif)
    if (function_exists('exec') && (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')) {
        $dest_real = realpath($dest);
        if ($dest_real) {
            $dest_win = str_replace('/', '\\', $dest_real);
            // Grant Everyone (menggunakan SID Universal *S-1-1-0 agar jalan di Windows Indonesia) read access
            exec('icacls "' . $dest_win . '" /grant "*S-1-1-0:(R)" /Q 2>&1', $output, $ret);
            if ($ret !== 0) {
                error_log("upload_helper: icacls failed untuk $dest_win: " . implode(' ', $output));
                // Fallback coba nama lokal Indonesia "Semua Orang"
                exec('icacls "' . $dest_win . '" /grant "Semua Orang:(R)" /Q 2>&1');
            }
            // Grant ke berbagai user web server Windows (IIS_IUSRS SID: *S-1-5-32-568)
            exec('icacls "' . $dest_win . '" /grant "*S-1-5-32-568:(R)" /Q 2>&1');
            exec('icacls "' . $dest_win . '" /grant "NETWORK SERVICE:(R)" /Q 2>&1');
            exec('icacls "' . $dest_win . '" /grant "IUSR:(R)" /Q 2>&1');
            // Grant ke user saat ini (untuk Windows local dev)
            $current_user = trim(shell_exec('whoami') ?: '');
            if ($current_user) {
                exec('icacls "' . $dest_win . '" /grant "' . $current_user . ':(R)" /Q 2>&1');
            }
        }
    }
    // chmod sebagai fallback (Linux/Mac compatibility)
    @chmod($dest, 0644);

    error_log("upload_helper: Berhasil upload $file_key → $dest");

    // Return path relatif untuk disimpan di DB (relatif terhadap storage/app/public/)
    return "pengajuan/" . $filename;
}