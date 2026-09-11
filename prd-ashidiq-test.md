# Ashidiq Test

## Master Prompt — Sistem Ujian Android Lockdown + Web Admin

Kamu adalah senior software architect, Android engineer, backend engineer, database engineer, dan security engineer.

Bangun sistem ujian sekolah bernama **Ashidiq Test** dengan dua aplikasi yang HARUS dipisahkan dengan jelas:

1. **Ashidiq Test Android** — APK untuk siswa.
2. **Ashidiq Test Admin** — Web Admin untuk admin/guru.

Backend menjadi layanan bersama untuk keduanya dan ditempatkan di shared hosting.

Google Form tetap digunakan sebagai mesin soal dan dibuat oleh masing-masing guru menggunakan akun Google mereka.

---

# 1. Tujuan Sistem

Ashidiq Test digunakan untuk pelaksanaan ujian sekolah.

### APK Android siswa bertugas:

- Login siswa.
- Memvalidasi sesi ujian.
- Menampilkan ujian yang tersedia.
- Membuka Google Form yang ditentukan.
- Menjalankan exam/kiosk mode.
- Mencegah siswa keluar dari aplikasi selama ujian semaksimal mungkin.
- Mengirim heartbeat ke server.
- Menerima perintah force logout/reset dari server.
- Mengakhiri sesi setelah ujian selesai.

### Web Admin bertugas:

- Login admin/guru.
- Mengelola siswa.
- Mengelola guru.
- Mengelola ujian.
- Mengatur Google Form.
- Mengatur jadwal ujian.
- Monitoring siswa.
- Force logout.
- Reset sesi.
- Import hasil Google Form.
- Melihat rekap hasil.
- Melihat audit log.

---

# 2. Nama Produk

## Nama utama

**Ashidiq Test**

## Komponen

```text
Ashidiq Test Android
Ashidiq Test Admin
Ashidiq Test API
```

Contoh domain:

```text
https://test.domain-sekolah.sch.id
```

Contoh struktur:

```text
https://test.domain-sekolah.sch.id/admin
https://test.domain-sekolah.sch.id/api
```

Domain harus configurable dan jangan di-hardcode ke business logic.

---

# 3. Arsitektur

Gunakan arsitektur:

```text
                    INTERNET
                       │
                       ▼
              ┌─────────────────┐
              │ SHARED HOSTING  │
              │                 │
              │ PHP REST API    │
              │ MySQL/MariaDB   │
              │ Web Admin       │
              └────────┬────────┘
                       │
                 HTTPS REST API
                       │
             ┌─────────┴─────────┐
             ▼                   ▼
   ASHIDIQ TEST ANDROID   ASHIDIQ TEST ADMIN
          SISWA              ADMIN / GURU
             │
             ▼
       Google Forms
             │
             ▼
       Google Sheets
             │
             ▼
     Import / Integration
             │
             ▼
          Backend
```

Google Form bukan bagian dari backend.

Google Form tetap dibuat dan dimiliki oleh akun Google masing-masing guru.

---

# 4. Teknologi

## Android

Gunakan:

- Kotlin
- Android Studio
- Jetpack
- MVVM atau Clean Architecture
- Kotlin Coroutines
- Retrofit
- OkHttp
- WebView
- Android Lock Task Mode
- Android Device Owner/Dedicated Device jika perangkat sekolah memungkinkan
- Encrypted local storage
- Material Design

Gunakan minimum Android version yang realistis untuk perangkat target dan dokumentasikan pilihannya.

Jangan menggunakan library yang tidak diperlukan.

## Backend

Gunakan:

- PHP 8.2+
- MySQL/MariaDB
- REST API
- PDO
- Prepared statements
- `password_hash`
- `password_verify`
- Authentication middleware
- Authorization middleware

## Web Admin

Gunakan framework yang sesuai dengan kemampuan shared hosting.

Pilihan utama:

- Laravel jika hosting mendukung Composer dan resource yang diperlukan.

Jika hosting sangat terbatas:

- PHP MVC custom yang clean dan modular.

Frontend:

- HTML
- CSS
- JavaScript
- Bootstrap atau Tailwind

Prioritaskan reliability daripada penggunaan framework berlebihan.

