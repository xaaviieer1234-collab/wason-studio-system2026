<?php
session_start();
include 'conexion.php';

// CORRECCIÓN AQUÍ: Validar usando las nuevas variables de sesión
if (!isset($_SESSION['usuario']) || $_SESSION['rol'] != 'admin' || empty($_SESSION['tenant_id'])) {
    header("Location: " . tenantRedirect('index.php'));
    exit();
}
$admin_id = intval($_SESSION['admin_id'] ?? 0);
if ($admin_id <= 0) {
    $admin_email = trim((string)($_SESSION['usuario'] ?? ''));
    $admin_stmt = $conn->prepare("SELECT id FROM usuarios WHERE correo = ? AND rol = 'admin' AND tenant_id = ? LIMIT 1");
    $admin_stmt->bind_param('si', $admin_email, $tenant_id);
    $admin_stmt->execute();
    $admin_row = $admin_stmt->get_result()->fetch_assoc();
    $admin_stmt->close();
    $admin_id = intval($admin_row['id'] ?? 0);
    $_SESSION['admin_id'] = $admin_id;
}
if ($admin_id <= 0) {
    session_destroy();
    header("Location: index.php?barberia=" . urlencode($barberia_actual['slug'] ?? ''));
    exit();
}
$admin_nombre = $_SESSION['usuario'];
$estadisticas_habilitadas = intval($barberia_actual['estadisticas_habilitadas'] ?? 0) === 1;
$admin_stmt = $conn->prepare("SELECT correo FROM usuarios WHERE id = ? AND rol = 'admin' AND tenant_id = ? LIMIT 1");
$admin_stmt->bind_param('ii', $admin_id, $tenant_id);
$admin_stmt->execute();
$admin_resultado = $admin_stmt->get_result();
if ($admin_resultado && $admin_resultado->num_rows > 0) {
    $admin_nombre = $admin_resultado->fetch_assoc()['correo'];
}
$admin_stmt->close();
if ($admin_id > 0) {
    $owner_stmt = $conn->prepare("UPDATE barberos SET owner_admin_id = ? WHERE owner_admin_id IS NULL AND tenant_id = ?");
    $owner_stmt->bind_param('ii', $admin_id, $tenant_id);
    $owner_stmt->execute();
    $owner_stmt->close();
}

$superadmin_banners = [];
$superadmin_banner_result = $conn->query("SELECT * FROM anuncios a WHERE a.estado=1 AND a.superadmin_id > 0 AND " . anuncioVisiblePara('admin', 'a') . " ORDER BY a.fecha_creacion DESC");
if ($superadmin_banner_result) {
    while ($superadmin_banner = $superadmin_banner_result->fetch_assoc()) {
        $superadmin_banners[] = $superadmin_banner;
    }
}
$superadmin_banners = array_values(array_filter($superadmin_banners, function ($banner) {
    $titulo = trim((string)($banner['titulo'] ?? ''));
    $contenido = trim((string)($banner['contenido'] ?? ''));
    return $titulo !== '' || $contenido !== '';
}));

// Lógica para cerrar sesión
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . tenantRedirect('index.php'));
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
        $hora_db = trim($hora_bloqueo);
        $check_stmt = $conn->prepare("SELECT id FROM horarios_bloqueados WHERE fecha = ? AND hora = ? AND tenant_id = ? LIMIT 1");
        $check_stmt->bind_param('ssi', $fecha_db, $hora_db, $tenant_id);
        $check_stmt->execute();
        $check_bloqueo = $check_stmt->get_result();
        if ($check_bloqueo && $check_bloqueo->num_rows > 0) {
            $delete_stmt = $conn->prepare("DELETE FROM horarios_bloqueados WHERE fecha = ? AND hora = ? AND tenant_id = ?");
            $delete_stmt->bind_param('ssi', $fecha_db, $hora_db, $tenant_id);
            $delete_stmt->execute();
            $delete_stmt->close();
        } else {
            $insert_stmt = $conn->prepare("INSERT INTO horarios_bloqueados (fecha, hora, tenant_id) VALUES (?, ?, ?)");
            $insert_stmt->bind_param('ssi', $fecha_db, $hora_db, $tenant_id);
            $insert_stmt->execute();
            $insert_stmt->close();
        }
        $check_stmt->close();
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
    $result_config = $conn->query("SELECT * FROM config_horario WHERE id=1 AND " . tenantFilter());
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

