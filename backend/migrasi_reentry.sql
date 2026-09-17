-- Jalankan SEKALI di hosting (cPanel > phpMyAdmin > pilih DB proftweb_cbt > SQL):
--   ALTER TABLE sessions ADD COLUMN escape_ms BIGINT NULL AFTER last_seen;
-- Catatan: kolom ini audit saja. Sistem re-entry lock tidak wajib butuh kolom ini
-- (flag disimpan di SharedPreferences APK), jadi kalau belum dijalankan pun fitur tetap jalan.
ALTER TABLE sessions ADD COLUMN escape_ms BIGINT NULL AFTER last_seen;
