<?php
session_start();
include 'conexion.php';

// CORRECCIÓN AQUÍ: Validar usando las nuevas variables de sesión
if (!isset($_SESSION['usuario']) || $_SESSION['rol'] != 'admin') {
    header("Location: index.php");
    exit();
}

// Lógica para cerrar sesión
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

function normalizarFechaVista($fecha) {
    $fecha = trim($fecha);
    $fecha_obj = DateTime::createFromFormat('d/m/Y', $fecha);
    if ($fecha_obj) {
        return $fecha_obj->format('d/m/Y');
    }
    $fecha_obj = DateTime::createFromFormat('Y-m-d', $fecha);
    return $fecha_obj ? $fecha_obj->format('d/m/Y') : $fecha;
}

function normalizarFechaBD($fecha) {
    $fecha = trim($fecha);
    $fecha_obj = DateTime::createFromFormat('d/m/Y', $fecha);
    if ($fecha_obj) {
        return $fecha_obj->format('Y-m-d');
    }
    $fecha_obj = DateTime::createFromFormat('Y-m-d', $fecha);
    return $fecha_obj ? $fecha_obj->format('Y-m-d') : $fecha;
}

if (isset($_GET['toggle_bloqueo_hora'])) {
    $fecha_bloqueo = trim($_GET['fecha'] ?? '');
    $hora_bloqueo = trim($_GET['hora'] ?? '');
    if ($fecha_bloqueo !== '' && $hora_bloqueo !== '') {
        $fecha_db = normalizarFechaBD($fecha_bloqueo);
        $hora_db = $conn->real_escape_string($hora_bloqueo);
        $check_bloqueo = $conn->query("SELECT id FROM horarios_bloqueados WHERE fecha='$fecha_db' AND hora='$hora_db'");
        if ($check_bloqueo && $check_bloqueo->num_rows > 0) {
            $conn->query("DELETE FROM horarios_bloqueados WHERE fecha='$fecha_db' AND hora='$hora_db'");
        } else {
            $conn->query("INSERT INTO horarios_bloqueados (fecha, hora) VALUES ('$fecha_db', '$hora_db')");
        }
    }

    $redirect = 'panel.php';
    if (isset($_GET['week_panel'])) {
        $redirect .= '?week_panel=' . intval($_GET['week_panel']);
    }
    header("Location: $redirect#reservas-table");
    exit();
}

// Crear tabla de configuración de horario si no existe
$conn->query("CREATE TABLE IF NOT EXISTS config_horario (
    id INT PRIMARY KEY,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    intervalo INT NOT NULL DEFAULT 60,
    dias_bloqueados VARCHAR(255) NOT NULL DEFAULT '',
    fechas_bloqueadas VARCHAR(255) NOT NULL DEFAULT ''
)");

// Inicializar configuración por defecto si no existe
$result_config = $conn->query("SELECT * FROM config_horario WHERE id=1");
if ($result_config->num_rows === 0) {
    $conn->query("INSERT INTO config_horario (id, hora_inicio, hora_fin, intervalo) VALUES (1, '08:00:00', '19:00:00', 60)");
}

// Crear tabla de servicios si no existe
$conn->query("CREATE TABLE IF NOT EXISTS servicios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    precio DECIMAL(10,2) NOT NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Guardar servicio nuevo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_servicio'])) {
    $nombre_servicio = $conn->real_escape_string($_POST['nombre_servicio']);
    $descripcion_servicio = $conn->real_escape_string($_POST['descripcion_servicio']);
    $precio_servicio = floatval($_POST['precio_servicio']);
    if ($nombre_servicio !== '' && $precio_servicio > 0) {
        $conn->query("INSERT INTO servicios (nombre, descripcion, precio) VALUES ('$nombre_servicio', '$descripcion_servicio', $precio_servicio)");
    }
    header("Location: panel.php");
    exit();
}

// Eliminar servicio
if (isset($_GET['delete_servicio_id'])) {
    $delete_service_id = intval($_GET['delete_servicio_id']);
    $conn->query("DELETE FROM servicios WHERE id=$delete_service_id");
    header("Location: panel.php");
    exit();
}

// Actualizar servicio existente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_servicio'])) {
    $id_servicio = intval($_POST['id_servicio']);
    $nombre_servicio = $conn->real_escape_string($_POST['nombre_servicio']);
    $descripcion_servicio = $conn->real_escape_string($_POST['descripcion_servicio']);
    $precio_servicio = floatval($_POST['precio_servicio']);
    if ($nombre_servicio !== '' && $precio_servicio > 0) {
        $conn->query("UPDATE servicios SET nombre='$nombre_servicio', descripcion='$descripcion_servicio', precio=$precio_servicio WHERE id=$id_servicio");
    }
    header("Location: panel.php");
    exit();
}

$editar_servicio = null;
if (isset($_GET['edit_servicio_id'])) {
    $edit_service_id = intval($_GET['edit_servicio_id']);
    $resultado_servicio = $conn->query("SELECT * FROM servicios WHERE id=$edit_service_id");
    if ($resultado_servicio && $resultado_servicio->num_rows > 0) {
        $editar_servicio = $resultado_servicio->fetch_assoc();
    }
}

// Actualizar configuración de horario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_horario'])) {
    $hora_inicio = $conn->real_escape_string($_POST['hora_inicio']);
    $hora_fin = $conn->real_escape_string($_POST['hora_fin']);
    $intervalo = intval($_POST['intervalo']);
    if ($intervalo <= 0) { $intervalo = 60; }

    $dias_bloqueados = '';
    if (!empty($_POST['dias_bloqueados']) && is_array($_POST['dias_bloqueados'])) {
        $dias_bloqueados = implode(',', array_map('trim', $_POST['dias_bloqueados']));
    }

    $config_actual = $conn->query("SELECT fechas_bloqueadas FROM config_horario WHERE id=1");
    $fechas_guardadas = '';
    if ($config_actual && $config_actual->num_rows > 0) {
        $config_row = $config_actual->fetch_assoc();
        $fechas_guardadas = $config_row['fechas_bloqueadas'] ?? '';
    }

    $fecha_bloqueada = trim($_POST['fecha_bloqueada'] ?? '');
    $fechas_actuales = array_filter(array_map('trim', explode(',', $fechas_guardadas)));
    if ($fecha_bloqueada !== '') {
        $fecha_obj = DateTime::createFromFormat('Y-m-d', $fecha_bloqueada);
        if ($fecha_obj) {
            $fecha_bloqueada_formateada = $fecha_obj->format('d/m/Y');
            if (!in_array($fecha_bloqueada_formateada, $fechas_actuales, true)) {
                $fechas_actuales[] = $fecha_bloqueada_formateada;
            }
        }
    }

    $fechas_bloqueadas = implode(',', $fechas_actuales);
    $conn->query("UPDATE config_horario SET hora_inicio='$hora_inicio', hora_fin='$hora_fin', intervalo=$intervalo, dias_bloqueados='$dias_bloqueados', fechas_bloqueadas='$fechas_bloqueadas' WHERE id=1");
    header("Location: panel.php");
    exit();
}