---

# 5. Pemisahan Project

Gunakan struktur repository:

```text
ashidiq-test/
│
├── backend/
│   ├── api/
│   ├── config/
│   ├── database/
│   ├── modules/
│   ├── middleware/
│   ├── services/
│   ├── repositories/
│   └── tests/
│
├── web-admin/
│   ├── app/
│   ├── public/
│   ├── resources/
│   └── tests/
│
├── android/
│   ├── app/
│   └── ...
│
├── docs/
│   ├── architecture.md
│   ├── api.md
│   ├── database.md
│   ├── deployment.md
│   └── google-forms.md
│
└── README.md
```

Jangan mencampur:

- Android source code dengan backend.
- Web source code dengan Android.
- Business logic Android dengan business logic backend.

---

# 6. Role

Minimal:

```text
SUPER_ADMIN
ADMIN
GURU
SISWA
```

Gunakan RBAC.

## SUPER_ADMIN

Bisa:

- Semua data.
- Semua ujian.
- Semua siswa.
- Semua guru.
- Reset session.
- Force logout.
- Konfigurasi sistem.

## ADMIN

Bisa:

- Siswa.
- Guru.
- Ujian.
- Monitoring.
- Hasil.

## GURU

Bisa:

- Melihat ujian miliknya.
- Menghubungkan Google Form.
- Melihat hasil ujian miliknya.

Guru tidak boleh mengakses data ujian guru lain tanpa permission.

## SISWA

Hanya bisa:

- Login.
- Melihat ujian yang tersedia.
- Mengikuti ujian.
- Menyelesaikan ujian.
- Logout sesuai aturan sistem.

---

# 7. Database

Minimal buat tabel:

```text
users
roles
students
teachers
exams
exam_assignments
exam_sessions
devices
google_forms
exam_results
exam_result_details
activity_logs
refresh_tokens
settings
```

Gunakan:

- Primary key.
- Foreign key.
- Index.
- Unique constraint jika diperlukan.
- Transaction untuk operasi kritis.

Gunakan:

```text
created_at
updated_at
```

dan `deleted_at` hanya jika soft delete memang diperlukan.

Jangan menyimpan password plaintext.

---

# 8. Login Siswa

Flow:

```text
APK
 ↓
Username/NIS + Password
 ↓
POST /api/auth/login
 ↓
Backend validasi
 ↓
Validasi status siswa
 ↓
Validasi perangkat
 ↓
Return access token + session information
 ↓
APK masuk dashboard
```

Jangan menyimpan password di APK.

Gunakan token yang aman dan memiliki expiry.

Jika refresh token digunakan, simpan dengan aman.

---

# 9. Exam Session

Setiap siswa yang masuk ujian HARUS mempunyai record:

```text
exam_session
```

Minimal:

```text
id
exam_id
student_id
device_id
started_at
last_heartbeat_at
finished_at
status
```

Status:

```text
READY
ACTIVE
DISCONNECTED
FINISHED
FORCE_LOGOUT
RESET
EXPIRED
```

Satu siswa tidak boleh mempunyai dua session `ACTIVE` untuk ujian yang sama.

Backend harus menjadi source of truth.

Jangan mempercayai status dari APK tanpa validasi server.

---

# 10. Kiosk / Lockdown

Ini bagian paling penting.

Jangan hanya menggunakan WebView.

Gunakan:

```text
Android Lock Task Mode
```

Jika perangkat sekolah dikelola:

```text
Device Owner
+
Dedicated Device
+
Lock Task
```

Target:

- Home tidak dapat digunakan.
- Recent Apps tidak dapat digunakan.
- Back tidak dapat keluar dari ujian.
- Aplikasi lain tidak dapat dibuka.
- Browser lain tidak dapat dibuka.
- Notification access dibatasi jika konfigurasi perangkat memungkinkan.
- Screenshot/screen capture dicegah jika memungkinkan.
- APK tetap berada dalam exam mode.
- Status aplikasi dipantau.

**Catatan keamanan penting:**

Android APK biasa tidak dapat menjamin 100% anti-keluar pada semua HP.

Untuk lockdown kuat, perangkat harus dikonfigurasi sebagai dedicated/kiosk device.

