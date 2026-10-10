<?php
include 'conexion.php';

if (($_SESSION['rol'] ?? '') !== 'superadmin') {
    header('Location: index.php');
    exit();
}

$perfil_feedback = $_SESSION['superadmin_perfil_feedback'] ?? null;
unset($_SESSION['superadmin_perfil_feedback']);

if (empty($_SESSION['csrf_superadmin_password'])) {
    $_SESSION['csrf_superadmin_password'] = bin2hex(random_bytes(32));
}

function superadminRedirect() {
    header('Location: superadmin.php');
    exit();
}

function textoFormulario($value) {
    return trim((string) ($value ?? ''));
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_password_superadmin'])) {
    $estado = 'error';
    $csrf_recibido = (string) ($_POST['csrf_token'] ?? '');
    $superadmin_id = intval($_SESSION['superadmin_id'] ?? 0);
    $password_actual = (string) ($_POST['password_actual'] ?? '');
    $password_nueva = (string) ($_POST['password_nueva'] ?? '');
    $password_confirmacion = (string) ($_POST['password_confirmacion'] ?? '');

    if (!hash_equals($_SESSION['csrf_superadmin_password'], $csrf_recibido)) {
        $estado = 'csrf';
    } else {
        $cuenta_stmt = $conn->prepare("SELECT password FROM superadmins WHERE id = ? AND activo = 1 LIMIT 1");
        $cuenta_stmt->bind_param('i', $superadmin_id);
        $cuenta_stmt->execute();
        $cuenta = $cuenta_stmt->get_result()->fetch_assoc();
        $cuenta_stmt->close();

        if (!$cuenta || !(password_verify($password_actual, $cuenta['password']) || hash_equals($cuenta['password'], $password_actual))) {
            $estado = 'actual';
        } elseif (strlen($password_nueva) < 12) {
            $estado = 'longitud';
        } elseif (!hash_equals($password_nueva, $password_confirmacion)) {
            $estado = 'confirmacion';
        } elseif (hash_equals($password_actual, $password_nueva)) {
            $estado = 'igual';
        } else {
            $password_hash = password_hash($password_nueva, PASSWORD_DEFAULT);
            $actualizar_stmt = $conn->prepare("UPDATE superadmins SET password = ? WHERE id = ? AND activo = 1");
            $actualizar_stmt->bind_param('si', $password_hash, $superadmin_id);
            $actualizar_stmt->execute();
            $actualizado = $actualizar_stmt->affected_rows === 1;
            $actualizar_stmt->close();
            if ($actualizado) {
                session_regenerate_id(true);
                $_SESSION['csrf_superadmin_password'] = bin2hex(random_bytes(32));
                $estado = 'ok';
            }
        }
    }

    header('Location: superadmin.php?password_estado=' . urlencode($estado) . '#password-settings');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_anuncio_superadmin'])) {
    $titulo = textoFormulario($_POST['titulo'] ?? '');
    $contenido = textoFormulario($_POST['contenido'] ?? '');
    $enlace = textoFormulario($_POST['enlace'] ?? '');
    $audiencia = ($_POST['audiencia'] ?? '') === 'barberia' ? 'barberia' : 'global';
    $rolesSeleccionados = !empty($_POST['roles_destino']) && is_array($_POST['roles_destino']) ? array_values(array_intersect($_POST['roles_destino'], ['admin', 'barbero', 'cliente'])) : [];
    $roles = $rolesSeleccionados !== [] ? implode(',', $rolesSeleccionados) : 'todos';
    $barberia_id = $audiencia === 'barberia' ? intval($_POST['barberia_id'] ?? 0) : 0;
    if ($titulo !== '' && $contenido !== '') {
        $stmt = $conn->prepare("INSERT INTO anuncios (titulo, contenido, enlace, estado, tenant_id, audiencia, roles_destino, superadmin_id) VALUES (?, ?, ?, 1, ?, ?, ?, ?)");
        $superadmin_id = intval($_SESSION['superadmin_id'] ?? 0);
        $stmt->bind_param('sssissi', $titulo, $contenido, $enlace, $barberia_id, $audiencia, $roles, $superadmin_id);
        $stmt->execute();
    }
    superadminRedirect();
}

if (isset($_GET['toggle_anuncio_superadmin'])) {
    $anuncio_id = intval($_GET['toggle_anuncio_superadmin']);
    $superadmin_id = intval($_SESSION['superadmin_id'] ?? 0);
    $stmt = $conn->prepare("UPDATE anuncios SET estado = IF(estado = 1, 0, 1) WHERE id = ? AND superadmin_id = ?");
    $stmt->bind_param('ii', $anuncio_id, $superadmin_id);
    $stmt->execute();
    $stmt->close();
    superadminRedirect();
}

