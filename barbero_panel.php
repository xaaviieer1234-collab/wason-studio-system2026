<?php
include 'conexion.php';

if (($_SESSION['rol'] ?? '') !== 'barbero' || empty($_SESSION['barbero_id'])) {
    header('Location: ' . tenantRedirect('index.php'));
    exit();
}

$barbero_id = intval($_SESSION['barbero_id']);
$barbero_result = $conn->query("SELECT * FROM barberos WHERE id=$barbero_id AND " . tenantFilter());
$barbero = $barbero_result ? $barbero_result->fetch_assoc() : null;
if (!$barbero) {
    session_destroy();
    header('Location: ' . tenantRedirect('index.php'));
    exit();
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . tenantRedirect('index.php'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_horario_barbero'])) {
    $hora_inicio = trim($_POST['hora_inicio'] ?? '');
    $hora_fin = trim($_POST['hora_fin'] ?? '');
    $dias = !empty($_POST['dias_trabajo']) && is_array($_POST['dias_trabajo']) ? implode(',', array_map('trim', $_POST['dias_trabajo'])) : '';
    $stmt = $conn->prepare("UPDATE barberos SET hora_inicio=?, hora_fin=?, dias_trabajo=? WHERE id=? AND " . tenantFilter());
    $stmt->bind_param('sssi', $hora_inicio, $hora_fin, $dias, $barbero_id);
    $stmt->execute();
    header('Location: barbero_panel.php?guardado=1');
    exit();
}

if (isset($_GET['mark_attended_id'])) {
    $id = intval($_GET['mark_attended_id']);
    $barbero_nombre = trim((string)($barbero['nombre'] ?? ''));
    $cita_stmt = $conn->prepare("SELECT usuario_correo, asistio FROM citas WHERE id = ? AND barbero = ? AND tenant_id = ? LIMIT 1");
    $cita_stmt->bind_param('isi', $id, $barbero_nombre, $tenant_id);
    $cita_stmt->execute();
    $cita_actual = $cita_stmt->get_result()->fetch_assoc();
    $cita_stmt->close();

    if ($cita_actual && ($cita_actual['asistio'] ?? 'pendiente') !== 'si') {
        $stmt = $conn->prepare("UPDATE citas SET asistio = 'si' WHERE id = ? AND barbero = ? AND tenant_id = ?");
        $stmt->bind_param('isi', $id, $barbero_nombre, $tenant_id);
        $stmt->execute();
        $asistencia_actualizada = $stmt->affected_rows > 0;
        $stmt->close();

        $cliente_correo = trim((string)($cita_actual['usuario_correo'] ?? ''));
        if ($asistencia_actualizada && $cliente_correo !== '' && $cliente_correo !== 'Invitado') {
            $config_stmt = $conn->prepare("SELECT fidelizacion_habilitada, puntos_por_servicio FROM barberias WHERE id = ? LIMIT 1");
            $config_stmt->bind_param('i', $tenant_id);
            $config_stmt->execute();
            $config_fidelizacion = $config_stmt->get_result()->fetch_assoc();
            $config_stmt->close();

            $cliente_stmt = $conn->prepare("SELECT id, fidelizacion_activa FROM usuarios WHERE correo = ? AND tenant_id = ? AND rol = 'cliente' LIMIT 1");
            $cliente_stmt->bind_param('si', $cliente_correo, $tenant_id);
            $cliente_stmt->execute();
            $cliente_fidelizacion = $cliente_stmt->get_result()->fetch_assoc();
            $cliente_stmt->close();

            if ($config_fidelizacion && intval($config_fidelizacion['fidelizacion_habilitada']) === 1 && $cliente_fidelizacion && intval($cliente_fidelizacion['fidelizacion_activa']) === 1) {
                $puntos = max(1, intval($config_fidelizacion['puntos_por_servicio'] ?? 10));
                $puntos_stmt = $conn->prepare("UPDATE usuarios SET puntos = puntos + ? WHERE id = ? AND tenant_id = ?");
                $puntos_stmt->bind_param('iii', $puntos, $cliente_fidelizacion['id'], $tenant_id);
                $puntos_stmt->execute();
                $puntos_stmt->close();
            }
        }
    }
    header('Location: barbero_panel.php');
    exit();
}

if (isset($_GET['mark_absent_id'])) {
    $id = intval($_GET['mark_absent_id']);
    $barbero_nombre = trim((string)($barbero['nombre'] ?? ''));
    $stmt = $conn->prepare("UPDATE citas SET asistio = 'no' WHERE id = ? AND barbero = ? AND tenant_id = ?");
    $stmt->bind_param('isi', $id, $barbero_nombre, $tenant_id);
    $stmt->execute();
    $stmt->close();
    header('Location: barbero_panel.php');
    exit();
}

$nombre_sql = trim((string)($barbero['nombre'] ?? ''));
$citas = [];
$stmt = $conn->prepare("SELECT * FROM citas WHERE barbero = ? AND tenant_id = ? ORDER BY id DESC");
$stmt->bind_param('si', $nombre_sql, $tenant_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $citas[] = $row;
$anuncios_barbero = [];
$anuncios_resultado = $conn->query("SELECT * FROM anuncios a WHERE a.estado=1 AND " . anuncioVisiblePara('barbero', 'a') . " ORDER BY a.fecha_creacion DESC");
while ($anuncio = $anuncios_resultado->fetch_assoc()) $anuncios_barbero[] = $anuncio;
$superadmin_banners = [];
$superadmin_banner_result = $conn->query("SELECT * FROM anuncios WHERE estado=1 AND audiencia='global' AND superadmin_id > 0 ORDER BY fecha_creacion DESC");
while ($superadmin_banner = $superadmin_banner_result && $superadmin_banner_result->fetch_assoc()) {
    $superadmin_banners[] = $superadmin_banner;
}
$superadmin_banners = array_values(array_filter($superadmin_banners, function ($banner) {
    $titulo = trim((string)($banner['titulo'] ?? ''));
    $contenido = trim((string)($banner['contenido'] ?? ''));
    return $titulo !== '' || $contenido !== '';
}));
$dias = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
diasort($citas);

function diasort(&$citas) {
    usort($citas, function ($a, $b) {
        $fecha_a = DateTime::createFromFormat('d/m/Y H:i', trim($a['dia']) . ' ' . trim($a['hora'])) ?: new DateTime('9999-12-31');
        $fecha_b = DateTime::createFromFormat('d/m/Y H:i', trim($b['dia']) . ' ' . trim($b['hora'])) ?: new DateTime('9999-12-31');
        return $fecha_a <=> $fecha_b;
    });
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Panel del barbero</title>
<link rel="icon" type="image/png" href="img/logomdj.png?v=20260930">
<style>
:root{--accent:<?php echo htmlspecialchars($barbero['color'] ?: '#d4af37'); ?>;--bg:#0b1220;--surface:#141e2d;--border:#2a3a50;--text:#f5f7fa;--muted:#9eafc4}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top left,color-mix(in srgb,var(--accent) 18%,transparent),transparent 28%),var(--bg);color:var(--text);font-family:system-ui,sans-serif;padding:24px}main{max-width:1100px;margin:auto}.top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px}.identity{display:flex;align-items:center;gap:14px}.avatar{width:58px;height:58px;border-radius:50%;object-fit:cover;border:3px solid var(--accent);background:var(--surface)}h1{margin:0;font-size:1.5rem}.muted{color:var(--muted)}.btn{display:inline-block;padding:10px 14px;border-radius:9px;background:var(--accent);color:#151515;text-decoration:none;font-weight:700;border:0;cursor:pointer}.announcement-wall{max-width:1100px;margin:0 auto 18px;display:flex;flex-direction:column;gap:12px}.announcement-card{width:calc(100% - 8px);padding:14px 18px;border-radius:14px;display:flex;flex-direction:column;gap:6px;box-shadow:0 12px 24px rgba(7,12,24,.18);transition:transform 180ms ease}.announcement-card:hover{transform:translateY(-1px)}.announcement-card + .announcement-card{margin-left:18px}.announcement-card.superadmin{background:linear-gradient(135deg, rgba(251,191,36,.22), rgba(245,158,11,.12));border:1px solid rgba(245,194,66,.46);color:#fdf3c2}.announcement-card.admin{background:linear-gradient(135deg, rgba(59,130,246,.12), rgba(15,23,42,.78));border:1px solid rgba(96,165,250,.22);color:#e0ebff}.announcement-card strong{font-size:1rem;color:inherit}.announcement-card span{color:inherit;opacity:.95;font-size:.92rem;line-height:1.5}.announcement-card.superadmin strong{color:#f7d55d}.announcement-card.admin strong{color:#a5d8ff}.grid{display:grid;grid-template-columns:320px 1fr;gap:18px}.card{background:rgba(20,30,45,.9);border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:18px}h2{margin:0 0 16px;color:var(--accent);font-size:1.05rem}label{display:grid;gap:7px;margin:13px 0;color:var(--muted)}input,select{width:100%;padding:11px;border-radius:8px;background:#0e1725;color:var(--text);border:1px solid var(--border);font:inherit}.days{display:grid;gap:8px}.day{display:flex;gap:8px;align-items:center;color:var(--text)}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px 10px;border-bottom:1px solid var(--border)}th{color:var(--muted);font-size:.8rem;text-transform:uppercase}.status{font-weight:700}.pending{color:#f5cf69}.confirmed{color:#76e0a0}.cancelled{color:#ff9090}.actions{display:flex;gap:8px;flex-wrap:wrap}.small{padding:7px 9px;font-size:.82rem;background:transparent;color:var(--accent);border:1px solid color-mix(in srgb,var(--accent) 55%,transparent)}@media(max-width:800px){body{padding:14px}.grid{grid-template-columns:1fr}.table-wrap{overflow:auto}table{min-width:760px}}
</style><link rel="stylesheet" href="responsive.css?v=2"><link rel="stylesheet" href="app-theme.css?v=2"></head>
<body><main>
<header class="top"><div class="identity"><img class="avatar" src="<?php echo htmlspecialchars($barbero['foto'] ?: 'img/logo.jpeg'); ?>" alt="Perfil"><div><h1><?php echo htmlspecialchars($barbero['nombre']); ?></h1><div class="muted">Panel personal del barbero</div></div></div><a class="btn" href="barbero_panel.php?logout=1">Cerrar sesión</a></header>
<?php $announcement_cards = []; foreach ($superadmin_banners as $banner): $bannerTitulo = trim((string)($banner['titulo'] ?? '')); $bannerContenido = trim((string)($banner['contenido'] ?? '')); if ($bannerTitulo !== '' || $bannerContenido !== '') { $announcement_cards[] = ['type' => 'superadmin', 'titulo' => $bannerTitulo, 'contenido' => $bannerContenido]; } endforeach; foreach ($anuncios_barbero as $anuncio): $titulo = trim((string)($anuncio['titulo'] ?? '')); $contenido = trim((string)($anuncio['contenido'] ?? '')); if ($titulo !== '' || $contenido !== '') { $announcement_cards[] = ['type' => 'admin', 'titulo' => $titulo, 'contenido' => $contenido]; } endforeach; ?>
<?php if (!empty($announcement_cards)): ?><section class="announcement-wall"><?php foreach ($announcement_cards as $card): ?><div class="announcement-card <?php echo htmlspecialchars($card['type']); ?>"><?php if (!empty($card['titulo'])): ?><strong><?php echo htmlspecialchars($card['titulo']); ?></strong><?php endif; ?><?php if (!empty($card['contenido'])): ?><span><?php echo nl2br(htmlspecialchars($card['contenido'])); ?></span><?php endif; ?></div><?php endforeach; ?></section><?php endif; ?>
<?php if (isset($_GET['guardado'])): ?><div class="card" style="border-color:var(--accent)">Horario actualizado correctamente.</div><?php endif; ?>
<div class="grid barber-dashboard"><div class="accordion-section barber-schedule"><div class="accordion-header" onclick="toggleAccordion(this)"><span>Mi horario de trabajo</span><span class="accordion-toggle">▼</span></div><div class="accordion-content"><section class="card"><form method="post"><input type="hidden" name="guardar_horario_barbero" value="1"><label>Hora de entrada<input type="time" name="hora_inicio" value="<?php echo htmlspecialchars(substr($barbero['hora_inicio'] ?? '',0,5)); ?>" required></label><label>Hora de salida<input type="time" name="hora_fin" value="<?php echo htmlspecialchars(substr($barbero['hora_fin'] ?? '',0,5)); ?>" required></label><label>Días de trabajo</label><div class="days"><?php foreach($dias as $dia): ?><label class="day"><input type="checkbox" name="dias_trabajo[]" value="<?php echo $dia; ?>" <?php echo in_array($dia,array_filter(array_map('trim',explode(',',$barbero['dias_trabajo'] ?? ''))),true)?'checked':''; ?>><?php echo $dia; ?></label><?php endforeach; ?></div><button class="btn" type="submit">Guardar horario</button></form></section></div></div>
<section class="card"><h2>Mis citas agendadas <span class="muted">(<?php echo count($citas); ?>)</span></h2><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Hora</th><th>Cliente</th><th>Teléfono</th><th>Servicio</th><th>Estado</th><th></th></tr></thead><tbody><?php foreach($citas as $cita): ?><tr><td><?php echo htmlspecialchars($cita['dia']); ?></td><td><?php echo htmlspecialchars($cita['hora']); ?></td><td><?php echo htmlspecialchars($cita['nombre']); ?></td><td><?php echo htmlspecialchars($cita['telefono']); ?></td><td><?php echo htmlspecialchars($cita['servicio']); ?></td><td class="status <?php echo $cita['asistio']==='si'?'confirmed':($cita['asistio']==='no'?'cancelled':'pending'); ?>"><?php echo $cita['asistio']==='si'?'Confirmada':($cita['asistio']==='no'?'No asistió':'Pendiente'); ?></td><td class="actions"><a class="btn small" href="barbero_panel.php?mark_attended_id=<?php echo $cita['id']; ?>">Asistió</a><a class="btn small" href="barbero_panel.php?mark_absent_id=<?php echo $cita['id']; ?>">No asistió</a></td></tr><?php endforeach; ?><?php if (!$citas): ?><tr><td colspan="7" class="muted">No tienes citas agendadas.</td></tr><?php endif; ?></tbody></table></div></section></div>
</main><script>
function toggleAccordion(header) {
    const content = header.nextElementSibling;
    const toggle = header.querySelector('.accordion-toggle');
    const isActive = content.classList.contains('active');
    content.classList.toggle('active', !isActive);
    header.classList.toggle('active', !isActive);
    toggle.classList.toggle('active', !isActive);
}
</script></body></html>