if (isset($_GET['remove_fecha_bloqueada'])) {
    $fecha_remover = trim($_GET['remove_fecha_bloqueada']);
    $fecha_remover_db = normalizarFechaBD($fecha_remover);
    $fecha_remover_vista = normalizarFechaVista($fecha_remover_db);

    $config_actual = $conn->query("SELECT fechas_bloqueadas FROM config_horario WHERE id=1");
    if ($config_actual && $config_actual->num_rows > 0) {
        $config_row = $config_actual->fetch_assoc();
        $fechas_guardadas = array_filter(array_map('trim', explode(',', $config_row['fechas_bloqueadas'] ?? '')));
        $fechas_filtradas = array_values(array_filter($fechas_guardadas, function($fecha) use ($fecha_remover_vista) {
            return $fecha !== $fecha_remover_vista;
        }));
        $conn->query("UPDATE config_horario SET fechas_bloqueadas='" . $conn->real_escape_string(implode(',', $fechas_filtradas)) . "' WHERE id=1");
    }
    header('Location: panel.php#horario-section');
    exit();
}

// Borrar cita
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $conn->query("DELETE FROM citas WHERE id=$delete_id");
    header("Location: panel.php");
    exit();
}

// Marcar asistencia del cliente
if (isset($_GET['mark_attended_id'])) {
    $mark_id = intval($_GET['mark_attended_id']);
    $conn->query("UPDATE citas SET asistio='si' WHERE id=$mark_id");
    header("Location: panel.php");
    exit();
}

if (isset($_GET['mark_absent_id'])) {
    $mark_id = intval($_GET['mark_absent_id']);
    $conn->query("UPDATE citas SET asistio='no' WHERE id=$mark_id");
    header("Location: panel.php");
    exit();
}

// Actualizar cita
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_cita'])) {
    $id = intval($_POST['id']);
    $dia_input = trim($_POST['fecha'] ?? '');
    if ($dia_input === '') {
        $dia_input = trim($_POST['dia'] ?? '');
    }
    $dia = $conn->real_escape_string(normalizarFechaVista($dia_input));
    $hora = $conn->real_escape_string($_POST['hora']);
    $nombre = $conn->real_escape_string($_POST['nombre']);
    $telefono = $conn->real_escape_string($_POST['telefono']);
    $servicio = $conn->real_escape_string($_POST['servicio']);
    $barbero = $conn->real_escape_string($_POST['barbero'] ?? '');
    $asistio = isset($_POST['asistio']) ? $conn->real_escape_string($_POST['asistio']) : 'pendiente';

    $check = $conn->query("SELECT * FROM citas WHERE dia='$dia' AND hora='$hora' AND id<>$id");
    if ($check->num_rows == 0) {
        $sql = "UPDATE citas SET dia='$dia', hora='$hora', nombre='$nombre', telefono='$telefono', servicio='$servicio', barbero='$barbero', asistio='$asistio' WHERE id=$id";
        $conn->query($sql);
        header("Location: panel.php");
        exit();
    } else {
        echo "<script>alert('No se puede actualizar: ese horario ya está ocupado.'); window.location.href='panel.php';</script>";
        exit();
    }
}

// Obtener datos para edición si se solicita
$editar_cita = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    $resultado_editar = $conn->query("SELECT * FROM citas WHERE id=$edit_id");
    if ($resultado_editar && $resultado_editar->num_rows > 0) {
        $editar_cita = $resultado_editar->fetch_assoc();
    }
}

// Obtener configuración actual de horario
$result_config = $conn->query("SELECT * FROM config_horario WHERE id=1");
$config_horario = $result_config->fetch_assoc();

// Obtener lista de servicios
$result_servicios = $conn->query("SELECT * FROM servicios ORDER BY nombre ASC");
$servicios = [];
while ($fila_servicio = $result_servicios->fetch_assoc()) {
    $servicios[] = $fila_servicio;
}

// Obtener lista de barberos
$result_barberos = $conn->query("SELECT * FROM barberos ORDER BY nombre ASC");
$barberos = [];
while ($fila_barbero = $result_barberos->fetch_assoc()) {
    $barberos[] = $fila_barbero;
}

// Anuncios administrables desde el panel
$editar_anuncio = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_toggle_anuncio'])) {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        $res = $conn->query("SELECT estado FROM anuncios WHERE id=$id");
        if ($res && $row = $res->fetch_assoc()) {
            $nuevo = $row['estado'] ? 0 : 1;
            $conn->query("UPDATE anuncios SET estado=$nuevo WHERE id=$id");
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'estado' => $nuevo]);
            exit();
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => false]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_anuncio'])) {
    $titulo = $conn->real_escape_string(trim($_POST['titulo'] ?? ''));
    $contenido = $conn->real_escape_string(trim($_POST['contenido'] ?? ''));
    $enlace = $conn->real_escape_string(trim($_POST['enlace'] ?? ''));
    $estado = isset($_POST['estado']) ? 1 : 0;

    $imagen_nombre = null;
    if (!empty($_FILES['imagen']['name'])) {
        $dir = __DIR__ . '/img/anuncios';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $ext = pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION);
        $imagen_nombre = uniqid('ad_') . '.' . $ext;
        move_uploaded_file($_FILES['imagen']['tmp_name'], $dir . '/' . $imagen_nombre);
    }

    if (!empty($_POST['id'])) {
        $id = intval($_POST['id']);
        $sql_update = "UPDATE anuncios SET titulo='$titulo', contenido='$contenido', enlace='$enlace', estado=$estado";
        if ($imagen_nombre) {
            $old_res = $conn->query("SELECT imagen FROM anuncios WHERE id=$id");
            if ($old_res && $old_row = $old_res->fetch_assoc()) {
                if (!empty($old_row['imagen'])) {
                    @unlink(__DIR__ . '/img/anuncios/' . $old_row['imagen']);
                }
            }
            $sql_update .= ", imagen='$imagen_nombre'";
        }
        $sql_update .= " WHERE id=$id";
        $conn->query($sql_update);
    } else {
        $img_sql = $imagen_nombre ? "'$imagen_nombre'" : 'NULL';
        $conn->query("INSERT INTO anuncios (titulo, contenido, imagen, enlace, estado) VALUES ('$titulo', '$contenido', $img_sql, '$enlace', $estado)");
    }

    header('Location: panel.php#anuncios-section');
    exit();
}

if (isset($_GET['delete_anuncio_id'])) {
    $delete_anuncio_id = intval($_GET['delete_anuncio_id']);
    if ($delete_anuncio_id > 0) {
        $img_res = $conn->query("SELECT imagen FROM anuncios WHERE id=$delete_anuncio_id");
        if ($img_res && $img_row = $img_res->fetch_assoc()) {
            if (!empty($img_row['imagen'])) {
                @unlink(__DIR__ . '/img/anuncios/' . $img_row['imagen']);
            }
        }
        $conn->query("DELETE FROM anuncios WHERE id=$delete_anuncio_id");
    }
    header('Location: panel.php#anuncios-section');
    exit();
}

