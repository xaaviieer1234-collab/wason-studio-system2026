<?php
session_start();
date_default_timezone_set('America/Bogota');
include 'conexion.php';

if (!isset($_SESSION['usuario']) || $_SESSION['rol'] != 'cliente' || empty($_SESSION['tenant_id'])) {
    header("Location: " . tenantRedirect('index.php'));
    exit();
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . tenantRedirect('index.php'));
    exit();
}

$cancelacion_config_stmt = $conn->prepare("SELECT cancelacion_cliente_activa, anticipacion_cancelacion_minutos FROM config_horario WHERE id = 1 AND tenant_id = ? LIMIT 1");
$cancelacion_config_stmt->bind_param('i', $tenant_id);
$cancelacion_config_stmt->execute();
$cancelacion_config = $cancelacion_config_stmt->get_result()->fetch_assoc() ?: [];
$cancelacion_config_stmt->close();
$cancelaciones_cliente_activas = intval($cancelacion_config['cancelacion_cliente_activa'] ?? 0) === 1;
$anticipacion_cancelacion_minutos = max(0, intval($cancelacion_config['anticipacion_cancelacion_minutos'] ?? 0));
if (empty($_SESSION['csrf_cancelar_cita'])) {
    $_SESSION['csrf_cancelar_cita'] = bin2hex(random_bytes(32));
}
$cancelacion_feedback = $_SESSION['cancelacion_cita_feedback'] ?? null;
unset($_SESSION['cancelacion_cita_feedback']);

$correo_cliente = trim((string)($_SESSION['usuario'] ?? ''));

function normalizarNombreBarberoCliente($nombre) {
    $nombre = preg_replace('/\s+/u', ' ', trim((string)$nombre));
    return function_exists('mb_strtolower') ? mb_strtolower($nombre, 'UTF-8') : strtolower($nombre);
}

$cliente_usuario_stmt = $conn->prepare("SELECT puntos, fidelizacion_activa FROM usuarios WHERE correo = ? AND tenant_id = ? AND rol = 'cliente' LIMIT 1");
$cliente_usuario_stmt->bind_param('si', $correo_cliente, $tenant_id);
$cliente_usuario_stmt->execute();
$cliente_usuario = $cliente_usuario_stmt->get_result()->fetch_assoc();
$cliente_usuario_stmt->close();
$cliente_puntos = intval($cliente_usuario['puntos'] ?? 0);
$cliente_fidelizacion_activa = intval($cliente_usuario['fidelizacion_activa'] ?? 0) === 1;

$stmt = $conn->prepare("SELECT * FROM citas WHERE usuario_correo = ? AND tenant_id = ? ORDER BY id DESC");
$stmt->bind_param('si', $correo_cliente, $tenant_id);
$stmt->execute();
$resultado = $stmt->get_result();

$citas = [];
while ($fila = $resultado->fetch_assoc()) {
    $citas[] = $fila;
}
$stmt->close();

$barberos_fotos = [];
$barberos_foto_stmt = $conn->prepare("SELECT nombre, foto FROM barberos WHERE tenant_id = ?");
$barberos_foto_stmt->bind_param('i', $tenant_id);
$barberos_foto_stmt->execute();
$barberos_foto_resultado = $barberos_foto_stmt->get_result();
while ($barbero_foto = $barberos_foto_resultado->fetch_assoc()) {
    $barberos_fotos[normalizarNombreBarberoCliente($barbero_foto['nombre'])] = trim((string)($barbero_foto['foto'] ?? ''));
}
$barberos_foto_stmt->close();

