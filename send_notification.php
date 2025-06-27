<?php
// send_notification.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Cargar dependencias de PHPMailer y la configuración principal
// ----- INICIO DE LA CORRECCIÓN -----
// Se elimina '../' para que busque los archivos en el directorio actual (la raíz del proyecto).
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
// ----- FIN DE LA CORRECCIÓN -----


// La función ahora está preparada para recibir diferentes tipos de notificaciones
function send_notification($conn, $type, $data) {
    // No hacer nada si falta la configuración SMTP.
    if (!defined('SMTP_HOST') || empty(SMTP_HOST) || !defined('SMTP_USERNAME') || empty(SMTP_USERNAME)) {
        error_log("Configuración SMTP incompleta o no definida. No se enviará el correo.");
        return; // Salir silenciosamente si el SMTP no está configurado
    }

    $mail = new PHPMailer(true);

    try {
        // Configuración del servidor
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = defined('SMTP_SECURE') ? SMTP_SECURE : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;
        $mail->CharSet    = 'UTF-8';

        // Remitente
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);

        // Contenido del correo
        $mail->isHTML(true);
        $subject = '';
        $body = '';

        // Obtener datos de la transacción para personalizar el correo
        $stmt = $conn->prepare("SELECT t.*, b.email as buyer_email, s.email as seller_email, b.name as buyer_name, s.name as seller_name FROM transactions t JOIN users b ON t.buyer_id = b.id JOIN users s ON t.seller_id = s.id WHERE t.transaction_uuid = ?");
        $stmt->bind_param("s", $data['transaction_uuid']);
        $stmt->execute();
        $transaction = $stmt->get_result()->fetch_assoc();

        if (!$transaction) return; // No se encontró la transacción

        $base_url = rtrim(APP_URL, '/');

        switch ($type) {
            case 'new_transaction_invitation':
                $initiator_name = $data['initiator_name'];
                $counterparty_email = $data['counterparty_email'];
                $counterparty_name = $data['counterparty_name'];
                $counterparty_link = $data['counterparty_link'];

                $subject = "Has sido invitado a una transacción en TuPacto";
                $body    = "Hola " . htmlspecialchars($counterparty_name) . ",<br><br><strong>" . htmlspecialchars($initiator_name) . "</strong> te ha invitado a una transacción segura para el producto: <strong>'" . htmlspecialchars($transaction['product_description']) . "'</strong>.<br><br>Puedes ver los detalles y continuar con el proceso en el siguiente enlace:<br><a href='" . $counterparty_link . "'>" . $counterparty_link . "</a>";

                $mail->addAddress($counterparty_email, $counterparty_name);
                break;

            case 'new_message':
                // Lógica para notificar un nuevo mensaje
                // ...
                break;

            case 'payment_approved':
                $seller_link = "{$base_url}/transaction.php?tx_uuid={$transaction['transaction_uuid']}&user_id={$transaction['seller_uuid']}";
                $subject = "¡Pago Confirmado! Transacción #" . substr($transaction['transaction_uuid'], 0, 8);
                $body    = "Hola {$transaction['seller_name']},<br><br>¡Buenas noticias! El comprador ha depositado los fondos para la transacción del producto '<b>{$transaction['product_description']}</b>'.<br>El dinero está en custodia de forma segura. Ya puedes proceder a enviar el producto.<br><br><a href='{$seller_link}'>Ir a la Transacción</a>";
                $mail->addAddress($transaction['seller_email'], $transaction['seller_name']);
                break;

            case 'status_update':
                 $transaction_link = "{$base_url}/transaction.php?tx_uuid=" . $transaction['transaction_uuid'];
                 $new_status_es = ucfirst(str_replace('_', ' ', $data['new_status']));
                 $subject = "Actualización en tu Transacción #" . substr($transaction['transaction_uuid'], 0, 8);
                 $body    = "Hola,<br><br>El estado de tu transacción para '<b>{$transaction['product_description']}</b>' ha sido actualizado a: <b>{$new_status_es}</b>.<br><br>Puedes ver los detalles en el siguiente enlace:<br><a href='{$transaction_link}'>Ver Transacción</a>";
                 // Enviar a ambos
                 $mail->addAddress($transaction['buyer_email'], $transaction['buyer_name']);
                 $mail->addAddress($transaction['seller_email'], $transaction['seller_name']);
                break;
        }

        if(!empty($mail->getAllRecipientAddresses())){
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);
            $mail->send();
        }

    } catch (Exception $e) {
        // En un entorno de producción, es mejor registrar el error en un archivo.
        error_log("El mensaje no pudo ser enviado. Mailer Error: {$mail->ErrorInfo}");
    }
}
?>
