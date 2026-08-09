<?php
$host = "localhost";
$user = "root";
$pass = "1406";  //22659328Javier clave de infinity free 
$db   = "wason_studio";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    correo VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    rol ENUM('admin','cliente') NOT NULL DEFAULT 'cliente',
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
    usuario_correo VARCHAR(255) NOT NULL DEFAULT 'Invitado',
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    asistio ENUM('pendiente','si','no') NOT NULL DEFAULT 'pendiente'
)");

$conn->query("CREATE TABLE IF NOT EXISTS barberos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS config_horario (
    id INT PRIMARY KEY,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    intervalo INT NOT NULL DEFAULT 60,
    dias_bloqueados VARCHAR(255) NOT NULL DEFAULT '',
    fechas_bloqueadas VARCHAR(255) NOT NULL DEFAULT ''
)");

$conn->query("INSERT IGNORE INTO config_horario (id, hora_inicio, hora_fin, intervalo) VALUES (1, '08:00:00', '19:00:00', 60)");

$conn->query("CREATE TABLE IF NOT EXISTS servicios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    precio DECIMAL(10,2) NOT NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS anuncios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(255) NOT NULL,
    contenido TEXT NOT NULL,
    imagen VARCHAR(255) DEFAULT NULL,
    enlace VARCHAR(512) DEFAULT NULL,
    estado TINYINT(1) NOT NULL DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS horarios_bloqueados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    hora VARCHAR(10) NOT NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fecha_hora (fecha, hora)
)");

if ($conn->query("SHOW TABLES LIKE 'citas'")->num_rows > 0) {
    $result = $conn->query("SHOW COLUMNS FROM citas LIKE 'asistio'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE citas ADD COLUMN asistio ENUM('pendiente','si','no') NOT NULL DEFAULT 'pendiente'");
    }
}

if ($conn->query("SHOW TABLES LIKE 'config_horario'")->num_rows > 0) {
    $result = $conn->query("SHOW COLUMNS FROM config_horario LIKE 'dias_bloqueados'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE config_horario ADD COLUMN dias_bloqueados VARCHAR(255) NOT NULL DEFAULT ''");
    }

    $result_fechas = $conn->query("SHOW COLUMNS FROM config_horario LIKE 'fechas_bloqueadas'");
    if ($result_fechas && $result_fechas->num_rows === 0) {
        $conn->query("ALTER TABLE config_horario ADD COLUMN fechas_bloqueadas VARCHAR(255) NOT NULL DEFAULT ''");
    }
}

if ($conn->query("SHOW TABLES LIKE 'citas'")->num_rows > 0) {
    $result_barbero = $conn->query("SHOW COLUMNS FROM citas LIKE 'barbero'");
    if ($result_barbero && $result_barbero->num_rows === 0) {
        $conn->query("ALTER TABLE citas ADD COLUMN barbero VARCHAR(100) NOT NULL DEFAULT ''");
    }
}

if ($conn->query("SHOW TABLES LIKE 'barberos'")->num_rows === 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS barberos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        descripcion TEXT,
        fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}
?>