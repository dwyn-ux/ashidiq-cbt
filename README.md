# Ashidiq CBT — monorepo

Backend PHP + MySQL, web admin/siswa satu file, APK Android wrapper.

## Struktur

```text
index.html
backend/api.php
backend/config.example.php
backend/schema.sql
backend/seed.sql
android/
PANDUAN_DEPLOY_SQL.md
prd-ashidiq-test.md
```

## Deploy singkat (SQL mode)

1. cPanel > buat DB + user. phpMyAdmin > import `backend/schema.sql` lalu `backend/seed.sql`.
2. Salin `backend/config.example.php` jadi `backend/config.php` di hosting, isi kredensial. `config.php` tidak masuk git.
3. Upload `/cbt/index.html`, `/cbt/backend/api.php`, `/cbt/backend/config.php`. Wajib HTTPS.
4. Login `admin / admin123`, ganti kredensial, isi santri + jadwal + link Form.
5. Verifikasi e2e 15 menit di `PANDUAN_DEPLOY_SQL.md`.

Akun awal: `admin / admin123`, `proktor / proktor123`. Ganti setelah login pertama.

## Android

Nunggu web stabil dulu. Isi `WEB_URL` + `API_URL` final di `android/app/build.gradle`, sync `cp index.html android/app/src/main/assets/index.html`, baru Build APK di Android Studio.
