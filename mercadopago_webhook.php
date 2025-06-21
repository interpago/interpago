<?php
// mercadopago_webhook.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

function log_message($message) {
    $log_file = __DIR__ . '/mercadopago_debug.log';
    file_put_contents($log_file, date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

log_message("--- INICIO DE PETICIÓN WEBHOOK ---");

require_once __DIR__ . '/lib/MercadoPagoSDK.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/send_notification.php';

log_message("Archivos cargados.");

try {
    MercadoPago\SDK::setAccessToken(MERCADO_PAGO_ACCESS_TOKEN);
    log_message("SDK de Mercado Pago configurado.");

    $body = file_get_contents('php://input');
    if (empty($body)) {
        log_message("ERROR: Cuerpo de la petición vacío.");
        http_response_code(400);
        exit;
    }
    $data = json_decode($body, true);
    log_message("Datos recibidos: " . json_encode($data));

    if (isset($data['type']) && $data['type'] === 'payment') {
        $payment_id = $data['data']['id'];
        log_message("ID de Pago recibido: " . $payment_id);

        $payment = MercadoPago\Payment::find_by_id($payment_id);

        if ($payment && $payment->status == 'approved') {
            log_message("Pago encontrado y APROBADO. Referencia externa: " . $payment->external_reference);
            $transaction_uuid = $payment->external_reference;
            $new_status = 'funded';

            $stmt = $conn->prepare("UPDATE transactions SET status = ? WHERE transaction_uuid = ? AND status = 'initiated'");
            $stmt->bind_param("ss", $new_status, $transaction_uuid);

            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    log_message("¡ÉXITO! Base de datos actualizada a 'En Custodia'.");
                    send_notification($conn, "payment_approved", ['transaction_uuid' => $transaction_uuid]);
                } else {
                    log_message("AVISO: La consulta no afectó ninguna fila (posiblemente ya estaba actualizada).");
                }
            } else {
                log_message("¡ERROR DE BASE DE DATOS! No se pudo actualizar. Error: " . $stmt->error);
            }
        } else {
             log_message("El estado del pago no es 'approved'. Estado recibido: " . ($payment->status ?? 'N/A'));
        }
    } else {
        log_message("Aviso: La notificación no es de tipo 'payment'.");
    }

    http_response_code(200);

} catch (Exception $e) {
    log_message("¡EXCEPCIÓN ATRAPADA! Error: " . $e->getMessage());
    http_response_code(500);
}

log_message("--- FIN DE PETICIÓN WEBHOOK ---");
exit;
?>
