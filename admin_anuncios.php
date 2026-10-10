<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['usuario']) || $_SESSION['rol'] != 'admin' || empty($_SESSION['tenant_id'])) {
    header('Location: ' . tenantRedirect('index.php'));
    exit();
}

// Manejo de peticiones AJAX para toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_toggle'])) {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        $select_stmt = $conn->prepare("SELECT estado FROM anuncios WHERE id = ? AND tenant_id = ? LIMIT 1");
        $select_stmt->bind_param('ii', $id, $tenant_id);
        $select_stmt->execute();
        $res = $select_stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $nuevo = $row['estado'] ? 0 : 1;
            $update_stmt = $conn->prepare("UPDATE anuncios SET estado = ? WHERE id = ? AND tenant_id = ?");
            $update_stmt->bind_param('iii', $nuevo, $id, $tenant_id);
            $update_stmt->execute();
            $update_stmt->close();
            $select_stmt->close();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'estado' => $nuevo]);
            exit();
        }
        $select_stmt->close();
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => false]);
    exit();
}

// Eliminar anuncio
if (isset($_GET['delete_id'])) {
    $del = intval($_GET['delete_id']);
    if ($del > 0) {
        $select_stmt = $conn->prepare("SELECT imagen FROM anuncios WHERE id = ? AND tenant_id = ? LIMIT 1");
        $select_stmt->bind_param('ii', $del, $tenant_id);
        $select_stmt->execute();
        $r = $select_stmt->get_result();
        if ($r && $row = $r->fetch_assoc()) {
            if (!empty($row['imagen'])) {
                @unlink(__DIR__ . '/img/anuncios/' . $row['imagen']);
            }
        }
        $select_stmt->close();

        $delete_stmt = $conn->prepare("DELETE FROM anuncios WHERE id = ? AND tenant_id = ?");
        $delete_stmt->bind_param('ii', $del, $tenant_id);
        $delete_stmt->execute();
        $delete_stmt->close();
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
    $audiencia = 'barberia';
    $roles_destino = 'todos';

    // manejar imagen
    $imagen_nombre = null;
    if (!empty($_FILES['imagen']['name']) && ($_FILES['imagen']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $dir = __DIR__ . '/img/anuncios';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (in_array($ext, $allowed, true) && @getimagesize($_FILES['imagen']['tmp_name'])) {
            $imagen_nombre = uniqid('ad_') . '.' . $ext;
            move_uploaded_file($_FILES['imagen']['tmp_name'], $dir . '/' . $imagen_nombre);
        }
    }

    if (!empty($_POST['id'])) {
        $id = intval($_POST['id']);
        $sql = "UPDATE anuncios SET titulo = ?, contenido = ?, enlace = ?, estado = ?, audiencia = 'barberia', roles_destino = 'todos'";
        if ($imagen_nombre) {
            $img_select = $conn->prepare("SELECT imagen FROM anuncios WHERE id = ? AND tenant_id = ? LIMIT 1");
            $img_select->bind_param('ii', $id, $tenant_id);
            $img_select->execute();
            $r = $img_select->get_result();
            if ($r && $row = $r->fetch_assoc()) {
                if (!empty($row['imagen'])) @unlink(__DIR__ . '/img/anuncios/' . $row['imagen']);
            }
            $img_select->close();
            $sql .= ", imagen = ?";
            $stmt = $conn->prepare($sql . " WHERE id = ? AND tenant_id = ?");
            $stmt->bind_param('sssiisi', $titulo, $contenido, $enlace, $estado, $imagen_nombre, $id, $tenant_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare($sql . " WHERE id = ? AND tenant_id = ?");
            $stmt->bind_param('sssii', $titulo, $contenido, $enlace, $estado, $id, $tenant_id);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $img_sql = $imagen_nombre ? $imagen_nombre : null;
        $stmt = $conn->prepare("INSERT INTO anuncios (titulo, contenido, imagen, enlace, estado, audiencia, roles_destino, tenant_id) VALUES (?, ?, ?, ?, ?, 'barberia', 'todos', ?)");
        $stmt->bind_param('ssssii', $titulo, $contenido, $img_sql, $enlace, $estado, $tenant_id);
        $stmt->execute();
        $stmt->close();
    }

    header('Location: admin_anuncios.php');
    exit();
}

if (isset($_GET['edit_id'])) {
    $eid = intval($_GET['edit_id']);
    $stmt = $conn->prepare("SELECT * FROM anuncios WHERE id = ? AND tenant_id = ? LIMIT 1");
    $stmt->bind_param('ii', $eid, $tenant_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) $editar = $res->fetch_assoc();
    $stmt->close();
}

// listar
$stmt = $conn->prepare("SELECT * FROM anuncios WHERE tenant_id = ? ORDER BY fecha_creacion DESC");
$stmt->bind_param('i', $tenant_id);
$stmt->execute();
$result = $stmt->get_result();
$anuncios = [];
while ($row = $result->fetch_assoc()) $anuncios[] = $row;
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="icon" type="image/png" href="img/logomdj.png?v=20260930">
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
    <link rel="stylesheet" href="responsive.css?v=1">
    <link rel="stylesheet" href="app-theme.css?v=2">
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