function parseFechaCita($cita) {
    $dia = trim($cita['dia'] ?? '');
    $hora = trim($cita['hora'] ?? '');
    if ($dia === '' || $hora === '') {
        return null;
    }

    $fecha_hora = DateTime::createFromFormat('d/m/Y H:i', $dia . ' ' . $hora);
    if (!$fecha_hora) {
        $fecha_hora = DateTime::createFromFormat('d/m/Y', $dia);
    }
    return $fecha_hora;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancelar_cita'])) {
    $mensaje_cancelacion = '';
    $tipo_cancelacion = 'error';
    $token_recibido = (string)($_POST['csrf_token'] ?? '');
    $cita_id_cancelar = intval($_POST['cita_id'] ?? 0);

    if (!hash_equals($_SESSION['csrf_cancelar_cita'], $token_recibido)) {
        $mensaje_cancelacion = 'La solicitud venció. Recarga la página e intenta de nuevo.';
    } elseif (!$cancelaciones_cliente_activas) {
        $mensaje_cancelacion = 'La barbería no tiene habilitada la cancelación en línea.';
    } elseif ($cita_id_cancelar <= 0) {
        $mensaje_cancelacion = 'No se encontró la cita que intentas cancelar.';
    } else {
        $cita_cancelar_stmt = $conn->prepare("SELECT id, dia, hora, asistio FROM citas WHERE id = ? AND usuario_correo = ? AND tenant_id = ? LIMIT 1");
        $cita_cancelar_stmt->bind_param('isi', $cita_id_cancelar, $correo_cliente, $tenant_id);
        $cita_cancelar_stmt->execute();
        $cita_cancelar = $cita_cancelar_stmt->get_result()->fetch_assoc();
        $cita_cancelar_stmt->close();

        if (!$cita_cancelar) {
            $mensaje_cancelacion = 'No se encontró una cita tuya para cancelar.';
        } else {
            $fecha_cancelar = parseFechaCita($cita_cancelar);
            $ahora_cancelar = new DateTime();
            $asistencia_cancelar = strtolower(trim((string)($cita_cancelar['asistio'] ?? '')));
            $estados_pendientes = ['', 'pendiente', 'pending'];
            $segundos_restantes = $fecha_cancelar ? $fecha_cancelar->getTimestamp() - $ahora_cancelar->getTimestamp() : -1;

            if (!$fecha_cancelar || $segundos_restantes < ($anticipacion_cancelacion_minutos * 60) || $segundos_restantes < 0) {
                $mensaje_cancelacion = 'Ya no se puede cancelar esta cita dentro del plazo permitido.';
            } elseif (!in_array($asistencia_cancelar, $estados_pendientes, true)) {
                $mensaje_cancelacion = 'Esta cita ya fue atendida o cerrada y no se puede cancelar.';
            } else {
                $eliminar_cita_stmt = $conn->prepare("DELETE FROM citas WHERE id = ? AND usuario_correo = ? AND tenant_id = ? AND asistio = 'pendiente'");
                $eliminar_cita_stmt->bind_param('isi', $cita_id_cancelar, $correo_cliente, $tenant_id);
                $eliminar_cita_stmt->execute();
                $cita_eliminada = $eliminar_cita_stmt->affected_rows > 0;
                $eliminar_cita_stmt->close();

                if ($cita_eliminada) {
                    $tipo_cancelacion = 'ok';
                    $mensaje_cancelacion = 'La cita se canceló correctamente.';
                } else {
                    $mensaje_cancelacion = 'No se pudo cancelar la cita. Actualiza la página e intenta de nuevo.';
                }
            }
        }
    }

    $_SESSION['cancelacion_cita_feedback'] = ['tipo' => $tipo_cancelacion, 'mensaje' => $mensaje_cancelacion];
    header('Location: cliente_panel.php');
    exit();
}

function obtenerEstadoCita($cita, $fecha_cita) {
    $ahora = new DateTime();
    $estado = 'Pendiente';
    $clase = 'pending';

    if (($cita['asistio'] ?? '') === 'si') {
        $estado = 'Confirmada';
        $clase = 'confirmed';
    } elseif (($cita['asistio'] ?? '') === 'no') {
        $estado = 'Cancelada';
        $clase = 'cancelled';
    } elseif ($fecha_cita && $fecha_cita < $ahora) {
        $estado = 'Finalizada';
        $clase = 'finished';
    }

    return ['estado' => $estado, 'clase' => $clase];
}

function obtenerTextoAsistencia($cita) {
    if (($cita['asistio'] ?? '') === 'si') {
        return ['texto' => 'Asistió', 'clase' => 'confirmed'];
    }
    if (($cita['asistio'] ?? '') === 'no') {
        return ['texto' => 'No asistió', 'clase' => 'cancelled'];
    }
    return ['texto' => 'Pendiente', 'clase' => 'pending'];
}

function obtenerTextoTiempo($fecha_cita) {
    if (!$fecha_cita) {
        return 'Próximamente';
    }

    $ahora = new DateTime();
    $diff = $fecha_cita->diff($ahora);

    if ($fecha_cita < $ahora) {
        return 'Finalizada';
    }

    if ($diff->days === 0) {
        return 'Hoy';
    }

    if ($diff->days === 1) {
        return 'Mañana';
    }

    return 'En ' . $diff->days . ' días';
}

