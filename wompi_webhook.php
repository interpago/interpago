<?php
// Muestra todos los errores para facilitar la depuración.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Cargar dependencias
require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/lib/send_notification.php')) {
    require_once __DIR__ . '/lib/send_notification.php';
}

// --- Función para registrar datos en un archivo de log ---
function log_webhook_event($message, $data = null) {
    $log_file = __DIR__ . '/wompi_webhook_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message\n";
    if ($data) {
        $log_entry .= json_encode($data, JSON_PRETTY_PRINT) . "\n";
    }
    $log_entry .= "--------------------------------------\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

// --- 1. Leer el cuerpo de la petición del webhook ---
$json_str = file_get_contents('php://input');
$event_data = json_decode($json_str, true);

log_webhook_event("Webhook recibido", $event_data);

if (!$event_data || !isset($event_data['data']['transaction'])) {
    log_webhook_event("Error: El cuerpo del webhook es inválido o no contiene datos de transacción.");
    http_response_code(400); // Bad Request
    exit;
}

// Extraer los datos relevantes
$transaction_data = $event_data['data']['transaction'];
$transaction_uuid = $transaction_data['reference'] ?? null;
$wompi_tx_id = $transaction_data['id'] ?? null;
$wompi_status = $transaction_data['status'] ?? null;
$event_type = $event_data['event'] ?? '';

// --- 2. Verificar la firma del evento (Máxima Seguridad) ---
// Esto asegura que la petición realmente viene de Wompi.
$signature_from_header = $_SERVER['HTTP_X_WOMPI_SIGNATURE'] ?? '';
if ($signature_from_header) {
    $signature_parts = explode(',', $signature_from_header);
    $timestamp_from_sig = '';
    $signature_to_check = '';
    foreach($signature_parts as $part) {
        list($key, $value) = explode('=', $part, 2);
        if ($key === 't') {
            $timestamp_from_sig = $value;
        } elseif ($key === 'v1') {
            $signature_to_check = $value;
        }
    }

    if ($timestamp_from_sig && $signature_to_check) {
        // Concatenar el cuerpo del evento, el timestamp y el secreto de eventos.
        $string_to_sign = $json_str . $timestamp_from_sig . WOMPI_EVENTS_SECRET;
        $calculated_signature = hash('sha256', $string_to_sign);

        if (!hash_equals($calculated_signature, $signature_to_check)) {
            log_webhook_event("Error: Firma de Webhook inválida.", ['recibida' => $signature_to_check, 'calculada' => $calculated_signature]);
            http_response_code(401); // Unauthorized
            exit;
        }
        log_webhook_event("Firma de Webhook verificada correctamente.");
    }
} else {
    log_webhook_event("Advertencia: No se recibió la cabecera de firma de Wompi.");
    // Dependiendo de tu política de seguridad, podrías detener la ejecución aquí.
}


// --- 3. Procesar el evento si es una transacción actualizada ---
if ($event_type === 'transaction.updated' && $wompi_status === 'APPROVED' && $transaction_uuid) {
    log_webhook_event("Procesando transacción APROBADA para UUID: " . $transaction_uuid);

    try {
        // Buscamos el estado actual en nuestra base de datos para evitar procesar dos veces
        $stmt_check = $conn->prepare("SELECT status, seller_id FROM transactions WHERE transaction_uuid = ?");
        $stmt_check->bind_param("s", $transaction_uuid);
        $stmt_check->execute();
        $current_tx = $stmt_check->get_result()->fetch_assoc();
        $stmt_check->close();

        if ($current_tx && $current_tx['status'] === 'initiated') {
            // El estado es el correcto, procedemos a actualizar.
            $conn->begin_transaction();
            $stmt_update = $conn->prepare("UPDATE transactions SET status = 'funded', payment_reference = ? WHERE transaction_uuid = ?");
            $stmt_update->bind_param("ss", $wompi_tx_id, $transaction_uuid);
            $stmt_update->execute();
            $stmt_update->close();
            $conn->commit();

            log_webhook_event("ÉXITO: La transacción " . $transaction_uuid . " ha sido actualizada a 'funded'.");

            if(function_exists('send_notification')) {
                send_notification($conn, $current_tx['seller_id'], "payment_approved", ['transaction_uuid' => $transaction_uuid]);
            }

        } elseif ($current_tx) {
            log_webhook_event("INFO: La transacción " . $transaction_uuid . " ya estaba en estado '" . $current_tx['status'] . "'. No se necesita acción.");
        } else {
            log_webhook_event("ERROR: No se encontró la transacción con UUID: " . $transaction_uuid . " en la base de datos.");
        }
    } catch (Exception $e) {
        if ($conn->in_transaction) {
            $conn->rollback();
        }
        log_webhook_event("ERROR CRÍTICO al procesar la transacción " . $transaction_uuid . ": " . $e->getMessage());
        http_response_code(500); // Internal Server Error
        exit;
    }
} else {
    log_webhook_event("INFO: Evento '" . $event_type . "' con estado '" . $wompi_status . "' no requiere acción.");
}

// --- 4. Responder a Wompi que todo está bien ---
http_response_code(200);
echo "OK";
exit;

?>
