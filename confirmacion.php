<?php
session_start();
include 'conexion.php';
if (!isset($_SESSION['ultima_cita'])) {
    header('Location: ' . tenantRedirect('index.php'));
    exit();
}
$cita = $_SESSION['ultima_cita'];
unset($_SESSION['ultima_cita']);

$barberoSeleccionado = !empty($cita['barbero']) ? $cita['barbero'] : 'Cualquiera';
$notaCita = !empty($cita['nota']) ? "\nNota: {$cita['nota']}" : '';
?>
<!DOCTYPE html>
<html lang="es">
    
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="img/logomdj.png?v=20260930">
    <title>Confirmación de Cita | Barbería</title>
    <style>
        body {
            background: radial-gradient(circle at top, #2e2e2e 0%, #111 55%);
            color: #f1f1f1;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .contenedor {
            width: min(520px, 100%);
            background: rgba(18, 18, 18, 0.96);
            border: 1px solid rgba(212, 175, 55, 0.16);
            border-radius: 24px;
            padding: 36px 34px 28px;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.45);
            backdrop-filter: blur(10px);
        }
        .cabecera {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            margin-bottom: 26px;
        }
        .icono {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #f7c948 0%, #d4af37 100%);
            color: #111;
            box-shadow: 0 14px 30px rgba(212, 175, 55, 0.28);
            font-size: 28px;
        }
        h1 {
            margin: 0;
            font-size: 30px;
            letter-spacing: 0.02em;
            color: #f7f1c4;
            text-transform: uppercase;
        }
        .subtitulo {
            margin: 8px 0 0;
            color: #beb29d;
            font-size: 15px;
            text-align: center;
        }
        .detalle {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 10px 18px;
            padding: 18px 0;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            color: #263a50;
        }
        .detalle p {
            margin: 0;
            line-height: 1.7;
            color: #263a50;
        }
        .detalle strong {
            color: #8b5e08;
            font-weight: 600;
        }
        .acciones {
            text-align: center;
            margin-top: 28px;
        }
        .boton {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 24px;
            background: #d4af37;
            color: #111;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 700;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .boton:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 38px rgba(212, 175, 55, 0.24);
        }
        .nota {
            margin-top: 18px;
            color: #9b9b9b;
            font-size: 14px;
            text-align: center;
            line-height: 1.6;
        }
    </style>
    <link rel="stylesheet" href="responsive.css?v=1">
    <link rel="stylesheet" href="app-theme.css?v=2">
</head>
<body>
    <div class="contenedor">
        <div class="cabecera">
            <div class="icono">✓</div>
            <div>
                <h1>¡Cita Agendada!</h1>
                <p class="subtitulo">Todo listo para tu experiencia en Wason Studio.</p>
            </div>
        </div>
        <div class="detalle">
            <p><strong>Nombre</strong></p>
            <p><?php echo htmlspecialchars($cita['nombre']); ?></p>
            <p><strong>Teléfono</strong></p>
            <p><?php echo htmlspecialchars($cita['telefono']); ?></p>
            <p><strong>Servicio</strong></p>
            <p><?php echo htmlspecialchars($cita['servicio']); ?></p>
            <p><strong>Barbero</strong></p>
            <p><?php echo htmlspecialchars(!empty($cita['barbero']) ? $cita['barbero'] : 'Cualquiera'); ?></p>
            <p><strong>Fecha</strong></p>
            <p><?php echo htmlspecialchars($cita['dia']); ?></p>
            <p><strong>Hora</strong></p>
            <p><?php echo htmlspecialchars($cita['hora']); ?></p>
        </div>
        <div class="acciones">
            <a class="boton" href="index.php">Volver a la agenda</a>
        </div>
        <p class="nota">Guarda esta información para tu referencia. Si necesitas cambiar tu cita, contáctanos.</p>
    </div>
</body>
</html>
