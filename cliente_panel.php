<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['usuario']) || $_SESSION['rol'] != 'cliente') {
    header("Location: index.php");
    exit();
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

$correo_cliente = $_SESSION['usuario'];
$resultado = $conn->query("SELECT * FROM citas WHERE usuario_correo = '$correo_cliente' ORDER BY id DESC");

$citas = [];
while ($fila = $resultado->fetch_assoc()) {
    $citas[] = $fila;
}

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

$servicios_resultado = $conn->query("SELECT * FROM servicios ORDER BY nombre ASC");
$servicios = [];
while ($servicio = $servicios_resultado->fetch_assoc()) {
    $servicios[] = $servicio;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-radius: 24px;
            margin-bottom: 20px;
        }

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
            padding: 24px;
            border-radius: 28px;
            margin-bottom: 20px;
        }

        .hero-main {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
        }

        .avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            font-size: 1.2rem;
            font-weight: 800;
            color: #111827;
            background: linear-gradient(135deg, #fde68a, var(--accent));
            border: 2px solid rgba(255,255,255,0.16);
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
            .topbar, .hero-card, .panel-card, .history-card { padding: 16px; }
            .hero-card { flex-direction: column; align-items: flex-start; }
            .topbar { flex-direction: column; align-items: flex-start; gap: 12px; }
            .cita-card { flex-direction: column; gap:10px; align-items: stretch; }
            .cita-meta { width: 100%; }
            .icon-box { width: 48px; height:48px; }
            .cita-card .left { gap: 10px; }
        }
    </style>
</head>
<body>
    <div class="page-shell">
        <header class="topbar glass">
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

        <section class="hero-card glass">
            <div class="hero-main">
                <div class="avatar"><?php echo strtoupper(substr($correo_cliente, 0, 1)); ?></div>
                <div>
                    <p class="pill">● Cliente premium</p>
                    <h2 class="hero-title">Hola, <?php echo htmlspecialchars(explode('@', $correo_cliente)[0]); ?></h2>
                    <p class="hero-copy">Tu agenda luce impecable. Revisa tus próximas reservas, explora servicios y mantén tu estilo al día.</p>
                </div>
            </div>
            <div class="pill"><?php echo htmlspecialchars($correo_cliente); ?></div>
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
                            $es_proxima = ($proxima_cita && isset($proxima_cita['cita']['id']) && $proxima_cita['cita']['id'] == $cita['id']);
                        ?>
                            <article class="cita-card<?php echo $es_proxima ? ' highlight' : ''; ?>">
                                <div class="left">
                                    <div class="icon-box">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="4" width="18" height="18" rx="4"></rect>
                                            <path d="M16 2v4"></path>
                                            <path d="M8 2v4"></path>
                                            <path d="M3 10h18"></path>
                                        </svg>
                                    </div>
                                    <div class="cita-meta">
                                        <span class="badge <?php echo $estado_info['clase']; ?>"><?php echo htmlspecialchars($estado_info['estado']); ?></span>
                                        <h4><?php echo htmlspecialchars($cita['servicio']); ?></h4>
                                        <p class="when"><?php echo htmlspecialchars($cita['dia']); ?> · <?php echo htmlspecialchars($cita['hora']); ?></p>
                                        <p class="when"><?php echo $fecha_cita ? htmlspecialchars($fecha_cita->format('d/m/Y')) : ''; ?></p>
                                        <?php $asistencia_info = obtenerTextoAsistencia($cita); ?>
                                        <p class="when">Asistencia: <strong style="color: var(--text);"><?php echo htmlspecialchars($asistencia_info['texto']); ?></strong></p>
                                        <div class="time-label"><?php echo htmlspecialchars(obtenerTextoTiempo($fecha_cita)); ?> • <strong style="color:var(--muted);">Barbero:</strong> <?php echo htmlspecialchars($cita['barbero'] ?? 'No asignado'); ?></div>
                                    </div>
                                </div>
                                <!-- Acciones removidas para simplificar la vista del cliente -->
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