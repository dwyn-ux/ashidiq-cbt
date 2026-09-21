<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function out($data): void { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $msg, string $kode = ''): void {
  $r = ['sukses' => false, 'pesan' => $msg];
  if ($kode) $r['kode'] = $kode;
  out($r);
}

$raw = file_get_contents('php://input');
$body = $raw ? (json_decode($raw, true) ?: []) : [];
$req = array_merge($_GET, $_POST, is_array($body) ? $body : []);
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || $raw !== '';
$action = (string)($req['action'] ?? '');

$MUST_POST = ['login','mulaiUjian','selesaiUjian','batalUjian','tambahData','bulkSantri','bulkMapel','updateSetting','forceLogout','kelolaSesi','bukaLogin','heartbeat','validateUnlock','logout','generateAllPasswords','generateAllTokens','setUnlockInterval','setViolationLimit','editMapel','clearLog','setFormUrl','detectFormEntries','getKelasList','cekSesiAktif'];
if (in_array($action, $MUST_POST, true) && !$isPost) fail('Gunakan POST.', 'METHOD_NOT_ALLOWED');

$ADMIN_ONLY = ['getDashboard','getSantriData','getAllMapel','generateAllPasswords','generateAllTokens','tambahData','bulkSantri','bulkMapel','updateSetting','forceLogout','kelolaSesi','bukaLogin','editMapel','getDokumenData','clearLog','getUnlockCode','setUnlockInterval','setViolationLimit','setFormUrl','detectFormEntries','getKelasList'];

function sess(string $token): ?array {
  if (!$token) return null;
  try { db()->exec('DELETE FROM tokens WHERE expires_at < NOW()'); } catch (Throwable $e) {}
  $st = db()->prepare('SELECT role, nis, nama, kelas FROM tokens WHERE token = ? AND expires_at > NOW()');
  $st->execute([$token]);
  $r = $st->fetch();
  return $r ?: null;
}

$s = ($action === 'login') ? null : sess((string)($req['token'] ?? ''));
if ($action !== 'login' && !$s) fail('Sesi habis, login ulang.', 'UNAUTHORIZED');
if (in_array($action, $ADMIN_ONLY, true) && !($s && in_array($s['role'], ['admin','proktor'], true))) fail('Akses ditolak.', 'FORBIDDEN');
if ($action === 'generateAllPasswords' || $action === 'generateAllTokens' || $action === 'tambahData' || $action === 'bulkSantri' || $action === 'bulkMapel' || $action === 'editMapel' || $action === 'updateSetting' || $action === 'clearLog') {
  if (($s['role'] ?? '') !== 'admin') fail('Khusus admin.', 'FORBIDDEN');
}
if ($action === 'logout') {
  $st = db()->prepare('DELETE FROM tokens WHERE token = ?');
  $st->execute([(string)($req['token'] ?? '')]);
  out(['sukses' => true]);
}

function setting(string $k, string $def = ''): string {
  $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
  $st->execute([$k]);
  $v = $st->fetchColumn();
  return ($v === false || $v === null || $v === '') ? $def : (string)$v;
}
function audit(?string $actor, string $action, string $detail = ''): void {
  try { db()->prepare('INSERT INTO audit_logs (actor, action, detail) VALUES (?,?,?)')->execute([$actor, $action, $detail]); } catch (Throwable $e) {}
}

// The student row serializes attempt mutations, including requests from other devices.
// Archived sessions remain part of an attempt: clearing the dashboard never grants a retry.
function lockStudentAttempt(PDO $db, string $nis): void {
  $db->beginTransaction();
  $st = $db->prepare('SELECT nis FROM students WHERE nis = ? FOR UPDATE');
  $st->execute([$nis]);
  if (!$st->fetch()) { $db->rollBack(); fail('Siswa tidak ditemukan.', 'INVALID_STUDENT'); }
}
function completedAttempt(PDO $db, string $nis, string $id): ?array {
  $st = $db->prepare("SELECT id, exam_id, end_ms, status FROM sessions WHERE nis = ? AND exam_id = ? AND status IN ('Selesai', 'Terlambat') ORDER BY id LIMIT 1");
  $st->execute([$nis, $id]);
  return $st->fetch() ?: null;
}
function openAttempt(PDO $db, string $nis, string $id): ?array {
  // Old installations may have duplicate rows; retain the earliest original timer.
  $st = $db->prepare("SELECT id, exam_id, end_ms, status FROM sessions WHERE nis = ? AND exam_id = ? AND status IN ('Sedang Mengerjakan', 'Di-Kick') ORDER BY id LIMIT 1");
  $st->execute([$nis, $id]);
  return $st->fetch() ?: null;
}
function sessionExamId(PDO $db, array $req, string $nis): string {
  $id = strtoupper(trim((string)($req['idUjian'] ?? '')));
  if ($id !== '') return $id;
  // Older clients sent only a subject name. Never guess between exams sharing a name.
  $sql = "SELECT DISTINCT exam_id FROM sessions WHERE nis = ? AND status IN ('Sedang Mengerjakan', 'Di-Kick', 'Selesai', 'Terlambat')";
  $args = [$nis];
  if (trim((string)($req['mapel'] ?? '')) !== '') { $sql .= ' AND mapel = ?'; $args[] = (string)$req['mapel']; }
  $st = $db->prepare($sql . ' LIMIT 2');
  $st->execute($args);
  $ids = $st->fetchAll(PDO::FETCH_COLUMN);
  if (count($ids) === 1) return (string)$ids[0];
  fail('ID ujian wajib dikirim. Muat ulang aplikasi lalu pilih ujian.', 'EXAM_ID_REQUIRED');
}