if (isset($_GET['edit_anuncio_id'])) {
    $edit_anuncio_id = intval($_GET['edit_anuncio_id']);
    $res_edit = $conn->query("SELECT * FROM anuncios WHERE id=$edit_anuncio_id");
    if ($res_edit && $res_edit->num_rows > 0) {
        $editar_anuncio = $res_edit->fetch_assoc();
    }
}

$anuncios = [];
$res_anuncios = $conn->query("SELECT * FROM anuncios ORDER BY fecha_creacion DESC");
while ($row_anuncio = $res_anuncios->fetch_assoc()) {
    $anuncios[] = $row_anuncio;
}

$result_bloqueos = $conn->query("SELECT fecha, hora FROM horarios_bloqueados ORDER BY fecha ASC, hora ASC");
$horarios_bloqueados_map = [];
while ($bloqueo = $result_bloqueos->fetch_assoc()) {
    $fecha_vista = normalizarFechaVista($bloqueo['fecha']);
    $horarios_bloqueados_map[$fecha_vista][] = $bloqueo['hora'];
}

// Calcular semana actual del panel y lista de horas
$week_offset = isset($_GET['week_panel']) ? intval($_GET['week_panel']) : 0;
$hoy_panel = strtotime('today') + ($week_offset * 7 * 86400);
$dia_semana_panel = date('N', $hoy_panel);
$segundos_atras_panel = ($dia_semana_panel - 1) * 86400;
$lunes_panel = $hoy_panel - $segundos_atras_panel;
$fecha_inicio_panel = date('d/m/Y', $lunes_panel);
$fecha_fin_panel = date('d/m/Y', $lunes_panel + 6 * 86400);
$fecha_inicio_semana = DateTime::createFromFormat('d/m/Y', $fecha_inicio_panel);
$fecha_fin_semana = DateTime::createFromFormat('d/m/Y', $fecha_fin_panel);
$dias_nombres = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];

$horas = [];
$intervalo = intval($config_horario['intervalo']) ?: 60;
$start = strtotime($config_horario['hora_inicio']);
$end = strtotime($config_horario['hora_fin']);
if ($start !== false && $end !== false && $end >= $start) {
    for ($t = $start; $t <= $end; $t += $intervalo * 60) {
        $horas[] = date('g A', $t);
    }
}