Jangan pernah mengklaim keamanan 100% jika perangkat tidak dikelola sebagai kiosk/device owner.

---

# 11. WebView Security

WebView harus:

- HTTPS only.
- JavaScript hanya jika diperlukan.
- Block arbitrary navigation.
- Allowlist domain.
- Validasi URL.
- Cegah intent keluar ke aplikasi lain.
- Cegah membuka browser eksternal.
- Block unsupported schemes.
- Disable file access jika tidak diperlukan.
- Disable universal access from file URLs.
- Clear sensitive cache jika diperlukan.

Google Forms membutuhkan JavaScript, jadi jangan mematikannya secara membabi buta.

Allowlist hanya domain Google yang memang diperlukan oleh Forms.

Jangan membuat allowlist terlalu longgar seperti:

```text
*.com
*.net
```

---

# 12. Google Forms

Guru bebas membuat Google Form dari akun Google masing-masing.

Contoh:

```text
Guru A
→ Google Form Matematika

Guru B
→ Google Form Bahasa Indonesia

Guru C
→ Google Form IPA
```

Admin/Guru memasukkan:

```text
exam_id
google_form_url
```

ke sistem.

APK hanya membuka URL Google Form yang diberikan backend.

Jangan izinkan siswa memasukkan URL sendiri.

---

# 13. Identitas Siswa

Jangan hanya mengandalkan nama.

Gunakan:

```text
student_id
NIS
```

sebagai identifier utama.

Google Form sebaiknya memiliki field:

```text
NIS
Nama
Kelas
```

Matching hasil harus berdasarkan:

```text
NIS + exam_id
```

bukan berdasarkan nama.

Nama hanya digunakan sebagai display.

---

# 14. Heartbeat

APK mengirim heartbeat secara berkala:

```text
POST /api/exam/heartbeat
```

Rekomendasi interval:

```text
15–30 detik
```

Backend memperbarui:

```text
last_heartbeat_at
```

Admin dapat melihat:

```text
ONLINE
OFFLINE
```

berdasarkan timeout yang ditentukan server.

Jangan menganggap APK online hanya karena login berhasil.

---

# 15. Force Logout

Admin Web memiliki tombol:

```text
Force Logout
```

Flow:

```text
Backend
 ↓
session.status = FORCE_LOGOUT
 ↓
APK heartbeat
 ↓
APK menerima status
 ↓
hapus session
 ↓
keluar dari exam
```

Push notification boleh digunakan sebagai optimasi, tetapi sistem inti HARUS tetap berfungsi dengan polling heartbeat.

Jangan membuat sistem bergantung sepenuhnya pada Firebase.

---

# 16. Reset Ujian

Admin dapat melakukan:

```text
RESET
```

Sistem harus memastikan:

- Session lama invalid.
- Token/session lama tidak dapat digunakan untuk melanjutkan.
- Siswa dapat login kembali jika kebijakan ujian mengizinkan.
- Audit log tercatat.

Reset bukan sekadar menghapus row database.

Gunakan session/version/token invalidation yang benar.

---

# 17. Penyelesaian Ujian

Flow:

```text
Siswa mengerjakan Google Form
 ↓
Siswa submit Google Form
 ↓
Google Form menampilkan confirmation
 ↓
Siswa menyelesaikan sesi melalui APK
 ↓
POST /api/exam/finish
 ↓
Backend validasi session
 ↓
status = FINISHED
 ↓
Lockdown dilepas sesuai kebijakan
 ↓
Kembali ke login
```

Google Form tidak memberikan sinyal universal yang dapat dipercaya langsung ke APK untuk membuktikan submission.

Karena itu, jangan menganggap navigasi ke URL tertentu sebagai bukti submit tanpa mekanisme validasi yang sesuai.

Jika diperlukan validasi otomatis, gunakan Google Sheets + Apps Script.

---

# 18. Hasil Google Form

Gunakan pendekatan HYBRID.

## Cara 1 — Manual Import

Guru:

```text
Google Form
 ↓
Google Sheets
 ↓
Download XLSX/CSV
 ↓
Web Admin
 ↓
Upload
 ↓
Preview
 ↓
Confirm Import
```