$citas_activas = [];
$citas_pasadas = [];
$contador_servicios = [];
$proxima_cita = null;

foreach ($citas as $cita) {
    $fecha_cita = parseFechaCita($cita);
    $estado_info = obtenerEstadoCita($cita, $fecha_cita);
    $item = [
        'cita' => $cita,
        'fecha' => $fecha_cita,
        'estado_info' => $estado_info,
    ];

    if ($fecha_cita && $fecha_cita >= new DateTime()) {
        $citas_activas[] = $item;
        if ($proxima_cita === null || $fecha_cita < $proxima_cita['fecha']) {
            $proxima_cita = $item;
        }
    } else {
        $citas_pasadas[] = $item;
    }

    $servicio = trim($cita['servicio'] ?? '');
    if ($servicio !== '') {
        $contador_servicios[$servicio] = ($contador_servicios[$servicio] ?? 0) + 1;
    }
}

arsort($contador_servicios);
$servicio_preferido = !empty($contador_servicios) ? array_key_first($contador_servicios) : 'Sin datos';

$servicios_stmt = $conn->prepare("SELECT * FROM servicios WHERE tenant_id = ? ORDER BY nombre ASC");
$servicios_stmt->bind_param('i', $tenant_id);
$servicios_stmt->execute();
$servicios_resultado = $servicios_stmt->get_result();
$servicios = [];
while ($servicio = $servicios_resultado->fetch_assoc()) {
    $servicios[] = $servicio;
}
$servicios_stmt->close();

$anuncios_cliente = [];
$anuncios_stmt = $conn->prepare("SELECT a.* FROM anuncios a WHERE a.estado = 1 AND a.tenant_id = ? AND (a.audiencia = 'global' OR a.audiencia = 'barberia') AND (a.roles_destino = 'todos' OR FIND_IN_SET('cliente', a.roles_destino)) ORDER BY a.fecha_creacion DESC");
$anuncios_stmt->bind_param('i', $tenant_id);
$anuncios_stmt->execute();
$anuncios_resultado = $anuncios_stmt->get_result();
while ($anuncio = $anuncios_resultado->fetch_assoc()) $anuncios_cliente[] = $anuncio;
$anuncios_stmt->close();

