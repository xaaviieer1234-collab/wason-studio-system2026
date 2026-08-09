<?php
session_start();
date_default_timezone_set('America/Bogota');
include 'conexion.php';

// 1. PROCESAR FORMULARIO DE AGENDAMIENTO
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['agendar'])) {
    $dia = $conn->real_escape_string($_POST['dia']);
    $hora = $conn->real_escape_string($_POST['hora']);
    $nombre = $conn->real_escape_string($_POST['nombre']);
    $telefono = $conn->real_escape_string($_POST['telefono']);
    $servicio = $conn->real_escape_string($_POST['servicio']);
    $barbero = $conn->real_escape_string($_POST['barbero'] ?? '');
    // Si tiene la sesión iniciada guarda su correo, si no, queda vacío
    $usuario_correo = isset($_SESSION['usuario']) ? $_SESSION['usuario'] : 'Invitado';

    $fecha_hora = parseDateTimeCita($dia, $hora);
    $ahora = new DateTime();
    if (!$fecha_hora) {
        echo "<script>alert('Formato de fecha/hora inválido.'); window.location.href='index.php';</script>";
        exit();
    }
    if ($fecha_hora < $ahora) {
        echo "<script>alert('No puedes agendar una cita en una fecha u hora pasada.'); window.location.href='index.php';</script>";
        exit();
    }

    $hora = $fecha_hora->format('H:i');
    $check = $conn->query("SELECT * FROM citas WHERE dia='$dia' AND hora='$hora'");
    if ($check->num_rows == 0) {
        // Insertar el correo del usuario y el barbero seleccionado
        $sql = "INSERT INTO citas (dia, hora, nombre, telefono, servicio, barbero, usuario_correo) VALUES ('$dia', '$hora', '$nombre', '$telefono', '$servicio', '$barbero', '$usuario_correo')";
        if ($conn->query($sql)) {
            $_SESSION['ultima_cita'] = [
                'dia' => $dia,
                'hora' => $hora,
                'nombre' => $nombre,
                'telefono' => $telefono,
                'servicio' => $servicio,
                'barbero' => $barbero,
                'usuario_correo' => $usuario_correo,
                'fecha_registro' => date('d/m/Y H:i')
            ];
            header('Location: confirmacion.php');
            exit();
        }
    } else {
        echo "<script>alert('Lo sentimos, este turno ya fue ocupado.'); window.location.href='index.php';</script>";
        exit();
    }
}

function parseDateTimeCita($dia, $hora) {
    $fecha_obj = DateTime::createFromFormat('d/m/Y', trim($dia));
    if (!$fecha_obj) {
        return false;
    }

    $hora = trim($hora);
    if (!preg_match('/^(\d{1,2})(?::(\d{2}))?\s*([AP]M)$/i', $hora, $matches)) {
        return false;
    }

    $hour = intval($matches[1]);
    $minute = isset($matches[2]) && $matches[2] !== '' ? intval($matches[2]) : 0;
    $meridiem = strtoupper($matches[3]);

    if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59) {
        return false;
    }

    if ($meridiem === 'PM' && $hour < 12) {
        $hour += 12;
    }
    if ($meridiem === 'AM' && $hour === 12) {
        $hour = 0;
    }

    $fecha_obj->setTime($hour, $minute, 0);
    return $fecha_obj;
}

function normalizeFechaCita($dia) {
    $fecha_obj = DateTime::createFromFormat('d/m/Y', trim($dia));
    return $fecha_obj ? $fecha_obj->format('d/m/Y') : trim($dia);
}

function normalizeFechaBloqueada($fecha) {
    $fecha = trim($fecha);
    $fecha_obj = DateTime::createFromFormat('d/m/Y', $fecha);
    if ($fecha_obj) {
        return $fecha_obj->format('d/m/Y');
    }
    $fecha_obj = DateTime::createFromFormat('Y-m-d', $fecha);
    if ($fecha_obj) {
        return $fecha_obj->format('d/m/Y');
    }
    return $fecha;
}

function normalizeHoraCita($hora) {
    $hora = trim($hora);
    if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*([AP]M)$/i', $hora, $matches)) {
        $hour = intval($matches[1]);
        $minute = isset($matches[2]) && $matches[2] !== '' ? intval($matches[2]) : 0;
        $meridiem = strtoupper($matches[3]);
        if ($meridiem === 'PM' && $hour < 12) {
            $hour += 12;
        }
        if ($meridiem === 'AM' && $hour === 12) {
            $hour = 0;
        }
        return DateTime::createFromFormat('H:i', sprintf('%02d:%02d', $hour, $minute))->format('H:i');
    }
    $timestamp = strtotime($hora);
    return $timestamp !== false ? date('H:i', $timestamp) : $hora;
}

// Función para obtener la fecha en formato dd/mm/yyyy basado en el nombre del día y la semana actual
function obtenerFechaDelDia($dia_nombre, $lunes_timestamp) {
    $dias_nombres = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    $indice = array_search($dia_nombre, $dias_nombres);
    if ($indice === false) return null;
    $timestamp = $lunes_timestamp + ($indice * 86400);
    return date('d/m/Y', $timestamp);
}

