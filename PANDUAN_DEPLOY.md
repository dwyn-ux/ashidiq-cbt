# Panduan Deploy — CBT Ashidiq (GAS + Hosting)

Sumber: `kode.gs.txt` (backend), `index.html` (frontend), `Database_CBT_Ashidiq.xlsx` (referensi struktur).

## A. Apps Script (wajib urut)

1. Buka Google Sheet database produksi di browser.
2. Set timezone: File > Settings > Time zone = **(GMT+07:00) Jakarta**. Parser waktu kode mengasumsikan WIB.
3. Extensions > Apps Script. Hapus isi `Code.gs`, paste penuh isi `kode.gs.txt` dari repo ini. Save (Ctrl+S), jalankan sekali fungsi `debugWaktu` untuk approve scope (Sheets, Forms, Drive, Cache, Properties).
4. Sheet `SETTING` — ganti password default SEKARANG:
   - `B4/B5` = user/pass admin (jangan `admin/123`, jangan `cbt_ashidiq/admin1234`)
   - `C6/C7` = user/pass proktor
   - `C8` = ID Master Nilai (jangan dihapus)
5. Sheet `LOG_KEHADIRAN` produksi — tambah header baris 1: `G=startMs`, `H=endMs`, `I=lastSeen`. File `Database_CBT_Ashidiq.xlsx` di repo sudah ditambah sekalian.
6. Sheet `LOG_ARSIP` tidak perlu dibuat manual — auto-create saat pertama klik Bersihkan Log. Jangan hapus manual tab ini.
7. Share spreadsheet: hanya owner + akun GAS. Jangan share "Anyone with link can edit". Editor sheet = bisa baca semua password plaintext.
8. Deploy > Manage deployments > Edit (ikon pensil) > **Version = New version** > Deploy. Wajib New version — save saja tidak update `/exec`.
   - Execute as: Me. Who has access: Anyone.
   - Salin URL `/exec` baru.
9. Batas bawaan (bisa diubah dari web admin, tersimpan di Script Properties):
   - `UNLOCK_INTERVAL` default 5 menit, `VIOLATION_LIMIT` default 5. Setting via tab Kode Unlock (POST `setUnlockInterval` / `setViolationLimit`).

## B. Regenerasi Form (wajib untuk match NIS)

Form lama (kolom F `MAPEL_AKTIF`) hanya punya `TEMPLATE_NAMA` + `TEMPLATE_KELAS`. Kode baru generate 3 field: `NIS` + `NAMA` + `KELAS` (`itemNis`, `kode.gs.txt:441`) dan prefill `TEMPLATE_NIS` (`kode.gs.txt:869`).

1. Di web admin > Generate Form > generate ulang tiap mapel aktif.
2. Link baru otomatis mengandung `TEMPLATE_NIS`. Link lama tetap jalan tapi deteksi submit fallback ke nama fuzzy (tidak disarankan).
3. Batas upload CSV: maks 200 soal / 512KB, 11 kolom, sel diawali `= + - @` otomatis diprefix `'` anti formula-injection.

## C. Hosting (index.html)

1. Buka `index.html` baris 598, ganti `API_URL` dengan URL `/exec` dari langkah A8.
2. Upload **satu file** `index.html` ke hosting via HTTPS, misal `https://domain-sekolah.sch.id/cbt/index.html`. Tidak ada build step (Tailwind + FontAwesome via CDN).
3. Logo dimuat dari `https://smpmuashidiq.sch.id/assets/logo%20smp.png` — pastikan domain itu boleh diakses (atau ganti ke file lokal).
4. Cache-busting tiap update: akses dengan `?v=2`, atau rename file. APK memuat `WEB_URL`, jadi update hosting otomatis kebawa ke APK (kecuali mode aset offline).
5. Wajib HTTPS. APK menolak `http` (`MIXED_CONTENT_NEVER_ALLOW` + allowlist https-only).

## D. Verifikasi end-to-end (15 menit)

1. Login siswa (NIS+password) → dapat `token` (CacheService TTL 7200s). Cek DevTools Network: semua request POST JSON berisi `token`.
2. Daftar mapel muncul (filter kelas + jadwal server). Input token mapel → `endTime` dari server tersimpan di `sessionStorage cbt_timer_end`.
3. Selama ujian: heartbeat tiap 20s (`heartbeat`). Admin Live Monitor harus lihat status update. Klik KICK → siswa dapat alert DIBERHENTIKAN dalam ≤20s.
4. Lock: tab-switch 5x (default) → lock-screen → minta kode ke admin (tab Kode Unlock, dari server `getUnlockCode`) → `validateUnlock` → unlock.
5. Submit Form → overlay SELESAI → `selesaiUjian`. Telat (>endTime+60s) → status `Terlambat` (badge amber), notice siswa "tercatat TERLAMBAT".
6. Gagal 5x login → `LOCKED` 5 menit. Tunggu, jangan deploy ulang (CacheService ikut ke-reset).
7. Bersihkan Log → cek tab `LOG_ARSIP` bertambah, layar kosong.
8. Kalau loop reload terus = token invalid (`UNAUTHORIZED`) — login ulang. Kalau "Gunakan POST" = deployment lama — ulangi A8.

## E. APK (setelah web stabil)

1. `android/app/build.gradle:11-12`: `API_URL` = URL exec (tidak dipakai langsung WebView tapi cadangan), `WEB_URL` = URL hosting langkah C2. Biarkan `HOSTING-KAMU` = pakai `android/app/src/main/assets/index.html` (sudah disync dari `index.html` terbaru).
2. Tiap update `index.html` hosting: `cp index.html android/app/src/main/assets/index.html` ulang.
3. Buka folder `android/` di Android Studio > Build > Build APK. Instalasi normal, tanpa reset HP (best-effort lock: immersive + FLAG_SECURE + allowlist + bridge `Android.setExamMode` dipanggil dari `paksaFullscreen`/`lepasKunciAPK`).

## Troubleshooting

| Gejala | Penyebab | Fix |
|---|---|---|
| Heartbeat/unlock/token gagal semua | Deploy lama, belum New version | Ulangi A8 |
| Timer null / tidak jalan | Sesi mulai sebelum patch timer-server | Mulai ujian ulang, `endTime` baru dari server |
| Submit tak terdeteksi | Form lama tanpa kolom NIS | Regenerasi (bagian B) |
| `LOCKED` terus | Rate-limit CacheService | Tunggu 5 menit |
| Password terlihat di sheet | By design (cetak kartu) | Batasi editor sheet, ganti berkala via Auto Generate |
