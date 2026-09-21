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

1. Edit `backend/config.php`: isi `DB_HOST, DB_NAME, DB_USER, DB_PASS` sesuai langkah 1. Isi `APP_KEY` dengan kunci acak 48-hex (contoh: `openssl rand -hex 24`), samakan ke `android/local.properties` (`APP_KEY=...`) lalu build ulang APK. Tanpa kunci cocok, login siswa ditolak (`APP_ONLY`); web hanya untuk admin/proktor. Zona waktu sudah `Asia/Jakarta` di file.
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

## 6. Mode halaman: HYBRID (default) / TWA

WebView bawaan app **tidak bisa** memakai login Google Chrome (cookie store terpisah per-app) dan Google memblokir sign-in di WebView (`disallowed_useragent`). Jadi akun Google tidak mungkin dipakai dari dalam WebView. Pilihannya di `build.gradle`:

```groovy
buildConfigField("String", "LAUNCH_MODE", "\"webview\"")   // default = HYBRID
```

### HYBRID (`webview`, dipakai sekarang)

Halaman ujian tetap di WebView dalam app → **lockdown utuh**: `FLAG_SECURE`, kunci JS `Android.setExamMode`, re-entry code, blokir Back, lock pelanggaran.

Yang dipinjam dari Chrome hanya **Google Form-nya**. Di layar ujian ada tombol **BUKA SOAL DI CHROME** → bridge `Android.openForm(url)` membuka Form lewat **Chrome Custom Tab**, jadi memakai profil & akun Google HP: tidak ada login/2FA lagi.

- Selama di Chrome, `formTab=true` mematikan sementara hitungan pelanggaran, re-entry lock, dan `bringBack()` — supaya siswa tidak dianggap keluar app.
- Saat siswa menekan **Back HP**, `kembaliDariFormChrome()` menyalakan kuncinya lagi dan meminta fullscreen.
- Kalau Form-nya sendiri tidak mewajibkan login, biarkan siswa pakai iframe di dalam app dan jangan pakai tombol Chrome — Custom Tab menampilkan address bar, jadi ada celah siswa mengetik URL lain.

> Gate "LANGKAH 1 — LOGIN GMAIL DULU" sudah **dihapus**. Gate itu mustahil dipenuhi di WebView (Google memblokir login di WebView), dan sekarang digantikan jalur Chrome di atas.

### TWA (`twa`)

Seluruh halaman dijalankan Chrome sebagai Trusted Web Activity. Ikut profil Chrome sepenuhnya, tapi kunci JS dan `FLAG_SECURE` hilang.

| | `webview` (HYBRID, default) | `twa` |
|---|---|---|
| Halaman ujian | WebView dalam app | Chrome |
| Form & login Google | Lewat tombol ke Chrome Custom Tab | Otomatis, seluruh app di Chrome |
| `FLAG_SECURE` anti screenshot | Aktif | Tidak aktif |
| Kunci JS (`Android.setExamMode`) | Ada | Tidak ada |
| Re-entry code | Ada | Tidak ada — harus divalidasi server |
| Perlu `assetlinks.json` | Tidak | Ya (kalau mau tanpa address bar) |

Ganti mode = ubah satu baris `LAUNCH_MODE` lalu build ulang.

### Menyalakan TWA penuh (tanpa address bar)

Kalau `assetlinks.json` belum terpasang, TWA otomatis turun jadi Custom Tab (masih profil Chrome, tapi muncul address bar). Untuk versi penuh:

1. Upload folder `.well-known/` dari repo ke **root subdomain** `cbt.smpmuashidiq.sch.id`, sejajar dengan `index.html`:
   ```
   https://cbt.smpmuashidiq.sch.id/.well-known/assetlinks.json
   ```
   Harus bisa dibuka langsung di browser dan bertipe `application/json` (bukan HTML). Redirect ke halaman lain = verifikasi gagal.
2. Fingerprint di file itu = keystore `ashidiq-release.jks` (dipakai APK testing lokal). Ambil ulang dengan:
   ```bash
   keytool -list -v -keystore ashidiq-release.jks -alias ashidiq
   ```
3. **Untuk APK dari Play Store**, tambahkan SHA-256 dari Play Console > app > Test and release > Setup > App integrity > App signing key certificate ke array `sha256_cert_fingerprints` (boleh lebih dari satu). Kalau tidak ditambah, APK Play Store hanya dapat Custom Tab + address bar.
4. Cek hasilnya: https://developers.google.com/digital-asset-links/tools/generator atau buka URL site dengan `?` — kalau masih ada address bar, `assetlinks.json` belum terbaca.

> Sebelum ujian: `LAUNCH_MODE=twa` belum pernah diuji di HP siswa. Kalau bermasalah, kembali ke `webview` dan build ulang — kode mode lama tetap utuh di `MainActivity.kt`.

