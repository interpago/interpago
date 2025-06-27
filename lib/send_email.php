<?php
// lib/send_email.php

// Importar las clases de PHPMailer al espacio de nombres global
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Cargar el autoloader de Composer para que PHPMailer esté disponible
require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Envía un correo electrónico de notificación a un usuario.
 *
 * @param string $recipient_email El correo del destinatario.
 * @param string $recipient_name El nombre del destinatario.
 * @param string $subject El asunto del correo.
 * @param string $body_html El contenido del correo en formato HTML.
 * @return bool Devuelve true si el correo se envió con éxito, false en caso contrario.
 */
function send_notification_email($recipient_email, $recipient_name, $subject, $body_html) {
    // Crear una nueva instancia de PHPMailer; pasar `true` habilita las excepciones
    $mail = new PHPMailer(true);

    try {
        // --- Configuración del Servidor SMTP ---
        // Descomenta la siguiente línea para obtener un log detallado del proceso de envío
        // $mail->SMTPDebug = SMTP::DEBUG_SERVER;

        $mail->isSMTP();                                    // Enviar usando SMTP
        $mail->Host       = SMTP_HOST;                      // El servidor SMTP a usar (definido en config.php)
        $mail->SMTPAuth   = true;                           // Habilitar autenticación SMTP
        $mail->Username   = SMTP_USERNAME;                  // Tu nombre de usuario SMTP
        $mail->Password   = SMTP_PASSWORD;                  // Tu contraseña SMTP
        $mail->SMTPSecure = defined('SMTP_SECURE') ? SMTP_SECURE : PHPMailer::ENCRYPTION_SMTPS; // Habilitar encriptación (ssl o tls)
        $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 465; // Puerto TCP para conectar

        // --- Configuración de Remitente y Destinatario ---
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($recipient_email, $recipient_name); // Añadir un destinatario

        // --- Contenido del Correo ---
        $mail->isHTML(true);                                // Establecer el formato del correo a HTML
        $mail->CharSet = 'UTF-8';                           // Para que los acentos y eñes se vean bien
        $mail->Subject = $subject;
        $mail->Body    = $body_html;
        $mail->AltBody = strip_tags($body_html); // Versión de texto plano para clientes de correo que no soportan HTML

        $mail->send();
        return true;
    } catch (Exception $e) {
        // En un entorno de producción, registra el error en lugar de mostrarlo.
        error_log("El mensaje no pudo ser enviado. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
?>
