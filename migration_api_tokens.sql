-- Migration: Tabel api_tokens untuk autentikasi API Sindesa Mobile
-- Jalankan di database db_sindesa

CREATE TABLE IF NOT EXISTS `api_tokens` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `token` VARCHAR(128) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_token` (`token`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cleanup: Hapus token yang sudah kadaluarsa (jalankan berkala via cron jika perlu)
-- DELETE FROM api_tokens WHERE expires_at < NOW();
