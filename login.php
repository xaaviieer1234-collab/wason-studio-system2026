<?php
include 'conexion.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $correo = trim($_POST['correo'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($correo === '' || $password === '') {
        echo "<script>alert('Completa todos los campos para iniciar sesión.'); window.location.href='index.php';</script>";
        exit();
    }

    $correo = $conn->real_escape_string($correo);
    $sql = "SELECT * FROM usuarios WHERE correo = '$correo'";
    $resultado = $conn->query($sql);

    if ($resultado && $resultado->num_rows > 0) {
        $usuario = $resultado->fetch_assoc();

        if ($password === $usuario['password']) {
            $_SESSION['usuario'] = $usuario['correo'];
            $_SESSION['rol'] = $usuario['rol'];

            if ($usuario['rol'] == 'admin') {
                header("Location: panel.php");
            } else {
                header("Location: cliente_panel.php");
            }
            exit();
        } else {
            echo "<script>alert('Contraseña incorrecta.'); window.location.href='index.php';</script>";
            exit();
        }
    } else {
        echo "<script>alert('El usuario no existe.'); window.location.href='index.php';</script>";
        exit();
    }
}
?>