// 2. OBTENER CITAS OCUPADAS PARA LA TABLA
$citas_ocupadas = [];
$resultado = $conn->query("SELECT dia, hora FROM citas");
while($fila = $resultado->fetch_assoc()) {
    $hora_norm = normalizeHoraCita($fila['hora']);
    $citas_ocupadas[$fila['dia']][] = $hora_norm;
}

$horarios_bloqueados = [];
$resultado_bloqueos = $conn->query("SELECT fecha, hora FROM horarios_bloqueados");
while ($fila_bloqueo = $resultado_bloqueos->fetch_assoc()) {
    $fecha_key = normalizeFechaBloqueada($fila_bloqueo['fecha']);
    $hora_norm = normalizeHoraCita($fila_bloqueo['hora']);
    $horarios_bloqueados[$fecha_key][] = $hora_norm;
}

// Guardar el lunes actual en timestamp para uso en JavaScript
$hoy = strtotime('today');
$dia_semana = date('N', $hoy);
$segundos_atras = ($dia_semana - 1) * 86400;
$lunes_actual = $hoy - $segundos_atras;
$lunes_actual_timestamp = $lunes_actual;

// Asegurar tabla de configuración y obtener horario
$conn->query("CREATE TABLE IF NOT EXISTS config_horario (
    id INT PRIMARY KEY,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    intervalo INT NOT NULL DEFAULT 60,
    dias_bloqueados VARCHAR(255) NOT NULL DEFAULT '',
    fechas_bloqueadas VARCHAR(255) NOT NULL DEFAULT ''
)");
$conn->query("INSERT IGNORE INTO config_horario (id, hora_inicio, hora_fin, intervalo) VALUES (1, '08:00:00', '19:00:00', 60)");
$config_result = $conn->query("SELECT * FROM config_horario WHERE id=1");
$config = $config_result->fetch_assoc();
$dias_bloqueados = array_filter(array_map('trim', explode(',', $config['dias_bloqueados'] ?? '')));
$fechas_bloqueadas = array_filter(array_map('normalizeFechaBloqueada', array_filter(array_map('trim', explode(',', $config['fechas_bloqueadas'] ?? '')))));

