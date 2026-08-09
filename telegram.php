<?php
/**
 * Envío de mensajes a Telegram desde PHP.
 *
 * Configuración:
 * 1. Crea un bot en Telegram con @BotFather.
 * 2. Obtén el token del bot.
 * 3. Añade el bot a un grupo o usa tu chat personal.
 * 4. Define estas variables de entorno o edita los valores por defecto:
 *    - TELEGRAM_BOT_TOKEN
 *    - TELEGRAM_CHAT_ID
 *
 * Ejemplo:
 *   setenv('TELEGRAM_BOT_TOKEN', '123456:ABCDEF');
 *   setenv('TELEGRAM_CHAT_ID', '123456789');
 */

function obtenerConfiguracionTelegram() {
    $token = getenv('TELEGRAM_BOT_TOKEN');
    if ($token === false || trim($token) === '' || $token === 'TU_BOT_TOKEN') {
        $token = '8881420412:AAGSPy-G09J8EB5hy6tISeU163iim4EXl_A';
    }

    $chatId = getenv('TELEGRAM_CHAT_ID');
    if ($chatId === false || trim($chatId) === '' || $chatId === 'TU_CHAT_ID') {
        $chatId = '6348546361';
    }

    return [
        'token' => trim($token),
        'chat_id' => trim($chatId),
    ];
}

function enviarMensajeTelegram($mensaje) {
    $config = obtenerConfiguracionTelegram();
    if (!$config) {
        return ['ok' => false, 'error' => 'Telegram no configurado'];
    }

    $url = 'https://api.telegram.org/bot' . $config['token'] . '/sendMessage';
    $datos = [
        'chat_id' => $config['chat_id'],
        'text' => $mensaje,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($datos));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $respuesta = curl_exec($ch);
        curl_close($ch);

        if ($respuesta === false) {
            return ['ok' => false, 'error' => 'No se pudo conectar con Telegram'];
        }

        $decoded = json_decode($respuesta, true);
        return ['ok' => ($decoded['ok'] ?? false), 'response' => $decoded];
    }

    return ['ok' => false, 'error' => 'cURL no disponible'];
}
?>