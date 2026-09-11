-- CBT Ashidiq — schema MySQL/MariaDB (fresh start, mode SQL manual Forms)
-- Import via phpMyAdmin. Timezone app: Asia/Jakarta (diatur di config.php).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (k, v) VALUES
  ('school_name', 'Nama Sekolah'),
  ('head_name', 'Nama Kepala Sekolah'),
  ('head_id', '-'),
  ('unlock_interval', '5'),
  ('violation_limit', '5');

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  pass_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','proktor') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ponytail: password santri plaintext demi cetak kartu (keputusan eksplisit).
-- Upgrade: hash + kartu tampil sekali saat reset.
CREATE TABLE IF NOT EXISTS students (
  nis VARCHAR(32) PRIMARY KEY,
  nama VARCHAR(128) NOT NULL,
  kelas VARCHAR(16) NOT NULL,
  pass VARCHAR(32) NOT NULL,
  INDEX idx_kelas (kelas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS exams (
  id VARCHAR(32) PRIMARY KEY,
  mapel VARCHAR(128) NOT NULL,
  kelas_target VARCHAR(128) NOT NULL DEFAULT '',
  token VARCHAR(16) NOT NULL DEFAULT '',
  status ENUM('Aktif','Nonaktif') NOT NULL DEFAULT 'Aktif',
  form_url TEXT NULL,
  tanggal DATE NULL,
  mulai TIME NULL,
  selesai TIME NULL,
  durasi SMALLINT NOT NULL DEFAULT 90
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  exam_id VARCHAR(32) NOT NULL,
  nis VARCHAR(32) NOT NULL,
  nama VARCHAR(128) NOT NULL,
  kelas VARCHAR(16) NOT NULL,
  mapel VARCHAR(128) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'Sedang Mengerjakan',
  started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  start_ms BIGINT NULL,
  end_ms BIGINT NULL,
  last_seen BIGINT NULL,
  archived_at TIMESTAMP NULL,
  INDEX idx_active (exam_id, nis, status),
  INDEX idx_nis (nis),
  CONSTRAINT fk_sess_exam FOREIGN KEY (exam_id) REFERENCES exams(id),
  CONSTRAINT fk_sess_nis FOREIGN KEY (nis) REFERENCES students(nis)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tokens (
  token CHAR(64) PRIMARY KEY,
  role VARCHAR(16) NOT NULL,
  nis VARCHAR(32) NULL,
  nama VARCHAR(128) NULL,
  kelas VARCHAR(16) NULL,
  expires_at TIMESTAMP NOT NULL,
  INDEX idx_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  k VARCHAR(128) PRIMARY KEY,
  fails TINYINT NOT NULL DEFAULT 0,
  locked_until TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  actor VARCHAR(64) NULL,
  action VARCHAR(64) NOT NULL,
  detail TEXT NULL,
  INDEX idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
