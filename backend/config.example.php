<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');

// Salin file ini menjadi config.php lalu isi sesuai cPanel > MySQL Databases.
const DB_HOST = 'localhost';
const DB_NAME = 'cbt_ashidiq';
const DB_USER = 'isi-user-db';
const DB_PASS = 'isi-password-kuat';
const SESSION_TTL = 7200;

function db(): PDO {
  static $p = null;
  if ($p) return $p;
  $p = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $p;
}
