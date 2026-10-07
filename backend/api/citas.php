<?php
// GET ?vista=hoy|proximas|historial&estado=  |  POST (paciente reserva)  |  PUT ?id= (cambia estado)
require '../config.php';
require '../slots.php';
$u = usuarioActual();
$rol = (int)$u['rol_id'];
$metodo = $_SERVER['REQUEST_METHOD'];

const SQL_CITAS = "SELECT c.id, c.paciente_id, up.nombre AS paciente, c.medico_id, um.nombre AS medico,
    e.nombre AS especialidad, e.icono, m.cmp, c.fecha_cita AS fecha,
    TIME_FORMAT(c.hora_cita,'%H:%i') AS hora, c.estado
  FROM citas c
  JOIN usuarios up ON up.id = c.paciente_id
  JOIN medicos m ON m.id = c.medico_id
  JOIN usuarios um ON um.id = m.usuario_id
  JOIN especialidades e ON e.id = m.especialidad_id";

function miMedicoId(array $u): ?int {
  $m = fila('SELECT id FROM medicos WHERE usuario_id=?', [$u['id']]);
  return $m ? (int)$m['id'] : null;
}

if ($metodo === 'GET') {
  $where = []; $params = [];
  if ($rol === ROL_PACIENTE) { $where[] = 'c.paciente_id=?'; $params[] = $u['id']; }
  if ($rol === ROL_MEDICO) { $where[] = 'c.medico_id=?'; $params[] = miMedicoId($u) ?? 0; }

  $inicioCita = "TIMESTAMP(c.fecha_cita, c.hora_cita)";
  switch ($_GET['vista'] ?? '') {
    case 'hoy':
      $where[] = 'c.fecha_cita = CURDATE()'; break;
    case 'proximas':
      // El médico ya tiene su pestaña "Hoy"; el paciente ve todo lo que aún no ocurrió.
      $where[] = "c.estado IN ('Pendiente','Confirmada')";
      $where[] = $rol === ROL_MEDICO ? 'c.fecha_cita > CURDATE()' : "$inicioCita >= NOW()"; break;
    case 'historial':
      $where[] = "(c.estado IN ('Atendida','Cancelada') OR $inicioCita < NOW())"; break;
  }
  if (in_array($_GET['estado'] ?? '', ['Pendiente', 'Confirmada', 'Atendida', 'Cancelada'], true)) {
    $where[] = 'c.estado=?'; $params[] = $_GET['estado'];
  }
  $sql = SQL_CITAS . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.fecha_cita, c.hora_cita';
  $filas = consulta($sql, $params);
  foreach ($filas as &$f) { $f['id'] = (int)$f['id']; $f['paciente_id'] = (int)$f['paciente_id']; $f['medico_id'] = (int)$f['medico_id']; }
  responder($filas);
}

if ($metodo === 'POST') {
  if ($rol !== ROL_PACIENTE) responder(['error' => 'Solo los pacientes reservan citas'], 403);
  $d = entrada();
  $medicoId = (int)($d['medico_id'] ?? 0);
  $fecha = $d['fecha'] ?? '';
  $hora = $d['hora'] ?? '';
  if (!$medicoId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !preg_match('/^\d{2}:\d{2}$/', $hora)) {
    responder(['error' => 'Datos de la cita inválidos'], 422);
  }
  $m = fila("SELECT m.usuario_id FROM medicos m JOIN usuarios u ON u.id=m.usuario_id WHERE m.id=? AND u.estado='Activo'", [$medicoId]);
  if (!$m) responder(['error' => 'El médico no está disponible'], 404);

  $libre = array_filter(slotsDelDia($medicoId, $fecha), fn($s) => $s['hora'] === $hora && $s['estado'] === 'libre');
  if (!$libre) responder(['error' => 'Ese horario ya no está disponible'], 409);

  try {
    ejecutar('INSERT INTO citas (paciente_id, medico_id, fecha_cita, hora_cita) VALUES (?,?,?,?)', [$u['id'], $medicoId, $fecha, $hora]);
  } catch (PDOException $e) {
    responder(['error' => 'No se pudo registrar la cita'], 500);
  }
  $id = (int)db()->lastInsertId();
  notificar((int)$m['usuario_id'], 'Nueva cita', "{$u['nombre']} reservó para el $fecha a las $hora.");
  responder(fila(SQL_CITAS . ' WHERE c.id=?', [$id]), 201);
}

if ($metodo === 'PUT') {
  $id = (int)($_GET['id'] ?? 0);
  $nuevo = entrada()['estado'] ?? '';
  $cita = fila(SQL_CITAS . ' WHERE c.id=?', [$id]);
  if (!$cita) responder(['error' => 'Cita no encontrada'], 404);

  $permitidos = match ($rol) {
    ROL_PACIENTE => (int)$cita['paciente_id'] === (int)$u['id'] ? ['Cancelada'] : [],
    ROL_MEDICO => (int)$cita['medico_id'] === miMedicoId($u) ? ['Confirmada', 'Atendida', 'Cancelada'] : [],
    default => ['Pendiente', 'Confirmada', 'Atendida', 'Cancelada'],
  };
  if (!in_array($nuevo, $permitidos, true)) responder(['error' => 'No puedes cambiar la cita a ese estado'], 403);
  if (in_array($cita['estado'], ['Atendida', 'Cancelada'], true) && $rol !== ROL_ADMIN) {
    responder(['error' => 'Esta cita ya está cerrada'], 409);
  }
  ejecutar('UPDATE citas SET estado=? WHERE id=?', [$nuevo, $id]);

  if ($rol === ROL_PACIENTE) {
    $med = fila('SELECT usuario_id FROM medicos WHERE id=?', [$cita['medico_id']]);
    notificar((int)$med['usuario_id'], 'Cita cancelada', "{$cita['paciente']} canceló su cita del {$cita['fecha']} a las {$cita['hora']}.");
  } else {
    notificar((int)$cita['paciente_id'], "Cita $nuevo", "Tu cita con {$cita['medico']} del {$cita['fecha']} a las {$cita['hora']} figura como $nuevo.");
  }
  responder(['ok' => true]);
}
responder(['error' => 'Método no permitido'], 405);
