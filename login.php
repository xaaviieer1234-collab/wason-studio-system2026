<?php
session_start();
include 'conexion.php';

function registrarAcceso($conn, $usuario) {
    $stmt = $conn->prepare("INSERT INTO accesos (usuario_id, tenant_id, rol, ip) VALUES (?, ?, ?, ?)");
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt->bind_param('iiss', $usuario['id'], $usuario['tenant_id'], $usuario['rol'], $ip);
    $stmt->execute();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $correo = trim($_POST['correo'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $barberia_slug = trim($_POST['barberia'] ?? $_GET['barberia'] ?? '');

    if ($correo === '' || $password === '') {
        $redirect = tenantRedirect('index.php', $barberia_slug);
        echo "<script>alert('Completa todos los campos para iniciar sesión.'); window.location.href=" . json_encode($redirect) . ";</script>";
        exit();
    }

    $superadmin_stmt = $conn->prepare("SELECT * FROM superadmins WHERE correo = ? AND activo = 1 LIMIT 1");
    $superadmin_stmt->bind_param('s', $correo);
    $superadmin_stmt->execute();
    $superadmin = $superadmin_stmt->get_result();
    if ($superadmin && $superadmin->num_rows > 0) {
        $cuenta = $superadmin->fetch_assoc();
        if (password_verify($password, $cuenta['password']) || hash_equals($cuenta['password'], $password)) {
            session_regenerate_id(true);
            unset($_SESSION['tenant_id'], $_SESSION['admin_id'], $_SESSION['barbero_id'], $_SESSION['barberia_slug']);
            $_SESSION['superadmin_id'] = $cuenta['id'];
            $_SESSION['usuario'] = $cuenta['correo'];
            $_SESSION['rol'] = 'superadmin';
            header("Location: superadmin.php");
            exit();
        }
        echo "<script>alert('Contraseña incorrecta.'); window.location.href=" . json_encode(tenantRedirect('index.php', $barberia_slug)) . ";</script>";
        exit();
    }

    if ($barberia_slug === '') {
        echo "<script>alert('Debes iniciar sesión desde el enlace de tu barbería.'); window.location.href=" . json_encode(tenantRedirect('index.php')) . ";</script>";
        exit();
    }

    $barberia_login_stmt = $conn->prepare("SELECT id FROM barberias WHERE slug = ? AND estado = 'activa' LIMIT 1");
    $barberia_login_stmt->bind_param('s', $barberia_slug);
    $barberia_login_stmt->execute();
    $barberia_login = $barberia_login_stmt->get_result();
    $barberia_login_row = $barberia_login ? $barberia_login->fetch_assoc() : null;
    $tenant_login_id = intval($barberia_login_row['id'] ?? 0);
    if ($tenant_login_id <= 0) {
        echo "<script>alert('La barbería indicada no está disponible.'); window.location.href=" . json_encode(tenantRedirect('index.php', $barberia_slug)) . ";</script>";
        exit();
    }

    $sql_admin = "SELECT u.*, b.estado AS barberia_estado, b.fecha_vencimiento, p.tipo AS plan_tipo
            FROM usuarios u
            LEFT JOIN barberias b ON b.id=u.tenant_id
            LEFT JOIN planes_suscripcion p ON p.id=b.plan_id
            WHERE u.correo = ? AND u.rol IN ('admin','barbero') AND u.tenant_id = ?
            LIMIT 1";
    $stmt_admin = $conn->prepare($sql_admin);
    $stmt_admin->bind_param('si', $correo, $tenant_login_id);
    $stmt_admin->execute();
    $resultado_admin = $stmt_admin->get_result();

    if ($resultado_admin && $resultado_admin->num_rows > 0) {
        $usuario = $resultado_admin->fetch_assoc();
        $password_valida = password_verify($password, $usuario['password']) || hash_equals($usuario['password'], $password);
        $suscripcion_vencida = ($usuario['plan_tipo'] ?? '') === 'mensual' && !empty($usuario['fecha_vencimiento']) && $usuario['fecha_vencimiento'] < date('Y-m-d');
        if (!$password_valida) {
            echo "<script>alert('Contraseña incorrecta.'); window.location.href=" . json_encode(tenantRedirect('index.php', $barberia_slug)) . ";</script>";
            exit();
        }
        if (!$usuario['activo'] || ($usuario['barberia_estado'] ?? '') !== 'activa' || $suscripcion_vencida) {
            echo "<script>alert('Esta cuenta está desactivada o su suscripción no está activa.'); window.location.href=" . json_encode(tenantRedirect('index.php', $barberia_slug)) . ";</script>";
            exit();
        }

        $_SESSION['tenant_id'] = intval($usuario['tenant_id']);
        $_SESSION['barberia_slug'] = $barberia_slug;
        $_SESSION['usuario'] = $usuario['correo'];
        $_SESSION['rol'] = $usuario['rol'];
        registrarAcceso($conn, $usuario);
        if ($usuario['rol'] === 'barbero') {
            unset($_SESSION['admin_id']);
            $_SESSION['barbero_id'] = intval($usuario['barbero_id'] ?? 0);
            header("Location: barbero_panel.php");
        } else {
            unset($_SESSION['barbero_id']);
            $_SESSION['admin_id'] = intval($usuario['id']);
            header("Location: panel.php");
        }
        exit();
    }

    $sql = "SELECT u.*, b.estado AS barberia_estado, b.fecha_vencimiento, p.tipo AS plan_tipo
            FROM usuarios u
            LEFT JOIN barberias b ON b.id=u.tenant_id
            LEFT JOIN planes_suscripcion p ON p.id=b.plan_id
            WHERE u.correo = ? AND u.rol='cliente' AND u.tenant_id = ?";
    $stmt_cliente = $conn->prepare($sql);
    $stmt_cliente->bind_param('si', $correo, $tenant_login_id);
    $stmt_cliente->execute();
    $resultado = $stmt_cliente->get_result();

    if ($resultado && $resultado->num_rows > 0) {
        $usuario = $resultado->fetch_assoc();

        $password_valida = password_verify($password, $usuario['password']) || hash_equals($usuario['password'], $password);
        $suscripcion_vencida = ($usuario['plan_tipo'] ?? '') === 'mensual' && !empty($usuario['fecha_vencimiento']) && $usuario['fecha_vencimiento'] < date('Y-m-d');
        if (!$usuario['activo'] || ($usuario['barberia_estado'] ?? '') !== 'activa' || $suscripcion_vencida) {
            echo "<script>alert('Esta cuenta está desactivada.'); window.location.href=" . json_encode(tenantRedirect('index.php', $barberia_slug)) . ";</script>";
            exit();
        }
        if ($password_valida) {
            session_regenerate_id(true);
            unset($_SESSION['admin_id'], $_SESSION['barbero_id']);
            $_SESSION['tenant_id'] = $tenant_login_id;
            $_SESSION['barberia_slug'] = $barberia_slug;
            $_SESSION['usuario'] = $usuario['correo'];
            $_SESSION['rol'] = $usuario['rol'];
            registrarAcceso($conn, $usuario);

            header("Location: cliente_panel.php");
            exit();
        } else {
            echo "<script>alert('Contraseña incorrecta.'); window.location.href=" . json_encode(tenantRedirect('index.php', $barberia_slug)) . ";</script>";
            exit();
        }
    } else {
        echo "<script>alert('El usuario no existe en esta barbería.'); window.location.href=" . json_encode(tenantRedirect('index.php', $barberia_slug)) . ";</script>";
        exit();
    }
}
?>