Backend membaca:

```text
NIS
Nama
Kelas
Nilai
Timestamp
Jawaban jika tersedia
```

Kemudian menyimpan hasil.

## Cara 2 — Google Apps Script

Opsional:

```text
Google Forms
 ↓
Google Sheets
 ↓
Google Apps Script
 ↓
POST HTTPS API
 ↓
Backend
 ↓
exam_results
```

Manual import tetap tersedia sebagai fallback.

---

# 19. Import File

File CSV/XLSX yang diupload harus:

- Divalidasi.
- Dibatasi ukuran.
- Diperiksa extension.
- Diperiksa MIME.
- Tidak dieksekusi.
- Diproses dengan parser aman.
- Divalidasi kolom.
- Memiliki preview sebelum commit jika memungkinkan.

Jangan menyimpan file upload di public directory jika tidak diperlukan.

Tangani duplicate result dengan aturan yang jelas.

---

# 20. Admin Dashboard

Dashboard minimal:

```text
Total Siswa
Ujian Aktif
Sedang Ujian
Selesai
Terputus
Force Logout
```

Monitoring:

```text
Nama | NIS | Kelas | Ujian | Device | Status | Last Seen | Action
```

Action:

```text
View
Force Logout
Reset
```

---

# 21. Audit Log

Semua aktivitas penting dicatat.

Contoh:

```text
LOGIN
LOGIN_FAILED
EXAM_STARTED
HEARTBEAT
EXAM_FINISHED
FORCE_LOGOUT
RESET_SESSION
IMPORT_RESULT
ADMIN_LOGIN
PASSWORD_CHANGED
```

Minimal:

```text
user_id
action
exam_id
ip_address
user_agent
created_at
metadata
```

Jangan mencatat:

```text
password
access_token
refresh_token
```

---

# 22. Security Backend

WAJIB:

- HTTPS.
- PDO prepared statements.
- CSRF protection untuk web.
- Authentication middleware.
- Authorization middleware.
- RBAC.
- Rate limiting login.
- Password hashing.
- Input validation.
- Output escaping.
- Secure cookies.
- Token expiry.
- Server-side authorization.
- Audit logging.
- Upload validation.
- MIME validation.
- File size limit.
- SQL injection protection.
- XSS protection.
- IDOR protection.

Jangan percaya data kritis dari client seperti:

```text
student_id
exam_id
role
status
```

Semua harus diverifikasi backend.

---

# 23. API Contract

Semua API harus didokumentasikan.

## Siswa

```text
POST /api/auth/login
POST /api/auth/logout

GET  /api/exams
GET  /api/exams/{id}

POST /api/exams/{id}/start
POST /api/exams/{id}/heartbeat
POST /api/exams/{id}/finish

GET /api/session
```

## Admin

```text
GET  /api/admin/students
POST /api/admin/students
PUT  /api/admin/students/{id}

GET  /api/admin/teachers
POST /api/admin/teachers
PUT  /api/admin/teachers/{id}

GET  /api/admin/exams
POST /api/admin/exams
PUT  /api/admin/exams/{id}

POST /api/admin/sessions/{id}/force-logout
POST /api/admin/sessions/{id}/reset

POST /api/admin/results/import
GET  /api/admin/results
```

Response JSON konsisten:

```json
{
  "success": true,
  "message": "Success",
  "data": {}
}
```

Error:

```json
{
  "success": false,
  "message": "Unauthorized",
  "error_code": "UNAUTHORIZED"
}
```

Jangan mengembalikan stack trace ke client production.

---

# 24. Error Handling

Semua error harus memiliki:

- Human-readable message.
- Machine-readable error code.
- HTTP status yang benar.
- Server-side logging.

Android harus menangani:

```text
No Internet
Timeout
401
403
404
409
429
500
```

Jangan crash hanya karena server sedang down.

---

# 25. Offline Behavior

Jangan membuat APK menganggap offline sebagai kondisi aman.

Jika heartbeat gagal beberapa kali:

```text
OFFLINE / CONNECTION LOST
```

Tampilkan peringatan.

Kebijakan koneksi harus configurable.

Untuk keamanan tinggi:

```text
Jika koneksi hilang terlalu lama
→ blokir ujian
→ tunggu koneksi
```

