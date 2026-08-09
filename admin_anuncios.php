<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['usuario']) || $_SESSION['rol'] != 'admin') {
    header('Location: index.php');
    exit();
}

// Manejo de peticiones AJAX para toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_toggle'])) {
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

// Eliminar anuncio
if (isset($_GET['delete_id'])) {
    $del = intval($_GET['delete_id']);
    if ($del > 0) {
        // eliminar imagen asociada
        $r = $conn->query("SELECT imagen FROM anuncios WHERE id=$del");
        if ($r && $row = $r->fetch_assoc()) {
            if (!empty($row['imagen'])) {
                @unlink(__DIR__ . '/img/anuncios/' . $row['imagen']);
            }
        }
        $conn->query("DELETE FROM anuncios WHERE id=$del");
    }
    header('Location: admin_anuncios.php');
    exit();
}

// Guardar (crear/editar)
$editar = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_anuncio'])) {
    $titulo = $conn->real_escape_string(trim($_POST['titulo'] ?? ''));
    $contenido = $conn->real_escape_string(trim($_POST['contenido'] ?? ''));
    $enlace = $conn->real_escape_string(trim($_POST['enlace'] ?? ''));
    $estado = isset($_POST['estado']) ? 1 : 0;

    // manejar imagen
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
        // actualizar
        $sql = "UPDATE anuncios SET titulo='$titulo', contenido='$contenido', enlace='" . $enlace . "', estado=$estado";
        if ($imagen_nombre) {
            // borrar vieja
            $r = $conn->query("SELECT imagen FROM anuncios WHERE id=$id");
            if ($r && $row = $r->fetch_assoc()) {
                if (!empty($row['imagen'])) @unlink(__DIR__ . '/img/anuncios/' . $row['imagen']);
            }
            $sql .= ", imagen='$imagen_nombre'";
        }
        $sql .= " WHERE id=$id";
        $conn->query($sql);
    } else {
        // insertar
        $img_sql = $imagen_nombre ? "'$imagen_nombre'" : 'NULL';
        $conn->query("INSERT INTO anuncios (titulo, contenido, imagen, enlace, estado) VALUES ('$titulo', '$contenido', $img_sql, '$enlace', $estado)");
    }

    header('Location: admin_anuncios.php');
    exit();
}

if (isset($_GET['edit_id'])) {
    $eid = intval($_GET['edit_id']);
    $res = $conn->query("SELECT * FROM anuncios WHERE id=$eid");
    if ($res && $res->num_rows > 0) $editar = $res->fetch_assoc();
}

// listar
$result = $conn->query("SELECT * FROM anuncios ORDER BY fecha_creacion DESC");
$anuncios = [];
while ($row = $result->fetch_assoc()) $anuncios[] = $row;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin - Anuncios</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body{font-family:Inter,system-ui,Arial;background:#0b0e14;color:#fff;padding:20px}
        .container{max-width:1100px;margin:0 auto}
        .card{background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);padding:16px;border-radius:12px;margin-bottom:14px}
        form .field{margin-bottom:10px}
        label{display:block;font-weight:600;margin-bottom:6px}
        input[type=text], textarea{width:100%;padding:10px;border-radius:8px;border:1px solid rgba(255,255,255,0.06);background:transparent;color:#fff}
        .grid{display:grid;grid-template-columns:1fr 360px;gap:16px}
        .list table{width:100%;border-collapse:collapse;margin:0 auto}
        .list th, .list td{padding:10px;border-bottom:1px solid rgba(255,255,255,0.04);text-align:center;}
        .list th:first-child, .list td:first-child{text-align:left;}
        .mini{padding:6px 12px;border-radius:999px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);color:#fff;text-decoration:none;display:inline-block;}
        .toggle{cursor:pointer;color:#f9c74f;font-weight:700;}
        .toggle:hover{color:#fcd34d;}
        @media(max-width:900px){.grid{grid-template-columns:1fr}.list th,.list td{text-align:left;}}
    </style>
</head>
<body>
    <div class="container">
        <h1>Administrar Anuncios</h1>
        <div class="grid">
            <div>
                <div class="card">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($editar['id'] ?? ''); ?>">
                        <div class="field">
                            <label>Título</label>
                            <input type="text" name="titulo" required value="<?php echo htmlspecialchars($editar['titulo'] ?? ''); ?>">
                        </div>
                        <div class="field">
                            <label>Contenido</label>
                            <textarea name="contenido" rows="4"><?php echo htmlspecialchars($editar['contenido'] ?? ''); ?></textarea>
                        </div>
                        <div class="field">
                            <label>Enlace (opcional)</label>
                            <input type="text" name="enlace" value="<?php echo htmlspecialchars($editar['enlace'] ?? ''); ?>">
                        </div>
                        <div class="field">
                            <label>Imagen (opcional)</label>
                            <input type="file" name="imagen" accept="image/*">
                            <?php if (!empty($editar['imagen'])): ?>
                                <div style="margin-top:8px;"><img src="img/anuncios/<?php echo htmlspecialchars($editar['imagen']); ?>" style="max-width:200px;border-radius:8px;border:1px solid rgba(255,255,255,0.04)"></div>
                            <?php endif; ?>
                        </div>
                        <div class="field">
                            <label><input type="checkbox" name="estado" <?php echo (isset($editar['estado']) && $editar['estado']) ? 'checked' : ''; ?>> Activo</label>
                        </div>
                        <div class="field">
                            <button class="mini" type="submit" name="guardar_anuncio">Guardar anuncio</button>
                        </div>
                    </form>
                </div>
            </div>
            <div>
                <div class="card list">
                    <h3>Anuncios existentes</h3>
                    <table>
                        <thead><tr><th>Título</th><th>Estado</th><th>Fecha</th><th>Acciones</th></tr></thead>
                        <tbody>
                            <?php foreach ($anuncios as $a): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($a['titulo']); ?></td>
                                    <td><span class="toggle" data-id="<?php echo $a['id']; ?>"><?php echo $a['estado'] ? 'Activo' : 'Inactivo'; ?></span></td>
                                    <td><?php echo htmlspecialchars($a['fecha_creacion']); ?></td>
                                    <td>
                                        <a class="mini" href="admin_anuncios.php?edit_id=<?php echo $a['id']; ?>">Editar</a>
                                        <a class="mini" href="admin_anuncios.php?delete_id=<?php echo $a['id']; ?>" onclick="return confirm('Eliminar anuncio?')">Eliminar</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('.toggle').forEach(function(el){
            el.addEventListener('click', function(){
                var id = this.dataset.id;
                var self = this;
                fetch('admin_anuncios.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: 'ajax_toggle=1&id=' + encodeURIComponent(id)
                }).then(r=>r.json()).then(function(data){
                    if (data && data.ok) {
                        self.textContent = data.estado == 1 ? 'Activo' : 'Inactivo';
                    }
                });
            });
        });
    </script>
</body>
</html>
