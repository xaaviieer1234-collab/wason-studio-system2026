<?php
include 'conexion.php';
include 'telegram.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $correo = trim($_POST['correo'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($correo === '' || $password === '') {
        echo "<script>alert('Completa todos los campos para registrarte.'); window.location.href='registro.php';</script>";
        exit();
    }

    $correo = $conn->real_escape_string($correo);
    $password = $conn->real_escape_string($password);

    $check = $conn->query("SELECT * FROM usuarios WHERE correo = '$correo'");
    if ($check && $check->num_rows > 0) {
        echo "<script>alert('Este correo ya está registrado.'); window.location.href='registro.php';</script>";
        exit();
    } else {
        $sql = "INSERT INTO usuarios (correo, password, rol) VALUES ('$correo', '$password', 'cliente')";
        if ($conn->query($sql)) {
            $mensaje = "Nuevo registro en Wason Studio:\nCorreo: $correo\nRol: cliente";
            enviarMensajeTelegram($mensaje);
            echo "<script>alert('¡Registro exitoso! Ya puedes iniciar sesión.'); window.location.href='index.php';</script>";
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro de Cliente | Barbería</title>
    <style>
        :root {
            --bg: #070b16;
            --surface: rgba(15, 23, 42, 0.88);
            --surface-strong: rgba(15, 23, 42, 0.98);
            --border: rgba(255, 255, 255, 0.08);
            --text: #f8fafc;
            --muted: #94a3b8;
            --primary: #f4c04d;
            --primary-dark: #f59e0b;
            --shadow: 0 28px 70px rgba(0, 0, 0, 0.34);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--text);
            display: grid;
            place-items: center;
            padding: 24px;
            background: radial-gradient(circle at top left, rgba(244, 192, 77, 0.16), transparent 25%),
                        linear-gradient(180deg, #02030a 0%, #0b1121 100%);
        }

        .contenedor {
            width: min(460px, 100%);
            padding: 36px 30px;
            border-radius: 32px;
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(7, 11, 22, 0.98));
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            backdrop-filter: blur(20px);
        }

        .brand-badge {
            width: 52px;
            height: 52px;
            border-radius: 18px;
            display: grid;
            place-items: center;
            margin-bottom: 20px;
            font-weight: 800;
            color: #0b1221;
            background: linear-gradient(135deg, #facc15, #f59e0b);
        }

        h3 {
            margin: 0 0 10px;
            font-size: 1.9rem;
            letter-spacing: 0.02em;
        }

        .subtitle {
            margin: 0 0 28px;
            color: var(--muted);
            line-height: 1.7;
        }

        .form-grid {
            display: grid;
            gap: 18px;
        }

        .field {
            display: grid;
            gap: 8px;
        }

        .field label {
            font-size: 0.95rem;
            color: var(--muted);
        }

        .field input {
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            background: rgba(15, 23, 42, 0.92);
            color: var(--text);
            padding: 16px 18px;
            font-size: 1rem;
            outline: none;
            transition: border-color 180ms ease, box-shadow 180ms ease;
        }

        .field input::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }

        .field input:focus {
            border-color: rgba(244, 192, 77, 0.75);
            box-shadow: 0 0 0 6px rgba(244, 192, 77, 0.12);
        }

        .btn-enviar {
            width: 100%;
            border: none;
            border-radius: 999px;
            padding: 16px 18px;
            font-size: 1rem;
            font-weight: 700;
            color: #0b1221;
            background: linear-gradient(135deg, #facc15, #f59e0b);
            cursor: pointer;
            box-shadow: 0 18px 32px rgba(245, 158, 11, 0.22);
            transition: transform 180ms ease, box-shadow 180ms ease;
        }

        .btn-enviar:hover {
            transform: translateY(-2px);
            box-shadow: 0 22px 38px rgba(245, 158, 11, 0.28);
        }

        .info-row {
            margin-top: 24px;
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
            color: var(--muted);
            font-size: 0.95rem;
        }

        .info-row a {
            color: #facc15;
            text-decoration: none;
            font-weight: 700;
        }

        @media (max-width: 500px) {
            .contenedor {
                padding: 28px 20px;
            }

            .brand-badge {
                width: 48px;
                height: 48px;
            }
        }
    </style>
</head>
<body>
    <div class="contenedor">
        <div class="brand-badge">WS</div>
        <h3>Crear cuenta</h3>
        <p class="subtitle">Registra tu correo y contraseña para reservar tu próxima cita en Wason Studio.</p>

        <form action="registro.php" method="POST">
            <div class="form-grid">
                <div class="field">
                    <label for="correo">Correo electrónico</label>
                    <input id="correo" type="email" name="correo" required placeholder="tu@correo.com">
                </div>
                <div class="field">
                    <label for="password">Contraseña</label>
                    <input id="password" type="password" name="password" required placeholder="••••••••">
                </div>
            </div>
            <button type="submit" class="btn-enviar">Registrarse</button>
        </form>
        <div class="info-row">
            <span>¿Ya tienes cuenta?</span>
            <a href="index.php">Inicia sesión</a>
        </div>
    </div>
</body>
</html>