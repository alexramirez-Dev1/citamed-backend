<?php
// Configuración común: conexión PDO, respuestas JSON y validación del token Bearer.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

date_default_timezone_set('America/Lima');

// Client ID de tipo "Web" creado en Google Cloud Console (el mismo que usa la app como serverClientId).
const GOOGLE_CLIENT_ID = 'TU_CLIENT_ID_WEB.apps.googleusercontent.com';
const ROL_PACIENTE = 1;
const ROL_MEDICO = 2;
const ROL_ADMIN = 3;

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $host = 'sql.freedb.tech';
    $port = '3306';
    $dbname = 'freedb_luMBdvBM';
    $user = 'u_vBOYOQ';
    $pass = 'CTDzc2vYTCzM';

    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone = '-05:00'");
  }
  return $pdo;
}

function responder($datos, int $codigo = 200): void {
  http_response_code($codigo);
  echo json_encode($datos, JSON_UNESCAPED_UNICODE);
  exit;
}

function entrada(): array {
  return json_decode(file_get_contents('php://input'), true) ?? [];
}

function consulta(string $sql, array $params = []): array {
  $st = db()->prepare($sql);
  $st->execute($params);
  return $st->fetchAll();
}

function fila(string $sql, array $params = []): ?array {
  $st = db()->prepare($sql);
  $st->execute($params);
  $r = $st->fetch();
  return $r === false ? null : $r;
}

function ejecutar(string $sql, array $params = []): void {
  db()->prepare($sql)->execute($params);
}

// Crea la sesión de 7 días y arma la respuesta que consume la app tras login/registro/Google.
function abrirSesion(array $u): void {
  ejecutar('DELETE FROM sesiones WHERE expira_en < NOW()');
  $token = bin2hex(random_bytes(32));
  ejecutar('INSERT INTO sesiones (token, usuario_id, expira_en) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 7 DAY))', [$token, $u['id']]);
  responder(['token' => $token, 'usuario' => usuarioPublico($u)]);
}

function usuarioPublico(array $u): array {
  $medico = $u['rol_id'] == ROL_MEDICO ? fila('SELECT id FROM medicos WHERE usuario_id=?', [$u['id']]) : null;
  return [
    'id' => (int)$u['id'],
    'nombre' => $u['nombre'],
    'email' => $u['email'],
    'telefono' => $u['telefono'] ?? null,
    'dni' => $u['dni'] ?? null,
    'rol_id' => (int)$u['rol_id'],
    'estado' => $u['estado'],
    'medico_id' => $medico ? (int)$medico['id'] : null,
  ];
}

// Valida "Authorization: Bearer xxx". Si se indica $roles, solo esos roles pasan.
function usuarioActual(array $roles = []): array {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/Bearer\s+([a-f0-9]{64})/', $h, $m)) responder(['error' => 'No autenticado'], 401);
  $u = fila('SELECT u.* FROM sesiones s JOIN usuarios u ON u.id = s.usuario_id WHERE s.token=? AND s.expira_en > NOW()', [$m[1]]);
  if (!$u) responder(['error' => 'Sesión expirada'], 401);
  if ($u['estado'] !== 'Activo') responder(['error' => 'Cuenta desactivada'], 403);
  if ($roles && !in_array((int)$u['rol_id'], $roles, true)) responder(['error' => 'No tienes permiso para esta acción'], 403);
  return $u;
}

function notificar(int $usuarioId, string $titulo, string $mensaje): void {
  ejecutar('INSERT INTO notificaciones (usuario_id, titulo, mensaje) VALUES (?,?,?)', [$usuarioId, $titulo, $mensaje]);
}