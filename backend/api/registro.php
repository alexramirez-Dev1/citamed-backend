<?php
// Solo registra pacientes; médicos y admins los crea el administrador.
require '../config.php';
$d = entrada();
$nombre = trim($d['nombre'] ?? '');
$email = strtolower(trim($d['email'] ?? ''));
$clave = $d['password'] ?? '';
$telefono = trim($d['telefono'] ?? '');
$dni = trim($d['dni'] ?? '');
if ($nombre === '' || mb_strlen($nombre) > 100) responder(['error' => 'Nombre inválido'], 422);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) responder(['error' => 'Correo inválido'], 422);
if (strlen($clave) < 8) responder(['error' => 'La contraseña debe tener al menos 8 caracteres'], 422);
if ($dni !== '' && !preg_match('/^\d{8}$/', $dni)) responder(['error' => 'El DNI debe tener 8 dígitos'], 422);
if (fila('SELECT id FROM usuarios WHERE email=?', [$email])) responder(['error' => 'Ese correo ya está registrado'], 409);
ejecutar('INSERT INTO usuarios (nombre,email,password_hash,telefono,dni,rol_id) VALUES (?,?,?,?,?,?)',
  [$nombre, $email, password_hash($clave, PASSWORD_DEFAULT), $telefono ?: null, $dni ?: null, ROL_PACIENTE]);
$u = fila('SELECT * FROM usuarios WHERE id=?', [db()->lastInsertId()]);
abrirSesion($u);
