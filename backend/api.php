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

$MUST_POST = ['login','mulaiUjian','selesaiUjian','tambahData','bulkSantri','bulkMapel','updateSetting','forceLogout','heartbeat','validateUnlock','logout','generateAllPasswords','generateAllTokens','setUnlockInterval','setViolationLimit','editMapel','clearLog','setFormUrl'];
if (in_array($action, $MUST_POST, true) && !$isPost) fail('Gunakan POST.', 'METHOD_NOT_ALLOWED');

$ADMIN_ONLY = ['getDashboard','getSantriData','getAllMapel','generateAllPasswords','generateAllTokens','tambahData','bulkSantri','bulkMapel','updateSetting','forceLogout','editMapel','getDokumenData','clearLog','getUnlockCode','setUnlockInterval','setViolationLimit','setFormUrl'];

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
function kelasCocok(string $target, string $siswa): bool {
  if (!$target) return false;
  if ($siswa === $target) return true;
  if (preg_match('/^[0-9]+$/', $target) && strpos($siswa, $target) === 0) {
    $sisa = substr($siswa, strlen($target));
    return $sisa === '' || preg_match('/^[A-Z]/', $sisa) === 1;
  }
  return false;
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
      $rows = db()->query('SELECT id, mapel, kelas_target AS kelas, token, status, form_url, tanggal AS tgl, DATE_FORMAT(mulai, "%H:%i") AS mulai, DATE_FORMAT(selesai, "%H:%i") AS selesai, durasi FROM exams ORDER BY tanggal DESC')->fetchAll();
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

    case 'getMapel': {
      $kelas = strtoupper(str_replace(' ', '', (string)($s['kelas'] ?? $req['kelas'] ?? '')));
      $rows = db()->query("SELECT id, mapel, kelas_target, durasi, tanggal, mulai, selesai FROM exams WHERE status = 'Aktif'")->fetchAll();
      $out = [];
      foreach ($rows as $r) {
        $targets = array_map(fn($k) => strtoupper(str_replace(' ', '', trim($k))), explode(',', (string)$r['kelas_target']));
        $match = false;
        foreach ($targets as $t) if (kelasCocok($t, $kelas)) { $match = true; break; }
        if (!$match) continue;
        [$now, $mulai, $selesai] = examWindow($r['tanggal'], $r['mulai'], $r['selesai']);
        if ($now >= $mulai && $now <= $selesai) $out[] = ['idUjian' => $r['id'], 'mapel' => $r['mapel'], 'durasi' => (int)$r['durasi']];
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
      $st = $db->prepare("SELECT end_ms FROM sessions WHERE exam_id = ? AND nis = ? AND status = 'Sedang Mengerjakan' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
      $st->execute([$id, $nis]);
      if ($old = $st->fetch()) {
        $link = (string)$ex['form_url'];
        $link = str_replace('TEMPLATE_NIS', rawurlencode($nis), $link);
        $link = str_replace('TEMPLATE_NAMA', rawurlencode($nama), $link);
        $link = str_replace('TEMPLATE_KELAS', rawurlencode($kelas), $link);
        out(['sukses' => true, 'link' => $link, 'mapel' => $ex['mapel'], 'durasi' => (int)$ex['durasi'], 'endTime' => (int)$old['end_ms']]);
      }
      $dur = (int)$ex['durasi'] > 0 ? (int)$ex['durasi'] : 90;
      $startMs = (int)(microtime(true) * 1000);
      $endMs = $startMs + $dur * 60 * 1000;
      $db->prepare('INSERT INTO sessions (exam_id, nis, nama, kelas, mapel, status, start_ms, end_ms, last_seen) VALUES (?,?,?,?,?,"Sedang Mengerjakan",?,?,?)')->execute([$id, $nis, $nama, $kelas, $ex['mapel'], $startMs, $endMs, $startMs]);
      $link = (string)$ex['form_url'];
      $link = str_replace('TEMPLATE_NIS', rawurlencode($nis), $link);
      $link = str_replace('TEMPLATE_NAMA', rawurlencode($nama), $link);
      $link = str_replace('TEMPLATE_KELAS', rawurlencode($kelas), $link);
      audit($nis, 'EXAM_START', $id);
      out(['sukses' => true, 'link' => $link, 'mapel' => $ex['mapel'], 'durasi' => $dur, 'endTime' => $endMs]);
    }

    case 'selesaiUjian': {
      $db = db();
      $nis = (string)($s['nis'] ?? $req['nis'] ?? '');
      $mapel = (string)($req['mapel'] ?? '');
      $st = $db->prepare("SELECT id, end_ms FROM sessions WHERE nis = ? AND mapel = ? AND status = 'Sedang Mengerjakan' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
      $st->execute([$nis, $mapel]);
      $row = $st->fetch();
      $telat = false;
      if ($row) {
        $end = (int)$row['end_ms'];
        if ($end > 0 && (int)(microtime(true) * 1000) > $end + 60000) {
          $db->prepare('UPDATE sessions SET status = "Terlambat" WHERE id = ?')->execute([$row['id']]);
          $telat = true;
        } else {
          $db->prepare('UPDATE sessions SET status = "Selesai" WHERE id = ?')->execute([$row['id']]);
        }
      } else {
        $db->prepare('INSERT INTO sessions (exam_id, nis, nama, kelas, mapel, status) VALUES ((SELECT id FROM exams WHERE mapel = ? LIMIT 1),?,?,?,?,"Selesai")')->execute([$mapel, $nis, (string)($s['nama'] ?? $req['nama'] ?? ''), (string)($s['kelas'] ?? $req['kelas'] ?? ''), $mapel]);
      }
      audit($nis, 'EXAM_FINISH', $mapel . ($telat ? ' TERLAMBAT' : ''));
      out($telat ? ['sukses' => true, 'terlambat' => true, 'pesan' => 'Waktu habis — tercatat TERLAMBAT.'] : ['sukses' => true]);
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

    case 'tambahData': {
      $db = db();
      $sheet = (string)($req['sheet'] ?? '');
      $row = $req['dataArray'] ?? [];
      if (!is_array($row)) $row = explode(',', (string)$row);
      if ($sheet === 'DATA_SANTRI') {
        $row = array_slice(array_pad($row, 4, ''), 0, 4);
        $db->prepare('INSERT INTO students (nis, nama, kelas, pass) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE nama = VALUES(nama), kelas = VALUES(kelas), pass = VALUES(pass)')->execute($row);
        out(['sukses' => true, 'pesan' => 'Berhasil disimpan!']);
      }
      if ($sheet === 'MAPEL_AKTIF') {
        $row = array_slice(array_pad($row, 10, ''), 0, 10);
        $row[9] = (int)$row[9] > 0 ? (int)$row[9] : 90;
        $db->prepare('INSERT INTO exams (id, mapel, kelas_target, token, status, form_url, tanggal, mulai, selesai, durasi) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE mapel = VALUES(mapel), kelas_target = VALUES(kelas_target), token = VALUES(token), status = VALUES(status), form_url = COALESCE(NULLIF(VALUES(form_url), ""), form_url), tanggal = NULLIF(VALUES(tanggal),""), mulai = NULLIF(VALUES(mulai),""), selesai = NULLIF(VALUES(selesai),""), durasi = VALUES(durasi)')->execute($row);
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
          $kelas = strtoupper(str_replace(' ', '', trim((string)($r['kelas'] ?? ''))));
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
      $upd = $db->prepare("UPDATE exams SET mapel = ?, kelas_target = ?, token = COALESCE(NULLIF(?, ''), token), status = ?, tanggal = COALESCE(NULLIF(?, ''), tanggal), mulai = COALESCE(NULLIF(?, ''), mulai), selesai = COALESCE(NULLIF(?, ''), selesai), durasi = ? WHERE id = ?");
      $ins = $db->prepare('INSERT INTO exams (id, mapel, kelas_target, token, status, tanggal, mulai, selesai, durasi) VALUES (?,?,?,?,?,?,?,?,?)');
      $ok = 0; $skip = 0;
      $db->beginTransaction();
      try {
        foreach ($rows as $r) {
          if (!is_array($r)) { $skip++; continue; }
          $id = strtoupper(trim((string)($r['id'] ?? '')));
          $mapel = trim((string)($r['mapel'] ?? ''));
          $kelas = strtoupper(str_replace(' ', '', trim((string)($r['kelas_target'] ?? ''))));
          $token = strtoupper(trim((string)($r['token'] ?? '')));
          $status = trim((string)($r['status'] ?? 'Aktif'));
          if ($status !== 'Aktif' && $status !== 'Nonaktif') $status = 'Aktif';
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
      } catch (Throwable $e) { $db->rollBack(); fail('Gagal bulk: ' . $e->getMessage()); }
      audit($s['nama'] ?? '', 'BULK_MAPEL', "$ok ok, $skip skip");
      out(['sukses' => true, 'pesan' => "Bulk mapel: $ok tersimpan, $skip dilewati."]);
    }

    case 'editMapel': {
      $db = db();
      $db->prepare('UPDATE exams SET mapel = COALESCE(NULLIF(?, ""), mapel), kelas_target = COALESCE(NULLIF(?, ""), kelas_target), token = COALESCE(NULLIF(?, ""), token), status = ?, tanggal = NULLIF(?, ""), mulai = NULLIF(?, ""), selesai = NULLIF(?, ""), durasi = ?, form_url = COALESCE(NULLIF(?, ""), form_url) WHERE id = ?')->execute([
        (string)($req['mapel'] ?? ''), (string)($req['kelas'] ?? ''), (string)($req['examToken'] ?? ''),
        (string)($req['status'] ?? 'Aktif'), (string)($req['tgl'] ?? ''), (string)($req['mulai'] ?? ''), (string)($req['selesai'] ?? ''),
        (int)($req['durasi'] ?? 90), (string)($req['formUrl'] ?? ''), (string)($req['id'] ?? ''),
      ]);
      out(['sukses' => true, 'pesan' => 'Jadwal Mapel (' . ($req['id'] ?? '') . ') berhasil diupdate!']);
    }

    case 'setFormUrl': {
      db()->prepare('UPDATE exams SET form_url = ? WHERE id = ?')->execute([(string)($req['formUrl'] ?? ''), strtoupper(trim((string)($req['idUjian'] ?? '')))]);
      out(['sukses' => true, 'pesan' => 'Link Form tersimpan.']);
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