Jangan membuat siswa dapat mematikan internet lalu bebas mengerjakan tanpa kontrol server.

---

# 26. Admin Web Security

Admin login harus terpisah dari login siswa.

Contoh:

```text
/admin
```

Admin session harus memiliki:

- Secure cookie.
- HttpOnly.
- SameSite.
- Timeout.
- CSRF protection.
- Role checking.

Jangan gunakan credential siswa untuk admin.

---

# 27. Configuration

Semua konfigurasi sensitif:

```text
DB_HOST
DB_NAME
DB_USER
DB_PASSWORD
APP_KEY
JWT_SECRET
```

disimpan dalam environment/config yang aman.

Jangan commit secret ke Git.

Sediakan:

```text
.env.example
```

Jangan commit:

```text
.env
```

---

# 28. Clean Code

WAJIB menerapkan:

- Single Responsibility Principle.
- Dependency Injection jika relevan.
- Repository/Service pattern jika relevan.
- DTO untuk API.
- Centralized error handling.
- Centralized API response.
- Constants untuk status.
- Tidak hardcode URL.
- Tidak hardcode credentials.
- Tidak duplikasi logic.
- Fungsi kecil dan jelas.
- Nama variable deskriptif.
- Komentar hanya untuk alasan/aturan bisnis yang tidak obvious.

Hindari:

```text
God class
God Activity
God Controller
God Function
```

Android Activity/Fragment tidak boleh berisi seluruh business logic.

---

# 29. Android Architecture

Gunakan boundary yang jelas:

```text
Presentation
    ↓
ViewModel
    ↓
Use Case
    ↓
Repository
    ↓
Remote Data Source
    ↓
REST API
```

WebView bukan tempat business logic.

Business rule kritis tetap berada di backend.

---

# 30. Database Transaction

Operasi kritis harus menggunakan transaction.

Contoh start exam:

```text
BEGIN
 ↓
check existing active session
 ↓
validate exam
 ↓
validate student
 ↓
create session
 ↓
COMMIT
```

Jika gagal:

```text
ROLLBACK
```

Harus aman dari race condition.

---

# 31. Testing

## Backend

Test:

- Login.
- Invalid login.
- Authorization.
- RBAC.
- Start exam.
- Duplicate session.
- Heartbeat.
- Finish exam.
- Force logout.
- Reset.
- Result import.
- Duplicate result.
- Invalid upload.

## Android

Test:

- Login.
- Token expiry.
- Network loss.
- WebView navigation.
- Back button.
- App lifecycle.
- Heartbeat.
- Force logout.
- Reset.
- Finish exam.
- Kiosk mode.

## Security

Test:

```text
SQL Injection
XSS
IDOR
Broken Authorization
Expired Token
Replay Request
Duplicate Session
Malformed Upload
Unauthorized Exam Access
```

---

# 32. Deployment Shared Hosting

Dokumentasikan:

```text
1. Create database
2. Create database user
3. Import migration
4. Upload backend
5. Configure environment
6. Configure PHP version
7. Configure document root
8. Enable HTTPS
9. Configure cron jika diperlukan
10. Test API
11. Deploy Web Admin
12. Build Android APK
13. Configure API base URL
```

Jangan membutuhkan Docker/VPS untuk deployment production karena target utama adalah shared hosting.

---

# 33. Android Build

Gunakan:

```text
debug
release
```

secara terpisah.

Release APK harus:

- Signed.
- Tidak berisi secret backend.
- API URL berasal dari build configuration.
- Debug logging dimatikan.
- Siap didistribusikan ke perangkat ujian.

---

# 34. Source of Truth

Aturan paling penting:

```text
SERVER = SOURCE OF TRUTH
APK = CLIENT
WEB = ADMIN CLIENT
GOOGLE FORM = ENGINE SOAL
GOOGLE SHEETS = SOURCE HASIL GOOGLE FORM
```

Business rule kritis tidak boleh hanya berada di APK.

---

# 35. Fase Pengerjaan

Kerjakan bertahap.

## PHASE 1
Architecture + database + API contract.

## PHASE 2
Backend authentication + RBAC.

