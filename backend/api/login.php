<?php
require '../config.php';
$d = entrada();
$email = strtolower(trim($d['email'] ?? ''));
$clave = $d['password'] ?? '';
$u = fila('SELECT * FROM usuarios WHERE email=?', [$email]);
if (!$u || !$u['password_hash'] || !password_verify($clave, $u['password_hash'])) {
  responder(['error' => 'Correo o contraseña incorrectos'], 401);
}
if ($u['estado'] !== 'Activo') responder(['error' => 'Cuenta desactivada'], 403);
abrirSesion($u);