$superadmin_banners = [];
$superadmin_banner_stmt = $conn->prepare("SELECT * FROM anuncios WHERE estado = 1 AND audiencia = 'global' AND superadmin_id > 0 ORDER BY fecha_creacion DESC");
$superadmin_banner_stmt->execute();
$superadmin_banner_result = $superadmin_banner_stmt->get_result();
while ($superadmin_banner = $superadmin_banner_result && $superadmin_banner_result->fetch_assoc()) {
    $superadmin_banners[] = $superadmin_banner;
}
$superadmin_banner_stmt->close();
$superadmin_banners = array_values(array_filter($superadmin_banners, function ($banner) {
    $titulo = trim((string)($banner['titulo'] ?? ''));
    $contenido = trim((string)($banner['contenido'] ?? ''));
    return $titulo !== '' || $contenido !== '';
}));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="img/logomdj.png?v=20260930">
    <title>Wason Studio | Mi Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
            --shadow: 0 20px 45px rgba(0,0,0,0.28);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(245,158,11,0.18), transparent 25%),
                linear-gradient(135deg, var(--bg), var(--bg-soft));
            color: var(--text);
            min-height: 100vh;
            padding: 24px;
        }

        .page-shell {
            max-width: 1280px;
            margin: 0 auto;
        }

        .glass {
            background: var(--card);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        .announcement-wall {
            max-width: 1280px;
            margin: 0 auto 18px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .announcement-card {
            width: calc(100% - 8px);
            padding: 14px 18px;
            border-radius: 14px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            box-shadow: 0 12px 24px rgba(7, 12, 24, 0.18);
        }
        .announcement-card + .announcement-card {
            margin-left: 18px;
        }
        .announcement-card.superadmin {
            background: linear-gradient(135deg, rgba(251,191,36,0.22), rgba(245,158,11,0.12));
            border: 1px solid rgba(245,194,66,0.46);
            color: #fdf3c2;
        }
        .announcement-card.admin {
            background: linear-gradient(135deg, rgba(59,130,246,0.12), rgba(15,23,42,0.78));
            border: 1px solid rgba(96,165,250,0.22);
            color: #e0ebff;
        }
        .announcement-card strong {
            font-size: 1rem;
            color: inherit;
        }
        .announcement-card span {
            color: inherit;
            opacity: 0.95;
            font-size: 0.92rem;
            line-height: 1.5;
        }
        .announcement-card.superadmin strong {
            color: #f7d55d;
        }
        .announcement-card.admin strong {
            color: #a5d8ff;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 0 18px;
            border-bottom: 1px solid var(--border);
            margin: 0 0 18px;
        }

        .client-header {
            padding: 20px 24px;
            border-radius: 24px;
            margin-bottom: 20px;
        }

        .client-header .topbar,
        .client-header .hero-card {
            background: transparent;
            border: 0;
            border-radius: 0;
            box-shadow: none;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
        }

        .client-header .topbar { border-bottom: 1px solid var(--border); }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-mark {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            font-weight: 800;
            color: #111827;
            background: linear-gradient(135deg, var(--accent), #fde68a);
        }

        .brand h1 {
            margin: 0;
            font-size: 1.05rem;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }

        .brand p {
            margin: 2px 0 0;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .header-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 16px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 600;
            transition: transform 180ms ease, background 180ms ease, border-color 180ms ease;
        }

        .btn:hover { transform: translateY(-1px); }
        .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            color: #111827;
        }

        .btn-ghost {
            border: 1px solid var(--border);
            color: var(--text);
            background: rgba(255,255,255,0.03);
        }

        .hero-card {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            padding: 0;
            margin: 0;
        }

        .hero-side {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: center;
            gap: 10px;
            min-width: 185px;
        }

        .points-compact {
            min-width: 170px;
            padding: 12px 14px;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(245,158,11,0.16), rgba(251,191,36,0.08));
            border: 1px solid rgba(245,194,66,0.24);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.08);
        }

        .points-label {
            display: block;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #754600;
            margin-bottom: 6px;
        }

        .points-value {
            display: block;
            font-size: 1.65rem;
            font-weight: 800;
            line-height: 1;
            color: #563300;
        }

        .points-meta {
            display: block;
            margin-top: 6px;
            font-size: 0.72rem;
            color: #704500;
        }

        .hero-main {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
        }

        .hero-title {
            margin: 0 0 6px;
            font-size: clamp(1.2rem, 2vw, 1.6rem);
        }

        .hero-copy {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(245,158,11,0.16);
            color: #fde68a;
            font-size: 0.9rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .email-pill {
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border);
            color: var(--muted);
            font-size: 0.8rem;
            width: 100%;
            max-width: 210px;
            justify-content: center;
            text-align: center;
            overflow: hidden;
            text-overflow: ellipsis;
        }


        .content-grid {
            display: grid;
            grid-template-columns: 1.6fr 0.9fr;
            gap: 16px;
            margin-bottom: 20px;
        }

        .panel-card {
            padding: 20px;
            border-radius: 24px;
        }

        .panel-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .panel-head h3 {
            margin: 0;
            font-size: 1.05rem;
        }

        .citas-grid {
            display: grid;
            gap: 14px;
        }

        .cita-card {
            border-radius: 18px;
            padding: 18px;
            border: 1px solid rgba(255,255,255,0.06);
            background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            transition: transform 180ms ease, box-shadow 180ms ease;
        }

        .cita-card:hover { transform: translateY(-4px); box-shadow: 0 18px 40px rgba(0,0,0,0.45); }

        .cita-card.highlight {
            background: linear-gradient(135deg, rgba(16,185,129,0.12), rgba(16,185,129,0.06));
            border-color: rgba(16,185,129,0.18);
            box-shadow: 0 18px 44px rgba(16,185,129,0.08);
        }

        .cita-card .left {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .cita-actions { display: flex; align-items: center; margin-left: auto; }
        .btn-cancelar-cita {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 13px;
            border: 1px solid rgba(251,113,133,0.4);
            border-radius: 10px;
            background: rgba(251,113,133,0.1);
            color: #fecdd3;
            font: inherit;
            font-size: 0.84rem;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-cancelar-cita:not(:disabled) {
            background: #c62828;
            border-color: #a61f2c;
            color: #fff !important;
        }
        .btn-cancelar-cita:hover:not(:disabled) { background: #a61f2c; border-color: #8f1724; }
        .btn-cancelar-cita:disabled { cursor: not-allowed; opacity: 0.55; }
        .btn-cancelar-cita svg { width: 16px; height: 16px; flex: 0 0 auto; }
        .cancelacion-feedback { margin: 0 0 16px; padding: 12px 16px; border: 1px solid var(--border); border-radius: 12px; }
        .cancelacion-feedback.ok { color: #a7f3d0; border-color: rgba(52,211,153,0.35); background: rgba(52,211,153,0.1); }
        .cancelacion-feedback.error { color: #fecdd3; border-color: rgba(251,113,133,0.35); background: rgba(251,113,133,0.1); }

        .icon-box {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: rgba(255,255,255,0.03);
            color: var(--muted);
            flex-shrink: 0;
            border: 1px solid rgba(255,255,255,0.04);
        }

        .icon-box .barber-photo {
            width: 100%;
            height: 100%;
            border-radius: inherit;
            object-fit: cover;
            display: block;
        }

        .cita-card.highlight .icon-box {
            background: linear-gradient(135deg, rgba(16,185,129,0.18), rgba(16,185,129,0.08));
            color: #064e3b;
            border-color: rgba(16,185,129,0.16);
        }

        .cita-meta h4 {
            margin: 0 0 6px;
            font-size: 1rem;
        }

        .cita-meta .time-label {
            margin-top: 6px;
            font-size: 0.86rem;
            color: var(--muted);
            font-weight: 700;
        }

        .cita-meta .when { display:block; color: var(--muted); font-size:0.92rem; }

        .cita-meta p {
            margin: 0;
            color: var(--muted);
            font-size: 0.92rem;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .badge.confirmed { background: rgba(52,211,153,0.16); color: #a7f3d0; }
        .badge.pending { background: rgba(251,191,36,0.16); color: #fde68a; }
        .badge.cancelled { background: rgba(251,113,133,0.16); color: #fecdd3; }
        .badge.finished { background: rgba(148,163,184,0.16); color: #e2e8f0; }

        /* Removed action buttons to simplify UI for clients. */

        .empty-state {
            text-align: center;
            padding: 24px 12px;
            border-radius: 18px;
            border: 1px dashed rgba(255,255,255,0.14);
            background: rgba(255,255,255,0.03);
        }

        .empty-state svg { margin-bottom: 10px; color: #f59e0b; }
        .empty-state h4 { margin: 0 0 8px; }
        .empty-state p { margin: 0 0 14px; color: var(--muted); }

        .service-list {
            display: grid;
            gap: 10px;
        }

        .service-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .service-item:last-child { border-bottom: none; }
        .service-name { font-weight: 600; }
        .service-price { color: #fcd34d; font-weight: 700; }

        .history-card {
            padding: 20px;
            border-radius: 24px;
            margin-top: 20px;
        }

        details summary {
            cursor: pointer;
            list-style: none;
            font-weight: 700;
            color: #f8fafc;
        }

        details summary::-webkit-details-marker { display: none; }
        .history-list {
            margin-top: 14px;
            display: grid;
            gap: 10px;
        }

        .history-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            color: var(--muted);
        }

        .history-item:last-child { border-bottom: none; }

        @media (max-width: 960px) {
            .content-grid { grid-template-columns: 1fr; }
            .quick-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 640px) {
            body { padding: 14px; }
            .client-header { padding: 16px; }
            .panel-card, .history-card { padding: 16px; }
            .hero-card { flex-direction: column; align-items: flex-start; }
            .client-header .topbar { flex-direction: column; align-items: flex-start; gap: 12px; }
            .cita-card { flex-direction: column; gap:10px; align-items: stretch; }
            .cita-meta { width: 100%; }
            .icon-box { width: 48px; height:48px; }
            .cita-card .left { gap: 10px; }
            .cita-actions, .btn-cancelar-cita { width: 100%; }
        }
    </style>
    <link rel="stylesheet" href="responsive.css?v=2">
    <link rel="stylesheet" href="app-theme.css?v=2">
</head>
<body>
    <?php $announcement_cards = []; foreach ($superadmin_banners as $banner): $bannerTitulo = trim((string)($banner['titulo'] ?? '')); $bannerContenido = trim((string)($banner['contenido'] ?? '')); if ($bannerTitulo !== '' || $bannerContenido !== '') { $announcement_cards[] = ['type' => 'superadmin', 'titulo' => $bannerTitulo, 'contenido' => $bannerContenido]; } endforeach; foreach ($anuncios_cliente as $anuncio): $titulo = trim((string)($anuncio['titulo'] ?? '')); $contenido = trim((string)($anuncio['contenido'] ?? '')); if ($titulo !== '' || $contenido !== '') { $announcement_cards[] = ['type' => 'admin', 'titulo' => $titulo, 'contenido' => $contenido]; } endforeach; ?>
    <?php if (!empty($announcement_cards)): ?><section class="announcement-wall"><?php foreach ($announcement_cards as $card): ?><div class="announcement-card <?php echo htmlspecialchars($card['type']); ?>"><?php if (!empty($card['titulo'])): ?><strong><?php echo htmlspecialchars($card['titulo']); ?></strong><?php endif; ?><?php if (!empty($card['contenido'])): ?><span><?php echo nl2br(htmlspecialchars($card['contenido'])); ?></span><?php endif; ?></div><?php endforeach; ?></section><?php endif; ?>
    <div class="page-shell">
        <?php if (is_array($cancelacion_feedback) && !empty($cancelacion_feedback['mensaje'])): ?>
            <div class="cancelacion-feedback <?php echo ($cancelacion_feedback['tipo'] ?? '') === 'ok' ? 'ok' : 'error'; ?>" role="status"><?php echo htmlspecialchars($cancelacion_feedback['mensaje']); ?></div>
        <?php endif; ?>
        <section class="client-header glass">
        <header class="topbar">
            <div class="brand">
                <div class="brand-mark">WS</div>
                <div>
                    <p>Panel de cliente</p>
                    <h1>Wason Studio</h1>
                </div>
            </div>
            <div class="header-actions">
                <a href="index.php" class="btn btn-primary">+ Agendar nueva cita</a>
                <a href="cliente_panel.php?logout=1" class="btn btn-ghost">Cerrar sesión</a>
            </div>
        </header>

        <section class="hero-card">
            <div class="hero-main">
                <div>
                    <h2 class="hero-title">Hola, <?php echo htmlspecialchars(explode('@', $correo_cliente)[0]); ?></h2>
                    <p class="hero-copy">Tu agenda luce impecable. Revisa tus próximas reservas, explora servicios y mantén tu estilo al día.</p>
                </div>
            </div>
            <div class="hero-side">
                <?php if ($cliente_fidelizacion_activa): ?>
                    <div class="points-compact">
                        <span class="points-label">Puntos</span>
                        <span class="points-value"><?php echo number_format($cliente_puntos, 0, ',', '.'); ?></span>
                        <span class="points-meta">Fidelidad activa</span>
                    </div>
                <?php endif; ?>
                <div class="pill email-pill"><?php echo htmlspecialchars($correo_cliente); ?></div>
            </div>
        </section>
        </section>

        <section class="content-grid">
            <div class="panel-card glass">
                <div class="panel-head">
                    <h3>Mis próximas citas</h3>
                    <span class="pill"><?php echo count($citas_activas); ?> en agenda</span>
                </div>

                <?php if (!empty($citas_activas)): ?>
                    <div class="citas-grid">
                        <?php foreach ($citas_activas as $item):
                            $cita = $item['cita'];
                            $estado_info = $item['estado_info'];
                            $fecha_cita = $item['fecha'];
                            $foto_barbero_cita = $barberos_fotos[normalizarNombreBarberoCliente($cita['barbero'] ?? '')] ?? '';
                            $asistencia_valor = strtolower(trim((string)($cita['asistio'] ?? '')));
                            $segundos_restantes_cancelacion = $fecha_cita ? $fecha_cita->getTimestamp() - time() : -1;
                            $puede_cancelar_cita = $cancelaciones_cliente_activas
                                && $segundos_restantes_cancelacion >= ($anticipacion_cancelacion_minutos * 60)
                                && $segundos_restantes_cancelacion >= 0
                                && in_array($asistencia_valor, ['', 'pendiente', 'pending'], true);
                            $es_proxima = ($proxima_cita && isset($proxima_cita['cita']['id']) && $proxima_cita['cita']['id'] == $cita['id']);
                        ?>
                            <article class="cita-card<?php echo $es_proxima ? ' highlight' : ''; ?>">
                                <div class="left">
                                    <div class="icon-box">
                                        <?php if ($foto_barbero_cita !== ''): ?>
                                            <img class="barber-photo" src="<?php echo htmlspecialchars($foto_barbero_cita); ?>" alt="Foto de <?php echo htmlspecialchars($cita['barbero'] ?? 'barbero'); ?>">
                                        <?php else: ?>
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="4" width="18" height="18" rx="4"></rect>
                                            <path d="M16 2v4"></path>
                                            <path d="M8 2v4"></path>
                                            <path d="M3 10h18"></path>
                                        </svg>
                                        <?php endif; ?>
                                    </div>
                                    <div class="cita-meta">
                                        <span class="badge <?php echo $estado_info['clase']; ?>"><?php echo htmlspecialchars($estado_info['estado']); ?></span>
                                        <h4><?php echo htmlspecialchars($cita['servicio']); ?></h4>
                                        <p class="when"><?php echo htmlspecialchars($cita['dia']); ?> · <?php echo htmlspecialchars($cita['hora']); ?></p>
                                        <?php $asistencia_info = obtenerTextoAsistencia($cita); ?>
                                        <p class="when">Asistencia: <strong style="color: var(--text);"><?php echo htmlspecialchars($asistencia_info['texto']); ?></strong></p>
                                        <div class="time-label"><?php echo htmlspecialchars(obtenerTextoTiempo($fecha_cita)); ?> • <strong style="color:var(--muted);">Barbero:</strong> <?php echo htmlspecialchars($cita['barbero'] ?? 'No asignado'); ?></div>
                                    </div>
                                </div>
                                <?php if ($cancelaciones_cliente_activas): ?>
                                    <form class="cita-actions" method="post" onsubmit="return confirm('¿Deseas cancelar esta cita?');">
                                        <input type="hidden" name="cancelar_cita" value="1">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_cancelar_cita']); ?>">
                                        <input type="hidden" name="cita_id" value="<?php echo intval($cita['id']); ?>">
                                        <button class="btn-cancelar-cita" type="submit" <?php echo $puede_cancelar_cita ? '' : 'disabled'; ?> title="<?php echo $puede_cancelar_cita ? 'Cancelar esta cita' : 'El plazo de cancelación ya cerró'; ?>">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="m19 6-1 14H6L5 6"></path><path d="M10 11v5"></path><path d="M14 11v5"></path></svg>
                                            <span><?php echo $puede_cancelar_cita ? 'Cancelar cita' : 'Plazo cerrado'; ?></span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M8 2v4"></path>
                            <path d="M16 2v4"></path>
                            <rect x="3" y="4" width="18" height="18" rx="4"></rect>
                            <path d="M3 10h18"></path>
                        </svg>
                        <h4>Tu agenda está vacía</h4>
                        <p>Aún no tienes reservas activas. Reserva tu primera cita y disfruta del mejor servicio.</p>
                        <a href="index.php" class="btn btn-primary">¡Agendar mi primera cita!</a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel-card glass">
                <div class="panel-head">
                    <h3>Catálogo rápido</h3>
                    <span class="pill">Servicios</span>
                </div>
                <div class="service-list" id="catalogo">
                    <?php if (!empty($servicios)): ?>
                        <?php foreach ($servicios as $servicio): ?>
                            <div class="service-item">
                                <div>
                                    <div class="service-name"><?php echo htmlspecialchars($servicio['nombre']); ?></div>
                                </div>
                                <div class="service-price">$<?php echo number_format((float)$servicio['precio'], 0, ',', '.'); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: var(--muted);">No hay servicios registrados aún.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="history-card glass">
            <details open>
                <summary>Historial de citas pasadas</summary>
                <div class="history-list">
                    <?php if (!empty($citas_pasadas)): ?>
                        <?php foreach ($citas_pasadas as $item):
                            $cita = $item['cita'];
                            $asistencia_info = obtenerTextoAsistencia($cita);
                        ?>
                            <div class="history-item">
                                <span><?php echo htmlspecialchars($cita['dia']); ?> · <?php echo htmlspecialchars($cita['hora']); ?></span>
                                <span><?php echo htmlspecialchars($cita['servicio']); ?></span>
                                <span style="color: <?php echo $asistencia_info['clase'] === 'confirmed' ? '#34d399' : ($asistencia_info['clase'] === 'cancelled' ? '#fb7185' : '#fbbf24'); ?>;"><?php echo htmlspecialchars($asistencia_info['texto']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="history-item">No hay historial disponible todavía.</div>
                    <?php endif; ?>
                </div>
            </details>
        </section>
    </div>
</body>
</html>