## PHASE 3
Ashidiq Test Admin Web.

## PHASE 4
Ashidiq Test Android login + API.

## PHASE 5
Android WebView + Google Forms.

## PHASE 6
Kiosk / Lock Task.

## PHASE 7
Exam session + heartbeat.

## PHASE 8
Force logout + reset.

## PHASE 9
Google Forms result import.

## PHASE 10
Google Apps Script integration.

## PHASE 11
Testing + security audit.

## PHASE 12
Production deployment.

Jangan mengerjakan semua fase sekaligus tanpa testing.

---

# 36. Aturan Kerja AI Coding Agent

Sebelum menulis kode:

1. Analisis requirement.
2. Buat architecture.
3. Buat database schema.
4. Buat API contract.
5. Buat folder structure.
6. Identifikasi security risk.
7. Baru implementasi.

Setiap fase harus menghasilkan kode yang dapat dijalankan.

Jangan memberikan pseudo-code ketika diminta implementation.

Jangan menghapus kode existing tanpa alasan.

Jangan mengubah API contract tanpa mendokumentasikan perubahan.

Jika ada requirement ambigu, pilih solusi paling aman dan dokumentasikan asumsi tersebut.

Setelah implementasi setiap fitur:

```text
Implement
→ Build
→ Test
→ Fix errors
→ Review
→ Continue
```

Jangan mengatakan "sudah selesai" jika build/test belum dilakukan.

---

# 37. Definition of Done

Project hanya dianggap selesai jika:

```text
[ ] Backend berjalan
[ ] Database migration berhasil
[ ] Admin login berhasil
[ ] Guru login berhasil
[ ] Siswa login berhasil
[ ] RBAC berjalan
[ ] CRUD siswa berjalan
[ ] CRUD guru berjalan
[ ] CRUD ujian berjalan
[ ] Google Form dapat dikonfigurasi
[ ] APK dapat mengambil ujian
[ ] WebView Google Form berjalan
[ ] Kiosk mode berjalan pada perangkat target
[ ] Back tidak keluar dari ujian
[ ] Recent Apps dibatasi
[ ] Heartbeat berjalan
[ ] Admin dapat monitoring
[ ] Force logout berjalan
[ ] Reset berjalan
[ ] Finish exam berjalan
[ ] CSV/XLSX import berjalan
[ ] Duplicate result ditangani
[ ] Audit log berjalan
[ ] Error handling berjalan
[ ] Security test dilakukan
[ ] Release APK berhasil build
[ ] Shared hosting deployment berhasil
[ ] Dokumentasi lengkap
```

---

# 38. Aturan Kualitas

Prioritas:

```text
CORRECTNESS
SECURITY
RELIABILITY
MAINTAINABILITY
PERFORMANCE
UI/UX
```

Jangan mengejar banyak fitur jika fitur inti belum stabil.

Tidak boleh:

```text
hardcoded password
hardcoded database credentials
plaintext password
SQL string concatenation
unvalidated input
trust client role
trust client exam status
duplicate business logic
silent exception
```

Semua kode harus production-oriented, clean, modular, testable, dan mudah dirawat.

---

# 39. Instruksi Awal untuk AI

Mulai project **Ashidiq Test** dari:

1. Analisis requirement.
2. Buat architecture diagram.
3. Buat ERD/database schema.
4. Buat API specification.
5. Buat folder structure.
6. Buat security threat model.
7. Jelaskan keputusan teknologi.
8. Setelah itu implementasikan **PHASE 1 saja**.

Jangan langsung membuat seluruh aplikasi.

Setelah PHASE 1 selesai dan tervalidasi, lanjut ke PHASE 2.

Setiap fase harus menyebutkan:

```text
FILES CREATED
FILES MODIFIED
DATABASE CHANGES
API CHANGES
TESTS ADDED
TEST RESULTS
KNOWN LIMITATIONS
NEXT PHASE
```

Tujuan akhir:

**Ashidiq Test harus menjadi sistem ujian sekolah yang stabil, aman, mudah dirawat, kompatibel dengan shared hosting, mendukung Google Forms milik masing-masing guru, dan memiliki pemisahan yang jelas antara Android APK, Web Admin, Backend API, dan Google Forms.**
