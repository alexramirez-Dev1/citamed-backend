<?php
require '../config.php';
require '../slots.php';
usuarioActual();
$medicoId = (int)($_GET['medico_id'] ?? 0);
$fecha = $_GET['fecha'] ?? '';
if (!$medicoId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) responder(['error' => 'Parámetros inválidos'], 422);
responder(slotsDelDia($medicoId, $fecha));
