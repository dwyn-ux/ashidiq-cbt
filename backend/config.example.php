<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');

// Salin file ini menjadi config.php lalu isi sesuai cPanel > MySQL Databases.
const DB_HOST = 'localhost';
const DB_NAME = 'cbt_ashidiq';
const DB_USER = 'isi-user-db';
const DB_PASS = 'isi-password-kuat';
const SESSION_TTL = 7200;
// Kunci wajib aplikasi siswa — samakan dengan APP_KEY di android/app/build.gradle.
// Login/aksi siswa tanpa kunci ini ditolak; web hanya untuk admin.
const APP_KEY = 'isi-kunci-acak-min-16-karakter-sama-dengan-apk';

function db(): PDO {
  static $p = null;
  if ($p) return $p;
  $p = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $p;
}