// ---------- auth ----------
function login(string $u, string $p, string $r) {
  $db = db();
  $key = ($r === 'admin' ? 'adm:' : 'sis:') . trim($u);
  $st = $db->prepare('SELECT fails, locked_until FROM login_attempts WHERE k = ?');
  $st->execute([$key]);
  $att = $st->fetch();
  if ($att && $att['locked_until'] && strtotime($att['locked_until']) > time()) fail('Terlalu banyak gagal. Coba lagi 5 menit.', 'LOCKED');
  $okFail = function() use ($db, $key, $att) {
    $n = (int)($att['fails'] ?? 0) + 1;
    $lock = $n >= 5 ? date('Y-m-d H:i:s', time() + 300) : null;
    $db->prepare('INSERT INTO login_attempts (k, fails, locked_until) VALUES (?,?,?) ON DUPLICATE KEY UPDATE fails = ?, locked_until = ?')->execute([$key, $n, $lock, $n, $lock]);
    return max(0, 5 - $n);
  };
  $ok = function() use ($db, $key) { $db->prepare('DELETE FROM login_attempts WHERE k = ?')->execute([$key]); };
  $mkToken = function(array $payload): string {
    $t = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO tokens (token, role, nis, nama, kelas, expires_at) VALUES (?,?,?,?,?,?)')->execute([$t, $payload['role'], $payload['nis'] ?? null, $payload['nama'] ?? null, $payload['kelas'] ?? null, date('Y-m-d H:i:s', time() + SESSION_TTL)]);
    return $t;
  };
  if ($r === 'admin') {
    $st = $db->prepare('SELECT username, pass_hash, role FROM users WHERE username = ?');
    $st->execute([trim($u)]);
    $row = $st->fetch();
    if ($row && password_verify($p, $row['pass_hash'])) {
      $ok();
      $nama = $row['role'] === 'admin' ? 'Super Administrator' : 'Proktor Ujian';
      audit($row['username'], 'ADMIN_LOGIN');
      out(['sukses' => true, 'role' => $row['role'], 'nama' => $nama, 'token' => $mkToken(['role' => $row['role'], 'nama' => $nama])]);
    }
    $sisa = $okFail();
    fail('Username / Password Admin/Proktor salah!' . ($sisa <= 2 ? " Sisa {$sisa}x." : ''));
  }
  $st = $db->prepare('SELECT nis, nama, kelas, pass FROM students WHERE nis = ?');
  $st->execute([trim($u)]);
  $row = $st->fetch();
  if ($row && hash_equals((string)$row['pass'], $p)) {
    $ok();
    $pl = ['role' => 'siswa', 'nis' => $row['nis'], 'nama' => $row['nama'], 'kelas' => $row['kelas']];
    audit($row['nis'], 'LOGIN');
    out(['sukses' => true, 'role' => 'siswa', 'nis' => $row['nis'], 'nama' => $row['nama'], 'kelas' => $row['kelas'], 'token' => $mkToken($pl)]);
  }
  $sisa = $okFail();
  fail('NIS / Password Siswa salah!' . ($sisa <= 2 ? " Sisa {$sisa}x." : ''));
}

// ---------- helpers ----------
// ponytail: suffix selain 1 huruf (IPA/IPS) dibuang; upgrade ke kolom jurusan bila perlu.
function normKelas(string $k): string {
  $k = strtoupper(trim($k));
  $k = preg_replace('/^KELAS\s+/', '', $k) ?? $k;
  $k = str_replace([' ', '.', '-', '_'], '', $k);
  // ponytail: tanpa word-boundary, 'XII' di 'XIII' ikut kepotong; aman utk tingkat valid 7-12.
  $map = ['XII' => '12', 'XI' => '11', 'IX' => '9', 'X' => '10', 'VIII' => '8', 'VII' => '7', 'VI' => '6'];
  foreach ($map as $r => $d) $k = preg_replace('/^' . $r . '(?=\d|[A-Z]|$)/', $d, $k) ?? $k;
  return $k;
}
function kelasCocok(string $target, string $siswa): bool {
  $target = normKelas($target);
  $siswa = normKelas($siswa);
  if ($target === '' || $target === 'SEMUA') return $target === 'SEMUA';
  if ($siswa === $target) return true;
  if (preg_match('/^[0-9]+$/', $target) && strpos($siswa, $target) === 0) {
    $sisa = substr($siswa, strlen($target));
    return $sisa === '' || preg_match('/^[A-Z]/', $sisa) === 1;
  }
  return false;
}
function kelasList(): array {
  $rows = db()->query('SELECT DISTINCT kelas FROM students ORDER BY kelas')->fetchAll(PDO::FETCH_COLUMN) ?: [];
  $out = [];
  foreach ($rows as $k) { $n = normKelas((string)$k); if ($n !== '' && !in_array($n, $out, true)) $out[] = $n; }
  sort($out);
  return $out;
}
function examWindow(?string $tgl, ?string $mulai, ?string $selesai): array {
  $tz = new DateTimeZone('Asia/Jakarta');
  $now = new DateTime('now', $tz);
  $d = ($tgl && $tgl !== '0000-00-00') ? $tgl : $now->format('Y-m-d');
  $m = $mulai ?: '00:00:00';
  $s = $selesai ?: '23:59:59';
  if (strlen($m) === 5) $m .= ':00';
  if (strlen($s) === 5) $s .= ':00';
  return [$now, new DateTime("$d $m", $tz), new DateTime("$d $s", $tz)];
}
function kodeFromSeed_(int $seed): string {
  $CH = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $code = ''; $n = abs($seed); $L = strlen($CH);
  for ($i = 0; $i < 6; $i++) { $code .= $CH[$n % $L]; $n = intdiv($n, $L) + ($n * 31 + 7); }
  return $code;
}
function unlockInterval(): int { $n = (int)setting('unlock_interval', '5'); return $n > 0 ? $n : 5; }
function unlockSeed(): int { return intdiv(time(), unlockInterval() * 60); }
function entryOK(string $v): bool { return $v === '' || preg_match('/^\d{4,12}$/', $v) === 1; }
function baseFormUrl(string $url): string {
  $p = parse_url(trim($url));
  if (!$p || strtolower((string)($p['host'] ?? '')) !== 'docs.google.com') return '';
  $path = $p['path'] ?? '';
  if (strpos($path, '/forms/') === false) return '';
  $m = [];
  if (!preg_match('#/forms/d(?:/e)?/([A-Za-z0-9_-]+)#', $path, $m)) return '';
  return 'https://docs.google.com/forms/d/e/' . $m[1] . '/viewform';
}
function isFormShortUrl(string $url): bool {
  $p = parse_url(trim($url));
  if (!$p || strtolower((string)($p['scheme'] ?? '')) !== 'https') return false;
  $host = strtolower((string)($p['host'] ?? ''));
  return in_array($host, ['forms.gle', 's.id', 'www.s.id'], true);
}
function resolveFormUrl(string $url): string {
  if (!function_exists('curl_init') || !isFormShortUrl($url)) return '';
  foreach ([true, false] as $headOnly) {
    $ch = curl_init($url);
    $opts = [CURLOPT_NOBODY => $headOnly, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'Mozilla/5.0'];
    if (!$headOnly) $opts[CURLOPT_RANGE] = '0-0';
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $eff = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    $base = baseFormUrl($eff);
    if ($base !== '') return $base;
  }
  return '';
}
function normalizeExamUrl(string $url): string {
  $url = trim($url);
  if ($url === '') return '';
  $p = parse_url($url);
  if (!$p || strtolower((string)($p['scheme'] ?? '')) !== 'https' || trim((string)($p['host'] ?? '')) === '') return '';
  if (baseFormUrl($url) !== '') return $url;
  if (isFormShortUrl($url)) return resolveFormUrl($url) ?: $url;
  return $url;
}
function buildPrefill(string $base, array $entries, array $vals): string {
  $q = [];
  $map = ['nis' => 'entry_nis', 'nama' => 'entry_nama', 'kelas' => 'entry_kelas', 'mapel' => 'entry_mapel'];
  foreach ($map as $vk => $ek) {
    $eid = (string)($entries[$ek] ?? '');
    $val = (string)($vals[$vk] ?? '');
    if ($eid !== '' && $val !== '') $q['entry.' . $eid] = $val;
  }
  if (!$q) return $base;
  return $base . (strpos($base, '?') === false ? '?' : '&') . 'usp=pp_url&' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
}
function detectEntries(string $url): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'Mozilla/5.0']);
  $html = (string)curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300 || $html === '') fail('Form tidak bisa dibaca. Pastikan link publik & hosting boleh akses google.com. Isi entry ID manual.');
  $found = ['entry_nis' => '', 'entry_nama' => '', 'entry_kelas' => '', 'entry_mapel' => ''];
  $labels = ['entry_nis' => ['nis', 'nim', 'nomor induk'], 'entry_nama' => ['nama lengkap', 'nama siswa', 'nama'], 'entry_kelas' => ['kelas'], 'entry_mapel' => ['mapel', 'mata pelajaran', 'pelajaran']];
  $low = mb_strtolower($html);
  preg_match_all('/entry\.(\d{4,12})/', $html, $m);
  $ids = array_values(array_unique($m[1] ?? []));
  foreach ($labels as $ek => $keys) {
    foreach ($ids as $id) {
      foreach ($keys as $k) {
        $pos = mb_strpos($low, $k);
        if ($pos === false) continue;
        $near = mb_substr($low, max(0, $pos - 3000), 6000);
        if (strpos($near, 'entry.' . $id) !== false) { $found[$ek] = $id; break 3; }
      }
    }
  }
  return $found;
}