// Asegurar tabla de servicios
$conn->query("CREATE TABLE IF NOT EXISTS servicios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    precio DECIMAL(10,2) NOT NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$servicios_result = $conn->query("SELECT * FROM servicios ORDER BY nombre ASC");
$servicios = [];
while ($fila_servicio = $servicios_result->fetch_assoc()) {
    $servicios[] = $fila_servicio;
}

$barberos_result = $conn->query("SELECT * FROM barberos ORDER BY nombre ASC");
$barberos = [];
while ($fila_barbero = $barberos_result->fetch_assoc()) {
    $barberos[] = $fila_barbero;
}

$week_offset = max(0, isset($_GET['week']) ? intval($_GET['week']) : 0);
$hoy_navegacion = strtotime('today') + ($week_offset * 7 * 86400);
$dia_semana_navegacion = date('N', $hoy_navegacion);
$segundos_atras_navegacion = ($dia_semana_navegacion - 1) * 86400;
$lunes_navegacion = $hoy_navegacion - $segundos_atras_navegacion;
$fecha_inicio_semana = date('d/m', $lunes_navegacion);
$fecha_fin_semana = date('d/m', $lunes_navegacion + 6 * 86400);

function generarHoras($inicio, $fin, $intervalo) {
    $horas = [];
    $start = strtotime($inicio);
    $end = strtotime($fin);
    if ($start === false || $end === false || $start > $end) {
        $start = strtotime('08:00');
        $end = strtotime('19:00');
        $intervalo = 60;
    }
    for ($t = $start; $t <= $end; $t += $intervalo * 60) {
        $horas[] = date('g A', $t);
    }
    return $horas;
}

// Función auxiliar para pintar las celdas
function verificarCelda($dia, $hora, $citas, $dias_bloqueados, $fechas_bloqueadas = [], $fechas_completas = [], $horarios_bloqueados = []) {
    $fecha_completa = normalizeFechaCita($fechas_completas[$dia] ?? $dia);
    if (in_array($fecha_completa, $fechas_bloqueadas, true)) {
        return "<td style='background-color: #331111; color: #ff5555; cursor: not-allowed;'>Cerrado</td>";
    }
    if (in_array($dia, $dias_bloqueados)) {
        return "<td style='background-color: #331111; color: #ff5555; cursor: not-allowed;'>Cerrado</td>";
    }
    $fecha_hora = parseDateTimeCita($fecha_completa, $hora);
    $ahora = new DateTime();
    if ($fecha_hora && $fecha_hora < $ahora) {
        return "<td style='background-color: #111; color: #888; cursor: not-allowed;'>Pasado</td>";
    }
    $hora_norm = normalizeHoraCita($hora);
    if (!empty($horarios_bloqueados[$fecha_completa]) && in_array($hora_norm, $horarios_bloqueados[$fecha_completa], true)) {
        return "<td style='background-color: #331111; color: #ff5555; cursor: not-allowed;'>Cerrado</td>";
    }
    if (isset($citas[$fecha_completa]) && in_array($hora_norm, $citas[$fecha_completa])) {
        return "<td style='background-color: #331111; color: #ff5555; cursor: not-allowed;'>Ocupado</td>";
    } else {
        return "<td class='celda-disponible' onclick=\"abrirAgendamiento('$dia', '$hora')\">$hora</td>";
    }
}

function obtenerEstadoTurno($dia, $hora, $citas, $dias_bloqueados, $fechas_bloqueadas = [], $fechas_completas = [], $horarios_bloqueados = []) {
    $fecha_completa = normalizeFechaCita($fechas_completas[$dia] ?? $dia);
    if (in_array($fecha_completa, $fechas_bloqueadas, true)) {
        return 'closed';
    }
    if (in_array($dia, $dias_bloqueados, true)) {
        return 'closed';
    }
    $fecha_hora = parseDateTimeCita($fecha_completa, $hora);
    $ahora = new DateTime();
    if ($fecha_hora && $fecha_hora < $ahora) {
        return 'past';
    }
    $hora_norm = normalizeHoraCita($hora);
    if (!empty($horarios_bloqueados[$fecha_completa]) && in_array($hora_norm, $horarios_bloqueados[$fecha_completa], true)) {
        return 'closed';
    }
    if (isset($citas[$fecha_completa]) && in_array($hora_norm, $citas[$fecha_completa])) {
        return 'occupied';
    }
    return 'available';
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wason Studio | Barbería</title>
    <link rel="icon" type="image/jpeg" href="img/favicon.jpeg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/swiper/swiper-bundle.min.css">
    <style>
        :root {
            --bg: #060816;
            --bg-soft: #0f172a;
            --card: rgba(255,255,255,0.05);
            --card-strong: rgba(255,255,255,0.08);
            --border: rgba(255,255,255,0.12);
            --text: #f8fafc;
            --muted: #94a3b8;
            --accent: #f59e0b;
            --accent-strong: #d97706;
            --success: #34d399;
            --warning: #fbbf24;
            --danger: #fb7185;
            --shadow: 0 20px 48px rgba(0, 0, 0, 0.3);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(245,158,11,0.18), transparent 24%),
                linear-gradient(135deg, var(--bg), var(--bg-soft));
            min-height: 100vh;
            padding: 24px;
        }

        .glass {
            background: var(--card);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        .page-shell {
            max-width: 980px;
            margin: 0 auto;
            padding: 0 14px;
        }

        .topbar,
        .week-nav,
        .agenda-layout,
        footer {
            width: 100%;
            max-width: 980px;
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 22px;
            border-radius: 24px;
            margin-bottom: 18px;
        }

        .brand { display: flex; align-items: center; gap: 14px; }
        .brand-mark {
            width: 52px; height: 52px; border-radius: 18px;
            display: grid; place-items: center;
            background: linear-gradient(135deg, rgba(245,158,11,0.95), rgba(253,230,138,0.95));
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.14);
            box-shadow: 0 14px 30px rgba(245,158,11,0.18);
        }
        .brand-logo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            filter: saturate(1.1) brightness(0.95);
        }
        .brand h1 { margin: 0; font-size: 1rem; letter-spacing: 0.18em; text-transform: uppercase; }
        .brand p { margin: 3px 0 0; color: var(--muted); font-size: 0.9rem; }

        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; padding: 11px 16px; border-radius: 999px;
            text-decoration: none; font-weight: 700; transition: transform 180ms ease, border-color 180ms ease, background 180ms ease;
            border: 1px solid transparent;
            cursor: pointer;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            color: #111827;
        }
        .btn-ghost {
            border-color: var(--border);
            color: var(--text);
            background: rgba(255,255,255,0.03);
        }


        .week-nav {
            display: flex; justify-content: space-between; align-items: center; gap: 12px;
            padding: 16px 20px; border-radius: 22px; margin: 0 auto 18px;
            flex-wrap: wrap;
            width: 100%;
        }
        .week-nav a {
            text-decoration: none; color: var(--text); display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 14px; border-radius: 999px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08);
            transition: border-color 180ms ease, transform 180ms ease;
        }
        .week-nav a:hover { transform: translateY(-1px); border-color: rgba(245,158,11,0.45); }
        .week-nav .disabled { opacity: 0.55; pointer-events: none; }
        .week-range { color: var(--muted); font-weight: 600; }

        .agenda-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            justify-content: center;
            gap: 16px;
            align-items: start;
            width: 100%;
            max-width: 980px;
            margin: 0 auto;
        }

        .panel-card {
            padding: 20px; border-radius: 24px;
            min-width: 0;
        }
        .section-title { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 14px; }
        .section-title h3 { margin: 0; font-size: 1rem; }
        .section-title span { color: var(--muted); font-size: 0.9rem; }

        .day-grid {
            display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px;
        }
        .day-card {
            border: 1px solid rgba(255,255,255,0.08); background: rgba(255,255,255,0.03); color: var(--text);
            border-radius: 18px; padding: 14px 12px; text-align: left; cursor: pointer; transition: all 180ms ease;
        }
        .day-card:hover, .day-card.active { border-color: rgba(245,158,11,0.45); transform: translateY(-1px); box-shadow: inset 0 0 0 1px rgba(245,158,11,0.18); }
        .day-name { display: block; font-weight: 700; margin-bottom: 4px; }
        .day-date { display: block; color: var(--muted); font-size: 0.86rem; margin-bottom: 8px; }
        .day-status { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 999px; font-size: 0.78rem; font-weight: 700; }
        .day-status.open { background: rgba(52,211,153,0.12); color: #a7f3d0; }
        .day-status.closed { background: rgba(251,113,133,0.12); color: #fecdd3; }
        .day-status.full { background: rgba(251,191,36,0.12); color: #fde68a; }

        .slot-panel { display: grid; }
        .slot-panel:not(.active) { display: none; }
        .slot-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
        .slot-btn {
            border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.03); color: var(--text); border-radius: 14px; padding: 12px 10px;
            text-align: center; cursor: pointer; transition: all 180ms ease;
        }
        .slot-modal {
            opacity: 0;
            visibility: hidden;
            display: grid;
            position: fixed;
            inset: 0;
            background: rgba(3, 9, 26, 0.86);
            z-index: 1150;
            justify-content: center;
            align-items: center;
            padding: 18px;
            transition: opacity 220ms ease, visibility 220ms ease;
        }
        .slot-modal.active {
            opacity: 1;
            visibility: visible;
        }
        .slot-card {
            width: min(520px, 100%);
            max-height: min(90vh, 760px);
            padding: 24px 22px 18px;
            border-radius: 26px;
            background: rgba(11, 18, 40, 0.98);
            border: 1px solid rgba(255,255,255,0.16);
            backdrop-filter: blur(20px);
            box-shadow: 0 30px 90px rgba(0,0,0,0.36);
            overflow-y: auto;
            transform: translateY(24px) scale(0.98);
            transition: transform 220ms ease;
        }
        .slot-modal.active .slot-card { transform: translateY(0) scale(1); }
        .slot-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
        }
        .slot-header strong { font-size: 1.12rem; color: #f8fafc; }
        .slot-header span { color: #9cb3d8; }
        .slot-close {
            width: 38px; height: 38px; border-radius: 50%;
            border: 1px solid rgba(255,255,255,0.12); background: rgba(255,255,255,0.08);
            color: #f8fafc; display: grid; place-items: center; cursor: pointer;
        }
        .slot-btn:hover:not(:disabled) { border-color: rgba(16,185,129,0.75); box-shadow: 0 0 0 1px rgba(16,185,129,0.15); }
        .slot-btn.available { color: #ecfdf5; background: rgba(16,185,129,0.16); border-color: rgba(16,185,129,0.35); }
        .slot-btn.available:hover:not(:disabled) { background: rgba(16,185,129,0.24); }
        .slot-btn.occupied, .slot-btn.past, .slot-btn.closed { opacity: 0.55; cursor: not-allowed; }
        .slot-btn.selected { border-color: rgba(245,158,11,0.75); background: rgba(245,158,11,0.16); }
        .slot-btn small { display: block; margin-top: 4px; color: var(--muted); font-size: 0.74rem; }

        .confirm-overlay {
            opacity: 0;
            visibility: hidden;
            display: grid;
            position: fixed;
            inset: 0;
            background: rgba(3, 9, 26, 0.8);
            z-index: 1200;
            justify-content: center;
            align-items: center;
            padding: 18px;
            transition: opacity 220ms ease, visibility 220ms ease;
        }
        .confirm-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .confirm-card {
            position: relative;
            width: min(440px, 100%);
            max-height: min(90vh, 720px);
            padding: 24px 22px 20px;
            border-radius: 28px;
            overflow-y: auto;
            background: rgba(11, 18, 40, 0.94);
            border: 1px solid rgba(255,255,255,0.14);
            backdrop-filter: blur(18px);
            box-shadow: 0 28px 80px rgba(0,0,0,0.35);
            transform: translateY(22px) scale(0.98);
            transition: transform 220ms ease;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .confirm-card::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
        }
        .confirm-overlay.active .confirm-card {
            transform: translateY(0) scale(1);
        }
        .confirm-close {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: rgba(255,255,255,0.08);
            color: #f8fafc;
            font-size: 20px;
            cursor: pointer;
            border: 1px solid rgba(255,255,255,0.12);
        }
        .confirm-card .section-title { padding-right: 48px; }
        .confirm-card .section-title h3 { color: #fce77d; font-size: 1.18rem; margin-bottom: 6px; }
        .confirm-card .section-title span { color: #99a7c7; font-size: 0.92rem; }
        .confirm-card:not(.active) .confirm-body { display: block; }
        .summary-box { display: grid; gap: 12px; margin: 20px 0 16px; }
        .summary-box > div { display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; border-radius: 16px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.08); color: #c8d0e2; }
        .summary-box strong { color: #f8fafc; }
        .form-stack { display: grid; gap: 14px; }
        .field { display: grid; gap: 8px; }
        .field label { font-size: 0.92rem; color: #9cb3d8; }
        .field input, .field select {
            width: 100%; border: 1px solid rgba(255,255,255,0.14); background: rgba(255,255,255,0.07); color: #eef2ff; border-radius: 14px; padding: 13px 14px; font: inherit;
        }
        .field input:focus, .field select:focus {
            outline: none;
            border-color: rgba(245,158,11,0.55);
            box-shadow: 0 0 0 4px rgba(245,158,11,0.12);
        }
        #servicio {
            background: #ffffff;
            color: #111111;
            border-color: rgba(214, 214, 214, 0.8);
        }
        #servicio option {
            color: #111111;
            background: #ffffff;
        }
        .btn-confirm {
            width: 100%; border: 0; margin-top: 10px; padding: 14px 14px; border-radius: 16px; font-weight: 800; color: #111827;
            background: linear-gradient(135deg, rgba(245,158,11,1), rgba(217,119,6,0.95));
            box-shadow: 0 14px 30px rgba(245,158,11,0.25);
            cursor: pointer;
            transition: transform 180ms ease, box-shadow 180ms ease;
        }
        .btn-confirm:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 38px rgba(245,158,11,0.28);
        }

        footer {
            margin: 20px auto 0;
            padding: 24px;
            border-radius: 24px;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border);
            width: 100%;
        }
        .footer-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
        .footer-section h3 { margin: 0 0 10px; color: #fde68a; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.16em; }
        .footer-section p, .footer-section a, .footer-section li { color: var(--muted); font-size: 0.94rem; line-height: 1.7; }
        .footer-section a { text-decoration: none; }
        .footer-section a:hover { color: #fcd34d; }
        .socials { display: flex; gap: 10px; margin-top: 10px; }
        .socials a {
            width: 36px; height: 36px; border-radius: 50%; display: grid; place-items: center; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
        }
        .footer-bottom { margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.08); text-align: center; color: var(--muted); font-size: 0.88rem; }

        .modal { display: none; position: fixed; inset: 0; background: rgba(2,6,23,0.82); justify-content: center; align-items: center; z-index: 1000; padding: 20px; }
        .modal-contenido {
            width: min(420px, 100%); border-radius: 20px; padding: 24px; background: rgba(15,23,42,0.95); border: 1px solid var(--border); box-shadow: var(--shadow);
        }
        .cerrar-modal { float: right; color: var(--muted); cursor: pointer; font-size: 1.2rem; }
        .modal h3 { margin: 0 0 16px; color: #fde68a; }
        .modal .field { margin-bottom: 12px; }

        @media (max-width: 980px) {
            .topbar {
                flex-direction: column;
                align-items: stretch;
                gap: 16px;
            }
            .week-nav {
                flex-direction: column;
                align-items: stretch;
            }
            .week-nav .week-range {
                order: -1;
            }
            .agenda-layout { grid-template-columns: 1fr; }
            .footer-grid { grid-template-columns: 1fr; }
            .day-grid, .slot-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .slot-card { max-height: 78vh; }
            .footer { padding: 20px; }
        }

        @media (max-width: 640px) {
            body { padding: 12px; }
            header, .topbar, .week-nav, .panel-card, footer, .slot-card, .confirm-card { padding: 16px; }
            .topbar, .week-nav { gap: 14px; }
            .brand { flex-wrap: wrap; }
            .brand h1 { font-size: 0.95rem; }
            .day-grid, .slot-grid { grid-template-columns: 1fr; }
            .day-card { min-height: 120px; }
            .slot-cell, .schedule-cell { min-height: 72px; }
            .slot-btn { padding: 14px 12px; }
            .slot-card { width: 100%; max-height: 85vh; padding: 18px; }
            .confirm-card { width: 100%; max-height: 85vh; padding: 18px; }
            .summary-box > div { flex-direction: column; align-items: flex-start; gap: 8px; }
            .footer-grid { gap: 14px; }
            .footer { padding: 18px; }
        }
    </style>
</head>
<body>
    <div class="page-shell">
        <header class="topbar glass">
            <div class="brand">
                <div class="brand-mark">
                <img src="img/logo.jpeg" alt="Wason Studio" class="brand-logo">
            </div>
                <div>
                    <p>Reserva tu turno</p>
                    <h1>Wason Studio</h1>
                </div>
            </div>
            <a href="login.php" class="btn btn-ghost" onclick="abrirModal('modalLogin'); return false;">Iniciar sesión</a>
        </header>

        <?php
        // Componente de anuncios administrables: muestra solo si hay anuncios activos
        $anuncios_activos = [];
        $res_ad = $conn->query("SELECT * FROM anuncios WHERE estado=1 ORDER BY fecha_creacion DESC");
        while ($row_ad = $res_ad->fetch_assoc()) { $anuncios_activos[] = $row_ad; }
        if (count($anuncios_activos) > 0):
        ?>
            <section style="max-width:980px;margin:18px auto;">
                <?php if (count($anuncios_activos) === 1):
                    $ad = $anuncios_activos[0]; ?>
                    <div style="border-radius:16px;padding:16px;background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));border:1px solid rgba(255,255,255,0.06);display:flex;gap:12px;align-items:center;justify-content:center;text-align:center;flex-wrap:wrap;">
                        <?php if (!empty($ad['imagen'])): ?>
                            <img src="img/anuncios/<?php echo htmlspecialchars($ad['imagen']); ?>" style="width:120px;height:72px;object-fit:cover;border-radius:8px;flex-shrink:0;">
                        <?php endif; ?>
                        <div style="max-width:calc(100% - 160px);min-width:220px;">
                            <h3 style="margin:0 0 6px;color:#fcd34d"><?php echo htmlspecialchars($ad['titulo']); ?></h3>
                            <p style="margin:0;color:var(--muted)"><?php echo htmlspecialchars($ad['contenido']); ?></p>
                        </div>
                        <?php if (!empty($ad['enlace'])): ?>
                            <a href="<?php echo htmlspecialchars($ad['enlace']); ?>" style="padding:8px 12px;border-radius:10px;background:rgba(245,158,11,0.14);color:#fde68a;text-decoration:none">Ver</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="swiper-container" style="--swiper-navigation-color: #d4af37;">
                        <div class="swiper-wrapper">
                            <?php foreach ($anuncios_activos as $ad): ?>
                                <div class="swiper-slide" style="padding:12px;display:flex;justify-content:center;">
                                    <div style="border-radius:12px;padding:14px;background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));border:1px solid rgba(255,255,255,0.06);display:flex;gap:12px;align-items:center;justify-content:center;text-align:center;flex-wrap:wrap;max-width:900px;width:100%;">
                                        <?php if (!empty($ad['imagen'])): ?>
                                            <img src="img/anuncios/<?php echo htmlspecialchars($ad['imagen']); ?>" style="width:140px;height:84px;object-fit:cover;border-radius:8px;flex-shrink:0;">
                                        <?php endif; ?>
                                        <div style="max-width:calc(100% - 180px);min-width:220px;">
                                            <h3 style="margin:0 0 6px;color:#fcd34d"><?php echo htmlspecialchars($ad['titulo']); ?></h3>
                                            <p style="margin:0;color:var(--muted)"><?php echo htmlspecialchars($ad['contenido']); ?></p>
                                        </div>
                                        <?php if (!empty($ad['enlace'])): ?>
                                            <a href="<?php echo htmlspecialchars($ad['enlace']); ?>" style="padding:8px 12px;border-radius:10px;background:rgba(245,158,11,0.12);color:#fde68a;text-decoration:none">Ir</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="swiper-button-prev"></div>
                        <div class="swiper-button-next"></div>
                        <div class="swiper-pagination"></div>
                    </div>
                    <style>
                        .swiper-container{width:100%}
                        .swiper-slide{display:flex}
                    </style>
                    <script src="https://unpkg.com/swiper/swiper-bundle.min.js"></script>
                    <script>
                        document.addEventListener('DOMContentLoaded', function(){
                            var swiper = new Swiper('.swiper-container', {
                                loop: true,
                                autoplay: { delay: 4500, disableOnInteraction: false },
                                slidesPerView: 1,
                                spaceBetween: 16,
                                pagination: { el: '.swiper-pagination', clickable: true },
                                navigation: { nextEl: '.swiper-button-next', prevEl: '.swiper-button-prev' }
                            });
                        });
                    </script>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <div class="week-nav glass">
            <?php if ($week_offset > 0): ?>
                <a href="?week=<?php echo $week_offset - 1; ?>">← Semana anterior</a>
            <?php else: ?>
                <span class="btn disabled" style="opacity:0.55;">← Semana anterior</span>
            <?php endif; ?>

            <div class="week-range">
                Semana del <strong><?php echo $fecha_inicio_semana; ?></strong> al <strong><?php echo $fecha_fin_semana; ?></strong>
            </div>

            <a href="?week=<?php echo $week_offset + 1; ?>">Semana siguiente →</a>
        </div>

        <?php
        $hoy = strtotime('today') + ($week_offset * 7 * 86400);
        $dia_semana = date('N', $hoy);
        $segundos_atras = ($dia_semana - 1) * 86400;
        $lunes = $hoy - $segundos_atras;

        $dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
        $horas = generarHoras($config['hora_inicio'], $config['hora_fin'], intval($config['intervalo']));
        $fechas_completas = [];
        for ($i = 0; $i < 7; $i++) {
            $fecha = $lunes + ($i * 86400);
            $fechas_completas[$dias[$i]] = date('d/m/Y', $fecha);
        }
        ?>

        <section class="agenda-layout">
            <div class="panel-card glass">
                <div class="section-title">
                    <h3>Selecciona un día</h3>
                    <span>Elige la jornada ideal</span>
                </div>
                <div class="day-grid">
                    <?php foreach ($dias as $index => $dia):
                        $fecha_dia = $fechas_completas[$dia] ?? '';
                        $fecha_obj = DateTime::createFromFormat('d/m/Y', $fecha_dia);
                        $fecha_label = $fecha_obj ? $fecha_obj->format('d M') : $fecha_dia;
                        $disponibles = 0;
                        foreach ($horas as $hora) {
                            if (obtenerEstadoTurno($dia, $hora, $citas_ocupadas, $dias_bloqueados, $fechas_bloqueadas, $fechas_completas, $horarios_bloqueados) === 'available') {
                                $disponibles++;
                            }
                        }
                        $status_class = 'open';
                        $status_text = $disponibles > 0 ? $disponibles . ' cupos' : 'Agotado';
                        $fecha_actual_dia = $fechas_completas[$dia] ?? '';
                        $fecha_bloqueada_exacta = in_array($fecha_actual_dia, $fechas_bloqueadas, true);
                        if (in_array($dia, $dias_bloqueados, true) || $fecha_bloqueada_exacta || $disponibles === 0) {
                            if (in_array($dia, $dias_bloqueados, true) || $fecha_bloqueada_exacta) {
                                $status_class = 'closed';
                                $status_text = 'Cerrado';
                            } else {
                                $status_class = 'full';
                                $status_text = 'Agotado';
                            }
                        }
                    ?>
                        <button type="button" class="day-card <?php echo $index === 0 ? 'active' : ''; ?>" data-day="<?php echo htmlspecialchars($dia); ?>" data-index="<?php echo $index; ?>" data-date="<?php echo htmlspecialchars($fecha_dia); ?>">
                            <span class="day-name"><?php echo htmlspecialchars($dia); ?></span>
                            <span class="day-date"><?php echo htmlspecialchars($fecha_label); ?></span>
                            <span class="day-status <?php echo $status_class; ?>"><?php echo htmlspecialchars($status_text); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

        </section>

        <div class="slot-modal" id="slotModal">
            <div class="slot-card glass">
                <div class="slot-header">
                    <div>
                        <strong id="slotModalTitle">Selecciona una hora</strong>
                        <span id="slotModalSubtitle">Elige una hora disponible para el día seleccionado.</span>
                    </div>
                    <button type="button" class="slot-close" id="closeSlotModal">×</button>
                </div>
                <div class="slot-panels">
                    <?php foreach ($dias as $index => $dia): ?>
                        <div class="slot-panel <?php echo $index === 0 ? 'active' : ''; ?>" id="panel-<?php echo $index; ?>">
                            <div class="slot-grid">
                                <?php foreach ($horas as $hora):
                                    $estado = obtenerEstadoTurno($dia, $hora, $citas_ocupadas, $dias_bloqueados, $fechas_bloqueadas, $fechas_completas, $horarios_bloqueados);
                                    $label = 'Disponible';
                                    if ($estado === 'occupied') { $label = 'Ocupado'; }
                                    elseif ($estado === 'past') { $label = 'Pasado'; }
                                    elseif ($estado === 'closed') { $label = 'Cerrado'; }
                                ?>
                                    <button type="button" class="slot-btn <?php echo $estado; ?>" data-day="<?php echo htmlspecialchars($dia); ?>" data-hour="<?php echo htmlspecialchars($hora); ?>" data-date="<?php echo htmlspecialchars($fechas_completas[$dia]); ?>" <?php echo $estado === 'available' ? '' : 'disabled'; ?>>
                                        <strong><?php echo htmlspecialchars($hora); ?></strong>
                                        <small><?php echo htmlspecialchars($label); ?></small>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="confirm-overlay" id="confirmOverlay">
            <aside class="confirm-card glass" id="confirmCard">
                <span class="confirm-close" id="confirmClose">×</span>
                <div class="section-title">
                    <h3>Confirmación rápida</h3>
                    <span>Tu reserva</span>
                </div>
                <div class="confirm-body">
                <div class="summary-box">
                    <div><span>Día</span><strong id="summaryDay">—</strong></div>
                    <div><span>Hora</span><strong id="summaryHour">—</strong></div>
                    <div><span>Fecha</span><strong id="summaryDate">—</strong></div>
                    <div><span>Barbero</span><strong id="summaryBarbero">Cualquiera</strong></div>
                </div>

                <form action="index.php" method="POST" id="formReserva">
                    <input type="hidden" name="agendar" value="1">
                    <input type="hidden" id="inputDia" name="dia">
                    <input type="hidden" id="inputHora" name="hora">
                    <div class="form-stack">
                        <div class="field">
                            <label for="servicio">Servicio</label>
                            <select id="servicio" name="servicio" required>
                                <?php if (count($servicios) > 0): ?>
                                    <?php foreach ($servicios as $servicio): ?>
                                        <option value="<?php echo htmlspecialchars($servicio['nombre']); ?>"><?php echo htmlspecialchars($servicio['nombre']); ?> • $<?php echo number_format($servicio['precio'], 0, ',', '.'); ?></option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="Corte Clasico">Corte clásico</option>
                                    <option value="Perfilado Barba">Perfilado de barba</option>
                                    <option value="Combo Completo">Combo completo</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="barbero">Barbero</label>
                            <select id="barbero" name="barbero">
                                <option value="">Cualquiera</option>
                                <?php if (count($barberos) > 0): ?>
                                    <?php foreach ($barberos as $barbero_item): ?>
                                        <option value="<?php echo htmlspecialchars($barbero_item['nombre']); ?>"><?php echo htmlspecialchars($barbero_item['nombre']); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="nombre">Nombre completo</label>
                            <input id="nombre" type="text" name="nombre" placeholder="Tu nombre" required>
                        </div>
                        <div class="field">
                            <label for="telefono">Teléfono</label>
                            <input id="telefono" type="tel" name="telefono" placeholder="Tu teléfono" required>
                        </div>
                    </div>
                    <button type="submit" class="btn-confirm">Confirmar y agendar cita</button>
                </form>
            </div>
            </aside>
        </div>

        <footer>
            <div class="footer-grid">
                <div class="footer-section">
                    <h3>Wason Studio</h3>
                    <p>Barbería de clase mundial con estilo, precisión y una experiencia premium en cada visita.</p>
                    <div class="socials">
                        <a href="https://www.facebook.com/share/1CJtEeXSqh/" target="_blank" rel="noopener">f</a>
                        <a href="https://www.instagram.com/wasonsbarbers?igsh=YzlpbWlpMmZiMGd5" target="_blank" rel="noopener">📷</a>
                        <a href="https://wa.me/573164397612" target="_blank" rel="noopener">💬</a>
                    </div>
                </div>
                <div class="footer-section">
                    <h3>Horarios</h3>
                    <ul>
                        <li>Lunes - Domingo: 08:00 AM - 07:00 PM</li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h3>Contacto</h3>
                    <p><a href="tel:+573164397612">(+57) 316 439 7612</a></p>
                    <p><a href="mailto:wasonbarber2020@gmail.com">wasonbarber2020@gmail.com</a></p>
                    <p>Carrera 14, Barrio Obreo<br>La Unión, Nariño</p>
                </div>
            </div>
            <div class="footer-bottom">© 2026 Wason Studio · Diseñado para una experiencia elegante y rápida.</div>
        </footer>
    </div>

    <div id="modalLogin" class="modal">
        <div class="modal-contenido">
            <span class="cerrar-modal" onclick="cerrarModal('modalLogin')">×</span>
            <h3>Iniciar sesión</h3>
            <form action="login.php" method="POST">
                <div class="field">
                    <label>Correo electrónico</label>
                    <input type="email" name="correo" required>
                </div>
                <div class="field">
                    <label>Contraseña</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit" class="btn-confirm">Ingresar</button>
                <p style="text-align:center; margin-top:12px; color:var(--muted);">
                    <a href="registro.php" style="color:#fde68a; text-decoration:none;">¿No tienes cuenta? Regístrate</a>
                </p>
            </form>
        </div>
    </div>

    <script>
        const dayCards = document.querySelectorAll('.day-card');
        const slotPanels = document.querySelectorAll('.slot-panel');
        const slotButtons = document.querySelectorAll('.slot-btn');
        const confirmOverlay = document.getElementById('confirmOverlay');
        const confirmCard = document.getElementById('confirmCard');
        const confirmClose = document.getElementById('confirmClose');
        const summaryDay = document.getElementById('summaryDay');
        const summaryHour = document.getElementById('summaryHour');
        const summaryDate = document.getElementById('summaryDate');
        const summaryBarbero = document.getElementById('summaryBarbero');
        const inputDia = document.getElementById('inputDia');
        const inputHora = document.getElementById('inputHora');
        const formReserva = document.getElementById('formReserva');
        const selectBarbero = document.getElementById('barbero');
        const slotModal = document.getElementById('slotModal');
        const slotModalTitle = document.getElementById('slotModalTitle');
        const slotModalSubtitle = document.getElementById('slotModalSubtitle');
        const closeSlotModal = document.getElementById('closeSlotModal');

        function abrirPanelHoras(card) {
            dayCards.forEach(item => item.classList.remove('active'));
            slotPanels.forEach(panel => panel.classList.remove('active'));
            card.classList.add('active');
            const panel = document.getElementById('panel-' + card.dataset.index);
            if (panel) panel.classList.add('active');
            slotModalTitle.textContent = 'Horas disponibles para ' + card.dataset.day;
            slotModalSubtitle.textContent = 'Selecciona una hora para ' + card.dataset.date + '.';
            slotModal.classList.add('active');
        }

        dayCards.forEach(card => {
            card.addEventListener('click', () => {
                abrirPanelHoras(card);
            });
        });

        slotButtons.forEach(button => {
            button.addEventListener('click', () => {
                if (button.disabled) return;
                slotButtons.forEach(item => item.classList.remove('selected'));
                button.classList.add('selected');
                summaryDay.textContent = button.dataset.day;
                summaryHour.textContent = button.dataset.hour;
                summaryDate.textContent = button.dataset.date;
                inputDia.value = button.dataset.date;
                inputHora.value = button.dataset.hour;
                cerrarSlotModal();
                confirmOverlay.classList.add('active');
            });
        });

        if (summaryBarbero && selectBarbero) {
            const updateBarberoSummary = () => {
                summaryBarbero.textContent = selectBarbero.value || 'Cualquiera';
            };
            selectBarbero.addEventListener('change', updateBarberoSummary);
            updateBarberoSummary();
        }

        formReserva.addEventListener('submit', function (event) {
            if (!inputDia.value || !inputHora.value) {
                event.preventDefault();
                alert('Selecciona un día y una hora antes de confirmar.');
            }
        });

        function cerrarConfirmOverlay() {
            confirmOverlay.classList.remove('active');
        }

        function cerrarSlotModal() {
            slotModal.classList.remove('active');
        }

        if (closeSlotModal) {
            closeSlotModal.addEventListener('click', cerrarSlotModal);
        }

        if (confirmClose) {
            confirmClose.addEventListener('click', cerrarConfirmOverlay);
        }

        confirmOverlay.addEventListener('click', function (event) {
            if (event.target === confirmOverlay) {
                cerrarConfirmOverlay();
            }
        });

        slotModal.addEventListener('click', function (event) {
            if (event.target === slotModal) {
                cerrarSlotModal();
            }
        });

        function abrirModal(id) {
            document.getElementById(id).style.display = 'flex';
        }

        function cerrarModal(id) {
            document.getElementById(id).style.display = 'none';
        }
    </script>
</body>
</html>