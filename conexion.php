<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$host = "localhost";
$user = "root";
$pass = "1406";
$db = "wason_studio";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

function ensureColumn($conn, $table, $column, $definition) {
    $exists = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($column) . "'");
    if ($exists && $exists->num_rows === 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function ensureIndex($conn, $table, $indexName, $columns) {
    $table = $conn->real_escape_string($table);
    $indexName = $conn->real_escape_string($indexName);
    $columns = trim($columns);
    $indexExists = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
    if ($indexExists && $indexExists->num_rows === 0) {
        $conn->query("ALTER TABLE `$table` ADD INDEX `$indexName` ($columns)");
    }
}

function tenantFilter($alias = '') {
    global $tenant_id;
    return ($alias !== '' ? $alias . '.' : '') . 'tenant_id=' . intval($tenant_id);
}

function tenantRedirect($path = 'index.php', $fallbackSlug = '') {
    $slug = trim($_SESSION['barberia_slug'] ?? ($fallbackSlug ?: ''));
    if ($slug === '') {
        global $barberia_actual;
        $slug = trim($barberia_actual['slug'] ?? '');
    }
    if ($slug !== '') {
        return $path . '?barberia=' . urlencode($slug);
    }
    return $path;
}

function anuncioVisiblePara($rol, $alias = 'a') {
    global $tenant_id;
    $prefix = $alias !== '' ? $alias . '.' : '';
    $rol = preg_replace('/[^a-z]/', '', strtolower($rol));
    $audienciaDefault = "(" . $prefix . "audiencia='' OR " . $prefix . "audiencia IS NULL OR " . $prefix . "audiencia='barberia')";
    $rolesDefault = "(" . $prefix . "roles_destino='' OR " . $prefix . "roles_destino IS NULL OR " . $prefix . "roles_destino='todos' OR FIND_IN_SET('$rol', " . $prefix . "roles_destino))";
    return "((" . $prefix . "audiencia='global' OR (" . $audienciaDefault . " AND " . $prefix . "tenant_id=" . intval($tenant_id) . ")) AND " . $rolesDefault . ")";
}

$conn->query("CREATE TABLE IF NOT EXISTS planes_suscripcion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    tipo ENUM('mensual','permanente') NOT NULL,
    precio DECIMAL(10,2) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS barberias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL UNIQUE,
    descripcion TEXT,
    telefono VARCHAR(80) NOT NULL DEFAULT '',
    correo VARCHAR(255) NOT NULL DEFAULT '',
    direccion VARCHAR(255) NOT NULL DEFAULT '',
    logo VARCHAR(255) NOT NULL DEFAULT 'img/logo.jpeg',
    tema ENUM('oscuro','claro') NOT NULL DEFAULT 'oscuro',
    facebook VARCHAR(255) NOT NULL DEFAULT '',
    instagram VARCHAR(255) NOT NULL DEFAULT '',
    whatsapp VARCHAR(255) NOT NULL DEFAULT '',
    plan_id INT NULL,
    estado ENUM('activa','suspendida') NOT NULL DEFAULT 'activa',
    estadisticas_habilitadas TINYINT(1) NOT NULL DEFAULT 0,
    fidelizacion_habilitada TINYINT(1) NOT NULL DEFAULT 0,
    puntos_por_servicio INT NOT NULL DEFAULT 10,
    fecha_vencimiento DATE NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS superadmins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    correo VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS accesos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    tenant_id INT NOT NULL,
    rol VARCHAR(30) NOT NULL,
    ip VARCHAR(64) NOT NULL DEFAULT '',
    fecha_acceso TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("INSERT INTO planes_suscripcion (nombre, tipo, precio) SELECT 'Mensual', 'mensual', 0 WHERE NOT EXISTS (SELECT 1 FROM planes_suscripcion WHERE tipo='mensual')");
$conn->query("INSERT INTO planes_suscripcion (nombre, tipo, precio) SELECT 'Permanente', 'permanente', 0 WHERE NOT EXISTS (SELECT 1 FROM planes_suscripcion WHERE tipo='permanente')");
$plan_permanente = $conn->query("SELECT id FROM planes_suscripcion WHERE tipo='permanente' ORDER BY id LIMIT 1")->fetch_assoc();
$conn->query("INSERT INTO barberias (nombre, slug, plan_id) SELECT 'Wason Studio', 'wason-studio', " . intval($plan_permanente['id']) . " WHERE NOT EXISTS (SELECT 1 FROM barberias WHERE slug='wason-studio')");
$legacy_tenant = $conn->query("SELECT id FROM barberias WHERE slug='wason-studio' LIMIT 1")->fetch_assoc();

$tenant_id = intval($_SESSION['tenant_id'] ?? 0);
$slug_request = trim($_GET['barberia'] ?? $_POST['barberia'] ?? '');
if ($slug_request !== '') {
    $slug = $conn->real_escape_string($slug_request);
    $tenant_row = $conn->query("SELECT id FROM barberias WHERE slug='$slug' AND estado='activa' LIMIT 1")->fetch_assoc();
    $tenant_id = intval($tenant_row['id'] ?? 0);
}
if ($tenant_id <= 0) {
    $tenant_id = intval($legacy_tenant['id']);
}
$_SESSION['tenant_id'] = $tenant_id;

$conn->query("CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    correo VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    rol ENUM('admin','cliente') NOT NULL DEFAULT 'cliente',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    tenant_id INT NOT NULL DEFAULT 1,
    puntos INT NOT NULL DEFAULT 0,
    fidelizacion_activa TINYINT(1) NOT NULL DEFAULT 0,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS citas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dia VARCHAR(20) NOT NULL,
    hora VARCHAR(10) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    telefono VARCHAR(50) NOT NULL,
    servicio VARCHAR(100) NOT NULL,
    barbero VARCHAR(100) NOT NULL DEFAULT '',
    nota VARCHAR(255) NOT NULL DEFAULT '',
    usuario_correo VARCHAR(255) NOT NULL DEFAULT 'Invitado',
    asistio ENUM('pendiente','si','no') NOT NULL DEFAULT 'pendiente',
    tenant_id INT NOT NULL DEFAULT 1,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS barberos (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL, descripcion TEXT, foto VARCHAR(255) NOT NULL DEFAULT '', color VARCHAR(7) NOT NULL DEFAULT '#d4af37', hora_inicio TIME NULL, hora_fin TIME NULL, dias_trabajo VARCHAR(255) NOT NULL DEFAULT '', activo TINYINT(1) NOT NULL DEFAULT 1, tenant_id INT NOT NULL DEFAULT 1, fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$conn->query("CREATE TABLE IF NOT EXISTS servicios (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL, descripcion TEXT, precio DECIMAL(10,2) NOT NULL DEFAULT 0, tenant_id INT NOT NULL DEFAULT 1, fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$conn->query("CREATE TABLE IF NOT EXISTS anuncios (id INT AUTO_INCREMENT PRIMARY KEY, titulo VARCHAR(255) NOT NULL, contenido TEXT NOT NULL, imagen VARCHAR(255) DEFAULT NULL, enlace VARCHAR(512) DEFAULT NULL, estado TINYINT(1) NOT NULL DEFAULT 1, tenant_id INT NOT NULL DEFAULT 1, fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$conn->query("CREATE TABLE IF NOT EXISTS horarios_bloqueados (id INT AUTO_INCREMENT PRIMARY KEY, fecha DATE NOT NULL, hora VARCHAR(10) NOT NULL, tenant_id INT NOT NULL DEFAULT 1, fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$conn->query("CREATE TABLE IF NOT EXISTS config_horario (id INT NOT NULL DEFAULT 1, tenant_id INT NOT NULL DEFAULT 1, hora_inicio TIME NOT NULL DEFAULT '08:00:00', hora_fin TIME NOT NULL DEFAULT '19:00:00', intervalo INT NOT NULL DEFAULT 60, dias_bloqueados VARCHAR(255) NOT NULL DEFAULT '', fechas_bloqueadas VARCHAR(255) NOT NULL DEFAULT '', cancelacion_cliente_activa TINYINT(1) NOT NULL DEFAULT 0, anticipacion_cancelacion_minutos INT NOT NULL DEFAULT 0, PRIMARY KEY (tenant_id, id))");

$legacy_tables = ['usuarios', 'citas', 'barberos', 'servicios', 'anuncios', 'horarios_bloqueados'];
foreach ($legacy_tables as $table) {
    ensureColumn($conn, $table, 'tenant_id', 'INT NOT NULL DEFAULT 1');
}
ensureColumn($conn, 'usuarios', 'activo', 'TINYINT(1) NOT NULL DEFAULT 1');
ensureColumn($conn, 'usuarios', 'puntos', 'INT NOT NULL DEFAULT 0');
ensureColumn($conn, 'usuarios', 'fidelizacion_activa', 'TINYINT(1) NOT NULL DEFAULT 0');
ensureColumn($conn, 'citas', 'nota', "VARCHAR(255) NOT NULL DEFAULT ''");
 $rol_column = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'rol'");
if ($rol_column && $rol_column->num_rows > 0) {
    $rol_definition = $rol_column->fetch_assoc();
    if (strpos($rol_definition['Type'], "'barbero'") === false) {
        $conn->query("ALTER TABLE usuarios MODIFY rol ENUM('admin','cliente','barbero') NOT NULL DEFAULT 'cliente'");
    }
}
ensureColumn($conn, 'barberos', 'foto', "VARCHAR(255) NOT NULL DEFAULT ''");
ensureColumn($conn, 'barberos', 'color', "VARCHAR(7) NOT NULL DEFAULT '#d4af37'");
ensureColumn($conn, 'barberos', 'hora_inicio', 'TIME NULL');
ensureColumn($conn, 'barberos', 'hora_fin', 'TIME NULL');
ensureColumn($conn, 'barberos', 'dias_trabajo', "VARCHAR(255) NOT NULL DEFAULT ''");
ensureColumn($conn, 'barberos', 'activo', 'TINYINT(1) NOT NULL DEFAULT 1');
ensureColumn($conn, 'barberos', 'owner_admin_id', 'INT NULL');
ensureColumn($conn, 'usuarios', 'barbero_id', 'INT NULL');
ensureColumn($conn, 'anuncios', 'audiencia', "VARCHAR(20) NOT NULL DEFAULT 'barberia'");
ensureColumn($conn, 'anuncios', 'roles_destino', "VARCHAR(100) NOT NULL DEFAULT 'todos'");
ensureColumn($conn, 'anuncios', 'superadmin_id', 'INT NULL');
ensureColumn($conn, 'config_horario', 'tenant_id', 'INT NOT NULL DEFAULT 1');
ensureColumn($conn, 'config_horario', 'cancelacion_cliente_activa', 'TINYINT(1) NOT NULL DEFAULT 0');
ensureColumn($conn, 'config_horario', 'anticipacion_cancelacion_minutos', 'INT NOT NULL DEFAULT 0');
ensureColumn($conn, 'barberias', 'descripcion', 'TEXT');
ensureColumn($conn, 'barberias', 'telefono', "VARCHAR(80) NOT NULL DEFAULT ''");
ensureColumn($conn, 'barberias', 'correo', "VARCHAR(255) NOT NULL DEFAULT ''");
ensureColumn($conn, 'barberias', 'direccion', "VARCHAR(255) NOT NULL DEFAULT ''");
ensureColumn($conn, 'barberias', 'logo', "VARCHAR(255) NOT NULL DEFAULT 'img/logo.jpeg'");
ensureColumn($conn, 'barberias', 'tema', "ENUM('oscuro','claro') NOT NULL DEFAULT 'oscuro'");
ensureColumn($conn, 'barberias', 'facebook', "VARCHAR(255) NOT NULL DEFAULT ''");
ensureColumn($conn, 'barberias', 'instagram', "VARCHAR(255) NOT NULL DEFAULT ''");
ensureColumn($conn, 'barberias', 'whatsapp', "VARCHAR(255) NOT NULL DEFAULT ''");
ensureColumn($conn, 'barberias', 'estadisticas_habilitadas', 'TINYINT(1) NOT NULL DEFAULT 0');
ensureColumn($conn, 'barberias', 'fidelizacion_habilitada', 'TINYINT(1) NOT NULL DEFAULT 0');
ensureColumn($conn, 'barberias', 'puntos_por_servicio', 'INT NOT NULL DEFAULT 10');
ensureIndex($conn, 'barberias', 'idx_barberias_slug_estado', 'slug, estado');
ensureIndex($conn, 'usuarios', 'idx_usuarios_tenant_correo', 'tenant_id, correo');
ensureIndex($conn, 'usuarios', 'idx_usuarios_tenant_rol', 'tenant_id, rol');
ensureIndex($conn, 'citas', 'idx_citas_tenant_dia_hora', 'tenant_id, dia, hora');
ensureIndex($conn, 'citas', 'idx_citas_tenant_usuario_correo', 'tenant_id, usuario_correo');
ensureIndex($conn, 'barberos', 'idx_barberos_tenant_activo', 'tenant_id, activo');
ensureIndex($conn, 'servicios', 'idx_servicios_tenant_nombre', 'tenant_id, nombre');
ensureIndex($conn, 'anuncios', 'idx_anuncios_tenant_estado_fecha', 'tenant_id, estado, fecha_creacion');
ensureIndex($conn, 'horarios_bloqueados', 'idx_horariosbloqueados_tenant_fecha_hora', 'tenant_id, fecha, hora');
ensureIndex($conn, 'config_horario', 'idx_confighorario_tenant', 'tenant_id');

$barberia_actual = $conn->query("SELECT id, nombre, slug, descripcion, telefono, correo, direccion, logo, tema, facebook, instagram, whatsapp, estadisticas_habilitadas FROM barberias WHERE id=" . intval($tenant_id) . " LIMIT 1")->fetch_assoc();
if ($barberia_actual) {
    $logo_guardado = trim((string)($barberia_actual['logo'] ?? ''));
    $logo_local = __DIR__ . '/' . ltrim($logo_guardado, '/\\');
    if ($logo_guardado === '' || (!preg_match('#^https?://#i', $logo_guardado) && !is_file($logo_local))) {
        $logos_tenant = glob(__DIR__ . '/img/barberias/logo_' . intval($tenant_id) . '_*') ?: [];
        usort($logos_tenant, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        $barberia_actual['logo'] = !empty($logos_tenant)
            ? 'img/barberias/' . basename($logos_tenant[0])
            : 'img/logo.jpeg';
    }
}
$_SESSION['barberia_slug'] = $barberia_actual['slug'] ?? '';

$conn->query("UPDATE barberias SET descripcion='Barbería de clase mundial con estilo, precisión y una experiencia premium en cada visita.', telefono='(+57) 316 439 7612', correo='wasonbarber2020@gmail.com', direccion='Carrera 14, Barrio Obreo, La Unión, Nariño' WHERE slug='wason-studio' AND (telefono='' OR correo='' OR direccion='')");
$config_keys = $conn->query("SHOW KEYS FROM config_horario WHERE Key_name='PRIMARY'");
$primary_columns = [];
if ($config_keys) {
    while ($key = $config_keys->fetch_assoc()) $primary_columns[] = $key['Column_name'];
}
if (count($primary_columns) === 1 && $primary_columns[0] === 'id') {
    $conn->query("ALTER TABLE config_horario DROP PRIMARY KEY, ADD PRIMARY KEY (tenant_id, id)");
}

$legacy_id = intval($legacy_tenant['id']);
foreach ($legacy_tables as $table) {
    $conn->query("UPDATE `$table` SET tenant_id=$legacy_id WHERE tenant_id=1");
}
$conn->query("DELETE legacy FROM config_horario legacy INNER JOIN config_horario actual ON actual.tenant_id=$legacy_id AND actual.id=legacy.id WHERE legacy.tenant_id=1");
$conn->query("UPDATE config_horario SET tenant_id=$legacy_id WHERE tenant_id=1");
$conn->query("INSERT IGNORE INTO config_horario (id, tenant_id, hora_inicio, hora_fin, intervalo) VALUES (1, $legacy_id, '08:00:00', '19:00:00', 60)");
$conn->query("INSERT IGNORE INTO config_horario (id, tenant_id, hora_inicio, hora_fin, intervalo) VALUES (1, " . intval($tenant_id) . ", '08:00:00', '19:00:00', 60)");
$conn->query("UPDATE anuncios SET audiencia='barberia', roles_destino='todos' WHERE audiencia IS NULL OR audiencia='' OR roles_destino IS NULL OR roles_destino=''");

$user_indexes = $conn->query("SHOW INDEX FROM usuarios");
$dropped_user_index = [];
if ($user_indexes) {
    while ($index = $user_indexes->fetch_assoc()) {
        if (intval($index['Non_unique']) === 0 && $index['Key_name'] !== 'PRIMARY' && $index['Column_name'] === 'correo' && empty($dropped_user_index[$index['Key_name']])) {
            $conn->query("ALTER TABLE usuarios DROP INDEX `" . $conn->real_escape_string($index['Key_name']) . "`");
            $dropped_user_index[$index['Key_name']] = true;
        }
    }
}
$tenant_user_index = $conn->query("SHOW INDEX FROM usuarios WHERE Key_name='uniq_tenant_correo'");
if (!$tenant_user_index || $tenant_user_index->num_rows === 0) {
    $conn->query("ALTER TABLE usuarios ADD UNIQUE KEY uniq_tenant_correo (tenant_id, correo)");
}
$old_schedule_index = $conn->query("SHOW INDEX FROM horarios_bloqueados WHERE Key_name='uniq_fecha_hora'");
if ($old_schedule_index && $old_schedule_index->num_rows > 0) {
    $conn->query("ALTER TABLE horarios_bloqueados DROP INDEX uniq_fecha_hora");
}
$tenant_schedule_index = $conn->query("SHOW INDEX FROM horarios_bloqueados WHERE Key_name='uniq_tenant_fecha_hora'");
if (!$tenant_schedule_index || $tenant_schedule_index->num_rows === 0) {
    $conn->query("ALTER TABLE horarios_bloqueados ADD UNIQUE KEY uniq_tenant_fecha_hora (tenant_id, fecha, hora)");
}

if ($conn->query("SELECT id FROM superadmins LIMIT 1")->num_rows === 0) {
    $seed_email = getenv('WASON_SUPERADMIN_EMAIL') ?: 'superadmin@wasonstudio.local';
    $seed_password = getenv('WASON_SUPERADMIN_PASSWORD') ?: 'Cambiar123!';
    $stmt = $conn->prepare("INSERT INTO superadmins (correo, password) VALUES (?, ?)");
    $hash = password_hash($seed_password, PASSWORD_DEFAULT);
    $stmt->bind_param('ss', $seed_email, $hash);
    $stmt->execute();
}
?>
