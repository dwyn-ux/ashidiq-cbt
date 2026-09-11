# Panduan Deploy — CBT Ashidiq (PHP + MySQL, mode SQL manual Forms)

Sumber: `backend/` (API), `index.html` (frontend). Tidak perlu Apps Script / Spreadsheet lagi.

## 1. Database (phpMyAdmin, 5 menit)

1. cPanel > MySQL Databases > buat database misal `cbt_ashidiq` + user + password kuat. Catat 4 nilai ini.
2. phpMyAdmin > pilih database > Import > `backend/schema.sql` > Go. Harus muncul 8 tabel: `settings, users, students, exams, sessions, tokens, login_attempts, audit_logs`.
3. Import > `backend/seed.sql` > Go. Akun awal:
   - `admin / admin123` (role admin)
   - `proktor / proktor123` (role proktor)
4. Login pertama sebagai admin > tab Setting > ganti kredensial proktor (atau update langsung tabel `users` dengan hash baru via `password_hash`). Proktor hanya bisa monitor + kick + kode unlock — tulis master (santri/mapel/setting/arsip) khusus admin.

## 2. Backend (upload 2 file)

1. Edit `backend/config.php`: isi `DB_HOST, DB_NAME, DB_USER, DB_PASS` sesuai langkah 1. Zona waktu sudah `Asia/Jakarta` di file.
2. Upload ke hosting dengan struktur tetap:
   ```
   /cbt/index.html
   /cbt/backend/api.php
   /cbt/backend/config.php
   ```
   `config.php` tidak diakses langsung (hanya di-require `api.php`), tapi pastikan permission 640 dan tidak bisa di-listing.
3. `API_URL` di `index.html` otomatis = `backend/api.php` satu folder — tidak perlu edit kalau struktur di atas dipatuhi. Kalau `index.html` dipindah, sesuaikan manual.
4. Wajib HTTPS.

## 3. Isi data awal (web admin)

1. Buka `https://domain/cbt/` > tab Admin > login `admin / admin123`.
2. Setting: isi nama sekolah, kepala, NBM.
3. Input Santri (atau via `tambahData`): NIS, nama, kelas, password. Kosongkan password lalu klik Auto Generate Password Kosong.
4. Tambah Jadwal Ujian: ID, nama mapel, kelas target (`11A,11B` atau `10,11`), tanggal + jam buka/tutup, durasi, token, status. **Kolom Link Form boleh kosong dulu.**
5. Link Form: tiap guru buat Google Form dari akun masing-masing dengan 3 field wajib `NIS, NAMA LENGKAP, KELAS` (boleh tambah placeholder `TEMPLATE_NIS/TEMPLATE_NAMA/TEMPLATE_KELAS` di prefill URL agar terisi otomatis). Tempel URL ke tab Link Form (atau tombol Edit per baris). Nilai masuk ke guru via Form tersebut — tidak ada rekap pusat.

## 4. Verifikasi end-to-end (15 menit)

1. Login siswa NIS+password → token tersimpan (`tokens`, TTL 7200s). Network tab: semua request POST JSON berisi `token`.
2. Daftar mapel muncul (filter kelas + jadwal server). Input token mapel → `endTime` server tersimpan.
3. Heartbeat 20s jalan. Monitor admin update. KICK → siswa terlempar ≤20s.
4. Tab-switch 5x → lock → kode dari tab Kode Unlock (server) → `validateUnlock` → unlock.
5. Submit Form → klik SUDAH SUBMIT → `selesaiUjian`. Telat (>endTime+60s) → `Terlambat` badge amber.
6. 5x gagal login → `LOCKED` 5 menit (tabel `login_attempts`).
7. Bersihkan Log → `sessions.archived_at` terisi (arsip, bukan hapus).
8. `audit_logs` mencatat LOGIN/ADMIN_LOGIN/EXAM_START/FINISH/FORCE_LOGOUT/GENERATE/ARCHIVE.

## 5. APK (setelah web stabil)

1. `android/app/build.gradle`: `WEB_URL` = URL hosting langkah 2, `API_URL` = URL `backend/api.php`. Mode offline (biarkan `HOSTING-KAMU`): aset bawaan dibuka dengan `?api=API_URL` sehingga tetap menunjuk backend hosting.
2. `cp index.html android/app/src/main/assets/index.html` tiap update web.
3. Android Studio > Build APK. Instalasi normal (best-effort lock).

## Troubleshooting

| Gejala | Penyebab | Fix |
|---|---|---|
| `SQLSTATE` / blank JSON | Kredensial `config.php` salah | Samakan dengan cPanel |
| Loop reload | Token expired/hapus | Login ulang |
| Mapel tidak muncul | Status Nonaktif / di luar jadwal / kelas tak cocok | Cek jadwal + format kelas target |
| `Link soal belum ada` | `form_url` kosong | Isi via tab Link Form |
| `LOCKED` | Rate-limit | Tunggu 5 menit |