// Crear y administrar barberos
$conn->query("CREATE TABLE IF NOT EXISTS barberos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_barbero'])) {
    $barbero_id = intval($_POST['barbero_id'] ?? 0);
    $nombre_barbero = trim($_POST['nombre_barbero'] ?? '');
    $descripcion_barbero = trim($_POST['descripcion_barbero'] ?? '');
    $correo_barbero = trim($_POST['correo_barbero'] ?? '');
    $password_barbero = $_POST['password_barbero'] ?? '';
    $color_barbero = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color_barbero'] ?? '') ? $_POST['color_barbero'] : '#d4af37';
    $hora_inicio_barbero = trim($_POST['hora_inicio_barbero'] ?? '');
    $hora_fin_barbero = trim($_POST['hora_fin_barbero'] ?? '');
    $dias_trabajo_barbero = !empty($_POST['dias_trabajo_barbero']) && is_array($_POST['dias_trabajo_barbero']) ? implode(',', array_map('trim', $_POST['dias_trabajo_barbero'])) : '';
    $foto_barbero = '';

    if (!empty($_FILES['foto_barbero']['name']) && ($_FILES['foto_barbero']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $extension = strtolower(pathinfo($_FILES['foto_barbero']['name'], PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) && @getimagesize($_FILES['foto_barbero']['tmp_name'])) {
            $directorio = __DIR__ . '/img/barberos';
            if (!is_dir($directorio)) mkdir($directorio, 0755, true);
            $nombre_archivo = 'barbero_' . bin2hex(random_bytes(5)) . '.' . $extension;
            if (move_uploaded_file($_FILES['foto_barbero']['tmp_name'], $directorio . '/' . $nombre_archivo)) {
                $foto_barbero = 'img/barberos/' . $nombre_archivo;
            }
        }
    }

    if ($nombre_barbero !== '') {
        if ($barbero_id > 0) {
            $sql = "UPDATE barberos SET nombre=?, descripcion=?, color=?, hora_inicio=?, hora_fin=?, dias_trabajo=?";
            if ($foto_barbero !== '') $sql .= ", foto=?";
            $sql .= " WHERE id=? AND owner_admin_id=? AND " . tenantFilter();
            $stmt = $conn->prepare($sql);
            if ($foto_barbero !== '') {
                $stmt->bind_param('sssssssii', $nombre_barbero, $descripcion_barbero, $color_barbero, $hora_inicio_barbero, $hora_fin_barbero, $dias_trabajo_barbero, $foto_barbero, $barbero_id, $admin_id);
            } else {
                $stmt->bind_param('ssssssii', $nombre_barbero, $descripcion_barbero, $color_barbero, $hora_inicio_barbero, $hora_fin_barbero, $dias_trabajo_barbero, $barbero_id, $admin_id);
            }
            $stmt->execute();
            if ($correo_barbero !== '' && filter_var($correo_barbero, FILTER_VALIDATE_EMAIL)) {
                $existing_stmt = $conn->prepare("SELECT id FROM usuarios WHERE barbero_id = ? AND tenant_id = ? AND rol = 'barbero' LIMIT 1");
                $existing_stmt->bind_param('ii', $barbero_id, $tenant_id);
                $existing_stmt->execute();
                $existing_user = $existing_stmt->get_result();
                if ($existing_user && $existing_user->num_rows > 0) {
                    $user_sql = "UPDATE usuarios SET correo=?" . ($password_barbero !== '' ? ", password=?" : '') . " WHERE barbero_id=? AND tenant_id=? AND rol='barbero'";
                    $user_stmt = $conn->prepare($user_sql);
                    if ($password_barbero !== '') {
                        $password_hash = password_hash($password_barbero, PASSWORD_DEFAULT);
                        $user_stmt->bind_param('ssii', $correo_barbero, $password_hash, $barbero_id, $tenant_id);
                    } else {
                        $user_stmt->bind_param('sii', $correo_barbero, $barbero_id, $tenant_id);
                    }
                    $user_stmt->execute();
                    $user_stmt->close();
                } else {
                    if ($password_barbero !== '' && strlen($password_barbero) >= 8) {
                        $password_hash = password_hash($password_barbero, PASSWORD_DEFAULT);
                        $user_stmt = $conn->prepare("INSERT INTO usuarios (correo, password, rol, activo, tenant_id, barbero_id) VALUES (?, ?, 'barbero', 1, ?, ?)");
                        $user_stmt->bind_param('ssii', $correo_barbero, $password_hash, $tenant_id, $barbero_id);
                        $user_stmt->execute();
                        $user_stmt->close();
                    }
                }
                $existing_stmt->close();
            }
        } elseif (filter_var($correo_barbero, FILTER_VALIDATE_EMAIL) && strlen($password_barbero) >= 8) {
            $stmt = $conn->prepare("INSERT INTO barberos (nombre, descripcion, foto, color, hora_inicio, hora_fin, dias_trabajo, tenant_id, owner_admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sssssssii', $nombre_barbero, $descripcion_barbero, $foto_barbero, $color_barbero, $hora_inicio_barbero, $hora_fin_barbero, $dias_trabajo_barbero, $tenant_id, $admin_id);
            if ($stmt->execute()) {
                $nuevo_barbero_id = $conn->insert_id;
                $password_hash = password_hash($password_barbero, PASSWORD_DEFAULT);
                $user_stmt = $conn->prepare("INSERT INTO usuarios (correo, password, rol, activo, tenant_id, barbero_id) VALUES (?, ?, 'barbero', 1, ?, ?)");
                $user_stmt->bind_param('ssii', $correo_barbero, $password_hash, $tenant_id, $nuevo_barbero_id);
                $user_stmt->execute();
            }
        }
    }
    header("Location: panel.php#barberos-section");
    exit();
}

$editar_barbero = null;
if (isset($_GET['edit_barbero_id'])) {
    $edit_barbero_id = intval($_GET['edit_barbero_id']);
    $barbero_edit_stmt = $conn->prepare("SELECT b.*, u.correo FROM barberos b LEFT JOIN usuarios u ON u.barbero_id = b.id AND u.rol = 'barbero' WHERE b.id = ? AND b.owner_admin_id = ? AND b.tenant_id = ?");
    $barbero_edit_stmt->bind_param('iii', $edit_barbero_id, $admin_id, $tenant_id);
    $barbero_edit_stmt->execute();
    $resultado_barbero = $barbero_edit_stmt->get_result();
    if ($resultado_barbero && $resultado_barbero->num_rows > 0) $editar_barbero = $resultado_barbero->fetch_assoc();
    $barbero_edit_stmt->close();
}

if (isset($_GET['delete_barbero_id'])) {
    $delete_barbero_id = intval($_GET['delete_barbero_id']);
    if ($delete_barbero_id > 0) {
        $delete_user_stmt = $conn->prepare("DELETE u FROM usuarios u INNER JOIN barberos b ON b.id = u.barbero_id WHERE u.barbero_id = ? AND b.owner_admin_id = ? AND b.tenant_id = ?");
        $delete_user_stmt->bind_param('iii', $delete_barbero_id, $admin_id, $tenant_id);
        $delete_user_stmt->execute();
        $delete_user_stmt->close();

        $delete_barbero_stmt = $conn->prepare("DELETE FROM barberos WHERE id = ? AND owner_admin_id = ? AND tenant_id = ?");
        $delete_barbero_stmt->bind_param('iii', $delete_barbero_id, $admin_id, $tenant_id);
        $delete_barbero_stmt->execute();
        $delete_barbero_stmt->close();
    }
    header("Location: panel.php#barberos-section");
    exit();
}

// Agendar una cita directamente desde el calendario del panel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agendar_panel']) && !isset($_POST['bloquear_dia_panel'])) {
    $dia = normalizarFechaVista($_POST['dia'] ?? '');
    $hora = trim($_POST['hora'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $servicio = trim($_POST['servicio'] ?? '');
    $barbero = trim($_POST['barbero'] ?? '');
    $nota = trim($_POST['nota'] ?? '');
    $week_redirect = intval($_POST['week_panel'] ?? 0);

    if ($dia !== '' && $hora !== '' && $nombre !== '' && $telefono !== '' && $servicio !== '') {
        if ($barbero === '') {
            $fecha_turno = DateTime::createFromFormat('d/m/Y', $dia);
            $hora_turno = strtotime($hora);
            $dias_semana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
            $dia_turno = $fecha_turno ? $dias_semana[(int) $fecha_turno->format('N') - 1] : '';
            $barberos_stmt = $conn->prepare("SELECT b.id, b.nombre, b.dias_trabajo, b.hora_inicio, b.hora_fin,
                    COUNT(CASE WHEN c.asistio = 'si' THEN 1 END) AS servicios_realizados,
                    COUNT(CASE WHEN c.asistio <> 'no' THEN 1 END) AS carga_dia
                FROM barberos b
                LEFT JOIN citas c ON c.tenant_id = b.tenant_id AND c.barbero = b.nombre COLLATE utf8mb4_general_ci AND c.dia = ? AND c.asistio <> 'no'
                WHERE b.tenant_id = ? AND b.activo = 1
                GROUP BY b.id, b.nombre, b.dias_trabajo, b.hora_inicio, b.hora_fin
                ORDER BY servicios_realizados ASC, carga_dia ASC, b.id ASC");
            $barberos_stmt->bind_param('si', $dia, $tenant_id);
            $barberos_stmt->execute();
            $barberos_disponibles = $barberos_stmt->get_result();

            while ($barbero_candidato = $barberos_disponibles->fetch_assoc()) {
                $dias_trabajo = array_filter(array_map('trim', explode(',', (string) $barbero_candidato['dias_trabajo'])));
                if ($dia_turno === '' || (!empty($dias_trabajo) && !in_array($dia_turno, $dias_trabajo, true))) {
                    continue;
                }

                $hora_inicio = trim((string) ($barbero_candidato['hora_inicio'] ?? ''));
                $hora_fin = trim((string) ($barbero_candidato['hora_fin'] ?? ''));
                if ($hora_inicio !== '' && $hora_fin !== '' && $hora_turno !== false) {
                    $inicio_turno = strtotime($hora_inicio);
                    $fin_turno = strtotime($hora_fin);
                    if ($inicio_turno !== false && $fin_turno !== false && ($hora_turno < $inicio_turno || $hora_turno >= $fin_turno)) {
                        continue;
                    }
                }

                $ocupacion_stmt = $conn->prepare("SELECT id FROM citas WHERE dia = ? AND hora = ? AND tenant_id = ? AND barbero = ? LIMIT 1");
                $ocupacion_stmt->bind_param('ssis', $dia, $hora, $tenant_id, $barbero_candidato['nombre']);
                $ocupacion_stmt->execute();
                $ocupado = $ocupacion_stmt->get_result()->num_rows > 0;
                $ocupacion_stmt->close();
                if (!$ocupado) {
                    $barbero = $barbero_candidato['nombre'];
                    break;
                }
            }
            $barberos_stmt->close();

            if ($barbero === '') {
                echo "<script>alert('No hay barberos disponibles para ese día y horario.'); window.location.href='panel.php?week_panel=$week_redirect#reservas-table';</script>";
                exit();
            }
        }

        $check_sql = "SELECT id FROM citas WHERE dia = ? AND hora = ? AND tenant_id = ?";
        $check_types = 'ssi';
        $check_params = [$dia, $hora, $tenant_id];
        if ($barbero !== '') {
            $check_sql .= " AND barbero = ?";
            $check_types .= 's';
            $check_params[] = $barbero;
        }
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param($check_types, ...$check_params);
        $check_stmt->execute();
        $check = $check_stmt->get_result();
        if ($check && $check->num_rows === 0) {
            $insert_stmt = $conn->prepare("INSERT INTO citas (dia, hora, nombre, telefono, servicio, barbero, nota, usuario_correo, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, 'Panel admin', ?)");
            $insert_stmt->bind_param('sssssssi', $dia, $hora, $nombre, $telefono, $servicio, $barbero, $nota, $tenant_id);
            $insert_stmt->execute();
            $insert_stmt->close();
        } else {
            echo "<script>alert('No se puede agendar: ese horario ya está ocupado.'); window.location.href='panel.php?week_panel=$week_redirect#reservas-table';</script>";
            exit();
        }
        $check_stmt->close();
    }

    header("Location: panel.php?week_panel=$week_redirect#reservas-table");
    exit();
}

// Bloquear una hora específica desde el formulario del calendario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bloquear_dia_panel'])) {
    $fecha_bloquear = normalizarFechaBD($_POST['dia'] ?? '');
    $hora_bloquear = $conn->real_escape_string(trim($_POST['hora'] ?? ''));
    $week_redirect = intval($_POST['week_panel'] ?? 0);
    if ($fecha_bloquear !== '' && $hora_bloquear !== '') {
        $conn->query("INSERT IGNORE INTO horarios_bloqueados (fecha, hora) VALUES ('$fecha_bloquear', '$hora_bloquear')");
    }

    header("Location: panel.php?week_panel=$week_redirect#reservas-table");
    exit();
}

// Guardar servicio nuevo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_servicio'])) {
    $nombre_servicio = $conn->real_escape_string($_POST['nombre_servicio']);
    $descripcion_servicio = $conn->real_escape_string($_POST['descripcion_servicio']);
    $precio_servicio = floatval($_POST['precio_servicio']);
    if ($nombre_servicio !== '' && $precio_servicio > 0) {
        $conn->query("INSERT INTO servicios (nombre, descripcion, precio, tenant_id) VALUES ('$nombre_servicio', '$descripcion_servicio', $precio_servicio, " . intval($tenant_id) . ")");
    }
    header("Location: panel.php");
    exit();
}

// Eliminar servicio
if (isset($_GET['delete_servicio_id'])) {
    $delete_service_id = intval($_GET['delete_servicio_id']);
    $conn->query("DELETE FROM servicios WHERE id=$delete_service_id AND " . tenantFilter());
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
        $conn->query("UPDATE servicios SET nombre='$nombre_servicio', descripcion='$descripcion_servicio', precio=$precio_servicio WHERE id=$id_servicio AND " . tenantFilter());
    }
    header("Location: panel.php");
    exit();
}

$editar_servicio = null;
if (isset($_GET['edit_servicio_id'])) {
    $edit_service_id = intval($_GET['edit_servicio_id']);
    $resultado_servicio = $conn->query("SELECT * FROM servicios WHERE id=$edit_service_id AND " . tenantFilter());
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

    $config_actual = $conn->query("SELECT fechas_bloqueadas FROM config_horario WHERE id=1 AND " . tenantFilter());
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
    $conn->query("UPDATE config_horario SET hora_inicio='$hora_inicio', hora_fin='$hora_fin', intervalo=$intervalo, dias_bloqueados='$dias_bloqueados', fechas_bloqueadas='$fechas_bloqueadas' WHERE id=1 AND " . tenantFilter());
    header("Location: panel.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_politica_cancelacion'])) {
    $cancelacion_activa = isset($_POST['cancelacion_cliente_activa']) ? 1 : 0;
    $unidad_anticipacion = ($_POST['unidad_anticipacion_cancelacion'] ?? '') === 'horas' ? 'horas' : 'minutos';
    $valor_anticipacion = max(1, intval($_POST['anticipacion_cancelacion_valor'] ?? 1));
    $valor_maximo = $unidad_anticipacion === 'horas' ? 720 : 43200;
    $valor_anticipacion = min($valor_anticipacion, $valor_maximo);
    $anticipacion_minutos = $valor_anticipacion * ($unidad_anticipacion === 'horas' ? 60 : 1);

    $cancelacion_stmt = $conn->prepare("UPDATE config_horario SET cancelacion_cliente_activa = ?, anticipacion_cancelacion_minutos = ? WHERE id = 1 AND tenant_id = ?");
    $cancelacion_stmt->bind_param('iii', $cancelacion_activa, $anticipacion_minutos, $tenant_id);
    $cancelacion_stmt->execute();
    $cancelacion_stmt->close();
    header('Location: panel.php?cancelaciones_guardadas=1#cancelaciones-section');
    exit();
}

if (isset($_GET['remove_dia_bloqueado'])) {
    $dia_remover = trim($_GET['remove_dia_bloqueado']);
    $config_actual = $conn->query("SELECT dias_bloqueados FROM config_horario WHERE id=1 AND " . tenantFilter());
    if ($config_actual && $config_row = $config_actual->fetch_assoc()) {
        $dias_guardados = array_filter(array_map('trim', explode(',', $config_row['dias_bloqueados'] ?? '')));
        $dias_filtrados = array_values(array_filter($dias_guardados, function($dia) use ($dia_remover) {
            return $dia !== $dia_remover;
        }));
        $conn->query("UPDATE config_horario SET dias_bloqueados='" . $conn->real_escape_string(implode(',', $dias_filtrados)) . "' WHERE id=1 AND " . tenantFilter());
    }
    header('Location: panel.php#horario-section');
    exit();
}

if (isset($_GET['remove_fecha_bloqueada'])) {
    $fecha_remover = trim($_GET['remove_fecha_bloqueada']);
    $fecha_remover_db = normalizarFechaBD($fecha_remover);
    $fecha_remover_vista = normalizarFechaVista($fecha_remover_db);

    $config_actual = $conn->query("SELECT fechas_bloqueadas FROM config_horario WHERE id=1 AND " . tenantFilter());
    if ($config_actual && $config_actual->num_rows > 0) {
        $config_row = $config_actual->fetch_assoc();
        $fechas_guardadas = array_filter(array_map('trim', explode(',', $config_row['fechas_bloqueadas'] ?? '')));
        $fechas_filtradas = array_values(array_filter($fechas_guardadas, function($fecha) use ($fecha_remover_vista) {
            return $fecha !== $fecha_remover_vista;
        }));
        $conn->query("UPDATE config_horario SET fechas_bloqueadas='" . $conn->real_escape_string(implode(',', $fechas_filtradas)) . "' WHERE id=1 AND " . tenantFilter());
    }
    header('Location: panel.php#horario-section');
    exit();
}

// Borrar cita
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $conn->query("DELETE FROM citas WHERE id=$delete_id AND " . tenantFilter());
    header("Location: panel.php");
    exit();
}

// Marcar asistencia del cliente
if (isset($_GET['mark_attended_id'])) {
    $mark_id = intval($_GET['mark_attended_id']);
    $cita_stmt = $conn->prepare("SELECT * FROM citas WHERE id = ? AND tenant_id = ? LIMIT 1");
    $cita_stmt->bind_param('ii', $mark_id, $tenant_id);
    $cita_stmt->execute();
    $cita_actual = $cita_stmt->get_result()->fetch_assoc();
    $cita_stmt->close();
    if ($cita_actual && ($cita_actual['asistio'] ?? 'pendiente') !== 'si') {
        $update_stmt = $conn->prepare("UPDATE citas SET asistio = 'si' WHERE id = ? AND tenant_id = ?");
        $update_stmt->bind_param('ii', $mark_id, $tenant_id);
        $update_stmt->execute();
        $update_stmt->close();

        $cliente_correo = trim((string)($cita_actual['usuario_correo'] ?? ''));
        if ($cliente_correo !== '' && $cliente_correo !== 'Invitado') {
            $config_fidelizacion = $conn->query("SELECT fidelizacion_habilitada, puntos_por_servicio FROM barberias WHERE id=" . intval($tenant_id) . " LIMIT 1")->fetch_assoc();
            $cliente_fidelizacion = $conn->query("SELECT id, puntos, fidelizacion_activa FROM usuarios WHERE correo='" . $conn->real_escape_string($cliente_correo) . "' AND tenant_id=" . intval($tenant_id) . " AND rol='cliente' LIMIT 1")->fetch_assoc();
            if ($config_fidelizacion && intval($config_fidelizacion['fidelizacion_habilitada']) === 1 && $cliente_fidelizacion && intval($cliente_fidelizacion['fidelizacion_activa']) === 1) {
                $puntos = max(1, intval($config_fidelizacion['puntos_por_servicio'] ?? 10));
                $nuevo_total = intval($cliente_fidelizacion['puntos']) + $puntos;
                $conn->query("UPDATE usuarios SET puntos=" . intval($nuevo_total) . " WHERE id=" . intval($cliente_fidelizacion['id']) . " AND tenant_id=" . intval($tenant_id));
            }
        }
    }
    header("Location: panel.php");
    exit();
}

if (isset($_GET['mark_absent_id'])) {
    $mark_id = intval($_GET['mark_absent_id']);
    $conn->query("UPDATE citas SET asistio='no' WHERE id=$mark_id AND " . tenantFilter());
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

    $barbero_filter = $barbero !== '' ? " AND barbero='$barbero'" : '';
    $check = $conn->query("SELECT * FROM citas WHERE dia='$dia' AND hora='$hora' AND id<>$id" . $barbero_filter . " AND " . tenantFilter());
    if ($check->num_rows == 0) {
        $cita_previa = $conn->query("SELECT usuario_correo, asistio FROM citas WHERE id=$id AND " . tenantFilter() . " LIMIT 1")->fetch_assoc();
        $sql = "UPDATE citas SET dia='$dia', hora='$hora', nombre='$nombre', telefono='$telefono', servicio='$servicio', barbero='$barbero', asistio='$asistio' WHERE id=$id AND " . tenantFilter();
        $conn->query($sql);
        if (($asistio ?? 'pendiente') === 'si' && (!empty($cita_previa) && ($cita_previa['asistio'] ?? 'pendiente') !== 'si')) {
            $cliente_correo = trim((string)($cita_previa['usuario_correo'] ?? ''));
            if ($cliente_correo !== '' && $cliente_correo !== 'Invitado') {
                $config_fidelizacion = $conn->query("SELECT fidelizacion_habilitada, puntos_por_servicio FROM barberias WHERE id=" . intval($tenant_id) . " LIMIT 1")->fetch_assoc();
                $cliente_fidelizacion = $conn->query("SELECT id, puntos, fidelizacion_activa FROM usuarios WHERE correo='" . $conn->real_escape_string($cliente_correo) . "' AND tenant_id=" . intval($tenant_id) . " AND rol='cliente' LIMIT 1")->fetch_assoc();
                if ($config_fidelizacion && intval($config_fidelizacion['fidelizacion_habilitada']) === 1 && $cliente_fidelizacion && intval($cliente_fidelizacion['fidelizacion_activa']) === 1) {
                    $puntos = max(1, intval($config_fidelizacion['puntos_por_servicio'] ?? 10));
                    $nuevo_total = intval($cliente_fidelizacion['puntos']) + $puntos;
                    $conn->query("UPDATE usuarios SET puntos=" . intval($nuevo_total) . " WHERE id=" . intval($cliente_fidelizacion['id']) . " AND tenant_id=" . intval($tenant_id));
                }
            }
        }
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
    $resultado_editar = $conn->query("SELECT * FROM citas WHERE id=$edit_id AND " . tenantFilter());
    if ($resultado_editar && $resultado_editar->num_rows > 0) {
        $editar_cita = $resultado_editar->fetch_assoc();
    }
}

// Obtener configuración actual de horario
$result_config = $conn->query("SELECT * FROM config_horario WHERE id=1 AND " . tenantFilter());
$config_horario = $result_config->fetch_assoc();

// Obtener lista de servicios
$result_servicios = $conn->query("SELECT * FROM servicios WHERE " . tenantFilter() . " ORDER BY nombre ASC");
$servicios = [];
while ($fila_servicio = $result_servicios->fetch_assoc()) {
    $servicios[] = $fila_servicio;
}

// Obtener lista de barberos
$result_barberos = $conn->query("SELECT * FROM barberos WHERE " . tenantFilter() . " ORDER BY nombre ASC");
$barberos = [];
while ($fila_barbero = $result_barberos->fetch_assoc()) {
    $barberos[] = $fila_barbero;
}
$barberos_propios = array_values(array_filter($barberos, function ($barbero) use ($admin_id) {
    return intval($barbero['owner_admin_id'] ?? 0) === $admin_id;
}));
$barberia_config_fidelizacion = $conn->query("SELECT fidelizacion_habilitada, puntos_por_servicio FROM barberias WHERE id=" . intval($tenant_id) . " LIMIT 1")->fetch_assoc();
$clientes_barberia = [];
$clientes_stmt = $conn->prepare("SELECT u.id, u.correo, u.puntos, u.fidelizacion_activa, COALESCE(MAX(c.nombre), '') AS nombre, COALESCE(MAX(c.telefono), '') AS telefono FROM usuarios u LEFT JOIN citas c ON c.tenant_id = u.tenant_id AND c.usuario_correo = u.correo WHERE u.rol = 'cliente' AND u.tenant_id = ? GROUP BY u.id, u.correo, u.puntos, u.fidelizacion_activa ORDER BY u.id DESC");
$clientes_stmt->bind_param('i', $tenant_id);
$clientes_stmt->execute();
$clientes_result = $clientes_stmt->get_result();
while ($cliente = $clientes_result->fetch_assoc()) $clientes_barberia[] = $cliente;
$clientes_stmt->close();

$estadisticas_panel = [];
if ($estadisticas_habilitadas) {
    $fecha_cita_sql = "COALESCE(STR_TO_DATE(NULLIF(c.dia, ''), '%d/%m/%Y'), STR_TO_DATE(NULLIF(c.dia, ''), '%Y-%m-%d'))";
    $join_precio_servicio_sql = "LEFT JOIN (SELECT tenant_id, nombre, MAX(precio) AS precio FROM servicios GROUP BY tenant_id, nombre) AS precio_servicio ON precio_servicio.tenant_id = c.tenant_id AND precio_servicio.nombre = c.servicio";

    $ingresos_stmt = $conn->prepare("SELECT
        COALESCE(SUM(CASE WHEN $fecha_cita_sql = CURDATE() THEN precio_servicio.precio ELSE 0 END), 0) AS hoy,
        COALESCE(SUM(CASE WHEN $fecha_cita_sql BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND CURDATE() THEN precio_servicio.precio ELSE 0 END), 0) AS semana,
        COALESCE(SUM(CASE WHEN YEAR($fecha_cita_sql) = YEAR(CURDATE()) AND MONTH($fecha_cita_sql) = MONTH(CURDATE()) THEN precio_servicio.precio ELSE 0 END), 0) AS mes
        FROM citas c $join_precio_servicio_sql WHERE c.tenant_id = ? AND c.asistio = 'si'");
    $ingresos_stmt->bind_param('i', $tenant_id);
    $ingresos_stmt->execute();
    $estadisticas_panel['ingresos'] = $ingresos_stmt->get_result()->fetch_assoc() ?: ['hoy' => 0, 'semana' => 0, 'mes' => 0];
    $ingresos_stmt->close();

    $clientes_metricas_sql = "SELECT COUNT(*) AS total, COALESCE(SUM(visitas >= 2), 0) AS recurrentes,
        COALESCE(SUM(visitas = 1 AND ultima_visita <= DATE_SUB(CURDATE(), INTERVAL 30 DAY)), 0) AS no_regresaron
        FROM (SELECT cliente_clave, COUNT(*) AS visitas, MAX(fecha_visita) AS ultima_visita
            FROM (SELECT CASE
                WHEN c.usuario_correo NOT IN ('', 'Invitado') THEN CONCAT('email:', LOWER(c.usuario_correo))
                WHEN NULLIF(TRIM(c.telefono), '') IS NOT NULL THEN CONCAT('tel:', REPLACE(REPLACE(TRIM(c.telefono), ' ', ''), '-', ''))
                ELSE CONCAT('cita:', c.id)
            END AS cliente_clave, $fecha_cita_sql AS fecha_visita
            FROM citas c WHERE c.tenant_id = ? AND c.asistio = 'si') AS citas_atendidas
            GROUP BY cliente_clave) AS clientes_atendidos";
    $clientes_metricas_stmt = $conn->prepare($clientes_metricas_sql);
    $clientes_metricas_stmt->bind_param('i', $tenant_id);
    $clientes_metricas_stmt->execute();
    $estadisticas_panel['clientes'] = $clientes_metricas_stmt->get_result()->fetch_assoc() ?: ['total' => 0, 'recurrentes' => 0, 'no_regresaron' => 0];
    $clientes_metricas_stmt->close();

    $registrados_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM usuarios WHERE tenant_id = ? AND rol = 'cliente'");
    $registrados_stmt->bind_param('i', $tenant_id);
    $registrados_stmt->execute();
    $estadisticas_panel['registrados'] = intval($registrados_stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $registrados_stmt->close();

    $top_barberos_stmt = $conn->prepare("SELECT c.barbero, COUNT(*) AS visitas, COALESCE(SUM(precio_servicio.precio), 0) AS ingresos
        FROM citas c $join_precio_servicio_sql
        WHERE c.tenant_id = ? AND c.asistio = 'si'
            AND $fecha_cita_sql >= DATE_SUB(CURDATE(), INTERVAL DAYOFMONTH(CURDATE()) - 1 DAY)
            AND $fecha_cita_sql <= CURDATE()
        GROUP BY c.barbero ORDER BY visitas DESC, ingresos DESC LIMIT 5");
    $top_barberos_stmt->bind_param('i', $tenant_id);
    $top_barberos_stmt->execute();
    $estadisticas_panel['barberos'] = $top_barberos_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $top_barberos_stmt->close();
}

if (empty($_SESSION['csrf_reset_points'])) {
    $_SESSION['csrf_reset_points'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_cliente_puntos'])) {
    $token_reset = (string)($_POST['csrf_reset_points'] ?? '');
    $cliente_id_reset = intval($_POST['reset_cliente_puntos']);
    if (hash_equals($_SESSION['csrf_reset_points'], $token_reset) && $cliente_id_reset > 0) {
        $reset_stmt = $conn->prepare("UPDATE usuarios SET puntos = 0 WHERE id = ? AND tenant_id = ? AND rol = 'cliente'");
        $reset_stmt->bind_param('ii', $cliente_id_reset, $tenant_id);
        $reset_stmt->execute();
        $reset_stmt->close();
    }
    header('Location: panel.php#fidelizacion-section');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_config_fidelizacion'])) {
    $habilitada = isset($_POST['fidelizacion_habilitada']) ? 1 : 0;
    $puntos_por_servicio = max(1, intval($_POST['puntos_por_servicio'] ?? 10));
    $fidelizacion_stmt = $conn->prepare("UPDATE barberias SET fidelizacion_habilitada = ?, puntos_por_servicio = ? WHERE id = ?");
    $fidelizacion_stmt->bind_param('iii', $habilitada, $puntos_por_servicio, $tenant_id);
    $fidelizacion_stmt->execute();
    $fidelizacion_stmt->close();
    header('Location: panel.php#fidelizacion-section');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_clientes_fidelizacion'])) {
    $seleccionados = $_POST['cliente_fidelizacion'] ?? [];
    foreach ($clientes_barberia as $cliente) {
        $cliente_id = intval($cliente['id']);
        $activo = isset($seleccionados[$cliente_id]) ? 1 : 0;
        $cliente_stmt = $conn->prepare("UPDATE usuarios SET fidelizacion_activa = ? WHERE id = ? AND tenant_id = ? AND rol = 'cliente'");
        $cliente_stmt->bind_param('iii', $activo, $cliente_id, $tenant_id);
        $cliente_stmt->execute();
        $cliente_stmt->close();
    }
    header('Location: panel.php#fidelizacion-section');
    exit();
}

// Anuncios administrables desde el panel
$editar_anuncio = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_toggle_anuncio'])) {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        $res = $conn->query("SELECT estado FROM anuncios WHERE id=$id AND " . tenantFilter() . " AND (superadmin_id IS NULL OR superadmin_id = 0)");
        if ($res && $row = $res->fetch_assoc()) {
            $nuevo = $row['estado'] ? 0 : 1;
            $conn->query("UPDATE anuncios SET estado=$nuevo WHERE id=$id AND " . tenantFilter() . " AND (superadmin_id IS NULL OR superadmin_id = 0)");
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
    $audiencia = 'barberia';
    $roles_destino = 'todos';

    $imagen_nombre = null;
    $archivo_imagen = $_FILES['imagen'] ?? null;
    if ($archivo_imagen && ($archivo_imagen['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $dir = __DIR__ . '/img/anuncios';
        $ext = strtolower(pathinfo($archivo_imagen['name'] ?? '', PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $upload_error = null;

        if (($archivo_imagen['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $codigo_error = $archivo_imagen['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($codigo_error === UPLOAD_ERR_INI_SIZE) {
                $limite_imagen = ini_get('upload_max_filesize');
                $upload_error = 'La imagen supera el límite del servidor (' . $limite_imagen . '). Reduce su tamaño o aumenta upload_max_filesize en php.ini.';
            } elseif ($codigo_error === UPLOAD_ERR_FORM_SIZE) {
                $upload_error = 'La imagen supera el tamaño máximo permitido por el formulario.';
            } else {
                $upload_error = 'No se pudo recibir la imagen (código ' . intval($codigo_error) . '). Revisa el límite y la carpeta temporal de PHP.';
            }
        } elseif (!in_array($ext, $allowed, true) || empty($archivo_imagen['tmp_name']) || !@getimagesize($archivo_imagen['tmp_name'])) {
            $upload_error = 'El archivo seleccionado no es una imagen válida o su formato no está permitido.';
        } elseif ((!is_dir($dir) && !mkdir($dir, 0755, true)) || !is_writable($dir)) {
            $upload_error = 'No se puede escribir en la carpeta de imágenes del servidor.';
        } else {
            $imagen_nombre = uniqid('ad_') . '.' . $ext;
            if (!move_uploaded_file($archivo_imagen['tmp_name'], $dir . '/' . $imagen_nombre)) {
                $imagen_nombre = null;
                $upload_error = 'No se pudo guardar la imagen en el servidor. Verifica los permisos de img/anuncios.';
            }
        }

        if ($upload_error !== null) {
            $_SESSION['anuncio_error'] = $upload_error;
            header('Location: panel.php#anuncios-section');
            exit();
        }
    }

    if (!empty($_POST['id'])) {
        $id = intval($_POST['id']);
        $sql_update = "UPDATE anuncios SET titulo='$titulo', contenido='$contenido', enlace='$enlace', estado=$estado, audiencia='barberia', roles_destino='todos'";
        if ($imagen_nombre) {
            $old_res = $conn->query("SELECT imagen FROM anuncios WHERE id=$id AND " . tenantFilter() . " AND (superadmin_id IS NULL OR superadmin_id = 0)");
            if ($old_res && $old_row = $old_res->fetch_assoc()) {
                if (!empty($old_row['imagen'])) {
                    @unlink(__DIR__ . '/img/anuncios/' . $old_row['imagen']);
                }
            }
            $sql_update .= ", imagen='$imagen_nombre'";
        }
        $sql_update .= " WHERE id=$id AND " . tenantFilter() . " AND (superadmin_id IS NULL OR superadmin_id = 0)";
        $conn->query($sql_update);
    } else {
        $img_sql = $imagen_nombre ? "'$imagen_nombre'" : 'NULL';
        $conn->query("INSERT INTO anuncios (titulo, contenido, imagen, enlace, estado, audiencia, roles_destino, tenant_id) VALUES ('$titulo', '$contenido', $img_sql, '$enlace', $estado, '$audiencia', '$roles_destino', " . intval($tenant_id) . ")");
    }

    header('Location: panel.php#anuncios-section');
    exit();
}

if (isset($_GET['delete_anuncio_id'])) {
    $delete_anuncio_id = intval($_GET['delete_anuncio_id']);
    if ($delete_anuncio_id > 0) {
        $img_res = $conn->query("SELECT imagen FROM anuncios WHERE id=$delete_anuncio_id AND " . tenantFilter() . " AND (superadmin_id IS NULL OR superadmin_id = 0)");
        if ($img_res && $img_row = $img_res->fetch_assoc()) {
            if (!empty($img_row['imagen'])) {
                @unlink(__DIR__ . '/img/anuncios/' . $img_row['imagen']);
            }
        }
        $conn->query("DELETE FROM anuncios WHERE id=$delete_anuncio_id AND " . tenantFilter() . " AND (superadmin_id IS NULL OR superadmin_id = 0)");
    }
    header('Location: panel.php#anuncios-section');
    exit();
}

if (isset($_GET['edit_anuncio_id'])) {
    $edit_anuncio_id = intval($_GET['edit_anuncio_id']);
    $res_edit = $conn->query("SELECT * FROM anuncios WHERE id=$edit_anuncio_id AND " . tenantFilter() . " AND (superadmin_id IS NULL OR superadmin_id = 0)");
    if ($res_edit && $res_edit->num_rows > 0) {
        $editar_anuncio = $res_edit->fetch_assoc();
    }
}

$anuncios = [];
$res_anuncios = $conn->query("SELECT * FROM anuncios a WHERE " . anuncioVisiblePara('admin', 'a') . " AND (a.superadmin_id IS NULL OR a.superadmin_id = 0) ORDER BY a.fecha_creacion DESC");
while ($row_anuncio = $res_anuncios->fetch_assoc()) {
    $anuncios[] = $row_anuncio;
}

$result_bloqueos = $conn->query("SELECT fecha, hora FROM horarios_bloqueados WHERE " . tenantFilter() . " ORDER BY fecha ASC, hora ASC");
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
$result_total = $conn->query("SELECT COUNT(*) AS total FROM citas WHERE " . tenantFilter());
if ($result_total && $row_total = $result_total->fetch_assoc()) {
    $total_citas = intval($row_total['total']);
}
$total_paginas = $total_citas > 0 ? intval(ceil($total_citas / $historia_por_pagina)) : 1;
$offset_citas = ($pagina_historial - 1) * $historia_por_pagina;
$sql_citas = "SELECT * FROM citas WHERE " . tenantFilter() . " ORDER BY id DESC LIMIT $historia_por_pagina OFFSET $offset_citas";
$resultado = $conn->query($sql_citas);
$aviso_suscripcion = null;
$suscripcion = $conn->query("SELECT b.nombre, b.fecha_vencimiento, p.tipo FROM barberias b LEFT JOIN planes_suscripcion p ON p.id=b.plan_id WHERE b.id=" . intval($tenant_id) . " LIMIT 1")->fetch_assoc();
if (($suscripcion['tipo'] ?? '') === 'mensual' && !empty($suscripcion['fecha_vencimiento'])) {
    $dias_restantes = (int) floor((strtotime($suscripcion['fecha_vencimiento']) - strtotime(date('Y-m-d'))) / 86400);
    if ($dias_restantes >= 0 && $dias_restantes <= 5) $aviso_suscripcion = $dias_restantes === 0 ? 'Tu suscripción vence hoy.' : 'Tu suscripción vence en ' . $dias_restantes . ' día(s).';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="img/logomdj.png?v=20260930">
    <title>Panel de Administración | Barbería</title>
    <style>
        :root {
            --bg-principal: #050b14;
            --bg-secundario: #0d1726;
            --dorado: #ffb703;
            --texto-claro: #edf6ff;
            --texto-muted: #a6bed8;
            --borde: rgba(96,165,250,0.18);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: sans-serif; }
        body {
            background:
                radial-gradient(circle at top left, rgba(43,177,255,0.20), transparent 23%),
                radial-gradient(circle at top right, rgba(255,183,3,0.14), transparent 18%),
                linear-gradient(135deg, #040a12 0%, #0b1524 42%, #0f1e2d 100%);
            color: var(--texto-claro); padding: 24px;
        }
        .admin-hero {
            position: relative;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            width: min(1200px, 100%);
            min-height: 220px;
            margin: 0 auto 26px auto;
            padding: 22px 28px 18px;
            border-radius: 26px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.08);
            background: linear-gradient(135deg, rgba(4,10,18,0.88), rgba(12,22,37,0.88));
            box-shadow: 0 26px 60px rgba(6, 12, 24, 0.44);
        }
        .admin-hero::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(6, 10, 18, 0.12), rgba(6, 10, 18, 0.72));
            z-index: 0;
        }
        .admin-hero-media {
            position: absolute;
            inset: 0;
            z-index: 0;
        }
        .admin-hero-media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            display: block;
            filter: saturate(1.12) contrast(1.04) brightness(0.88);
        }
        .admin-hero-content {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 18px;
            flex: 1;
            min-width: 0;
        }
        .admin-hero-avatar {
            width: 120px;
            height: 120px;
            border-radius: 20px;
            overflow: hidden;
            border: 3px solid rgba(255,255,255,0.22);
            background: rgba(255,255,255,0.08);
            box-shadow: 0 20px 40px rgba(0,0,0,0.28);
            flex-shrink: 0;
        }
        .admin-hero-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .admin-hero-text {
            min-width: 0;
        }
        h1 {
            color: #f8fafc;
            font-size: clamp(1.15rem, 2vw, 2.2rem);
            letter-spacing: 0.05em;
            margin: 0;
            line-height: 1.1;
            text-transform: uppercase;
            text-shadow: 0 8px 26px rgba(0,0,0,0.32);
        }
        .admin-account {
            margin-top: 8px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }
        .admin-role, .admin-email {
            display: inline-flex;
            align-items: center;
            min-height: 32px;
            padding: 6px 10px;
            border: 1px solid rgba(255,255,255,0.16);
            border-radius: 8px;
            background: rgba(3,8,18,0.68);
        }
        .admin-role {
            color: #ffcf55;
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
        }
        .admin-email {
            max-width: 100%;
            color: rgba(255,255,255,0.9);
            font-size: 0.9rem;
            font-weight: 600;
            overflow-wrap: anywhere;
        }
        .btn-logout {
            position: relative;
            z-index: 1;
            background: linear-gradient(135deg, rgba(43,177,255,0.18), rgba(15,94,247,0.18));
            color: #d9efff;
            border: 1px solid rgba(96,165,250,0.28);
            padding: 12px 18px;
            cursor: pointer;
            border-radius: 999px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
        }
        .announcement-wall { max-width: 1200px; margin: 0 auto 18px; display: flex; flex-direction: column; gap: 12px; }
        .announcement-card { width: calc(100% - 8px); margin-left: 0; padding: 14px 18px; border-radius: 14px; display: flex; flex-direction: column; gap: 6px; box-shadow: 0 12px 24px rgba(7, 12, 24, 0.18); transition: transform 180ms ease, box-shadow 180ms ease; }
        .announcement-card:hover { transform: translateY(-1px); }
        .announcement-card + .announcement-card { margin-left: 18px; }
        .announcement-card.superadmin { background: linear-gradient(135deg, rgba(251,191,36,0.22), rgba(245, 158, 11, 0.12)); border: 1px solid rgba(245, 194, 66, 0.46); color: #fdf3c2; }
        .announcement-card.admin { background: linear-gradient(135deg, rgba(59,130,246,0.12), rgba(15,23,42,0.78)); border: 1px solid rgba(96,165,250,0.22); color: #e0ebff; }
        .announcement-card strong { font-size: 1rem; color: inherit; }
        .announcement-card span { color: inherit; opacity: 0.95; font-size: 0.92rem; line-height: 1.5; }
        .announcement-card.superadmin strong { color: #f7d55d; }
        .announcement-card.admin strong { color: #a5d8ff; }
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
        .schedule-cell { display: flex; flex-direction: column; gap: 4px; padding: 8px; border-radius: 12px; justify-content: center; width: 100%; height: 84px; min-height: 84px; max-height: 84px; box-sizing: border-box; overflow: hidden; }
        .schedule-cell .slot-name { color: #f8fafc; font-size: 0.95rem; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .schedule-cell .slot-hour { color: #e2e8f0; font-size: 1rem; font-weight: 700; }
        .schedule-cell .slot-status { color: #cbd5e1; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.08em; opacity: 0.85; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .slot-available { background: rgba(16,185,129,0.16); border: 1px solid rgba(16,185,129,0.35); }
        .slot-occupied, .slot-blocked { background: rgba(248,113,113,0.16); border: 1px solid rgba(248,113,113,0.35); }
        .slot-compact { display: none; color: #fecaca; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.08em; opacity: 0.85; }
        .schedule-cell-action { width: 100%; border: 0; color: inherit; font: inherit; text-align: left; cursor: pointer; }
        .schedule-cell-action:hover { filter: brightness(1.2); }
        .schedule-cell-details { width:100%; border:0; color:inherit; font:inherit; text-align:left; cursor:pointer; }
        .schedule-cell-details:hover { filter:brightness(1.18); }
        .barber-flags { display:flex; gap:5px; flex-wrap:nowrap; max-height:12px; overflow:hidden; }
        .barber-flags i { width:12px; height:12px; border-radius:3px; display:inline-block; border:1px solid rgba(255,255,255,.42); }
        .appointments-modal { position:fixed; inset:0; z-index:10001; display:flex; align-items:center; justify-content:center; padding:20px; background:rgba(3,8,18,.78); opacity:0; visibility:hidden; pointer-events:none; transition:opacity 180ms ease,visibility 180ms ease; }
        .appointments-modal.active { opacity:1; visibility:visible; pointer-events:auto; }
        .appointments-card { width:min(620px,100%); max-height:88vh; overflow:auto; padding:24px; border-radius:22px; background:#101827; border:1px solid rgba(255,255,255,.14); box-shadow:0 30px 80px rgba(0,0,0,.45); }
        .appointment-item { display:grid; grid-template-columns:10px 1fr; gap:12px; padding:14px 0; border-bottom:1px solid rgba(255,255,255,.08); }
        .appointment-item > i { width:10px; height:100%; min-height:64px; border-radius:6px; }
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
        .booking-modal {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            z-index: 10000;
            background: rgba(4, 8, 18, 0.78);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 180ms ease, visibility 180ms ease;
        }
        .booking-modal.active { opacity: 1; visibility: visible; pointer-events: auto; }
        .booking-card { width: min(520px, 100%); max-height: 92vh; overflow-y: auto; background: #101827; border: 1px solid rgba(255,255,255,0.14); border-radius: 22px; padding: 24px; box-shadow: 0 30px 80px rgba(0,0,0,0.45); }
        .booking-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
        .booking-header h3 { margin: 0 0 6px; color: var(--dorado); }
        .booking-header p { margin: 0; color: var(--texto-muted); font-size: 14px; }
        .booking-close { border: 0; background: transparent; color: var(--texto-claro); font-size: 28px; cursor: pointer; line-height: 1; }
        .booking-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
        .booking-actions button { flex: 1 1 180px; cursor: pointer; }
        .btn-reservar-panel { background: rgba(50,162,89,0.2); color: #b9f6c7; border-color: rgba(50,162,89,0.45); }
        .btn-bloquear-panel { background: rgba(212,175,55,0.14); color: #f5e8a0; border-color: rgba(212,175,55,0.4); }
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
        .admin-anuncios .anuncio-card { background: rgba(255,255,255,0.025); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 22px; }
        .admin-anuncios .anuncio-card h3 { margin: 0 0 4px; color: var(--texto-claro); }
        .admin-anuncios .anuncio-intro { margin: 0 0 20px; color: var(--texto-muted); font-size: 14px; }
        .admin-anuncios .anuncio-card img { max-width: 140px; border-radius: 12px; }
        .admin-anuncios .anuncio-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .admin-anuncios .anuncio-form .campo-form { margin-bottom: 0; }
        .admin-anuncios .anuncio-form .campo-form:first-of-type,
        .admin-anuncios .anuncio-form .campo-form:nth-of-type(2) { grid-column: 1 / -1; }
        .admin-anuncios .anuncio-form .campo-form input,
        .admin-anuncios .anuncio-form .campo-form select,
        .admin-anuncios .anuncio-form .campo-form textarea { background: #101010; border-color: rgba(255,255,255,0.14); }
        .admin-anuncios .anuncio-form .campo-estado { display: flex; align-items: center; min-height: 42px; }
        .admin-anuncios .anuncio-form .campo-estado label { display: inline-flex; align-items: center; gap: 9px; margin: 0; color: var(--texto-claro); cursor: pointer; }
        .admin-anuncios .anuncio-form .campo-estado input { width: 17px; height: 17px; accent-color: var(--dorado); }
        .admin-anuncios .anuncio-form .anuncio-submit { display: flex; align-items: center; justify-content: flex-end; gap: 10px; }
        .admin-anuncios .anuncio-form .anuncio-submit .btn-cancelar { margin-left: auto; }
        .admin-anuncios .anuncios-lista { margin-top: 18px; overflow-x: auto; }
        .admin-anuncios .anuncios-lista h3 { padding: 18px 22px 0; }
        .admin-anuncios table { width: 100%; border-collapse: collapse; min-width: 620px; }
        .admin-anuncios th { padding: 14px 16px; color: var(--texto-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.07em; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .admin-anuncios td { padding: 15px 16px; color: var(--texto-claro); border-bottom: 1px solid rgba(255,255,255,0.06); text-align: left; }
        .admin-anuncios tbody tr:last-child td { border-bottom: 0; }
        .admin-anuncios tbody tr:hover { background: rgba(255,255,255,0.035); }
        .admin-anuncios .anuncio-titulo { font-weight: 700; }
        .admin-anuncios .anuncio-fecha { color: var(--texto-muted); font-size: 13px; white-space: nowrap; }
        .admin-anuncios .anuncio-estado { cursor: pointer; display: inline-flex; padding: 5px 10px; border-radius: 999px; background: rgba(52,211,153,0.12); color: #a7f3d0; font-size: 12px; font-weight: 700; }
        .admin-anuncios .anuncio-estado.inactivo { background: rgba(255,255,255,0.08); color: var(--texto-muted); }
        .admin-anuncios .anuncio-acciones { display: flex; gap: 8px; flex-wrap: wrap; white-space: nowrap; }
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
        .summary-card { flex: 1 1 220px; background: linear-gradient(145deg, #ffffff, #f2f7fb); border: 1px solid #c8d8e7; border-top: 3px solid #36876f; border-radius: 12px; padding: 20px; min-width: 220px; box-shadow: 0 6px 18px rgba(35, 63, 87, 0.08); }
        .summary-card:nth-child(n+4) { border-top-color: #477daf; }
        .summary-card h3 { margin-bottom: 10px; color: #38566f; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; }
        .summary-card p { margin: 0; font-size: 24px; font-weight: bold; color: #173c53; }
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
            .schedule-table { min-width: 720px; }
            .schedule-table th, .schedule-table td { padding: 10px; font-size: 0.82rem; }
            .schedule-cell { height: 42px; min-height: 42px; max-height: 42px; gap: 2px; padding: 2px 4px; border-radius: 6px; }
            .schedule-cell .slot-name { font-size: 0.68rem; }
            .schedule-cell .slot-hour { font-size: 0.78rem; }
            .schedule-cell .slot-status { font-size: 0.58rem; letter-spacing: 0.02em; }
            .barber-flags { gap: 2px; max-height: 8px; }
            .barber-flags i { width: 8px; height: 8px; border-radius: 2px; }
            .form-editar, .tabla-contenedor, .admin-anuncios .anuncio-card { padding: 18px; }
            .edit-card { width: 100%; padding: 20px; }
            .edit-actions { flex-direction: column; align-items: stretch; }
            .edit-actions a, .edit-actions button, .botones-editar button, .botones-editar a { width: 100%; }
            .btn-actualizar, .btn-cancelar, .btn-editar, .btn-borrar, .btn-asistio, .btn-noasistio { justify-content: center; }
            .admin-anuncios th, .admin-anuncios td { padding: 10px 12px; }
            .admin-anuncios .anuncio-form { grid-template-columns: 1fr; }
            .admin-anuncios .anuncio-form .campo-form:first-of-type,
            .admin-anuncios .anuncio-form .campo-form:nth-of-type(2) { grid-column: auto; }
            .admin-anuncios .anuncio-form .anuncio-submit { justify-content: stretch; flex-direction: column-reverse; align-items: stretch; }
            .admin-anuncios .anuncio-form .anuncio-submit .btn-cancelar { margin-left: 0; }
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
        .barber-color-dot { display:inline-block; width:12px; height:12px; border-radius:50%; margin-right:8px; vertical-align:middle; border:1px solid rgba(255,255,255,0.35); }
        .barber-avatar-preview { display:block; width:72px; height:72px; object-fit:cover; border-radius:50%; margin-top:8px; border:2px solid var(--dorado); }
    </style>
    <link rel="stylesheet" href="responsive.css?v=2">
    <link rel="stylesheet" href="app-theme.css?v=2">
</head>
<body>

    <?php if ($aviso_suscripcion): ?><div style="max-width:1200px;margin:0 auto 16px;padding:14px 18px;border:1px solid #f5b942;background:rgba(245,185,66,.12);color:#ffe4a3;border-radius:12px;font-weight:700;text-align:center;">Aviso de suscripción: <?php echo htmlspecialchars($aviso_suscripcion); ?> Contacta al superadministrador para renovarla.</div><?php endif; ?>

    <header class="admin-hero">
        <div class="admin-hero-media">
            <img src="<?php echo htmlspecialchars($barberia_actual['logo'] ?? 'img/logo.jpeg'); ?>" alt="<?php echo htmlspecialchars($barberia_actual['nombre'] ?? 'Barbería'); ?>">
        </div>
        <div class="admin-hero-content">
            <div class="admin-hero-avatar">
                <img src="<?php echo htmlspecialchars($barberia_actual['logo'] ?? 'img/logo.jpeg'); ?>" alt="Logo">
            </div>
            <div class="admin-hero-text">
                <h1>Control <?php echo htmlspecialchars($barberia_actual['nombre'] ?? 'Barbería'); ?></h1>
                <div class="admin-account"><span class="admin-role">Administrador</span><span class="admin-email"><?php echo htmlspecialchars($admin_nombre); ?></span></div>
            </div>
        </div>
        <a href="panel.php?logout=1" class="btn-logout">Cerrar Sesión</a>
    </header>

    <?php $announcement_cards = []; foreach ($superadmin_banners as $banner): $bannerTitulo = trim((string)($banner['titulo'] ?? '')); $bannerContenido = trim((string)($banner['contenido'] ?? '')); if ($bannerTitulo !== '' || $bannerContenido !== '') { $announcement_cards[] = ['type' => 'superadmin', 'titulo' => $bannerTitulo, 'contenido' => $bannerContenido]; } endforeach; foreach ($anuncios as $anuncio): $audiencia_anuncio = trim((string)($anuncio['audiencia'] ?? 'barberia')); if ($audiencia_anuncio === 'global') { $titulo = trim((string)($anuncio['titulo'] ?? '')); $contenido = trim((string)($anuncio['contenido'] ?? '')); if ($titulo !== '' || $contenido !== '') { $announcement_cards[] = ['type' => 'admin', 'titulo' => $titulo, 'contenido' => $contenido]; } } endforeach; ?>
    <?php if (!empty($announcement_cards)): ?>
        <section class="announcement-wall">
            <?php foreach ($announcement_cards as $card): ?>
                <div class="announcement-card <?php echo htmlspecialchars($card['type']); ?>">
                    <?php if (!empty($card['titulo'])): ?><strong><?php echo htmlspecialchars($card['titulo']); ?></strong><?php endif; ?>
                    <?php if (!empty($card['contenido'])): ?><span><?php echo nl2br(htmlspecialchars($card['contenido'])); ?></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

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

        <!-- Acordeón: Cancelaciones de citas -->
        <div class="accordion-section" id="cancelaciones-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span>🗓️ Cancelaciones de citas</span>
                <span class="accordion-toggle">▼</span>
            </div>
            <div class="accordion-content">
                <div class="form-editar" style="margin-bottom: 0; border-radius: 0 0 8px 8px; border-top: none;">
                    <?php if (isset($_GET['cancelaciones_guardadas'])): ?><p role="status" style="color:#a7f3d0;">Configuración de cancelaciones guardada.</p><?php endif; ?>
                    <?php
                    $anticipacion_guardada = max(0, intval($config_horario['anticipacion_cancelacion_minutos'] ?? 0));
                    $unidad_guardada = $anticipacion_guardada > 0 && $anticipacion_guardada % 60 === 0 ? 'horas' : 'minutos';
                    $valor_guardado = $unidad_guardada === 'horas' ? intdiv($anticipacion_guardada, 60) : $anticipacion_guardada;
                    if ($valor_guardado < 1) $valor_guardado = 1;
                    ?>
                    <form action="panel.php" method="POST">
                        <input type="hidden" name="guardar_politica_cancelacion" value="1">
                        <label style="display:flex;align-items:center;gap:10px;color:var(--texto-claro);">
                            <input type="checkbox" name="cancelacion_cliente_activa" value="1" style="width:auto;" <?php echo intval($config_horario['cancelacion_cliente_activa'] ?? 0) === 1 ? 'checked' : ''; ?>>
                            Permitir que los clientes cancelen sus citas
                        </label>
                        <div class="campo-form">
                            <label for="anticipacion_cancelacion_valor">Anticipación mínima</label>
                            <div style="display:flex;gap:10px;max-width:360px;">
                                <input id="anticipacion_cancelacion_valor" type="number" name="anticipacion_cancelacion_valor" min="1" max="43200" value="<?php echo $valor_guardado; ?>" required>
                                <select name="unidad_anticipacion_cancelacion" aria-label="Unidad de anticipación">
                                    <option value="minutos" <?php echo $unidad_guardada === 'minutos' ? 'selected' : ''; ?>>Minutos</option>
                                    <option value="horas" <?php echo $unidad_guardada === 'horas' ? 'selected' : ''; ?>>Horas</option>
                                </select>
                            </div>
                            <small style="display:block;margin-top:6px;color:var(--texto-muted);">El botón de cancelar solo aparecerá en el perfil del cliente cuando esta opción esté habilitada.</small>
                        </div>
                        <div class="botones-editar">
                            <button type="submit" class="btn-actualizar">Guardar configuración</button>
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
        <div class="accordion-section" id="fidelizacion-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span>🎁 Fidelización</span>
                <span class="accordion-toggle">▼</span>
            </div>
            <div class="accordion-content">
                <div class="form-editar" style="margin-bottom: 18px; border-radius: 8px;">
                    <h3>Configuración de puntos</h3>
                    <form action="panel.php" method="POST">
                        <input type="hidden" name="guardar_config_fidelizacion" value="1">
                        <div class="campo-form">
                            <label style="display:flex; align-items:center; gap:10px; margin-bottom: 12px;">
                                <input type="checkbox" name="fidelizacion_habilitada" value="1" <?php echo !empty($barberia_config_fidelizacion['fidelizacion_habilitada']) ? 'checked' : ''; ?>>
                                Habilitar sistema de fidelización para esta barbería
                            </label>
                        </div>
                        <div class="campo-form">
                            <label>Puntos por servicio asistido</label>
                            <input type="number" min="1" name="puntos_por_servicio" value="<?php echo intval($barberia_config_fidelizacion['puntos_por_servicio'] ?? 10); ?>" required>
                        </div>
                        <div class="botones-editar">
                            <button type="submit" class="btn-actualizar">Guardar configuración</button>
                        </div>
                    </form>
                </div>

                <div class="tabla-contenedor" style="border-radius: 8px;">
                    <h3>Clientes de la barbería</h3>
                    <form action="panel.php" method="POST">
                        <input type="hidden" name="guardar_clientes_fidelizacion" value="1">
                        <input type="hidden" name="csrf_reset_points" value="<?php echo htmlspecialchars($_SESSION['csrf_reset_points']); ?>">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Teléfono</th>
                                    <th>Correo</th>
                                    <th>Puntos</th>
                                    <th>Fidelización</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($clientes_barberia)): ?>
                                    <?php foreach ($clientes_barberia as $cliente): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($cliente['nombre'] ?: 'Sin nombre'); ?></td>
                                            <td><?php echo htmlspecialchars($cliente['telefono'] ?: 'Sin teléfono'); ?></td>
                                            <td><?php echo htmlspecialchars($cliente['correo']); ?></td>
                                            <td><?php echo number_format(intval($cliente['puntos'] ?? 0), 0, ',', '.'); ?></td>
                                            <td>
                                                <label style="display:flex; align-items:center; justify-content:center; gap:8px;">
                                                    <input type="checkbox" name="cliente_fidelizacion[<?php echo intval($cliente['id']); ?>]" value="1" <?php echo intval($cliente['fidelizacion_activa'] ?? 0) === 1 ? 'checked' : ''; ?>>
                                                    Activa
                                                </label>
                                            </td>
                                            <td>
                                                <button type="submit" class="btn-borrar" name="reset_cliente_puntos" value="<?php echo intval($cliente['id']); ?>" onclick="return confirm('¿Poner los puntos de este cliente en cero? Esta acción no se puede deshacer.');">Poner en 0</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" style="text-align:center; color:var(--texto-muted);">Aún no hay clientes registrados en esta barbería.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <?php if (!empty($clientes_barberia)): ?>
                            <div class="botones-editar" style="margin-top: 16px;">
                                <button type="submit" class="btn-actualizar">Guardar cambios de fidelización</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <div class="accordion-section" id="barberos-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span>💈 Barberos</span>
                <span class="accordion-toggle">▼</span>
            </div>
            <div class="accordion-content">
                <div class="form-editar" style="margin-bottom: 0; border-radius: 0 0 8px 8px; border-top: none;">
                    <h3><?php echo $editar_barbero ? 'Editar perfil de barbero' : 'Crear perfil de barbero'; ?></h3>
                    <form action="panel.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="guardar_barbero" value="1">
                        <input type="hidden" name="barbero_id" value="<?php echo intval($editar_barbero['id'] ?? 0); ?>">
                        <div class="campo-form">
                            <label>Nombre del barbero</label>
                            <input type="text" name="nombre_barbero" placeholder="Ej. Juan" value="<?php echo htmlspecialchars($editar_barbero['nombre'] ?? ''); ?>" required>
                        </div>
                        <div class="campo-form">
                            <label>Descripción</label>
                            <textarea name="descripcion_barbero" rows="3" placeholder="Especialidad, estilo o notas"><?php echo htmlspecialchars($editar_barbero['descripcion'] ?? ''); ?></textarea>
                        </div>
                        <div class="campo-form"><label>Correo de acceso</label><input type="email" name="correo_barbero" value="<?php echo htmlspecialchars($editar_barbero['correo'] ?? ''); ?>" required></div>
                        <div class="campo-form"><label><?php echo $editar_barbero ? 'Nueva contraseña (opcional)' : 'Contraseña inicial'; ?></label><input type="password" name="password_barbero" minlength="8" <?php echo $editar_barbero ? '' : 'required'; ?>></div>
                        <div class="campo-form"><label>Foto de perfil</label><input type="file" name="foto_barbero" accept="image/jpeg,image/png,image/webp"><?php if (!empty($editar_barbero['foto'])): ?><img class="barber-avatar-preview" src="<?php echo htmlspecialchars($editar_barbero['foto']); ?>" alt="Foto actual"><?php endif; ?></div>
                        <div class="campo-form"><label>Color en la agenda</label><input type="color" name="color_barbero" value="<?php echo htmlspecialchars($editar_barbero['color'] ?? '#d4af37'); ?>"></div>
                        <div class="campo-form"><label>Horario individual</label><div style="display:flex;gap:10px;flex-wrap:wrap"><input type="time" name="hora_inicio_barbero" value="<?php echo htmlspecialchars(substr($editar_barbero['hora_inicio'] ?? '', 0, 5)); ?>"><input type="time" name="hora_fin_barbero" value="<?php echo htmlspecialchars(substr($editar_barbero['hora_fin'] ?? '', 0, 5)); ?>"></div></div>
                        <div class="campo-form"><label>Días de trabajo</label><div style="display:flex;flex-wrap:wrap;gap:10px"><?php foreach (['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'] as $dia_trabajo): ?><label style="display:flex;align-items:center;gap:5px"><input type="checkbox" name="dias_trabajo_barbero[]" value="<?php echo $dia_trabajo; ?>" <?php echo in_array($dia_trabajo, array_filter(array_map('trim', explode(',', $editar_barbero['dias_trabajo'] ?? ''))), true) ? 'checked' : ''; ?>><?php echo $dia_trabajo; ?></label><?php endforeach; ?></div></div>
                        <div class="botones-editar">
                            <button type="submit" class="btn-actualizar"><?php echo $editar_barbero ? 'Guardar cambios' : 'Crear barbero'; ?></button>
                            <?php if ($editar_barbero): ?><a class="btn-cancelar" href="panel.php#barberos-section">Cancelar</a><?php endif; ?>
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
                            <?php if (count($barberos_propios) > 0): ?>
                                <?php foreach ($barberos_propios as $barbero): ?>
                                    <tr>
                                        <td><?php echo $barbero['id']; ?></td>
                                        <td><span class="barber-color-dot" style="background:<?php echo htmlspecialchars($barbero['color'] ?? '#d4af37'); ?>"></span><?php echo htmlspecialchars($barbero['nombre']); ?></td>
                                        <td><?php echo htmlspecialchars($barbero['descripcion'] ?: 'Sin descripción'); ?></td>
                                        <td style="white-space:nowrap;">
                                            <a class="btn-editar" href="panel.php?edit_barbero_id=<?php echo $barbero['id']; ?>#barberos-section">Editar</a>
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

        <div class="accordion-section" id="estadisticas-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span>📊 Estadísticas del negocio</span>
                <span class="accordion-toggle">▼</span>
            </div>
            <div class="accordion-content">
                <?php if ($estadisticas_habilitadas): ?>
                    <div class="summary-cards">
                        <div class="summary-card"><h3>Ingresos hoy</h3><p>$<?php echo number_format((float) ($estadisticas_panel['ingresos']['hoy'] ?? 0), 0, ',', '.'); ?></p></div>
                        <div class="summary-card"><h3>Ingresos esta semana</h3><p>$<?php echo number_format((float) ($estadisticas_panel['ingresos']['semana'] ?? 0), 0, ',', '.'); ?></p></div>
                        <div class="summary-card"><h3>Ingresos este mes</h3><p>$<?php echo number_format((float) ($estadisticas_panel['ingresos']['mes'] ?? 0), 0, ',', '.'); ?></p></div>
                        <div class="summary-card"><h3>Clientes atendidos</h3><p><?php echo intval($estadisticas_panel['clientes']['total'] ?? 0); ?></p></div>
                        <div class="summary-card"><h3>Clientes recurrentes</h3><p><?php echo intval($estadisticas_panel['clientes']['recurrentes'] ?? 0); ?></p></div>
                        <div class="summary-card"><h3>Sin retorno · 30+ días</h3><p><?php echo intval($estadisticas_panel['clientes']['no_regresaron'] ?? 0); ?></p></div>
                        <div class="summary-card"><h3>Clientes registrados</h3><p><?php echo intval($estadisticas_panel['registrados'] ?? 0); ?></p></div>
                    </div>
                    <div class="tabla-contenedor" style="margin-top:16px;">
                        <h3>Top barberos · mes actual</h3>
                        <table>
                            <thead><tr><th>Barbero</th><th>Servicios atendidos</th><th>Ingresos estimados</th></tr></thead>
                            <tbody>
                                <?php if (!empty($estadisticas_panel['barberos'])): ?>
                                    <?php foreach ($estadisticas_panel['barberos'] as $barbero_metrica): ?>
                                        <tr><td><?php echo htmlspecialchars($barbero_metrica['barbero'] ?: 'Sin asignar'); ?></td><td><?php echo intval($barbero_metrica['visitas']); ?></td><td>$<?php echo number_format((float) $barbero_metrica['ingresos'], 0, ',', '.'); ?></td></tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" style="text-align:center; color:var(--texto-muted);">Aún no hay servicios marcados como atendidos este mes.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <p style="margin-top:12px;color:var(--texto-muted);">Los ingresos son estimados con el precio actual de cada servicio y solo incluyen citas marcadas como asistidas. Los clientes sin retorno tienen una visita y llevan al menos 30 días sin volver.</p>
                <?php else: ?>
                    <div class="form-editar"><h3>Función no activada</h3><p>Las estadísticas avanzadas no están habilitadas para esta barbería. Contacta al superadministrador para contratar esta función.</p></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="accordion-section" id="anuncios-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span>📣 Anuncios</span>
                <span class="accordion-toggle">▼</span>
            </div>
            <div class="accordion-content">
                <div class="admin-anuncios">
                <div class="anuncio-card">
                    <h3><?php echo $editar_anuncio ? 'Editar anuncio' : 'Crear anuncio'; ?></h3>
                    <p class="anuncio-intro">Publica promociones, novedades o información importante para tus clientes.</p>
                    <?php if (!empty($_SESSION['anuncio_error'])): ?>
                        <p role="alert" style="color:#f87171;"><?php echo htmlspecialchars($_SESSION['anuncio_error']); ?></p>
                        <?php unset($_SESSION['anuncio_error']); ?>
                    <?php endif; ?>
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
                            <p id="anuncio-imagen-estado" role="status" aria-live="polite" style="color:var(--texto-muted);"></p>
                            <?php if (!empty($editar_anuncio['imagen'])): ?>
                                <div style="margin-top:10px;"><img src="img/anuncios/<?php echo htmlspecialchars($editar_anuncio['imagen']); ?>" alt="Imagen anuncio"></div>
                            <?php endif; ?>
                        </div>
                        <div class="campo-form campo-estado">
                            <label><input type="checkbox" name="estado" <?php echo (!isset($editar_anuncio) || (isset($editar_anuncio['estado']) && $editar_anuncio['estado'])) ? 'checked' : ''; ?>> Mostrar anuncio</label>
                        </div>
                        <div class="anuncio-submit">
                            <?php if ($editar_anuncio): ?>
                                <a href="panel.php#anuncios-section" class="btn-cancelar">Cancelar</a>
                            <?php endif; ?>
                            <button type="submit" class="btn-actualizar"><?php echo $editar_anuncio ? 'Actualizar anuncio' : 'Guardar anuncio'; ?></button>
                        </div>
                    </form>
                </div>

                <div class="tabla-contenedor anuncio-card anuncios-lista">
                    <h3>Anuncios publicados</h3>
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
                                            <td class="anuncio-titulo"><?php echo htmlspecialchars($anuncio['titulo']); ?></td>
                                            <td><span class="toggle-anuncio anuncio-estado <?php echo $anuncio['estado'] ? '' : 'inactivo'; ?>" data-id="<?php echo $anuncio['id']; ?>"><?php echo $anuncio['estado'] ? 'Activo' : 'Inactivo'; ?></span></td>
                                            <td class="anuncio-fecha"><?php echo htmlspecialchars($anuncio['fecha_creacion']); ?></td>
                                            <td>
                                                <div class="anuncio-acciones">
                                                    <a class="btn-editar" href="panel.php?edit_anuncio_id=<?php echo $anuncio['id']; ?>#anuncios-section">Editar</a>
                                                    <a class="btn-borrar" href="panel.php?delete_anuncio_id=<?php echo $anuncio['id']; ?>#anuncios-section" onclick="return confirm('¿Eliminar este anuncio?')">Eliminar</a>
                                                </div>
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
                    $servicios_query = $conn->query("SELECT * FROM servicios WHERE " . tenantFilter() . " ORDER BY id ASC");
                    
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
                                <th>Barbero</th>
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
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                        <?php foreach ($barberos as $barbero_leyenda): ?><span class="badge" style="border:1px solid <?php echo htmlspecialchars($barbero_leyenda['color'] ?? '#d4af37'); ?>;color:<?php echo htmlspecialchars($barbero_leyenda['color'] ?? '#d4af37'); ?>"><i class="barber-color-dot" style="background:<?php echo htmlspecialchars($barbero_leyenda['color'] ?? '#d4af37'); ?>"></i><?php echo htmlspecialchars($barbero_leyenda['nombre']); ?></span><?php endforeach; ?>
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
                                $colores_barberos = [];
                                foreach ($barberos as $barbero_item) $colores_barberos[$barbero_item['nombre']] = $barbero_item['color'] ?: '#d4af37';
                                $todas_citas = $conn->query("SELECT dia, hora, nombre, telefono, servicio, barbero, nota, id, asistio FROM citas WHERE " . tenantFilter());
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
                                            $citas_map[$dia_nombre][$hora_key][] = $cita;
                                        } elseif (in_array($cita['dia'], $dias_nombres, true)) {
                                            $citas_map[$cita['dia']][$hora_key][] = $cita;
                                        }
                                    }
                                }

                                foreach ($horas as $h):
                                    echo "<tr>";
                                    foreach ($dias_nombres as $index => $d) {
                                        $fecha_dia = date('d/m/Y', $lunes_panel + ($index * 86400));
                                        $esta_bloqueado = isset($horarios_bloqueados_map[$fecha_dia]) && in_array($h, $horarios_bloqueados_map[$fecha_dia], true);
                                        $citas_hora = $citas_map[$d][$h] ?? [];
                                        $nombres_ocupados = array_column($citas_hora, 'barbero');
                                        $barberos_libres = array_values(array_filter($barberos, function ($barbero_disponible) use ($nombres_ocupados) {
                                            return intval($barbero_disponible['activo'] ?? 1) === 1 && !in_array($barbero_disponible['nombre'], $nombres_ocupados, true);
                                        }));
                                        $estado_label = $citas_hora ? 'Ocupado' : ($esta_bloqueado ? 'Bloqueado' : 'Libre');
                                        $estado_class = $citas_hora ? 'slot-occupied' : ($esta_bloqueado ? 'slot-blocked' : 'slot-available');
                                        $cell_hour = htmlspecialchars($h);
                                        echo "<td style='padding:0;'>";
                                        if (!$citas_hora && !$esta_bloqueado) {
                                            echo "<button type='button' class='schedule-cell schedule-cell-action " . $estado_class . "' data-booking-date='" . htmlspecialchars($fecha_dia, ENT_QUOTES) . "' data-booking-hour='" . htmlspecialchars($h, ENT_QUOTES) . "'>";
                                        } elseif ($citas_hora) {
                                            $detalle_json = htmlspecialchars(json_encode($citas_hora, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
                                            $libres_json = htmlspecialchars(json_encode($barberos_libres, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
                                            echo "<button type='button' class='schedule-cell schedule-cell-details " . $estado_class . "' data-appointments='" . $detalle_json . "' data-booking-date='" . htmlspecialchars($fecha_dia, ENT_QUOTES) . "' data-booking-hour='" . htmlspecialchars($h, ENT_QUOTES) . "' data-available-barbers='" . $libres_json . "'>";
                                        } else {
                                            echo "<a href='panel.php?week_panel=" . urlencode($week_offset) . "&toggle_bloqueo_hora=1&fecha=" . urlencode($fecha_dia) . "&hora=" . urlencode($h) . "#reservas-table' style='display:block; color:inherit; text-decoration:none; padding:0; background:transparent;'>"
                                                . "<div class='schedule-cell " . $estado_class . "'>";
                                        }
                                        if ($citas_hora) {
                                            echo "<strong class='slot-name'>" . count($citas_hora) . " cita(s)</strong>";
                                            echo "<span class='slot-compact'>Ver barberos</span><span class='barber-flags'>";
                                            foreach ($citas_hora as $cita_hora) {
                                                $color = htmlspecialchars($colores_barberos[$cita_hora['barbero']] ?? '#d4af37', ENT_QUOTES);
                                                echo "<i style='background:$color' title='" . htmlspecialchars($cita_hora['barbero'] ?: 'Sin asignar', ENT_QUOTES) . "'></i>";
                                            }
                                            echo "</span>";
                                        }
                                        echo "<span class='slot-hour'>" . $cell_hour . "</span>";
                                        if ($esta_bloqueado) {
                                            echo "<span class='slot-status'>Bloqueado</span>";
                                        }
                                        if ($citas_hora) {
                                            echo "</button>";
                                        } elseif ($esta_bloqueado) {
                                            echo "</div></a>";
                                        } else {
                                            echo "</button>";
                                        }
                                        echo "</td>";
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

        <div class="appointments-modal" id="appointmentsModal" aria-hidden="true">
            <div class="appointments-card">
                <div class="booking-header"><div><h3>Citas de esta hora</h3><p id="appointmentsSummary">Detalle por barbero</p></div><button type="button" class="booking-close" id="appointmentsClose">&times;</button></div>
                <div id="appointmentsList"></div>
                <button type="button" class="btn-actualizar" id="appointmentNewBooking" style="display:none;width:100%;margin-top:18px;">Agendar otro barbero</button>
            </div>
        </div>

        <div class="booking-modal" id="bookingModal" aria-hidden="true">
            <div class="booking-card">
                <div class="booking-header">
                    <div>
                        <h3>Agendar cita desde el panel</h3>
                        <p id="bookingSummary">Selecciona los datos de la reserva.</p>
                    </div>
                    <button type="button" class="booking-close" id="bookingClose" aria-label="Cerrar">&times;</button>
                </div>
                <form action="panel.php" method="POST" id="panelBookingForm">
                    <input type="hidden" name="agendar_panel" value="1">
                    <input type="hidden" name="week_panel" value="<?php echo $week_offset; ?>">
                    <input type="hidden" name="dia" id="bookingDate">
                    <input type="hidden" name="hora" id="bookingHour">
                    <div class="campo-form">
                        <label for="bookingService">Servicio</label>
                        <select name="servicio" id="bookingService" required>
                            <?php if (!empty($servicios)): ?>
                                <?php foreach ($servicios as $servicio): ?>
                                    <option value="<?php echo htmlspecialchars($servicio['nombre']); ?>"><?php echo htmlspecialchars($servicio['nombre']); ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="Corte Clasico">Corte clásico</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="campo-form">
                        <label for="bookingBarber">Barbero</label>
                        <select name="barbero" id="bookingBarber">
                            <option value="">Cualquiera</option>
                            <?php foreach ($barberos as $barbero): ?>
                                <option value="<?php echo htmlspecialchars($barbero['nombre']); ?>"><?php echo htmlspecialchars($barbero['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="campo-form">
                        <label for="bookingName">Nombre completo</label>
                        <input type="text" name="nombre" id="bookingName" required>
                    </div>
                    <div class="campo-form">
                        <label for="bookingPhone">Teléfono</label>
                        <input type="tel" name="telefono" id="bookingPhone" required>
                    </div>
                    <div class="campo-form">
                        <label for="bookingNote">Nota (opcional)</label>
                        <textarea name="nota" id="bookingNote" maxlength="255" placeholder="¿Tienes alguna indicación?"></textarea>
                    </div>
                    <div class="booking-actions">
                        <button type="submit" name="bloquear_dia_panel" value="1" formnovalidate class="btn-actualizar btn-bloquear-panel">Bloquear hora</button>
                        <button type="submit" class="btn-actualizar btn-reservar-panel">Agendar cita</button>
                    </div>
                </form>
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
                        self.classList.toggle('inactivo', data.estado != 1);
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

        const bookingModal = document.getElementById('bookingModal');
        const bookingClose = document.getElementById('bookingClose');
        const bookingDate = document.getElementById('bookingDate');
        const bookingHour = document.getElementById('bookingHour');
        const bookingBarber = document.getElementById('bookingBarber');
        const bookingSummary = document.getElementById('bookingSummary');
        const closeBookingModal = function() {
            if (!bookingModal) return;
            bookingModal.classList.remove('active');
            bookingModal.setAttribute('aria-hidden', 'true');
        };

        document.querySelectorAll('.schedule-cell-action').forEach(function(cell) {
            cell.addEventListener('click', function() {
                bookingDate.value = this.dataset.bookingDate;
                bookingHour.value = this.dataset.bookingHour;
                bookingSummary.textContent = 'Fecha: ' + this.dataset.bookingDate + ' · Hora: ' + this.dataset.bookingHour;
                bookingModal.classList.add('active');
                bookingModal.setAttribute('aria-hidden', 'false');
            });
        });

        bookingClose?.addEventListener('click', closeBookingModal);
        bookingModal?.addEventListener('click', function(event) {
            if (event.target === bookingModal) closeBookingModal();
        });

        const appointmentsModal = document.getElementById('appointmentsModal');
        const appointmentsList = document.getElementById('appointmentsList');
        const appointmentsSummary = document.getElementById('appointmentsSummary');
        const appointmentNewBooking = document.getElementById('appointmentNewBooking');
        let nextBooking = null;
        const closeAppointments = function() {
            appointmentsModal?.classList.remove('active');
            appointmentsModal?.setAttribute('aria-hidden', 'true');
        };
        document.querySelectorAll('.schedule-cell-details').forEach(function(cell) {
            cell.addEventListener('click', function() {
                const appointments = JSON.parse(this.dataset.appointments || '[]');
                const availableBarbers = JSON.parse(this.dataset.availableBarbers || '[]');
                nextBooking = {
                    date: this.dataset.bookingDate,
                    hour: this.dataset.bookingHour,
                    barbers: availableBarbers
                };
                appointmentsSummary.textContent = appointments.length + ' cita(s) en esta hora';
                appointmentsList.innerHTML = appointments.map(function(item) {
                    const color = <?php echo json_encode($colores_barberos ?? []); ?>[item.barbero] || '#d4af37';
                    const state = item.asistio === 'si' ? 'Asistió' : (item.asistio === 'no' ? 'No asistió' : 'Pendiente');
                    return '<article class="appointment-item"><i style="background:' + color + '"></i><div><strong>' + (item.barbero || 'Sin asignar') + '</strong><div>' + item.nombre + ' · ' + item.telefono + '</div><div class="muted">' + item.servicio + (item.nota ? ' · ' + item.nota : '') + ' · ' + state + '</div></div></article>';
                }).join('');
                appointmentNewBooking.style.display = availableBarbers.length > 0 ? 'block' : 'none';
                appointmentNewBooking.textContent = availableBarbers.length > 0
                    ? 'Agendar otro barbero (' + availableBarbers.length + ' disponible' + (availableBarbers.length === 1 ? '' : 's') + ')'
                    : 'No hay otro barbero disponible';
                appointmentsModal.classList.add('active');
                appointmentsModal.setAttribute('aria-hidden', 'false');
            });
        });
        appointmentNewBooking?.addEventListener('click', function() {
            if (!nextBooking || !nextBooking.barbers.length) return;
            closeAppointments();
            bookingDate.value = nextBooking.date;
            bookingHour.value = nextBooking.hour;
            bookingSummary.textContent = 'Fecha: ' + nextBooking.date + ' · Hora: ' + nextBooking.hour;
            bookingBarber.innerHTML = nextBooking.barbers.map(function(barbero) {
                return '<option value="' + String(barbero.nombre).replace(/"/g, '&quot;') + '">' + String(barbero.nombre).replace(/</g, '&lt;') + '</option>';
            }).join('');
            bookingModal.classList.add('active');
            bookingModal.setAttribute('aria-hidden', 'false');
        });
        document.getElementById('appointmentsClose')?.addEventListener('click', closeAppointments);
        appointmentsModal?.addEventListener('click', function(event) { if (event.target === appointmentsModal) closeAppointments(); });

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

        const anuncioForm = document.querySelector('.anuncio-form');
        const imagenAnuncio = anuncioForm?.querySelector('input[name="imagen"]');
        const estadoImagenAnuncio = document.getElementById('anuncio-imagen-estado');
        const botonAnuncio = anuncioForm?.querySelector('button[type="submit"]');
        if (anuncioForm && imagenAnuncio && estadoImagenAnuncio && botonAnuncio) {
            const textoOriginalBoton = botonAnuncio.textContent;
            anuncioForm.addEventListener('submit', async function(event) {
                const imagenSeleccionada = imagenAnuncio.files?.[0];
                if (!imagenSeleccionada || imagenSeleccionada.size <= 1800000) return;

                event.preventDefault();
                botonAnuncio.disabled = true;
                botonAnuncio.textContent = 'Optimizando imagen...';
                estadoImagenAnuncio.textContent = '';

                try {
                    const imagenOptimizada = await optimizarImagenAnuncio(imagenSeleccionada);
                    const nombreWebp = imagenSeleccionada.name.replace(/\.[^/.]+$/, '') + '.webp';
                    const archivoWebp = new File([imagenOptimizada], nombreWebp, { type: 'image/webp' });
                    const transferencia = new DataTransfer();
                    transferencia.items.add(archivoWebp);
                    imagenAnuncio.files = transferencia.files;
                    HTMLFormElement.prototype.submit.call(anuncioForm);
                } catch (error) {
                    estadoImagenAnuncio.textContent = error.message || 'No se pudo optimizar la imagen. Selecciona otra imagen.';
                    botonAnuncio.disabled = false;
                    botonAnuncio.textContent = textoOriginalBoton;
                }
            });
        }

        async function optimizarImagenAnuncio(archivo) {
            if (archivo.type === 'image/gif') {
                throw new Error('Los GIF grandes no se optimizan para conservar su animación. Usa JPG, PNG o WebP.');
            }
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(archivo.type)) {
                throw new Error('El formato no se puede optimizar automáticamente. Usa JPG, PNG o WebP.');
            }

            const bitmap = await createImageBitmap(archivo);
            let escala = Math.min(1, 1920 / Math.max(bitmap.width, bitmap.height));
            let ancho = Math.max(1, Math.round(bitmap.width * escala));
            let alto = Math.max(1, Math.round(bitmap.height * escala));
            const canvas = document.createElement('canvas');
            const contexto = canvas.getContext('2d');
            if (!contexto) {
                bitmap.close();
                throw new Error('El navegador no pudo procesar la imagen.');
            }

            while (true) {
                canvas.width = ancho;
                canvas.height = alto;
                contexto.drawImage(bitmap, 0, 0, ancho, alto);

                for (const calidad of [0.84, 0.74, 0.64, 0.54, 0.44]) {
                    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/webp', calidad));
                    if (!blob || blob.type !== 'image/webp') {
                        bitmap.close();
                        throw new Error('Este navegador no admite la optimización automática a WebP.');
                    }
                    if (blob && blob.size <= 1800000) {
                        bitmap.close();
                        return blob;
                    }
                }

                if (Math.max(ancho, alto) <= 640) break;
                ancho = Math.max(1, Math.round(ancho * 0.82));
                alto = Math.max(1, Math.round(alto * 0.82));
            }

            bitmap.close();
            throw new Error('No se pudo reducir la imagen por debajo de 1,8 MB. Prueba con otra imagen.');
        }
    </script>

</body>
</html>