// Obtener todas las citas registradas con paginación
$historia_por_pagina = 15;
$pagina_historial = isset($_GET['page_historial']) ? max(1, intval($_GET['page_historial'])) : 1;
$total_citas = 0;
$result_total = $conn->query("SELECT COUNT(*) AS total FROM citas");
if ($result_total && $row_total = $result_total->fetch_assoc()) {
    $total_citas = intval($row_total['total']);
}
$total_paginas = $total_citas > 0 ? intval(ceil($total_citas / $historia_por_pagina)) : 1;
$offset_citas = ($pagina_historial - 1) * $historia_por_pagina;
$sql_citas = "SELECT * FROM citas ORDER BY id DESC LIMIT $historia_por_pagina OFFSET $offset_citas";
$resultado = $conn->query($sql_citas);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administración | Barbería</title>
    <style>
        :root {
            --bg-principal: #121212;
            --bg-secundario: #1a1a1a;
            --dorado: #d4af37;
            --texto-claro: #ffffff;
            --texto-muted: #a0a0a0;
            --borde: #333333;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: sans-serif; }
        body { background-color: #0B0E14; color: var(--texto-claro); padding: 24px; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 24px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; max-width: 1200px; margin: 0 auto 28px auto; backdrop-filter: blur(14px); }
        h1 { color: var(--dorado); font-size: 24px; letter-spacing: 0.08em; margin: 0; }
        .btn-logout { background-color: rgba(204,51,51,0.16); color: #ffefef; border: 1px solid rgba(204,51,51,0.35); padding: 10px 18px; cursor: pointer; border-radius: 999px; text-decoration: none; font-size: 14px; font-weight: 700; }
        main { max-width: 1200px; margin: 0 auto; }
        h2 { margin-bottom: 20px; color: var(--texto-claro); }
        .tabla-contenedor { overflow-x: auto; background-color: var(--bg-secundario); border-radius: 12px; border: 1px solid rgba(255,255,255,0.08); }
        .schedule-shell { padding: 20px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; }
        .schedule-meta { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 18px; }
        .schedule-meta strong { color: #f5f3ce; }
        .schedule-table { width: 100%; border-collapse: collapse; text-align: left; table-layout: fixed; min-width: 720px; }
        .schedule-table th, .schedule-table td { width: calc(100% / 7); padding: 14px 16px; border: 1px solid rgba(255,255,255,0.08); vertical-align: top; overflow: hidden; word-wrap: break-word; }
        .schedule-table th { background-color: rgba(255,255,255,0.04); color: #e3e8ff; text-transform: uppercase; font-size: 12px; letter-spacing: 0.08em; }
        .schedule-table td { background-color: rgba(255,255,255,0.02); color: #dbe2ff; }
        .schedule-table tr:hover td { background-color: rgba(255,255,255,0.05); }
        .sticky-col { position: sticky; left: 0; background: #101824; z-index: 2; color: #f8fafc; font-weight: 700; }
        .schedule-cell { display: flex; flex-direction: column; gap: 6px; padding: 12px; border-radius: 12px; justify-content: center; min-height: 84px; box-sizing: border-box; }
        .schedule-cell .slot-name { color: #f8fafc; font-size: 0.95rem; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .schedule-cell .slot-hour { color: #e2e8f0; font-size: 1rem; font-weight: 700; }
        .schedule-cell .slot-status { color: #cbd5e1; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.08em; opacity: 0.85; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .slot-available { background: rgba(16,185,129,0.16); border: 1px solid rgba(16,185,129,0.35); }
        .slot-occupied, .slot-blocked { background: rgba(248,113,113,0.16); border: 1px solid rgba(248,113,113,0.35); }
        .slot-compact { display: none; color: #fecaca; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.08em; opacity: 0.85; }
        body.hide-schedule-details .slot-name,
        body.hide-schedule-details .slot-status { display: none; }
        body.hide-schedule-details .slot-compact { display: block; }
        .btn-toggle-schedule { margin-left: 0; }
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: 999px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; }
        .badge-gold { background: rgba(212,175,55,0.14); color: #f5e8a0; border: 1px solid rgba(212,175,55,0.18); }
        .badge-success { background: rgba(52,211,153,0.14); color: #a7f3d0; border: 1px solid rgba(52,211,153,0.18); }
        .form-editar { background-color: #121824; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 24px; margin-bottom: 20px; }
        .form-editar h3 { color: var(--dorado); margin-bottom: 15px; }
        .campo-form { margin-bottom: 12px; }
        .campo-form label { display: block; margin-bottom: 6px; color: var(--texto-muted); }
        .campo-form input, .campo-form select, .campo-form textarea { width: 100%; padding: 10px; background-color: #121212; border: 1px solid var(--borde); border-radius: 4px; color: var(--texto-claro); }
        .campo-form textarea { resize: vertical; min-height: 90px; }
        .botones-editar { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
        .btn-actualizar, .btn-cancelar, .btn-editar, .btn-borrar { display: inline-block; padding: 8px 12px; border-radius: 4px; text-decoration: none; font-size: 13px; font-weight: bold; }
        .edit-modal {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 220ms ease, visibility 220ms ease;
        }
        .edit-modal.active {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        .edit-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(4, 8, 18, 0.75);
            backdrop-filter: blur(8px);
        }
        .edit-card {
            position: relative;
            z-index: 2;
            width: min(720px, 100%);
            background: rgba(9, 14, 26, 0.98);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 28px;
            box-shadow: 0 36px 90px rgba(0,0,0,0.4);
            padding: 30px;
            overflow: hidden;
            transform: translateY(24px);
            transition: transform 220ms ease;
        }
        .edit-modal.active .edit-card {
            transform: translateY(0);
        }
        .edit-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            margin-bottom: 24px;
        }
        .edit-header h3 {
            margin: 0;
            color: var(--dorado);
            font-size: 1.35rem;
        }
        .edit-header .edit-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: rgba(212,175,55,0.12);
            border-radius: 999px;
            color: #f8f8f2;
            font-size: 0.9rem;
        }
        .modal-close {
            width: 44px;
            height: 44px;
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 50%;
            background: rgba(255,255,255,0.05);
            color: var(--texto-claro);
            font-size: 1.4rem;
            display: grid;
            place-items: center;
            cursor: pointer;
            transition: background 180ms ease, transform 180ms ease;
        }
        .modal-close:hover {
            transform: translateY(-2px);
            background: rgba(255,255,255,0.12);
        }
        .edit-form {
            display: grid;
            gap: 16px;
        }
        .edit-form .campo-form input,
        .edit-form .campo-form select {
            background-color: #0f1624;
            border-color: rgba(255,255,255,0.1);
        }
        .edit-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .panel-grid { display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start; margin-bottom: 20px; }
        .panel-grid > .panel-card { flex: 1 1 48%; min-width: 320px; max-width: 560px; }
        .panel-grid .tabla-contenedor { width: 100%; max-width: 100%; }
        .panel-grid .form-editar { margin-bottom: 0; }
        .tabla-contenedor table { width: 100%; min-width: 0; }
        .admin-anuncios { margin-bottom: 20px; }
        .admin-anuncios .anuncio-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
        .admin-anuncios .anuncio-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 22px; }
        .admin-anuncios .anuncio-card img { max-width: 140px; border-radius: 12px; }
        .admin-anuncios table { width: 100%; border-collapse: collapse; }
        .admin-anuncios th, .admin-anuncios td { padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,0.08); text-align: center; }
        .admin-anuncios th:first-child, .admin-anuncios td:first-child { text-align: left; }
        .admin-anuncios .toggle-anuncio { cursor: pointer; color: #f9c74f; font-weight: 700; }
        .admin-anuncios .toggle-anuncio:hover { color: #fff; }
        .admin-anuncios .anuncio-form { display: grid; gap: 14px; }
        .admin-anuncios .anuncio-form .campo-form { margin-bottom: 0; }
        .btn-actualizar, .btn-cancelar, .btn-editar, .btn-borrar, .btn-asistio, .btn-noasistio { border-radius: 999px; padding: 10px 14px; font-size: 13px; font-weight: 700; border: 1px solid rgba(255,255,255,0.12); background: rgba(255,255,255,0.04); color: var(--texto-claro); text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: background 180ms ease, transform 180ms ease; }
        .btn-actualizar { background: transparent; color: var(--dorado); border-color: rgba(212,175,55,0.35); }
        .btn-cancelar { background: transparent; color: var(--texto-claro); border-color: rgba(255,255,255,0.12); }
        .btn-editar { background: transparent; color: #86b7ff; border-color: rgba(134,183,255,0.3); }
        .btn-asistio { background: transparent; color: #8ee1a4; border-color: rgba(50,162,89,0.25); }
        .btn-noasistio, .btn-borrar { background: transparent; color: #ff9fa5; border-color: rgba(255,99,110,0.25); }
        .btn-actualizar:hover, .btn-cancelar:hover, .btn-editar:hover, .btn-borrar:hover, .btn-asistio:hover, .btn-noasistio:hover { transform: translateY(-1px); background: rgba(255,255,255,0.08); }
        .status-si { display: inline-block; padding: 4px 8px; background-color: #1f5f25; color: #c7f2c7; border-radius: 4px; }
        .status-no { display: inline-block; padding: 4px 8px; background-color: #5f1f1f; color: #f2c7c7; border-radius: 4px; }
        .status-pendiente { display: inline-block; padding: 4px 8px; background-color: #3a3a3a; color: #cccccc; border-radius: 4px; }
        .summary-cards { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 20px; }
        .summary-card { flex: 1 1 220px; background-color: #1f1f1f; border: 1px solid #333; border-radius: 12px; padding: 20px; min-width: 220px; }
        .summary-card h3 { margin-bottom: 10px; color: var(--dorado); font-size: 14px; text-transform: uppercase; letter-spacing: 1px; }
        .summary-card p { margin: 0; font-size: 24px; font-weight: bold; color: var(--texto-claro); }
        @media (max-width: 900px) {
            .panel-grid { flex-direction: column; }
            .panel-grid > .panel-card { min-width: 100%; max-width: 100%; }
            .tabla-contenedor, .form-editar { margin-bottom: 20px; }
            .schedule-meta { flex-direction: column; align-items: flex-start; gap: 12px; }
            .schedule-table { min-width: 680px; }
            .schedule-table th, .schedule-table td { padding: 12px; }
            table { font-size: 13px; }
        }
        @media (max-width: 640px) {
            body { padding: 16px; }
            header { padding: 18px; }
            .schedule-shell { padding: 16px; }
            .schedule-meta { gap: 10px; }
            .schedule-table { min-width: 100%; }
            .schedule-table th, .schedule-table td { padding: 10px; font-size: 0.82rem; }
            .schedule-cell { min-height: 64px; }
            .form-editar, .tabla-contenedor, .admin-anuncios .anuncio-card { padding: 18px; }
            .edit-card { width: 100%; padding: 20px; }
            .edit-actions { flex-direction: column; align-items: stretch; }
            .edit-actions a, .edit-actions button, .botones-editar button, .botones-editar a { width: 100%; }
            .btn-actualizar, .btn-cancelar, .btn-editar, .btn-borrar, .btn-asistio, .btn-noasistio { justify-content: center; }
            .admin-anuncios th, .admin-anuncios td { padding: 10px 12px; }
            .textarea, .campo-form input, .campo-form select { width: 100%; }
        }
        .btn-borrar:hover, .btn-editar:hover, .btn-noasistio:hover, .btn-asistio:hover, .btn-cancelar:hover, .btn-actualizar:hover { opacity: 0.9; }
        
        /* Estilos para acordeón */
        .accordion-header {
            background-color: #1f1f1f;
            border: 1px solid #333;
            padding: 15px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 8px;
            margin-bottom: 15px;
            user-select: none;
            font-size: 16px;
            font-weight: bold;
            color: var(--dorado);
            transition: all 0.3s ease;
        }
        .accordion-header:hover {
            background-color: #2a2a2a;
            box-shadow: 0 0 10px rgba(212, 175, 55, 0.2);
        }
        .accordion-header.active {
            background-color: #0f0f0f;
            border-color: var(--dorado);
        }
        .accordion-toggle {
            font-size: 20px;
            transition: transform 0.3s ease;
        }
        .accordion-toggle.active {
            transform: rotate(180deg);
        }
        .accordion-content {
            display: none;
            padding: 0;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        .accordion-content.active {
            display: block;
        }
        .accordion-section {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

    <header>
        <h1>Control Wason Studio</h1>
        <a href="panel.php?logout=1" class="btn-logout">Cerrar Sesión</a>
    </header>

    <main>
        <!-- Acordeón: Configuración de horario -->
        <div class="accordion-section" id="horario-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span>⚙️ Configuración de horario</span>
                <span class="accordion-toggle">▼</span>
            </div>
            <div class="accordion-content">
                <div class="form-editar" style="margin-bottom: 0; border-radius: 0 0 8px 8px; border-top: none;">
                    <form action="panel.php" method="POST">
                <input type="hidden" name="actualizar_horario" value="1">
                <div class="campo-form">
                    <label>Hora de inicio</label>
                    <input type="time" name="hora_inicio" value="<?php echo htmlspecialchars($config_horario['hora_inicio']); ?>" required>
                </div>
                <div class="campo-form">
                    <label>Hora de fin</label>
                    <input type="time" name="hora_fin" value="<?php echo htmlspecialchars($config_horario['hora_fin']); ?>" required>
                </div>
                <div class="campo-form">
                    <label>Intervalo (minutos)</label>
                    <input type="number" name="intervalo" min="15" step="15" value="<?php echo intval($config_horario['intervalo']); ?>" required>
                </div>
                <div class="campo-form">
                    <label>Días sin servicio (repetir cada semana)</label>
                    <?php $dias_bloqueados_actuales = array_filter(array_map('trim', explode(',', $config_horario['dias_bloqueados'] ?? ''))); ?>
                    <div style="display:flex; flex-wrap:wrap; gap:10px;">
                        <?php foreach (['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'] as $dia_bloqueado): ?>
                            <label style="display:flex; align-items:center; gap:6px; font-size:14px; color:var(--texto-claro);">
                                <input type="checkbox" name="dias_bloqueados[]" value="<?php echo $dia_bloqueado; ?>" <?php echo in_array($dia_bloqueado, $dias_bloqueados_actuales) ? 'checked' : ''; ?>>
                                <?php echo $dia_bloqueado; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="campo-form">
                    <label>Fecha específica sin servicio</label>
                    <input type="date" name="fecha_bloqueada">
                    <div style="margin-top:8px; color:var(--texto-muted); font-size:13px;">Si eliges una fecha aquí, solo se bloqueará esa fecha concreta y no las siguientes semanas.</div>
                    <?php $fechas_bloqueadas_actuales = array_filter(array_map('trim', explode(',', $config_horario['fechas_bloqueadas'] ?? ''))); ?>
                    <?php if (!empty($fechas_bloqueadas_actuales)): ?>
                        <div style="margin-top:10px; padding:12px; background:#161616; border:1px solid var(--borde); border-radius:10px; color:var(--texto-muted); font-size:13px; display:grid; gap:8px;">
                            <?php foreach ($fechas_bloqueadas_actuales as $fecha_especifica): ?>
                                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
                                    <span><?php echo htmlspecialchars($fecha_especifica); ?></span>
                                    <a href="panel.php?remove_fecha_bloqueada=<?php echo urlencode(date('Y-m-d', strtotime(str_replace('/', '-', $fecha_especifica)))); ?>#horario-section" style="color:#f9c74f; text-decoration:none; font-weight:700;">Eliminar</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="botones-editar">
                    <button type="submit" class="btn-actualizar">Guardar horario</button>
                </div>
            </form>
                </div>
            </div>
        </div>

        <!-- Acordeón: Servicios -->
        <div class="accordion-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span>💼 Servicios</span>
                <span class="accordion-toggle">▼</span>
            </div>
            <div class="accordion-content">
                <div class="form-editar" style="margin-bottom: 0; border-radius: 0 0 8px 8px; border-top: none;">
                    <h3><?php echo $editar_servicio ? 'Editar servicio' : 'Agregar/Editar Servicios'; ?></h3>
            <form action="panel.php" method="POST">
                <?php if ($editar_servicio): ?>
                    <input type="hidden" name="actualizar_servicio" value="1">
                    <input type="hidden" name="id_servicio" value="<?php echo $editar_servicio['id']; ?>">
                <?php else: ?>
                    <input type="hidden" name="guardar_servicio" value="1">
                <?php endif; ?>
                <div class="campo-form">
                    <label>Nombre del servicio</label>
                    <input type="text" name="nombre_servicio" placeholder="Ej. Corte Clásico" required value="<?php echo htmlspecialchars($editar_servicio['nombre'] ?? ''); ?>">
                </div>
                <div class="campo-form">
                    <label>Descripción</label>
                    <textarea name="descripcion_servicio" rows="3" placeholder="Descripción del servicio"><?php echo htmlspecialchars($editar_servicio['descripcion'] ?? ''); ?></textarea>
                </div>
                <div class="campo-form">
                    <label>Precio</label>
                    <input type="number" name="precio_servicio" min="0" step="0.01" placeholder="0.00" required value="<?php echo htmlspecialchars($editar_servicio['precio'] ?? ''); ?>">
                </div>
                <div class="botones-editar">
                    <button type="submit" class="btn-actualizar"><?php echo $editar_servicio ? 'Actualizar servicio' : 'Agregar servicio'; ?></button>
                    <?php if ($editar_servicio): ?>
                        <a href="panel.php" class="btn-cancelar">Cancelar edición</a>
                    <?php endif; ?>
                </div>
            </form>
                </div>
            </div>
        </div>

        <!-- Acordeón: Barberos -->
        <div class="accordion-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span>💈 Barberos</span>
                <span class="accordion-toggle">▼</span>
            </div>
            <div class="accordion-content">
                <div class="form-editar" style="margin-bottom: 0; border-radius: 0 0 8px 8px; border-top: none;">
                    <h3>Agregar barbero</h3>
                    <form action="panel.php" method="POST">
                        <input type="hidden" name="guardar_barbero" value="1">
                        <div class="campo-form">
                            <label>Nombre del barbero</label>
                            <input type="text" name="nombre_barbero" placeholder="Ej. Juan" required>
                        </div>
                        <div class="campo-form">
                            <label>Descripción</label>
                            <textarea name="descripcion_barbero" rows="3" placeholder="Especialidad, estilo o notas"></textarea>
                        </div>
                        <div class="botones-editar">
                            <button type="submit" class="btn-actualizar">Guardar barbero</button>
                        </div>
                    </form>
                </div>
                <div class="tabla-contenedor" style="border-radius: 0 0 8px 8px; border-top: none; margin-top: 16px;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Descripción</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($barberos) > 0): ?>
                                <?php foreach ($barberos as $barbero): ?>
                                    <tr>
                                        <td><?php echo $barbero['id']; ?></td>
                                        <td><?php echo htmlspecialchars($barbero['nombre']); ?></td>
                                        <td><?php echo htmlspecialchars($barbero['descripcion'] ?: 'Sin descripción'); ?></td>
                                        <td style="white-space:nowrap;">
                                            <a class="btn-borrar" href="panel.php?delete_barbero_id=<?php echo $barbero['id']; ?>" onclick="return confirm('¿Eliminar este barbero?')">Eliminar</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align:center; color:var(--texto-muted);">No hay barberos registrados aún.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="accordion-section" id="anuncios-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span>📣 Anuncios</span>
                <span class="accordion-toggle">▼</span>
            </div>
            <div class="accordion-content">
                <div class="anuncio-card">
                    <h3><?php echo $editar_anuncio ? 'Editar anuncio' : 'Crear anuncio'; ?></h3>
                    <form action="panel.php" method="POST" enctype="multipart/form-data" class="anuncio-form">
                        <?php if ($editar_anuncio): ?>
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($editar_anuncio['id']); ?>">
                        <?php endif; ?>
                        <input type="hidden" name="guardar_anuncio" value="1">
                        <div class="campo-form">
                            <label>Título</label>
                            <input type="text" name="titulo" required value="<?php echo htmlspecialchars($editar_anuncio['titulo'] ?? ''); ?>">
                        </div>
                        <div class="campo-form">
                            <label>Contenido</label>
                            <textarea name="contenido" rows="3"><?php echo htmlspecialchars($editar_anuncio['contenido'] ?? ''); ?></textarea>
                        </div>
                        <div class="campo-form">
                            <label>Enlace (opcional)</label>
                            <input type="text" name="enlace" value="<?php echo htmlspecialchars($editar_anuncio['enlace'] ?? ''); ?>">
                        </div>
                        <div class="campo-form">
                            <label>Imagen (opcional)</label>
                            <input type="file" name="imagen" accept="image/*">
                            <?php if (!empty($editar_anuncio['imagen'])): ?>
                                <div style="margin-top:10px;"><img src="img/anuncios/<?php echo htmlspecialchars($editar_anuncio['imagen']); ?>" alt="Imagen anuncio"></div>
                            <?php endif; ?>
                        </div>
                        <div class="campo-form">
                            <label><input type="checkbox" name="estado" <?php echo (isset($editar_anuncio['estado']) && $editar_anuncio['estado']) ? 'checked' : ''; ?>> Activo</label>
                        </div>
                        <button type="submit" class="btn-actualizar"><?php echo $editar_anuncio ? 'Actualizar anuncio' : 'Guardar anuncio'; ?></button>
                    </form>
                </div>

                <div class="tabla-contenedor anuncio-card" style="margin-top:18px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Título</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($anuncios) > 0): ?>
                                <?php foreach ($anuncios as $anuncio): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($anuncio['titulo']); ?></td>
                                        <td><span class="toggle-anuncio" data-id="<?php echo $anuncio['id']; ?>"><?php echo $anuncio['estado'] ? 'Activo' : 'Inactivo'; ?></span></td>
                                        <td><?php echo htmlspecialchars($anuncio['fecha_creacion']); ?></td>
                                        <td style="white-space:nowrap;">
                                            <a class="btn-editar" href="panel.php?edit_anuncio_id=<?php echo $anuncio['id']; ?>#anuncios-section">Editar</a>
                                            <a class="btn-borrar" href="panel.php?delete_anuncio_id=<?php echo $anuncio['id']; ?>#anuncios-section" onclick="return confirm('¿Eliminar este anuncio?')">Eliminar</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align:center; color:var(--texto-muted);">No hay anuncios registrados.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<div class="accordion-section">
    <div class="accordion-header" onclick="toggleAccordion(this)">
        <span>✂️ Lista de Servicios</span>
        <span class="accordion-toggle">▼</span>
    </div>
    <div class="accordion-content">
        <div class="tabla-contenedor" style="border-radius: 0 0 8px 8px; border-top: none;">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre del Servicio</th>
                        <th>Descripción</th>
                        <th>Precio</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Consultamos la tabla 'servicios' exactamente como se llama en tu phpMyAdmin
                    $servicios_query = $conn->query("SELECT * FROM servicios ORDER BY id ASC");
                    
                    if ($servicios_query && $servicios_query->num_rows > 0) {
                        while ($servicio = $servicios_query->fetch_assoc()) {
                            echo "<tr>";
                            // Mapeo exacto basado en la estructura de tu imagen
                            echo "<td>" . $servicio['id'] . "</td>";
                            echo "<td>" . htmlspecialchars($servicio['nombre']) . "</td>";
                            // Validamos que si la descripción está vacía (NULL) muestre un texto amigable
                            echo "<td>" . htmlspecialchars($servicio['descripcion'] ?? 'Sin descripción') . "</td>";
                            // Formateamos el precio para que se vea estético en la Barbería (Ej: $15.000)
                            echo "<td>$" . number_format($servicio['precio'], 0, ',', '.') . "</td>";
                            
                            // Parámetros dinámicos para editar y borrar servicios en panel.php
                            echo "<td style='white-space:nowrap;'>";
                            echo "<a class='btn-editar' href='panel.php?edit_servicio_id=" . $servicio['id'] . "'>Editar</a> ";
                            echo "<a class='btn-borrar' href='panel.php?delete_servicio_id=" . $servicio['id'] . "' onclick='return confirm(\"¿Estás seguro de eliminar este servicio?\")'>Borrar</a>";
                            echo "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='5' style='text-align:center; color:var(--texto-muted);'>No hay servicios registrados aún.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

        <!-- Acordeón: Tabla de Citas -->
        <div class="accordion-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span>📋 Historial de Citas</span>
                <span class="accordion-toggle">▼</span>
            </div>
            <div class="accordion-content">
                <div class="tabla-contenedor" style="border-radius: 0 0 8px 8px; border-top: none;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Cliente</th>
                                <th>Teléfono</th>
                                <th>Día</th>
                                <th>Hora</th>
                                <th>Servicio Solicitado</th>
                                <th>Fecha Registro</th>
                                <th>Asistencia</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($resultado->num_rows > 0) {
                                while($cita = $resultado->fetch_assoc()) {
                                    $estado = '<span class="status-pendiente">Pendiente</span>';
                                    if ($cita['asistio'] === 'si') {
                                        $estado = '<span class="status-si">Asistió</span>';
                                    } elseif ($cita['asistio'] === 'no') {
                                        $estado = '<span class="status-no">No asistió</span>';
                                    }

                                    echo "<tr>";
                                    echo "<td>" . $cita['id'] . "</td>";
                                    echo "<td>" . htmlspecialchars($cita['nombre']) . "</td>";
                                    echo "<td>" . htmlspecialchars($cita['telefono']) . "</td>";
                                    echo "<td>" . $cita['dia'] . "</td>";
                                    echo "<td>" . $cita['hora'] . "</td>";
                                    echo "<td>" . $cita['servicio'] . "</td>";
                                    echo "<td>" . htmlspecialchars($cita['barbero'] ?: 'Sin asignar') . "</td>";
                                    echo "<td>" . $cita['fecha_registro'] . "</td>";
                                    echo "<td>" . $estado . "</td>";
                                    $boton_asistencia = $cita['asistio'] === 'si'
                                        ? "<a class='btn-noasistio' href='panel.php?mark_absent_id=" . $cita['id'] . "' onclick='return confirm(\"Marcar como no asistido?\")'>No asistió</a> "
                                        : "<a class='btn-asistio' href='panel.php?mark_attended_id=" . $cita['id'] . "'>Asistió</a> ";
                                    echo "<td style='white-space:nowrap;'>";
                                    echo "<a class='btn-editar' href='panel.php?edit_id=" . $cita['id'] . "'>Editar</a> ";
                                    echo $boton_asistencia;
                                    echo "<a class='btn-borrar' href='panel.php?delete_id=" . $cita['id'] . "' title='Eliminar' onclick='return confirm(\"¿Eliminar esta cita?\")'>🗑️</a>";
                                    echo "</td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='9' style='text-align:center; color:var(--texto-muted);'>No hay citas agendadas aún.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                    <?php if ($total_citas > $historia_por_pagina): ?>
                        <div style="padding: 16px 18px; display:flex; justify-content:flex-end; gap:10px; background: rgba(255,255,255,0.03); border-top: 1px solid rgba(255,255,255,0.08);">
                            <?php if ($pagina_historial > 1): ?>
                                <a href="panel.php?page_historial=<?php echo $pagina_historial - 1; ?>#reservas-section" class="btn-cancelar">Anterior</a>
                            <?php endif; ?>
                            <?php if ($pagina_historial < $total_paginas): ?>
                                <a href="panel.php?page_historial=<?php echo $pagina_historial + 1; ?>#reservas-section" class="btn-actualizar">Siguiente</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($editar_cita): ?>
            <?php
                $fecha_cita_edit = DateTime::createFromFormat('d/m/Y', $editar_cita['dia']);
                $fecha_input_value = $fecha_cita_edit ? $fecha_cita_edit->format('Y-m-d') : '';
                $dia_seleccionado = $fecha_cita_edit ? ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'][$fecha_cita_edit->format('N') - 1] : $editar_cita['dia'];
            ?>
            <div id="editModal" class="edit-modal active">
                <div id="editBackdrop" class="edit-backdrop"></div>
                <div class="edit-card">
                    <div class="edit-header">
                        <div>
                            <h3>Editar cita #<?php echo $editar_cita['id']; ?></h3>
                            <span class="edit-label">Actualiza los datos y guarda los cambios</span>
                        </div>
                        <button type="button" id="closeEditModal" class="modal-close" aria-label="Cerrar modal">×</button>
                    </div>
                    <form action="panel.php" method="POST" class="edit-form">
                        <input type="hidden" name="actualizar_cita" value="1">
                        <input type="hidden" name="id" value="<?php echo $editar_cita['id']; ?>">
                    <div class="campo-form">
                        <label>Nombre Completo</label>
                        <input type="text" name="nombre" value="<?php echo htmlspecialchars($editar_cita['nombre']); ?>" required>
                    </div>
                    <div class="campo-form">
                        <label>Teléfono</label>
                        <input type="tel" name="telefono" value="<?php echo htmlspecialchars($editar_cita['telefono']); ?>" required>
                    </div>
                    <div class="campo-form">
                        <label>Fecha</label>
                        <input type="date" name="fecha" value="<?php echo htmlspecialchars($fecha_input_value); ?>">
                    </div>
                    <div class="campo-form">
                        <label>Día de la semana</label>
                        <select name="dia" required>
                            <?php
                            $dias = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
                            foreach ($dias as $dia) {
                                $selected = $dia_seleccionado === $dia ? 'selected' : '';
                                echo "<option value=\"$dia\" $selected>$dia</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="campo-form">
                        <label>Hora</label>
                        <select name="hora" required>
                            <?php
                            $horas = ['08:00 AM','09:00 AM','10:00 AM','11:00 AM','12:00 PM','01:00 PM','02:00 PM','03:00 PM','04:00 PM','05:00 PM','06:00 PM','07:00 PM'];
                            foreach ($horas as $hora) {
                                $selected = $editar_cita['hora'] === $hora ? 'selected' : '';
                                echo "<option value=\"$hora\" $selected>$hora</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="campo-form">
                        <label>Servicio</label>
                        <select name="servicio" required>
                            <?php if (count($servicios) > 0): ?>
                                <?php foreach ($servicios as $serv): ?>
                                    <?php $selected = $editar_cita['servicio'] === $serv['nombre'] ? 'selected' : ''; ?>
                                    <option value="<?php echo htmlspecialchars($serv['nombre']); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($serv['nombre']); ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="Corte Clasico">Corte de Cabello Clásico</option>
                                <option value="Perfilado Barba">Perfilado de Barba</option>
                                <option value="Combo Completo">Combo Corte + Barba</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="campo-form">
                        <label>Barbero</label>
                        <select name="barbero">
                            <option value="">Sin asignar</option>
                            <?php foreach ($barberos as $barbero): ?>
                                <?php $selected = $editar_cita['barbero'] === $barbero['nombre'] ? 'selected' : ''; ?>
                                <option value="<?php echo htmlspecialchars($barbero['nombre']); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($barbero['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="campo-form">
                        <label>Estado de asistencia</label>
                        <select name="asistio" required>
                            <option value="pendiente" <?php echo $editar_cita['asistio'] === 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                            <option value="si" <?php echo $editar_cita['asistio'] === 'si' ? 'selected' : ''; ?>>Asistió</option>
                            <option value="no" <?php echo $editar_cita['asistio'] === 'no' ? 'selected' : ''; ?>>No asistió</option>
                        </select>
                    </div>
                    <div class="botones-editar">
                        <button type="submit" class="btn-actualizar">Actualizar</button>
                        <a href="panel.php" class="btn-cancelar">Cancelar</a>
                    </div>
                </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- <div class="tabla-contenedor">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Cliente</th>
                        <th>Teléfono</th>
                        <th>Día</th>
                        <th>Hora</th>
                        <th>Servicio Solicitado</th>
                        <th>Fecha Registro</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($resultado->num_rows > 0) {
                        while($cita = $resultado->fetch_assoc()) {
                            echo "<tr>";
                            echo "<td>" . $cita['id'] . "</td>";
                            echo "<td>" . htmlspecialchars($cita['nombre']) . "</td>";
                            echo "<td>" . htmlspecialchars($cita['telefono']) . "</td>";
                            echo "<td>" . $cita['dia'] . "</td>";
                            echo "<td>" . $cita['hora'] . "</td>";
                            echo "<td>" . $cita['servicio'] . "</td>";
                            echo "<td>" . $cita['fecha_registro'] . "</td>";
                            echo "<td style='white-space:nowrap;'>";
                            echo "<a class='btn-editar' href='panel.php?edit_id=" . $cita['id'] . "'>Editar</a> ";
                            echo "<a class='btn-borrar' href='panel.php?delete_id=" . $cita['id'] . "' onclick='return confirm(\"¿Eliminar esta cita?\")'>Borrar</a>";
                            echo "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='8' style='text-align:center; color:var(--texto-muted);'>No hay citas agendadas aún.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div> -->
        
        <!-- Tabla de Reservas -->
        <div class="accordion-section" id="reservas-section">
            <div class="schedule-shell">
                <div class="schedule-meta">
                    <div>
                        <strong>Semana:</strong> <?php echo $fecha_inicio_panel; ?> — <?php echo $fecha_fin_panel; ?>
                    </div>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center; justify-content:flex-end;">
                        <button type="button" id="toggle-schedule-view" class="btn-actualizar btn-toggle-schedule">Mostrar solo hora</button>
                        <a href="?week_panel=<?php echo $week_offset - 1; ?>#reservas-table" class="btn-actualizar">&lsaquo; Semana anterior</a>
                        <a href="?week_panel=<?php echo $week_offset + 1; ?>#reservas-table" class="btn-actualizar">Semana siguiente &rsaquo;</a>
                    </div>
                </div>
                <div class="tabla-contenedor" id="reservas-table" style="border-radius: 12px; border-top: none;">
                        <table class="schedule-table">
                            <thead>
                                <tr>
                                    <?php foreach ($dias_nombres as $dia): ?>
                                        <th><?php echo $dia; ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Obtener todas las citas para la tabla de calendario
                                $todas_citas = $conn->query("SELECT dia, hora, nombre, id FROM citas");
                                $citas_map = [];
                                if ($fecha_inicio_semana && $fecha_fin_semana) {
                                    while($cita = $todas_citas->fetch_assoc()) {
                                        $fecha_obj = DateTime::createFromFormat('d/m/Y', $cita['dia']);
                                        $hora_key = $cita['hora'];
                                        $timestamp_hora = strtotime($cita['hora']);
                                        if ($timestamp_hora !== false) {
                                            $hora_key = date('g A', $timestamp_hora);
                                        }
                                        if ($fecha_obj && $fecha_obj >= $fecha_inicio_semana && $fecha_obj <= $fecha_fin_semana) {
                                            $dia_nombre = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'][$fecha_obj->format('N') - 1];
                                            $citas_map[$dia_nombre][$hora_key] = ['nombre' => $cita['nombre'], 'id' => $cita['id']];
                                        } elseif (in_array($cita['dia'], $dias_nombres, true)) {
                                            $citas_map[$cita['dia']][$hora_key] = ['nombre' => $cita['nombre'], 'id' => $cita['id']];
                                        }
                                    }
                                }

                                foreach ($horas as $h):
                                    echo "<tr>";
                                    foreach ($dias_nombres as $index => $d) {
                                        $fecha_dia = date('d/m/Y', $lunes_panel + ($index * 86400));
                                        $esta_bloqueado = isset($horarios_bloqueados_map[$fecha_dia]) && in_array($h, $horarios_bloqueados_map[$fecha_dia], true);
                                        $cita_info = $citas_map[$d][$h] ?? null;
                                        $estado_label = $cita_info ? 'Ocupado' : ($esta_bloqueado ? 'Bloqueado' : 'Libre');
                                        $estado_class = $cita_info ? 'slot-occupied' : ($esta_bloqueado ? 'slot-blocked' : 'slot-available');
                                        $cell_hour = htmlspecialchars($h);
                                        echo "<td style='padding:0;'>"
                                            . "<a href='panel.php?week_panel=" . urlencode($week_offset) . "&toggle_bloqueo_hora=1&fecha=" . urlencode($fecha_dia) . "&hora=" . urlencode($h) . "#reservas-table'"
                                            . " style='display:block; color:inherit; text-decoration:none; padding:0; background:transparent; min-height:84px;'>"
                                            . "<div class='schedule-cell " . $estado_class . "'>";
                                        if ($cita_info) {
                                            echo "<strong class='slot-name'>" . htmlspecialchars($cita_info['nombre']) . "</strong>";
                                            echo "<span class='slot-compact'>Ocupado</span>";
                                        }
                                        echo "<span class='slot-hour'>" . $cell_hour . "</span>";
                                        if ($esta_bloqueado) {
                                            echo "<span class='slot-status'>Bloqueado</span>";
                                        }
                                        echo "</div>"
                                            . "</a>"
                                            . "</td>";
                                    }
                                    echo "</tr>";
                                endforeach;
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        function toggleAccordion(header) {
            const content = header.nextElementSibling;
            const toggle = header.querySelector('.accordion-toggle');
            const isActive = content.classList.contains('active');
            if (isActive) {
                content.classList.remove('active');
                header.classList.remove('active');
                toggle.classList.remove('active');
            } else {
                content.classList.add('active');
                header.classList.add('active');
                toggle.classList.add('active');
            }
        }

        document.querySelectorAll('.toggle-anuncio').forEach(function(el) {
            el.addEventListener('click', function() {
                var id = this.dataset.id;
                var self = this;
                fetch('panel.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'ajax_toggle_anuncio=1&id=' + encodeURIComponent(id)
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.ok) {
                        self.textContent = data.estado == 1 ? 'Activo' : 'Inactivo';
                    }
                });
            });
        });

        const toggleScheduleViewButton = document.getElementById('toggle-schedule-view');
        if (toggleScheduleViewButton) {
            let showNames = true;
            const updateToggleText = function() {
                toggleScheduleViewButton.textContent = showNames ? 'Mostrar solo hora' : 'Mostrar nombres';
            };
            toggleScheduleViewButton.addEventListener('click', function() {
                showNames = !showNames;
                document.body.classList.toggle('hide-schedule-details', !showNames);
                updateToggleText();
            });
            updateToggleText();
        }

        const editModal = document.getElementById('editModal');
        if (editModal) {
            const closeEditModal = document.getElementById('closeEditModal');
            const editBackdrop = document.getElementById('editBackdrop');
            const closeModal = function() {
                editModal.classList.remove('active');
                window.history.replaceState({}, document.title, 'panel.php');
            };
            closeEditModal?.addEventListener('click', closeModal);
            editBackdrop?.addEventListener('click', closeModal);

            const editFechaInput = editModal.querySelector('input[name="fecha"]');
            const editDiaSelect = editModal.querySelector('select[name="dia"]');
            if (editFechaInput && editDiaSelect) {
                const diasSemana = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
                editFechaInput.addEventListener('change', function() {
                    const fechaSeleccionada = new Date(this.value + 'T00:00:00');
                    if (!isNaN(fechaSeleccionada.getTime())) {
                        const diaNombre = diasSemana[fechaSeleccionada.getDay()];
                        editDiaSelect.value = diaNombre;
                    }
                });
            }
        }
    </script>

</body>
</html>