if (isset($_GET['delete_anuncio_superadmin'])) {
    $anuncio_id = intval($_GET['delete_anuncio_superadmin']);
    if ($anuncio_id > 0) {
        $superadmin_id = intval($_SESSION['superadmin_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM anuncios WHERE id = ? AND superadmin_id = ?");
        $stmt->bind_param('ii', $anuncio_id, $superadmin_id);
        $stmt->execute();
        $stmt->close();
    }
    superadminRedirect();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_barberia'])) {
    $nombre = trim($_POST['nombre'] ?? '');
    $slug = strtolower(trim($_POST['slug'] ?? ''));
    $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug);
    $plan_id = intval($_POST['plan_id'] ?? 0);
    $fecha_vencimiento = ($_POST['fecha_vencimiento'] ?? '') !== '' ? $_POST['fecha_vencimiento'] : null;
    if ($nombre !== '' && $slug !== '' && $plan_id > 0) {
        $stmt = $conn->prepare("INSERT INTO barberias (nombre, slug, plan_id, fecha_vencimiento) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssis', $nombre, $slug, $plan_id, $fecha_vencimiento);
        $stmt->execute();
    }
    superadminRedirect();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_admin'])) {
    $correo = trim($_POST['correo'] ?? '');
    $password = $_POST['password'] ?? '';
    $barberia_id = intval($_POST['barberia_id'] ?? 0);
    if (filter_var($correo, FILTER_VALIDATE_EMAIL) && strlen($password) >= 8 && $barberia_id > 0) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO usuarios (correo, password, rol, activo, tenant_id) VALUES (?, ?, 'admin', 1, ?)");
        $stmt->bind_param('ssi', $correo, $hash, $barberia_id);
        $stmt->execute();
    }
    superadminRedirect();
}

if (isset($_GET['toggle_admin'])) {
    $admin_id = intval($_GET['toggle_admin']);
    $stmt = $conn->prepare("UPDATE usuarios SET activo = IF(activo = 1, 0, 1) WHERE id = ? AND rol = 'admin'");
    $stmt->bind_param('i', $admin_id);
    $stmt->execute();
    $stmt->close();
    superadminRedirect();
}

if (isset($_GET['toggle_barberia'])) {
    $barberia_id = intval($_GET['toggle_barberia']);
    $stmt = $conn->prepare("UPDATE barberias SET estado = IF(estado = 'activa', 'suspendida', 'activa') WHERE id = ?");
    $stmt->bind_param('i', $barberia_id);
    $stmt->execute();
    $stmt->close();
    superadminRedirect();
}

if (isset($_GET['delete_admin'])) {
    $admin_id = intval($_GET['delete_admin']);
    if ($admin_id > 0) {
        $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ? AND rol = 'admin'");
        $stmt->bind_param('i', $admin_id);
        $stmt->execute();
        $stmt->close();
    }
    superadminRedirect();
}

if (isset($_GET['delete_barberia'])) {
    $barberia_id = intval($_GET['delete_barberia']);
    if ($barberia_id > 0) {
        $queries = [
            ["DELETE FROM horarios_bloqueados WHERE tenant_id = ?", 'i'],
            ["DELETE FROM citas WHERE tenant_id = ?", 'i'],
            ["DELETE FROM servicios WHERE tenant_id = ?", 'i'],
            ["DELETE FROM anuncios WHERE tenant_id = ?", 'i'],
            ["DELETE FROM barberos WHERE tenant_id = ?", 'i'],
            ["DELETE FROM usuarios WHERE tenant_id = ?", 'i'],
            ["DELETE FROM config_horario WHERE tenant_id = ?", 'i'],
            ["DELETE FROM barberias WHERE id = ?", 'i'],
        ];
        foreach ($queries as [$query, $type]) {
            $stmt = $conn->prepare($query);
            $stmt->bind_param($type, $barberia_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    superadminRedirect();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_plan'])) {
    $barberia_id = intval($_POST['barberia_id'] ?? 0);
    $plan_id = intval($_POST['plan_id'] ?? 0);
    $fecha = ($_POST['fecha_vencimiento'] ?? '') !== '' ? $_POST['fecha_vencimiento'] : null;
    $stmt = $conn->prepare("UPDATE barberias SET plan_id=?, fecha_vencimiento=? WHERE id=?");
    $stmt->bind_param('isi', $plan_id, $fecha, $barberia_id);
    $stmt->execute();
    superadminRedirect();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_estadisticas'])) {
    $barberia_id = intval($_POST['barberia_id'] ?? 0);
    $habilitadas = intval($_POST['estadisticas_habilitadas'] ?? 0) === 1 ? 1 : 0;
    if ($barberia_id > 0) {
        $stmt = $conn->prepare("UPDATE barberias SET estadisticas_habilitadas = ? WHERE id = ?");
        $stmt->bind_param('ii', $habilitadas, $barberia_id);
        $stmt->execute();
        $stmt->close();
    }
    superadminRedirect();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_perfil'])) {
    $barberia_id = intval($_POST['barberia_id'] ?? 0);
    $nombre = textoFormulario($_POST['nombre'] ?? '');
    $descripcion = textoFormulario($_POST['descripcion'] ?? '');
    $telefono = textoFormulario($_POST['telefono'] ?? '');
    $correo = textoFormulario($_POST['correo'] ?? '');
    $direccion = textoFormulario($_POST['direccion'] ?? '');
    $facebook = textoFormulario($_POST['facebook'] ?? '');
    $instagram = textoFormulario($_POST['instagram'] ?? '');
    $whatsapp = textoFormulario($_POST['whatsapp'] ?? '');
    $tema = ($_POST['tema'] ?? '') === 'claro' ? 'claro' : 'oscuro';
    $logo = '';
    $logo_error = '';

    $error_subida = $_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error_subida !== UPLOAD_ERR_NO_FILE) {
        if ($error_subida !== UPLOAD_ERR_OK) {
            $logo_error = 'El servidor no recibió la imagen correctamente. Intenta con un archivo más pequeño.';
        } else {
            $extension = strtolower(pathinfo($_FILES['logo']['name'] ?? '', PATHINFO_EXTENSION));
            $permitidas = ['jpg', 'jpeg', 'png', 'webp'];
            $imagen_valida = @getimagesize($_FILES['logo']['tmp_name']);
            if (!in_array($extension, $permitidas, true) || !$imagen_valida) {
                $logo_error = 'La imagen debe ser un archivo JPG, PNG o WEBP válido.';
            } else {
                $directorio = __DIR__ . '/img/barberias';
                if (!is_dir($directorio) && !@mkdir($directorio, 0755, true) && !is_dir($directorio)) {
                    $logo_error = 'No se pudo preparar la carpeta para guardar el logo.';
                } else {
                    $nombre_archivo = 'logo_' . $barberia_id . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $directorio . '/' . $nombre_archivo)) {
                        $logo = 'img/barberias/' . $nombre_archivo;
                    } else {
                        $logo_error = 'No se pudo guardar la imagen. Revisa los permisos de la carpeta de logos.';
                    }
                }
            }
        }
    }

    if ($logo_error !== '') {
        $_SESSION['superadmin_perfil_feedback'] = ['tipo' => 'error', 'mensaje' => $logo_error];
        superadminRedirect();
    }

    if ($barberia_id > 0 && $nombre !== '') {
        if ($logo !== '') {
            $stmt = $conn->prepare("UPDATE barberias SET nombre=?, descripcion=?, telefono=?, correo=?, direccion=?, tema=?, facebook=?, instagram=?, whatsapp=?, logo=? WHERE id=?");
            $stmt->bind_param('ssssssssssi', $nombre, $descripcion, $telefono, $correo, $direccion, $tema, $facebook, $instagram, $whatsapp, $logo, $barberia_id);
        } else {
            $stmt = $conn->prepare("UPDATE barberias SET nombre=?, descripcion=?, telefono=?, correo=?, direccion=?, tema=?, facebook=?, instagram=?, whatsapp=? WHERE id=?");
            $stmt->bind_param('sssssssssi', $nombre, $descripcion, $telefono, $correo, $direccion, $tema, $facebook, $instagram, $whatsapp, $barberia_id);
        }
        $perfil_guardado = $stmt->execute();
        $stmt->close();
        $_SESSION['superadmin_perfil_feedback'] = $perfil_guardado
            ? ['tipo' => 'ok', 'mensaje' => $logo !== '' ? 'Logo guardado correctamente. Ya se verá en el index y el panel del administrador.' : 'Datos públicos actualizados. No se seleccionó un logo nuevo.']
            : ['tipo' => 'error', 'mensaje' => 'No se pudieron guardar los datos de la barbería.'];

        $hora_inicio = textoFormulario($_POST['hora_inicio'] ?? '08:00');
        $hora_fin = textoFormulario($_POST['hora_fin'] ?? '19:00');
        $intervalo = max(15, intval($_POST['intervalo'] ?? 60));
        $config = $conn->prepare("INSERT INTO config_horario (id, tenant_id, hora_inicio, hora_fin, intervalo) VALUES (1, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE hora_inicio=VALUES(hora_inicio), hora_fin=VALUES(hora_fin), intervalo=VALUES(intervalo)");
        $config->bind_param('issi', $barberia_id, $hora_inicio, $hora_fin, $intervalo);
        $config->execute();
    }
    superadminRedirect();
}

$planes = [];
$res = $conn->query("SELECT * FROM planes_suscripcion WHERE activo=1 ORDER BY tipo, nombre");
while ($row = $res->fetch_assoc()) $planes[] = $row;
$barberias = [];
$res = $conn->query("SELECT b.*, p.nombre AS plan_nombre, p.tipo AS plan_tipo, c.hora_inicio, c.hora_fin, c.intervalo FROM barberias b LEFT JOIN planes_suscripcion p ON p.id=b.plan_id LEFT JOIN config_horario c ON c.tenant_id=b.id AND c.id=1 ORDER BY b.id DESC");
while ($row = $res->fetch_assoc()) $barberias[] = $row;
$admins = [];
$res = $conn->query("SELECT u.*, b.nombre AS barberia_nombre FROM usuarios u LEFT JOIN barberias b ON b.id=u.tenant_id WHERE u.rol='admin' ORDER BY u.id DESC");
while ($row = $res->fetch_assoc()) $admins[] = $row;
$anuncios_superadmin = [];
$res = $conn->query("SELECT a.*, b.nombre AS barberia_nombre FROM anuncios a LEFT JOIN barberias b ON b.id=a.tenant_id WHERE a.superadmin_id=" . intval($_SESSION['superadmin_id'] ?? 0) . " ORDER BY a.fecha_creacion DESC");
while ($row = $res->fetch_assoc()) $anuncios_superadmin[] = $row;
$fecha_cita_sql = "COALESCE(STR_TO_DATE(NULLIF(c.dia, ''), '%d/%m/%Y'), STR_TO_DATE(NULLIF(c.dia, ''), '%Y-%m-%d'))";
$join_precio_servicio_sql = "LEFT JOIN (SELECT tenant_id, nombre, MAX(precio) AS precio FROM servicios GROUP BY tenant_id, nombre) AS precio_servicio ON precio_servicio.tenant_id = c.tenant_id AND precio_servicio.nombre = c.servicio";
$estadisticas = [];
foreach ($barberias as $barberia) {
    $id = intval($barberia['id']);
    $clientes = $conn->query("SELECT COUNT(*) AS total FROM usuarios WHERE tenant_id=$id AND rol='cliente'")->fetch_assoc();
    $citas = $conn->query("SELECT COUNT(*) AS total FROM citas WHERE tenant_id=$id")->fetch_assoc();
    $clientes_consolidados = $conn->query("SELECT COUNT(DISTINCT usuario_correo) AS total FROM citas WHERE tenant_id=$id AND usuario_correo <> 'Invitado' AND usuario_correo <> ''")->fetch_assoc();
    $clientes_no_regresaron = $conn->query("SELECT COUNT(*) AS total FROM (SELECT usuario_correo, COUNT(*) AS visitas FROM citas WHERE tenant_id=$id AND usuario_correo <> 'Invitado' AND usuario_correo <> '' GROUP BY usuario_correo HAVING visitas=1) AS una_visita")->fetch_assoc();
    $ingresos = $conn->query("SELECT
        COALESCE(SUM(precio_servicio.precio), 0) AS total,
        COALESCE(SUM(CASE WHEN $fecha_cita_sql = CURDATE() THEN precio_servicio.precio ELSE 0 END), 0) AS hoy,
        COALESCE(SUM(CASE WHEN $fecha_cita_sql BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND CURDATE() THEN precio_servicio.precio ELSE 0 END), 0) AS semana,
        COALESCE(SUM(CASE WHEN YEAR($fecha_cita_sql) = YEAR(CURDATE()) AND MONTH($fecha_cita_sql) = MONTH(CURDATE()) THEN precio_servicio.precio ELSE 0 END), 0) AS mes
        FROM citas c $join_precio_servicio_sql WHERE c.tenant_id=$id AND c.asistio='si'")->fetch_assoc();
    $top_barbero = $conn->query("SELECT c.barbero, COUNT(*) AS servicios, COALESCE(SUM(precio_servicio.precio), 0) AS ingresos
        FROM citas c $join_precio_servicio_sql WHERE c.tenant_id=$id AND c.asistio='si'
        AND $fecha_cita_sql >= DATE_SUB(CURDATE(), INTERVAL DAYOFMONTH(CURDATE()) - 1 DAY) AND $fecha_cita_sql <= CURDATE()
        GROUP BY c.barbero ORDER BY servicios DESC, ingresos DESC LIMIT 1")->fetch_assoc();
    $accesos = $conn->query("SELECT COUNT(*) AS total, MAX(fecha_acceso) AS ultimo FROM accesos WHERE tenant_id=$id")->fetch_assoc();
    $estadisticas[$id] = ['clientes'=>intval($clientes['total'] ?? 0), 'citas'=>intval($citas['total'] ?? 0), 'consolidados'=>intval($clientes_consolidados['total'] ?? 0), 'no_regresaron'=>intval($clientes_no_regresaron['total'] ?? 0), 'ingresos'=>floatval($ingresos['total'] ?? 0), 'ingresos_hoy'=>floatval($ingresos['hoy'] ?? 0), 'ingresos_semana'=>floatval($ingresos['semana'] ?? 0), 'ingresos_mes'=>floatval($ingresos['mes'] ?? 0), 'top_barbero'=>$top_barbero['barbero'] ?? 'Sin datos', 'top_barbero_servicios'=>intval($top_barbero['servicios'] ?? 0), 'accesos'=>intval($accesos['total'] ?? 0), 'ultimo_acceso'=>$accesos['ultimo'] ?? 'Nunca'];
}
$barberia_edicion_id = intval($_GET['editar_barberia'] ?? 0);
$barberia_edicion = null;
foreach ($barberias as $barberia) {
    if (intval($barberia['id']) === $barberia_edicion_id) {
        $barberia_edicion = $barberia;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" type="image/png" href="img/logomdj.png?v=20260930"><title>Superadmin | Wason Studio</title>
<style>
body{margin:0;background:radial-gradient(circle at top left, rgba(43,177,255,0.18), transparent 22%),radial-gradient(circle at top right, rgba(255,183,3,0.14), transparent 18%),linear-gradient(135deg,#040a12 0%,#0a1320 42%,#0d1726 100%);color:#edf6ff;font-family:system-ui,sans-serif;padding:24px}main{max-width:1180px;margin:auto}.top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:24px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px}.card{background:rgba(10,18,31,0.82);border:1px solid rgba(96,165,250,0.18);border-radius:14px;padding:20px;margin-bottom:18px;box-shadow:0 20px 40px rgba(7,12,24,0.4)}h1{margin:0;color:#ffb703}h2{font-size:1.05rem;margin-top:0;color:#ffb703}h3{margin:0 0 14px;color:#ffb703}label{display:grid;gap:6px;margin:12px 0;color:#a9bfd6}input,select,textarea{padding:11px;border-radius:8px;border:1px solid rgba(96,165,250,0.2);background:#0d1726;color:#edf6ff;font:inherit}textarea{resize:vertical}.profile-form{border-top:1px solid rgba(96,165,250,0.18);padding-top:18px;margin-top:18px}.profile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 16px}.profile-grid .wide{grid-column:1/-1}.profile-form button{margin-top:8px}.logo-preview{display:block;width:64px;height:64px;object-fit:cover;border-radius:12px;margin-top:4px;border:1px solid rgba(96,165,250,0.2)}.danger{color:#f87171}.muted{color:#a6bed8}.table{overflow:auto}.table table{width:100%;border-collapse:collapse}.table th,.table td{text-align:left;padding:11px;border-bottom:1px solid rgba(96,165,250,0.12);white-space:nowrap}.status{font-weight:700}.active{color:#34d399}.inactive{color:#f87171}.actions{display:flex;gap:8px;flex-wrap:wrap}.small{font-size:.85rem;padding:7px 9px}.profile-settings{display:none}.profile-settings.is-editing{display:block}@media(max-width:700px){.profile-grid{grid-template-columns:1fr}.profile-grid .wide{grid-column:auto}}
</style>
<style>
.account-actions{display:flex;align-items:center;gap:10px}
.password-menu{position:relative}
.password-menu summary{list-style:none;cursor:pointer;padding:10px 12px;border:1px solid rgba(96,165,250,.2);border-radius:8px;color:#edf6ff;background:#0d1726;white-space:nowrap}
.password-menu summary::-webkit-details-marker{display:none}
.password-dropdown{position:absolute;top:calc(100% + 10px);right:0;z-index:30;width:min(380px,calc(100vw - 32px));padding:18px;background:#0a121f;border:1px solid rgba(96,165,250,.25);border-radius:12px;box-shadow:0 20px 40px rgba(0,0,0,.45)}
.password-dropdown .profile-grid{grid-template-columns:1fr}
.password-dropdown .profile-grid .wide{grid-column:auto}
@media(max-width:700px){.top{flex-wrap:wrap}.account-actions{width:100%;justify-content:flex-end}}
</style><link rel="stylesheet" href="responsive.css?v=1"><link rel="stylesheet" href="app-theme.css?v=4"></head>
<body><main>
<div class="top"><div><h1>Centro de control</h1><div class="muted">Superadmin de Wason Studio</div></div><div class="account-actions">
<details class="password-menu" id="password-settings" <?php echo isset($_GET['password_estado']) ? 'open' : ''; ?>>
    <summary>Cambiar contraseña</summary>
    <div class="password-dropdown">
    <h2>Cambiar mi contraseña</h2>
    <p class="muted">Usa al menos 12 caracteres.</p>
    <?php
    $mensajes_password = [
        'ok' => 'Contraseña actualizada correctamente.',
        'actual' => 'La contraseña actual no es correcta.',
        'longitud' => 'La nueva contraseña debe tener al menos 12 caracteres.',
        'confirmacion' => 'La confirmación no coincide con la nueva contraseña.',
        'igual' => 'La nueva contraseña debe ser diferente de la actual.',
        'csrf' => 'La sesión del formulario venció. Recarga la página e intenta de nuevo.',
        'error' => 'No se pudo actualizar la contraseña. Intenta nuevamente.'
    ];
    $estado_password = (string) ($_GET['password_estado'] ?? '');
    if (isset($mensajes_password[$estado_password])): ?>
        <p role="status" class="<?php echo $estado_password === 'ok' ? 'active' : 'danger'; ?>"><?php echo htmlspecialchars($mensajes_password[$estado_password]); ?></p>
    <?php endif; ?>
    <form method="post" class="profile-grid" autocomplete="off">
        <input type="hidden" name="cambiar_password_superadmin" value="1">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_superadmin_password']); ?>">
        <label>Contraseña actual<input type="password" name="password_actual" autocomplete="current-password" required></label>
        <label>Nueva contraseña<input type="password" name="password_nueva" minlength="12" autocomplete="new-password" required></label>
        <label>Confirmar nueva contraseña<input type="password" name="password_confirmacion" minlength="12" autocomplete="new-password" required></label>
        <button class="wide">Guardar nueva contraseña</button>
    </form>
</div>
</details>
<a class="btn" href="superadmin.php?logout=1">Cerrar sesión</a>
</div></div>
<?php if (is_array($perfil_feedback) && !empty($perfil_feedback['mensaje'])): ?>
    <div role="status" style="max-width:1200px;margin:0 auto 16px;padding:12px 16px;border:1px solid <?php echo ($perfil_feedback['tipo'] ?? '') === 'ok' ? 'rgba(52,211,153,.45)' : 'rgba(248,113,113,.5)'; ?>;border-radius:10px;background:<?php echo ($perfil_feedback['tipo'] ?? '') === 'ok' ? 'rgba(16,185,129,.12)' : 'rgba(248,113,113,.12)'; ?>;color:<?php echo ($perfil_feedback['tipo'] ?? '') === 'ok' ? '#a7f3d0' : '#fecaca'; ?>;font-weight:700;"><?php echo htmlspecialchars($perfil_feedback['mensaje']); ?></div>
<?php endif; ?>
<div class="grid creation-sections">
<section class="card"><h2>Crear barbería</h2><form method="post"><input type="hidden" name="crear_barberia" value="1"><label>Nombre<input name="nombre" required></label><label>Slug público<input name="slug" placeholder="mi-barberia" required></label><label>Plan<select name="plan_id" required><?php foreach($planes as $plan): ?><option value="<?php echo $plan['id']; ?>"><?php echo htmlspecialchars($plan['nombre']); ?> (<?php echo htmlspecialchars($plan['tipo']); ?>)</option><?php endforeach; ?></select></label><label>Vencimiento mensual (opcional)<input type="date" name="fecha_vencimiento"></label><button>Crear barbería</button></form></section>
<details class="card superadmin-accordion">
    <summary><h2>Crear administrador</h2></summary>
    <div class="superadmin-accordion-content">
        <p class="muted">Los administradores no se registran solos. Entrarán con las credenciales que definas aquí.</p>
        <form method="post"><input type="hidden" name="crear_admin" value="1"><label>Correo<input type="email" name="correo" required></label><label>Contraseña inicial<input type="password" name="password" minlength="8" required></label><label>Barbería<select name="barberia_id" required><?php foreach($barberias as $barberia): ?><option value="<?php echo $barberia['id']; ?>"><?php echo htmlspecialchars($barberia['nombre']); ?></option><?php endforeach; ?></select></label><button>Crear administrador</button></form>
    </div>
</details>
</div>
<section class="card table"><h2>Barberías y suscripciones</h2><table><tr><th>Barbería</th><th>Plan</th><th>Estado</th><th>Vencimiento</th><th>Acciones</th></tr><?php foreach($barberias as $barberia): ?><tr><td><?php echo htmlspecialchars($barberia['nombre']); ?><br><span class="muted">/<?php echo htmlspecialchars($barberia['slug']); ?></span></td><td><form method="post" class="actions"><input type="hidden" name="actualizar_plan" value="1"><input type="hidden" name="barberia_id" value="<?php echo $barberia['id']; ?>"><select name="plan_id"><?php foreach($planes as $plan): ?><option value="<?php echo $plan['id']; ?>" <?php echo intval($barberia['plan_id'])===intval($plan['id'])?'selected':''; ?>><?php echo htmlspecialchars($plan['nombre']); ?></option><?php endforeach; ?></select><input type="date" name="fecha_vencimiento" value="<?php echo htmlspecialchars($barberia['fecha_vencimiento'] ?? ''); ?>"><button class="small">Guardar</button></form></td><td class="status <?php echo $barberia['estado']==='activa'?'active':'inactive'; ?>"><?php echo htmlspecialchars($barberia['estado']); ?></td><td><?php echo htmlspecialchars($barberia['fecha_vencimiento'] ?? 'Permanente'); ?></td><td><div class="actions"><a class="btn small" href="superadmin.php?editar_barberia=<?php echo intval($barberia['id']); ?>">Editar</a><a class="btn small" href="index.php?barberia=<?php echo urlencode($barberia['slug']); ?>">Abrir local</a><a class="btn small" href="superadmin.php?toggle_barberia=<?php echo $barberia['id']; ?>">Activar/desactivar</a><a class="btn small danger" href="superadmin.php?delete_barberia=<?php echo intval($barberia['id']); ?>" onclick="return confirm('¿Eliminar esta barbería y todos sus datos asociados?');">Eliminar</a></div></td></tr><?php endforeach; ?></table></section>
<section class="card profile-settings"><h2>Editar datos públicos de la barbería</h2><p class="muted">Estos datos aparecen en el local público, incluido el pie de página, el logo y el tema visual.</p><?php foreach($barberias as $barberia): ?><form method="post" enctype="multipart/form-data" class="profile-form" data-barberia-id="<?php echo $barberia['id']; ?>"><input type="hidden" name="actualizar_perfil" value="1"><input type="hidden" name="barberia_id" value="<?php echo $barberia['id']; ?>"><h3><?php echo htmlspecialchars($barberia['nombre']); ?> <span class="muted">/<?php echo htmlspecialchars($barberia['slug']); ?></span></h3><div class="profile-grid"><label>Nombre público<input name="nombre" value="<?php echo htmlspecialchars($barberia['nombre']); ?>" required></label><label>Tema<select name="tema"><option value="oscuro" <?php echo ($barberia['tema'] ?? 'oscuro') === 'oscuro' ? 'selected' : ''; ?>>Oscuro elegante</option><option value="claro" <?php echo ($barberia['tema'] ?? '') === 'claro' ? 'selected' : ''; ?>>Claro elegante</option></select></label><label class="wide">Descripción<textarea name="descripcion" rows="3"><?php echo htmlspecialchars($barberia['descripcion'] ?? ''); ?></textarea></label><label>Teléfono<input name="telefono" value="<?php echo htmlspecialchars($barberia['telefono'] ?? ''); ?>" placeholder="(+57) 300 000 0000"></label><label>Correo público<input type="email" name="correo" value="<?php echo htmlspecialchars($barberia['correo'] ?? ''); ?>"></label><label class="wide">Dirección<input name="direccion" value="<?php echo htmlspecialchars($barberia['direccion'] ?? ''); ?>"></label><label>Facebook<input name="facebook" value="<?php echo htmlspecialchars($barberia['facebook'] ?? ''); ?>" placeholder="https://facebook.com/tuusuario"></label><label>Instagram<input name="instagram" value="<?php echo htmlspecialchars($barberia['instagram'] ?? ''); ?>" placeholder="https://instagram.com/tuusuario"></label><label>WhatsApp<input name="whatsapp" value="<?php echo htmlspecialchars($barberia['whatsapp'] ?? ''); ?>" placeholder="https://wa.me/57300..."></label><label>Logo<input type="file" name="logo" accept="image/jpeg,image/png,image/webp"><?php if (!empty($barberia['logo'])): ?><img class="logo-preview" src="<?php echo htmlspecialchars($barberia['logo']); ?>" alt="Logo actual"><?php endif; ?></label><label>Hora de apertura<input type="time" name="hora_inicio" value="<?php echo htmlspecialchars(substr($barberia['hora_inicio'] ?? '08:00', 0, 5)); ?>" required></label><label>Hora de cierre<input type="time" name="hora_fin" value="<?php echo htmlspecialchars(substr($barberia['hora_fin'] ?? '19:00', 0, 5)); ?>" required></label><label>Intervalo de citas<select name="intervalo"><option value="15" <?php echo intval($barberia['intervalo'] ?? 60) === 15 ? 'selected' : ''; ?>>15 minutos</option><option value="30" <?php echo intval($barberia['intervalo'] ?? 60) === 30 ? 'selected' : ''; ?>>30 minutos</option><option value="60" <?php echo intval($barberia['intervalo'] ?? 60) === 60 ? 'selected' : ''; ?>>60 minutos</option></select></label></div><button>Guardar perfil y horarios</button></form><?php endforeach; ?></section>
<section class="card table"><h2>Administradores</h2><table><tr><th>Correo</th><th>Barbería</th><th>Estado</th><th>Acción</th></tr><?php foreach($admins as $admin): ?><tr><td><?php echo htmlspecialchars($admin['correo']); ?></td><td><?php echo htmlspecialchars($admin['barberia_nombre'] ?? ''); ?></td><td class="status <?php echo $admin['activo']?'active':'inactive'; ?>"><?php echo $admin['activo']?'Activo':'Desactivado'; ?></td><td><div class="actions"><a class="btn small" href="superadmin.php?toggle_admin=<?php echo $admin['id']; ?>">Activar/desactivar</a><a class="btn small danger" href="superadmin.php?delete_admin=<?php echo $admin['id']; ?>" onclick="return confirm('¿Eliminar este administrador/suscriptor?');">Eliminar</a></div></td></tr><?php endforeach; ?></table></section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const editId = new URLSearchParams(window.location.search).get('editar_barberia');
    const profileSection = document.querySelector('.profile-settings');
    if (editId && profileSection) {
        const selectedForm = profileSection.querySelector('[data-barberia-id="' + CSS.escape(editId) + '"]');
        if (selectedForm) {
            profileSection.classList.add('is-editing');
            profileSection.querySelectorAll('.profile-form').forEach(function (form) {
                if (form !== selectedForm) form.remove();
            });
            selectedForm.insertAdjacentHTML('afterbegin', '<a class="btn small" href="superadmin.php">Volver a barberías</a>');
            selectedForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
});
</script>
<section class="card table">
    <h2>Acceso a estadísticas avanzadas</h2>
    <p class="muted">Activa esta función únicamente para las barberías que contrataron el servicio adicional.</p>
    <table>
        <tr><th>Barbería</th><th>Estado</th><th>Acción</th></tr>
        <?php foreach ($barberias as $barberia): ?>
            <tr>
                <td><?php echo htmlspecialchars($barberia['nombre']); ?></td>
                <td class="status <?php echo !empty($barberia['estadisticas_habilitadas']) ? 'active' : 'inactive'; ?>"><?php echo !empty($barberia['estadisticas_habilitadas']) ? 'Activas' : 'Inactivas'; ?></td>
                <td>
                    <form method="post" class="actions">
                        <input type="hidden" name="actualizar_estadisticas" value="1">
                        <input type="hidden" name="barberia_id" value="<?php echo intval($barberia['id']); ?>">
                        <input type="hidden" name="estadisticas_habilitadas" value="<?php echo !empty($barberia['estadisticas_habilitadas']) ? 0 : 1; ?>">
                        <button class="small"><?php echo !empty($barberia['estadisticas_habilitadas']) ? 'Desactivar' : 'Activar'; ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php include __DIR__ . '/superadmin_widgets.php'; ?>
<section class="card table">
    <h2>Rendimiento por barbería</h2>
    <table>
        <tr><th>Barbería</th><th>Ingresos hoy</th><th>Ingresos semana</th><th>Ingresos mes</th><th>Top barbero del mes</th></tr>
        <?php foreach ($barberias as $barberia): $stat = $estadisticas[$barberia['id']]; ?>
            <tr>
                <td><?php echo htmlspecialchars($barberia['nombre']); ?></td>
                <td>$<?php echo number_format($stat['ingresos_hoy'], 0, ',', '.'); ?></td>
                <td>$<?php echo number_format($stat['ingresos_semana'], 0, ',', '.'); ?></td>
                <td>$<?php echo number_format($stat['ingresos_mes'], 0, ',', '.'); ?></td>
                <td><?php echo htmlspecialchars($stat['top_barbero']); ?> (<?php echo $stat['top_barbero_servicios']; ?> servicios)</td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p class="muted">Ingresos estimados según el precio actual de los servicios y citas marcadas como asistidas.</p>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('main .card').forEach(function (card) {
        const heading = card.querySelector(':scope > h2');
        if (!heading) return;

        const accordion = document.createElement('details');
        accordion.className = Array.from(card.classList).concat('superadmin-accordion').join(' ');
        accordion.open = card.classList.contains('is-editing');

        const summary = document.createElement('summary');
        summary.appendChild(heading);

        const content = document.createElement('div');
        content.className = 'superadmin-accordion-content';
        while (card.firstChild) content.appendChild(card.firstChild);

        accordion.append(summary, content);
        card.replaceWith(accordion);
    });

    const profileSection = document.querySelector('.profile-settings.superadmin-accordion');
    if (profileSection && profileSection.classList.contains('is-editing')) profileSection.open = true;
});
</script>
</main></body></html>
