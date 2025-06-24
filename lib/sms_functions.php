<?php
// lib/sms_functions.php

require_once __DIR__ . '/../vendor/autoload.php';
use Twilio\Rest\Client;

function send_sms($to_number, $message_body) {
    // Validar que las credenciales de Twilio estén definidas en tu config.php
    if (!defined('TWILIO_ACCOUNT_SID') || !defined('TWILIO_AUTH_TOKEN') || !defined('TWILIO_PHONE_NUMBER')) {
        // En un entorno de producción, deberías registrar este error en un log.
        error_log("Error de Twilio: Las credenciales no están definidas en config.php.");
        return false;
    }

    // Asegurarse de que el número de destino esté en formato E.164 (+57...)
    // Esta lógica es robusta y añade el prefijo de Colombia si no está presente.
    $to_number_clean = preg_replace('/[^0-9]/', '', $to_number);
    if (strlen($to_number_clean) == 10) {
        $to_number_e164 = '+57' . $to_number_clean;
    } elseif (strlen($to_number_clean) == 12 && strpos($to_number_clean, '57') === 0) {
         $to_number_e164 = '+' . $to_number_clean;
    } else {
        $to_number_e164 = $to_number; // Asumimos que ya está en formato E.164
    }

    try {
        $client = new Client(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN);
        $message = $client->messages->create(
            $to_number_e164,
            [
                'from' => TWILIO_PHONE_NUMBER,
                'body' => $message_body
            ]
        );
        // El envío fue exitoso si se crea el objeto del mensaje
        return !empty($message->sid);
    } catch (Exception $e) {
        // Capturar errores de la API (ej. número inválido, API key incorrecta)
        error_log('Twilio SMS Error: ' . $e->getMessage());
        return false;
    }
}