### Kalau Form memang tidak boleh minta login

Cara paling murah (tanpa build APK, tanpa Chrome): buka Google Form > **Settings** > tab **Responses** → matikan **"Limit to 1 response"** dan pilih **"Collect email addresses" = Do not collect**. Setelah itu Form terbuka di iframe dalam app tanpa login sama sekali, dan semua kunci ujian tetap aktif.

## 7. Kunci penuh: Device Owner (blokir Home / app lain)

App biasa **tidak bisa** memblokir tombol Home, Recent Apps, atau app lain. Yang bisa hanya **Device Owner + Lock Task Mode**. Tanpa itu app hanya best-effort: siswa masih bisa keluar, tapi app balik sendiri dan wajib kode admin untuk masuk lagi.

### 7.1 Provisioning (sekali per HP, butuh kabel + adb)

HP wajib **belum punya akun** dan **belum punya device owner** saat di-provision. Kalau sudah dipakai siswa, factory reset dulu.

```bash
adb shell dpm set-device-owner id.sch.ashidiq.cbt/.AdminReceiver
```

Muncul `Success: Device owner set to package ComponentInfo{...}` = berhasil.

Urutannya: reset HP → pasang APK (jangan dibuka dulu) → jalankan perintah adb → buka app → **baru** login akun Google di Chrome. Akun Google boleh ditambah setelah provisioning — larangan "tanpa akun" hanya berlaku saat perintah `set-device-owner` dijalankan.

### 7.2 Verifikasi di app

Layar **DAFTAR UJIAN AKTIF** menampilkan status kunci:

- **🔒 KUNCI PENUH AKTIF** → device owner terpasang, Home/Recents/app lain diblokir selama ujian.
- **⚠ KUNCI TERBATAS — HP belum jadi device owner** → hanya kunci best-effort.

Jadikan ini checklist per tablet sebelum ujian dimulai.

### 7.3 Cara keluar (sesuai permintaan: wajib lewat kode admin)

Selama ujian, `Android.setExamMode(true)` memicu Lock Task Mode. Keluar hanya bisa lewat alur di dalam app:

- ujian selesai normal (`selesaiMengerjakan`), atau
- **BANTUAN / pintu darurat** + kode dari tab **Kode Unlock** admin, atau
- `batalUjian()`.

Chrome sudah dimasukkan ke `setLockTaskPackages(admin, [app, com.android.chrome])` supaya Lock Task tetap menahan siswa saat mereka mengerjakan Google Form di Chrome.

### 7.4 Batasan yang masih ada

Custom Tab Chrome menampilkan address bar — di dalam Chrome siswa masih bisa mengetik URL lain. Menutup celah ini butuh **URLBlocklist** lewat managed configuration Chrome (juga butuh device owner).

## Troubleshooting

| Gejala | Penyebab | Fix |
|---|---|---|
| Siswa bisa tekan Home / keluar ke app lain | HP belum device owner | Ikuti bagian 7 |
| Status kunci tetap "TERBATAS" padahal sudah adb | HP sudah punya akun/device owner saat provisioning | Factory reset, ulangi bagian 7.1 |
| Tombol BUKA SOAL DI CHROME tidak bereaksi | APK lama (tanpa `Android.openForm`) atau Chrome tidak terpasang | Install APK ≥ 1.7; pastikan Chrome ada di HP |
| Siswa kembali dari Chrome lalu langsung minta kode admin | `escape_pending` ter-set padahal siswa sengaja ke Chrome | Pastikan `formTab` di-set sebelum Custom Tab dibuka (APK ≥ 1.7) |
| Form di iframe minta login terus | WebView tidak punya sesi Google | Pakai tombol BUKA SOAL DI CHROME, atau matikan syarat login di Form |
| Mode TWA muncul address bar | `assetlinks.json` belum ada/salah | Ikuti bagian 6 |
| Mode TWA dan siswa keluar app tidak minta kode | Re-entry code masih di app | Validasi gap `sessions.last_seen` di server |
| Screenshot bisa diambil di mode TWA | `FLAG_SECURE` tidak berlaku untuk Chrome | Pindahkan deteksi ke server, atau pakai `webview` |
| `SQLSTATE` / blank JSON | Kredensial `config.php` salah | Samakan dengan cPanel |
| Loop reload | Token expired/hapus | Login ulang |
| Mapel tidak muncul | Status Nonaktif / di luar jadwal / kelas tak cocok | Cek jadwal + format kelas target |
| `Link soal belum ada` | `form_url` kosong | Isi via tab Link Form |
| `LOCKED` | Rate-limit | Tunggu 5 menit |