try {
  switch ($action) {
    case 'login': login((string)($req['username'] ?? ''), (string)($req['password'] ?? ''), (string)($req['role'] ?? 'siswa'));

    case 'getDashboard': {
      $db = db();
      $ts = (int)$db->query('SELECT COUNT(*) FROM students')->fetchColumn();
      $tm = (int)$db->query("SELECT COUNT(*) FROM exams WHERE status = 'Aktif'")->fetchColumn();
      $rows = $db->query("SELECT nis, nama, mapel, status, started_at FROM sessions WHERE archived_at IS NULL ORDER BY id DESC LIMIT 50")->fetchAll();
      $logs = array_map(fn($r) => ['waktu' => date('H:i', strtotime($r['started_at'])), 'nis' => $r['nis'], 'nama' => $r['nama'], 'mapel' => $r['mapel'], 'status' => $r['status']], $rows);
      out(['totalSantri' => $ts, 'totalMapel' => $tm, 'logKehadiran' => $logs]);
    }

    case 'getSantriData': {
      $rows = db()->query('SELECT nis, nama, kelas, pass FROM students ORDER BY kelas, nama')->fetchAll();
      foreach ($rows as &$r) { $r['adaPass'] = ($r['pass'] ?? '') !== ''; }
      out(array_values($rows));
    }

    case 'getAllMapel': {
      try {
        $rows = db()->query('SELECT id, mapel, kelas_target AS kelas, token, status, form_url, entry_nis, entry_nama, entry_kelas, entry_mapel, tanggal AS tgl, DATE_FORMAT(mulai, "%H:%i") AS mulai, DATE_FORMAT(selesai, "%H:%i") AS selesai, durasi FROM exams ORDER BY tanggal DESC')->fetchAll();
      } catch (Throwable $e) {
        $rows = db()->query('SELECT id, mapel, kelas_target AS kelas, token, status, form_url, tanggal AS tgl, DATE_FORMAT(mulai, "%H:%i") AS mulai, DATE_FORMAT(selesai, "%H:%i") AS selesai, durasi FROM exams ORDER BY tanggal DESC')->fetchAll();
      }
      foreach ($rows as &$r) { $r['form_url'] = $r['form_url'] ?? ''; }
      out(array_values($rows));
    }

    case 'generateAllPasswords': {
      $n = 0;
      foreach (db()->query("SELECT nis FROM students WHERE pass IS NULL OR pass = ''")->fetchAll() as $r) {
        $c = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6);
        db()->prepare('UPDATE students SET pass = ? WHERE nis = ?')->execute([$c, $r['nis']]); $n++;
      }
      audit($s['nama'] ?? $s['nis'] ?? '', 'GENERATE_PASS', "$n dibuat");
      out(['sukses' => true, 'pesan' => "$n Password dibuat!"]);
    }

    case 'generateAllTokens': {
      $n = 0;
      foreach (db()->query("SELECT id FROM exams WHERE token IS NULL OR token = ''")->fetchAll() as $r) {
        $c = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 5);
        db()->prepare('UPDATE exams SET token = ? WHERE id = ?')->execute([$c, $r['id']]); $n++;
      }
      audit($s['nama'] ?? $s['nis'] ?? '', 'GENERATE_TOKEN', "$n dibuat");
      out(['sukses' => true, 'pesan' => "$n Token dibuat!"]);
    }

    case 'getKelasList': {
      out(['sukses' => true, 'kelas' => kelasList()]);
    }

    case 'getMapel': {
      $kelas = normKelas((string)($s['kelas'] ?? $req['kelas'] ?? ''));
      $nisQ = (string)($s['nis'] ?? $req['nis'] ?? '');
      $done = [];
      if ($nisQ !== '') {
        try { $done = db()->prepare("SELECT DISTINCT exam_id FROM sessions WHERE nis = ? AND status IN ('Selesai', 'Terlambat')"); $done->execute([$nisQ]); $done = $done->fetchAll(PDO::FETCH_COLUMN) ?: []; }
        catch (Throwable $e) { $done = []; }
      }
      $rows = db()->query("SELECT id, mapel, kelas_target, durasi, tanggal, mulai, selesai FROM exams WHERE status = 'Aktif'")->fetchAll();
      $out = [];
      $doneSet = array_flip(array_map('strval', $done));
      foreach ($rows as $r) {
        $targets = array_map(fn($k) => trim($k), explode(',', (string)$r['kelas_target']));
        $match = false;
        foreach ($targets as $t) if (kelasCocok($t, $kelas)) { $match = true; break; }
        if (!$match) continue;
        [$now, $mulai, $selesai] = examWindow($r['tanggal'], $r['mulai'], $r['selesai']);
        if (!($now >= $mulai && $now <= $selesai)) continue;
        $row = ['idUjian' => $r['id'], 'mapel' => $r['mapel'], 'durasi' => (int)$r['durasi']];
        if (isset($doneSet[(string)$r['id']])) $row['sudah'] = true;
        $out[] = $row;
      }
      out(array_values($out));
    }

    case 'mulaiUjian': {
      $db = db();
      $id = strtoupper(trim((string)($req['idUjian'] ?? '')));
      $st = $db->prepare('SELECT * FROM exams WHERE id = ?');
      $st->execute([$id]);
      $ex = $st->fetch();
      if (!$ex) fail('Ujian tidak ditemukan!');
      if ($ex['status'] !== 'Aktif') fail('Ujian dinonaktifkan!');
      if (strtoupper(trim((string)($req['inputToken'] ?? ''))) !== strtoupper(trim((string)$ex['token']))) fail('TOKEN SALAH!');
      [$now, $mulai, $selesai] = examWindow($ex['tanggal'], $ex['mulai'], $ex['selesai']);
      if ($now < $mulai) fail('Ujian belum dibuka! (Mulai: ' . $mulai->format('H:i') . ')');
      if ($now > $selesai) fail('Waktu ujian sudah habis! (Selesai: ' . $selesai->format('H:i') . ')');
      if (!$ex['form_url']) fail('Link soal belum ada!');
      $nis = (string)($s['nis'] ?? $req['nis'] ?? '');
      $nama = (string)($s['nama'] ?? $req['nama'] ?? '');
      $kelas = (string)($s['kelas'] ?? $req['kelas'] ?? '');
      $done0 = completedAttempt($db, $nis, $id);
      if ($done0) fail('Jatah 1x sudah dipakai. Ujian ini sudah selesai, tidak bisa diulang.', 'ALREADY_FINISHED');
      $stK = $db->prepare("SELECT id, end_ms FROM sessions WHERE exam_id = ? AND nis = ? AND status = 'Di-Kick' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
      $stK->execute([$id, $nis]);
      $kicked = $stK->fetch() ?: null;
      if ($kicked) {
        $inpK = strtoupper(trim((string)($req['reentryCode'] ?? '')));
        if ($inpK === '') fail('Akun ini dihentikan admin. Minta KODE UNLOCK ke pengawas untuk masuk lagi.', 'KICKED_REQUIRED');
        if (!($inpK === kodeFromSeed_(unlockSeed()) || $inpK === kodeFromSeed_(unlockSeed() - 1))) fail('KODE UNLOCK salah/kadaluarsa. Minta kode terbaru ke pengawas.', 'KICKED_INVALID');
        $nowMs = (int)(microtime(true) * 1000);
        $db->prepare('UPDATE sessions SET status = "Sedang Mengerjakan", last_seen = ? WHERE id = ?')->execute([$nowMs, $kicked['id']]);
        $st2 = $db->prepare('SELECT * FROM exams WHERE id = ?');
        $st2->execute([$id]);
        $ex = $st2->fetch();
        $mkLinkK = function() use ($ex, $nis, $nama, $kelas): string {
          $raw = (string)$ex['form_url'];
          if (strpos($raw, 'TEMPLATE_') !== false) {
            $link = str_replace('TEMPLATE_NIS', rawurlencode($nis), $raw);
            $link = str_replace('TEMPLATE_NAMA', rawurlencode($nama), $link);
            return str_replace('TEMPLATE_KELAS', rawurlencode($kelas), $link);
          }
          $base = baseFormUrl($raw);
          if ($base === '' && isFormShortUrl($raw)) $base = resolveFormUrl($raw);
          if ($base === '') return $raw;
          return buildPrefill($base, $ex, ['nis' => $nis, 'nama' => $nama, 'kelas' => $kelas, 'mapel' => (string)$ex['mapel']]);
        };
        audit($nis, 'EXAM_UNKICK', $id);
        out(['sukses' => true, 'link' => $mkLinkK(), 'mapel' => $ex['mapel'], 'durasi' => (int)$ex['durasi'], 'endTime' => (int)$kicked['end_ms']]);
      }
      // ✅ RE-ENTRY LOCK: app keluar saat ujian (Home/Overview/recent apps) → wajib kode unlock admin untuk lanjut
      if (!empty($req['reentryCode'])) {
        $inp = strtoupper(trim((string)$req['reentryCode']));
        if (!($inp === kodeFromSeed_(unlockSeed()) || $inp === kodeFromSeed_(unlockSeed() - 1))) fail('KODE ADMIN salah/kadaluarsa. Minta kode terbaru ke pengawas.', 'REENTRY_INVALID');
      } else {
        $stR = $db->prepare("SELECT id FROM sessions WHERE exam_id = ? AND nis = ? AND status = 'Sedang Mengerjakan' AND archived_at IS NULL LIMIT 1");
        $stR->execute([$id, $nis]);
        if ($stR->fetch()) fail('Ujian terkunci: terdeteksi keluar aplikasi saat ujian. Minta KODE ADMIN ke pengawas untuk melanjutkan.', 'REENTRY_REQUIRED');
      }
      $mkLink = function() use ($ex, $nis, $nama, $kelas): string {
        $raw = (string)$ex['form_url'];
        if (strpos($raw, 'TEMPLATE_') !== false) {
          $link = $raw;
          $link = str_replace('TEMPLATE_NIS', rawurlencode($nis), $link);
          $link = str_replace('TEMPLATE_NAMA', rawurlencode($nama), $link);
          $link = str_replace('TEMPLATE_KELAS', rawurlencode($kelas), $link);
          return $link;
        }
        $base = baseFormUrl($raw);
        if ($base === '' && isFormShortUrl($raw)) $base = resolveFormUrl($raw);
        if ($base === '') return $raw;
        return buildPrefill($base, $ex, ['nis' => $nis, 'nama' => $nama, 'kelas' => $kelas, 'mapel' => (string)$ex['mapel']]);
      };
      $st = $db->prepare("SELECT end_ms FROM sessions WHERE exam_id = ? AND nis = ? AND status = 'Sedang Mengerjakan' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
      $st->execute([$id, $nis]);
      if ($old = $st->fetch()) {
        out(['sukses' => true, 'link' => $mkLink(), 'mapel' => $ex['mapel'], 'durasi' => (int)$ex['durasi'], 'endTime' => (int)$old['end_ms']]);
      }
      $dur = (int)$ex['durasi'] > 0 ? (int)$ex['durasi'] : 90;
      $startMs = (int)(microtime(true) * 1000);
      $endMs = $startMs + $dur * 60 * 1000;
      $db->prepare('INSERT INTO sessions (exam_id, nis, nama, kelas, mapel, status, start_ms, end_ms, last_seen) VALUES (?,?,?,?,?,"Sedang Mengerjakan",?,?,?)')->execute([$id, $nis, $nama, $kelas, $ex['mapel'], $startMs, $endMs, $startMs]);
      $link = $mkLink();
      audit($nis, 'EXAM_START', $id);
      out(['sukses' => true, 'link' => $link, 'mapel' => $ex['mapel'], 'durasi' => $dur, 'endTime' => $endMs]);
    }

    case 'selesaiUjian': {
      $db = db();
      $nis = (string)($s['nis'] ?? $req['nis'] ?? '');
      $id = sessionExamId($db, $req, $nis);
      $st = $db->prepare("SELECT id, end_ms, status FROM sessions WHERE nis = ? AND exam_id = ? AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
      $st->execute([$nis, $id]);
      $row = $st->fetch();
      if (!$row) fail('Tidak ada sesi aktif untuk ujian ini.', 'NO_SESSION');
      if (in_array($row['status'], ['Selesai', 'Terlambat'], true)) fail('Jatah 1x sudah dipakai. Ujian ini sudah selesai.', 'ALREADY_FINISHED');
      $telat = false;
      $end = (int)($row['end_ms'] ?? 0);
      if ($end > 0 && (int)(microtime(true) * 1000) > $end + 60000) {
        $db->prepare('UPDATE sessions SET status = "Terlambat" WHERE id = ?')->execute([$row['id']]);
        $telat = true;
      } else {
        $db->prepare('UPDATE sessions SET status = "Selesai" WHERE id = ?')->execute([$row['id']]);
      }
      audit($nis, 'EXAM_FINISH', $id . ($telat ? ' TERLAMBAT' : ''));
      out($telat ? ['sukses' => true, 'terlambat' => true, 'pesan' => 'Waktu habis — tercatat TERLAMBAT.'] : ['sukses' => true]);
    }

    case 'batalUjian': {
      $db = db();
      $nis = (string)($s['nis'] ?? $req['nis'] ?? '');
      $id = strtoupper(trim((string)($req['idUjian'] ?? '')));
      if ($nis === '' || $id === '') fail('Sesi tidak valid.');
      $st = $db->prepare("SELECT id FROM sessions WHERE exam_id = ? AND nis = ? AND status = 'Sedang Mengerjakan' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
      $st->execute([$id, $nis]);
      $row = $st->fetch();
      if (!$row) fail('Tidak ada sesi persiapan aktif.');
      $db->prepare('UPDATE sessions SET status = "Dibatalkan", archived_at = NOW() WHERE id = ?')->execute([$row['id']]);
      audit($nis, 'EXAM_CANCEL', $id);
      out(['sukses' => true, 'pesan' => 'Sesi persiapan dibatalkan.']);
    }

    case 'heartbeat':
    case 'cekStatusSiswa': {
      $db = db();
      $nis = (string)($s['nis'] ?? $req['nis'] ?? '');
      if (($s['role'] ?? '') === 'siswa' && $s['nis'] !== $nis && $nis !== '') out(['sukses' => true, 'status' => 'Kicked']);
      $st = $db->prepare("SELECT id, end_ms, status FROM sessions WHERE nis = ? AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
      $st->execute([$nis]);
      $row = $st->fetch();
      if (!$row) out(['sukses' => true, 'status' => 'Aman']);
      if ($row['status'] === 'Di-Kick') out(['sukses' => true, 'status' => 'Kicked']);
      if ($row['status'] === 'Sedang Mengerjakan') {
        $db->prepare('UPDATE sessions SET last_seen = ? WHERE id = ?')->execute([(int)(microtime(true) * 1000), $row['id']]);
        out(['sukses' => true, 'status' => 'Aman', 'endTime' => (int)$row['end_ms']]);
      }
      out(['sukses' => true, 'status' => 'Aman']);
    }

    case 'forceLogout': {
      $db = db();
      $st = $db->prepare("UPDATE sessions SET status = 'Di-Kick' WHERE nis = ? AND mapel = ? AND status = 'Sedang Mengerjakan' AND archived_at IS NULL");
      $st->execute([(string)($req['nis'] ?? ''), (string)($req['mapel'] ?? '')]);
      if ($st->rowCount() > 0) { audit($s['nama'] ?? '', 'FORCE_LOGOUT', (string)($req['nis'] ?? '')); out(['sukses' => true, 'pesan' => 'Siswa berhasil dikeluarkan!']); }
      fail('Siswa tidak sedang ujian.');
    }

    case 'kelolaSesi': {
      $db = db();
      $nis = strtoupper(trim((string)($req['nis'] ?? '')));
      $mapel = trim((string)($req['mapel'] ?? ''));
      $aksi = strtolower(trim((string)($req['aksi'] ?? '')));
      if ($nis === '' || $mapel === '') fail('NIS dan mapel wajib diisi.');
      if (!in_array($aksi, ['kick', 'pulihkan', 'selesaikan', 'reset'], true)) fail('Aksi tidak dikenal.');
      $st = $db->prepare("SELECT id, exam_id, end_ms, status FROM sessions WHERE nis = ? AND mapel = ? AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
      $st->execute([$nis, $mapel]);
      $row = $st->fetch();
      if (!$row) fail('Tidak ada sesi aktif untuk siswa ini.');
      $id = (int)$row['id'];
      $actor = (string)($s['nama'] ?? $s['nis'] ?? 'admin');
      if ($aksi === 'kick') {
        if ($row['status'] !== 'Sedang Mengerjakan') fail('Hanya sesi Sedang Mengerjakan yang bisa di-kick.');
        $db->prepare("UPDATE sessions SET status = 'Di-Kick' WHERE id = ?")->execute([$id]);
        audit($actor, 'FORCE_LOGOUT', "$nis $mapel");
        out(['sukses' => true, 'pesan' => 'Siswa di-kick. Masuk lagi wajib kode unlock.']);
      }
      if ($aksi === 'pulihkan') {
        if (!in_array($row['status'], ['Di-Kick'], true)) fail('Hanya sesi Di-Kick yang bisa dipulihkan (= setujui lanjut).');
        $db->prepare("UPDATE sessions SET status = 'Sedang Mengerjakan', last_seen = ? WHERE id = ?")->execute([(int)(microtime(true) * 1000), $id]);
        audit($actor, 'SESSION_RESTORE', "$nis $mapel");
        out(['sukses' => true, 'pesan' => 'Sesi disetujui & dipulihkan. Siswa lanjut tanpa kode.']);
      }
      if ($aksi === 'selesaikan') {
        if (in_array($row['status'], ['Selesai', 'Terlambat'], true)) fail('Sesi sudah selesai.');
        $telat = (int)($row['end_ms'] ?? 0) > 0 && (int)(microtime(true) * 1000) > (int)$row['end_ms'] + 60000;
        $db->prepare($telat ? 'UPDATE sessions SET status = "Terlambat" WHERE id = ?' : 'UPDATE sessions SET status = "Selesai" WHERE id = ?')->execute([$id]);
        audit($actor, 'EXAM_FINISH_ADMIN', "$nis $mapel" . ($telat ? ' TERLAMBAT' : ''));
        out(['sukses' => true, 'pesan' => $telat ? 'Sesi diselesaikan (Terlambat).' : 'Sesi diselesaikan. Jatah 1x hangus.']);
      }
      $db->prepare('UPDATE sessions SET status = "Dibatalkan", archived_at = NOW() WHERE id = ?')->execute([$id]);
      audit($actor, 'SESSION_RESET', "$nis $mapel");
      out(['sukses' => true, 'pesan' => 'Sesi di-reset. Siswa dapat jatah baru dari awal.']);
    }

    case 'bukaLogin': {
      $db = db();
      $nis = strtoupper(trim((string)($req['nis'] ?? '')));
      if ($nis === '') fail('NIS wajib diisi.');
      $db->prepare('DELETE FROM login_attempts WHERE k = ?')->execute(['sis:' . $nis]);
      audit((string)($s['nama'] ?? $s['nis'] ?? 'admin'), 'LOGIN_UNLOCK', $nis);
      out(['sukses' => true, 'pesan' => "Login $nis dibuka. Siswa bisa login ulang."]);
    }

    case 'getUnlockCode': {
      $iv = unlockInterval();
      out(['sukses' => true, 'kode' => kodeFromSeed_(unlockSeed()), 'interval' => $iv, 'sisaDetik' => $iv * 60 - (time() % ($iv * 60))]);
    }
    case 'setUnlockInterval': {
      $n = (int)($req['interval'] ?? 0);
      if ($n < 1 || $n > 60) fail('Interval 1-60 menit.');
      db()->prepare('INSERT INTO settings (k, v) VALUES ("unlock_interval", ?) ON DUPLICATE KEY UPDATE v = ?')->execute([(string)$n, (string)$n]);
      out(['sukses' => true, 'pesan' => "Interval kode diset ke $n menit."]);
    }
    case 'getViolationLimit':
      out(['sukses' => true, 'limit' => max(1, (int)setting('violation_limit', '5'))]);
    case 'setViolationLimit': {
      $n = (int)($req['limit'] ?? 0);
      if ($n < 1 || $n > 20) fail('Batas 1-20.');
      db()->prepare('INSERT INTO settings (k, v) VALUES ("violation_limit", ?) ON DUPLICATE KEY UPDATE v = ?')->execute([(string)$n, (string)$n]);
      out(['sukses' => true, 'pesan' => "Batas pelanggaran diset ke {$n}x."]);
    }
    case 'validateUnlock': {
      $inp = strtoupper(trim((string)($req['code'] ?? '')));
      if ($inp === kodeFromSeed_(unlockSeed()) || $inp === kodeFromSeed_(unlockSeed() - 1)) out(['sukses' => true]);
      fail('Kode salah atau kadaluarsa.');
    }

    // ✅ Re-entry: cek apakah sesi ujian siswa masih aktif (untuk membuka/clear input kode admin di form login)
    case 'cekSesiAktif': {
      $db = db();
      $nis = (string)($s['nis'] ?? '');
      $st = $db->prepare("SELECT id FROM sessions WHERE nis = ? AND status = 'Sedang Mengerjakan' AND archived_at IS NULL LIMIT 1");
      $st->execute([$nis]);
      if (!$st->fetch()) out(['sukses' => true, 'aktif' => false]);
      $aktif = true;
      if (!empty($req['idUjian'])) {
        $st2 = $db->prepare("SELECT id FROM sessions WHERE exam_id = ? AND nis = ? AND status = 'Sedang Mengerjakan' AND archived_at IS NULL LIMIT 1");
        $st2->execute([(string)$req['idUjian'], $nis]);
        $aktif = (bool)$st2->fetch();
      }
      out(['sukses' => true, 'aktif' => $aktif]);
    }

    case 'tambahData': {
      $db = db();
      $sheet = (string)($req['sheet'] ?? '');
      $row = $req['dataArray'] ?? [];
      if (!is_array($row)) $row = explode(',', (string)$row);
      if ($sheet === 'DATA_SANTRI') {
        $row = array_slice(array_pad($row, 4, ''), 0, 4);
        $row[2] = normKelas((string)$row[2]);
        $db->prepare('INSERT INTO students (nis, nama, kelas, pass) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE nama = VALUES(nama), kelas = VALUES(kelas), pass = VALUES(pass)')->execute($row);
        out(['sukses' => true, 'pesan' => 'Berhasil disimpan!']);
      }
      if ($sheet === 'MAPEL_AKTIF') {
        $row = array_slice(array_pad($row, 10, ''), 0, 10);
        $row[9] = (int)$row[9] > 0 ? (int)$row[9] : 90;
        if (trim((string)$row[0]) === '' || trim((string)$row[1]) === '' || trim((string)$row[2]) === '') fail('ID, mapel, dan kelas target wajib diisi.');
        $parts = array_values(array_filter(array_map(fn($k) => normKelas($k), explode(',', (string)$row[2])), fn($v) => $v !== ''));
        $row[2] = in_array('SEMUA', $parts, true) ? 'SEMUA' : implode(',', $parts);
        if ($row[2] !== '' && $row[2] !== 'SEMUA') {
          $daftarS = kelasList();
          foreach (explode(',', $row[2]) as $satu) if (!in_array($satu, $daftarS, true)) fail("Kelas '$satu' tidak ada di data santri.");
        }
        if (trim((string)$row[3]) === '') $row[3] = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 5);
        foreach ([6, 7, 8] as $i) if (trim((string)$row[$i]) === '') $row[$i] = null;
        if ($row[6] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$row[6])) fail('Format tanggal harus YYYY-MM-DD.');
        foreach ([7, 8] as $i) if ($row[$i] !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string)$row[$i])) fail('Format jam harus HH:MM.');
        $db->prepare("INSERT INTO exams (id, mapel, kelas_target, token, status, form_url, tanggal, mulai, selesai, durasi) VALUES (?,?,?,?,?,?,NULLIF(?,''),NULLIF(?,''),NULLIF(?,''),?) ON DUPLICATE KEY UPDATE mapel = VALUES(mapel), kelas_target = VALUES(kelas_target), token = VALUES(token), status = VALUES(status), form_url = COALESCE(NULLIF(VALUES(form_url), ''), form_url), tanggal = COALESCE(VALUES(tanggal), tanggal), mulai = COALESCE(VALUES(mulai), mulai), selesai = COALESCE(VALUES(selesai), selesai), durasi = VALUES(durasi)")->execute($row);
        out(['sukses' => true, 'pesan' => 'Berhasil disimpan!']);
      }
      fail('Sheet tidak diizinkan.');
    }

    case 'bulkSantri': {
      $db = db();
      $rows = $req['rows'] ?? [];
      if (!is_array($rows)) fail('Format rows tidak valid.');
      if (!count($rows)) fail('Tidak ada data.');
      if (count($rows) > 500) fail('Maksimal 500 baris per upload.');
      $updFull = $db->prepare('UPDATE students SET nama = ?, kelas = ?, pass = ? WHERE nis = ?');
      $updKeep = $db->prepare('UPDATE students SET nama = ?, kelas = ? WHERE nis = ?');
      $ins = $db->prepare('INSERT INTO students (nis, nama, kelas, pass) VALUES (?,?,?,?)');
      $ok = 0; $skip = 0;
      $db->beginTransaction();
      try {
        foreach ($rows as $r) {
          if (!is_array($r)) { $skip++; continue; }
          $nis = strtoupper(trim((string)($r['nis'] ?? '')));
          $nama = trim((string)($r['nama'] ?? ''));
          $kelas = normKelas((string)($r['kelas'] ?? ''));
          $pass = trim((string)($r['pass'] ?? ''));
          if ($nis === '' || $nama === '' || $kelas === '') { $skip++; continue; }
          if (strlen($nis) > 32 || strlen($nama) > 128 || strlen($kelas) > 16 || strlen($pass) > 32) { $skip++; continue; }
          if ($pass === '') $updKeep->execute([$nama, $kelas, $nis]);
          else $updFull->execute([$nama, $kelas, $pass, $nis]);
          $n = $updFull->rowCount() + $updKeep->rowCount();
          if ($n === 0) {
            try { $ins->execute([$nis, $nama, $kelas, $pass]); }
            catch (Throwable $e) { $skip++; continue; }
          }
          $ok++;
        }
        $db->commit();
      } catch (Throwable $e) { $db->rollBack(); fail('Gagal bulk: ' . $e->getMessage()); }
      audit($s['nama'] ?? '', 'BULK_SANTRI', "$ok ok, $skip skip");
      out(['sukses' => true, 'pesan' => "Bulk santri: $ok tersimpan, $skip dilewati."]);
    }

    case 'bulkMapel': {
      $db = db();
      $rows = $req['rows'] ?? [];
      if (!is_array($rows)) fail('Format rows tidak valid.');
      if (!count($rows)) fail('Tidak ada data.');
      if (count($rows) > 500) fail('Maksimal 500 baris per upload.');
      $daftar = kelasList();
      $upd = $db->prepare("UPDATE exams SET mapel = ?, kelas_target = ?, token = COALESCE(NULLIF(?, ''), token), status = ?, tanggal = COALESCE(NULLIF(?, ''), tanggal), mulai = COALESCE(NULLIF(?, ''), mulai), selesai = COALESCE(NULLIF(?, ''), selesai), durasi = ? WHERE id = ?");
      $ins = $db->prepare("INSERT INTO exams (id, mapel, kelas_target, token, status, tanggal, mulai, selesai, durasi) VALUES (?,?,?,?,?,NULLIF(?,''),NULLIF(?,''),NULLIF(?,''),?)");
      $ok = 0; $skip = 0;
      $db->beginTransaction();
      try {
        $ln = 1;
        foreach ($rows as $r) {
          $ln++;
          if (!is_array($r)) { $skip++; continue; }
          $id = strtoupper(trim((string)($r['id'] ?? '')));
          $mapel = trim((string)($r['mapel'] ?? ''));
          $rawK = [(string)($r['kelas_1'] ?? ''), (string)($r['kelas_2'] ?? ''), (string)($r['kelas_3'] ?? '')];
          if (trim(implode('', $rawK)) === '' && isset($r['kelas_target'])) $rawK = array_merge(explode(',', (string)$r['kelas_target']), ['', '', '']);
          $ks = [];
          foreach (array_slice($rawK, 0, 3) as $rk) {
            $nk = normKelas($rk);
            if ($nk === '') continue;
            if ($nk !== 'SEMUA' && !in_array($nk, $daftar, true)) { $db->rollBack(); fail("Baris $ln: kelas '$rk' tidak ada di data santri. Pilih dari dropdown template."); }
            if (!in_array($nk, $ks, true)) $ks[] = $nk;
          }
          $kelas = in_array('SEMUA', $ks, true) ? 'SEMUA' : implode(',', $ks);
          $token = strtoupper(trim((string)($r['token'] ?? '')));
          $status = trim((string)($r['status'] ?? 'Aktif'));
          if ($status !== 'Aktif' && $status !== 'Nonaktif') { $db->rollBack(); fail("Baris $ln: status harus Aktif/Nonaktif (pilih dari dropdown)."); }
          $tgl = trim((string)($r['tanggal'] ?? ''));
          if ($tgl !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) $tgl = '';
          $mulai = trim((string)($r['mulai'] ?? ''));
          if ($mulai !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $mulai)) $mulai = '';
          $selesai = trim((string)($r['selesai'] ?? ''));
          if ($selesai !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $selesai)) $selesai = '';
          $dur = (int)($r['durasi'] ?? 90);
          if ($dur < 1 || $dur > 999) $dur = 90;
          if ($id === '' || $mapel === '' || $kelas === '') { $skip++; continue; }
          if (strlen($id) > 32 || strlen($mapel) > 128 || strlen($kelas) > 128 || strlen($token) > 16) { $skip++; continue; }
          if ($token === '') $token = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 5);
          $upd->execute([$mapel, $kelas, $token, $status, $tgl, $mulai, $selesai, $dur, $id]);
          if ($upd->rowCount() === 0) {
            try { $ins->execute([$id, $mapel, $kelas, $token, $status, ($tgl !== '' ? $tgl : null), ($mulai !== '' ? $mulai : null), ($selesai !== '' ? $selesai : null), $dur]); }
            catch (Throwable $e) { $skip++; continue; }
          }
          $ok++;
        }
        $db->commit();
      } catch (Throwable $e) { try { $db->rollBack(); } catch (Throwable $ignored) {} fail(strpos($e->getMessage(), 'Baris ') === 0 ? $e->getMessage() : 'Gagal bulk: ' . $e->getMessage()); }
      audit($s['nama'] ?? '', 'BULK_MAPEL', "$ok ok, $skip skip");
      out(['sukses' => true, 'pesan' => "Bulk mapel: $ok tersimpan, $skip dilewati."]);
    }

    case 'editMapel': {
      $db = db();
      $formInput = trim((string)($req['formUrl'] ?? ''));
      $formUrl = $formInput === '' ? '' : normalizeExamUrl($formInput);
      if ($formInput !== '' && $formUrl === '') fail('Link ujian harus menggunakan HTTPS yang valid.');
      $klsRaw = trim((string)($req['kelas'] ?? ''));
      $klsParts = $klsRaw === '' ? [] : array_map(fn($k) => normKelas($k), explode(',', $klsRaw));
      $klsParts = array_values(array_filter($klsParts, fn($v) => $v !== ''));
      $kls = in_array('SEMUA', $klsParts, true) ? 'SEMUA' : implode(',', $klsParts);
      if ($kls !== '' && $kls !== 'SEMUA') {
        $daftar = kelasList();
        foreach (explode(',', $kls) as $satu) if (!in_array($satu, $daftar, true)) fail("Kelas '$satu' tidak ada di data santri.");
      }
      $db->prepare('UPDATE exams SET mapel = COALESCE(NULLIF(?, ""), mapel), kelas_target = COALESCE(NULLIF(?, ""), kelas_target), token = COALESCE(NULLIF(?, ""), token), status = ?, tanggal = NULLIF(?, ""), mulai = NULLIF(?, ""), selesai = NULLIF(?, ""), durasi = ?, form_url = COALESCE(NULLIF(?, ""), form_url) WHERE id = ?')->execute([
        (string)($req['mapel'] ?? ''), $kls, (string)($req['examToken'] ?? ''),
        (string)($req['status'] ?? 'Aktif'), (string)($req['tgl'] ?? ''), (string)($req['mulai'] ?? ''), (string)($req['selesai'] ?? ''),
        (int)($req['durasi'] ?? 90), $formUrl, (string)($req['id'] ?? ''),
      ]);
      try {
        $ens = [trim((string)($req['entryNis'] ?? '')), trim((string)($req['entryNama'] ?? '')), trim((string)($req['entryKelas'] ?? '')), trim((string)($req['entryMapel'] ?? ''))];
        $has = isset($req['entryNis']) || isset($req['entryNama']) || isset($req['entryKelas']) || isset($req['entryMapel']);
        if ($has) {
          foreach ($ens as $v) if (!entryOK($v)) fail('Entry ID harus angka 4-12 digit atau kosong.');
          $db->prepare('UPDATE exams SET entry_nis = NULLIF(?, ""), entry_nama = NULLIF(?, ""), entry_kelas = NULLIF(?, ""), entry_mapel = NULLIF(?, "") WHERE id = ?')->execute([$ens[0], $ens[1], $ens[2], $ens[3], (string)($req['id'] ?? '')]);
        }
      } catch (Throwable $e) { if (strpos($e->getMessage(), 'Entry ID') !== false) fail($e->getMessage()); }
      out(['sukses' => true, 'pesan' => 'Jadwal Mapel (' . ($req['id'] ?? '') . ') berhasil diupdate!', 'formUrl' => $formUrl]);
    }

    case 'setFormUrl': {
      $idU = strtoupper(trim((string)($req['idUjian'] ?? '')));
      $formInput = trim((string)($req['formUrl'] ?? ''));
      if ($idU === '' || $formInput === '') fail('Isi ID ujian dan URL ujian!');
      $fromShort = isFormShortUrl($formInput);
      $rawUrl = normalizeExamUrl($formInput);
      if ($rawUrl === '') fail('Link ujian harus menggunakan HTTPS yang valid.');
      $ens = ['entry_nis' => trim((string)($req['entryNis'] ?? '')), 'entry_nama' => trim((string)($req['entryNama'] ?? '')), 'entry_kelas' => trim((string)($req['entryKelas'] ?? '')), 'entry_mapel' => trim((string)($req['entryMapel'] ?? ''))];
      foreach ($ens as $k => $v) if (!entryOK($v)) fail("Entry ID $k harus angka 4-12 digit atau kosong.");
      $cols = '';
      try { $cols = (string)db()->query('SELECT GROUP_CONCAT(COLUMN_NAME) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "exams"')->fetchColumn(); } catch (Throwable $e) {}
      if (strpos($cols, 'entry_nis') !== false) {
        db()->prepare('UPDATE exams SET form_url = ?, entry_nis = NULLIF(?, ""), entry_nama = NULLIF(?, ""), entry_kelas = NULLIF(?, ""), entry_mapel = NULLIF(?, "") WHERE id = ?')->execute([$rawUrl, $ens['entry_nis'], $ens['entry_nama'], $ens['entry_kelas'], $ens['entry_mapel'], $idU]);
      } else {
        db()->prepare('UPDATE exams SET form_url = ? WHERE id = ?')->execute([$rawUrl, $idU]);
      }
      audit($s['nama'] ?? $s['nis'] ?? '', 'SET_FORM', $idU);
      $isGoogleForm = baseFormUrl($rawUrl) !== '';
      $warn = $isGoogleForm ? ((array_sum(array_map(fn($v) => $v === '' ? 0 : 1, $ens)) === 0) ? ' — entry ID kosong, Form terbuka tanpa prefill.' : ' — prefill aktif.') : '';
      $prefix = ($fromShort && $rawUrl !== $formInput) ? 'Shortlink Google Form berhasil diubah ke URL asli. ' : '';
      out(['sukses' => true, 'pesan' => $prefix . 'Link ujian tersimpan' . $warn, 'formUrl' => $rawUrl]);
    }

    case 'detectFormEntries': {
      $idU = strtoupper(trim((string)($req['idUjian'] ?? '')));
      $st = db()->prepare('SELECT form_url FROM exams WHERE id = ?');
      $st->execute([$idU]);
      $ex = $st->fetch();
      if (!$ex || empty($ex['form_url'])) fail('Simpan link ujian dulu sebelum deteksi.');
      if (!function_exists('curl_init')) fail('cURL tidak tersedia di hosting. Isi entry ID manual.');
      $formUrl = baseFormUrl((string)$ex['form_url']);
      if ($formUrl === '' && isFormShortUrl((string)$ex['form_url'])) $formUrl = resolveFormUrl((string)$ex['form_url']);
      if ($formUrl === '') fail('Deteksi kolom hanya tersedia untuk Google Form. Link ujian tetap sudah tersimpan.');
      out(['sukses' => true, 'entries' => detectEntries($formUrl), 'pesan' => 'Deteksi selesai. Cek & simpan.']);
    }

    case 'updateSetting': {
      $db = db();
      foreach (['namaSekolah' => 'school_name', 'kepala' => 'head_name', 'nbm' => 'head_id'] as $in => $k) {
        if (isset($req[$in])) $db->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = ?')->execute([$k, (string)$req[$in], (string)$req[$in]]);
      }
      if (!empty($req['uProktor'])) {
        $db->prepare('UPDATE users SET username = ? WHERE role = "proktor"')->execute([(string)$req['uProktor']]);
        if (!empty($req['pProktor'])) $db->prepare('UPDATE users SET pass_hash = ? WHERE role = "proktor"')->execute([password_hash((string)$req['pProktor'], PASSWORD_DEFAULT)]);
      }
      out(['sukses' => true, 'pesan' => 'Update Setting Berhasil!']);
    }

    case 'getDokumenData': {
      $db = db();
      $rows = $db->query('SELECT id, mapel AS nama, kelas_target AS kelas, DATE_FORMAT(tanggal, "%Y-%m-%d") AS tgl, DATE_FORMAT(mulai, "%H:%i") AS mulai, DATE_FORMAT(selesai, "%H:%i") AS selesai FROM exams')->fetchAll();
      out([
        'settings' => ['sekolah' => setting('school_name', 'SMP UNGGULAN ASHIDIQ'), 'kepala' => setting('head_name', 'Nama Kepala Sekolah'), 'nbm' => setting('head_id', '-')],
        'mapel' => $rows,
        'santri' => $db->query('SELECT nis, nama, kelas FROM students ORDER BY kelas, nama')->fetchAll(),
      ]);
    }

    case 'clearLog': {
      $n = db()->exec('UPDATE sessions SET archived_at = NOW() WHERE archived_at IS NULL');
      audit($s['nama'] ?? '', 'ARCHIVE_LOG', "$n baris");
      out(['sukses' => true, 'pesan' => "Log diarsipkan ($n baris), layar dibersihkan."]);
    }

    default: fail('Aksi tidak dikenali.');
  }
} catch (Throwable $e) {
  fail('Error Server: ' . $e->getMessage());
}
