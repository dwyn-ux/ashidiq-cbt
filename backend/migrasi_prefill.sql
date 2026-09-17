-- Migrasi prefill otomatis: link biasa -> NIS/Nama/Kelas/Mapel terisi sendiri.
-- Jalankan sekali via phpMyAdmin SETELAH schema.sql (idempotent, aman diulang).

-- MariaDB dukung IF NOT EXISTS; MySQL 8 tidak — abaikan error duplicate column di MySQL.
ALTER TABLE exams
  ADD COLUMN entry_nis VARCHAR(32) NULL,
  ADD COLUMN entry_nama VARCHAR(32) NULL,
  ADD COLUMN entry_kelas VARCHAR(32) NULL,
  ADD COLUMN entry_mapel VARCHAR(32) NULL;
