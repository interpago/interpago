<?php
// Muestra todos los errores para facilitar la depuración.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- FUNCIÓN DE DIAGNÓSTICO ---
// Esta función escribirá en un archivo de log para que podamos ver qué pasa.
function log_payment_event($message) {
    $log_file = __DIR__ . '/payment_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] " . $message . "\n", FILE_APPEND);
}

// Limpiar el log para cada nueva prueba (opcional, puedes comentarlo si quieres un historial)
if (isset($_GET['new_test'])) {
    file_put_contents(__DIR__ . '/payment_log.txt', '');
}

log_payment_event("--- INICIO DE RESPUESTA DE PAGO ---");

// Cargar configuración de la base de datos y otras dependencias
require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/lib/send_notification.php')) {
    require_once __DIR__ . '/lib/send_notification.php';
}

session_start();

// --- 1. Obtener el ID de la transacción de Wompi desde la URL ---
$wompi_tx_id = $_GET['id'] ?? null;
$redirect_url = 'dashboard.php'; // URL por defecto si algo falla
$message = '';
$message_type = 'error';

if (!$wompi_tx_id) {
    log_payment_event("ERROR: No se recibió 'id' en la URL. Terminando ejecución.");
    $_SESSION['error_message'] = 'No se recibió un ID de transacción para verificar.';
    header("Location: " . $redirect_url);
    exit;
}

log_payment_event("ID de Wompi recibido: " . $wompi_tx_id);

// --- 2. Consultar la transacción directamente a la API de Wompi ---
log_payment_event("Consultando a la API de Wompi: " . WOMPI_API_URL . '/transactions/' . $wompi_tx_id);
$ch = curl_init(WOMPI_API_URL . '/transactions/' . $wompi_tx_id);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WOMPI_PRIVATE_KEY]
]);
$response_body = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

log_payment_event("Respuesta de Wompi - Código HTTP: " . $http_code);
log_payment_event("Respuesta de Wompi - Cuerpo: " . $response_body);

if ($http_code !== 200) {
    log_payment_event("ERROR: Wompi devolvió un código no exitoso.");
    $_SESSION['error_message'] = 'No pudimos verificar el estado de tu pago con Wompi en este momento.';
    $fallback_url = isset($_SESSION['last_tx_uuid']) ? 'transaction.php?tx_uuid=' . $_SESSION['last_tx_uuid'] : $redirect_url;
    header("Location: " . $fallback_url);
    exit;
}

// --- 3. Procesar la respuesta de Wompi con lógica mejorada ---
$wompi_data = json_decode($response_body, true);
$transaction_data = $wompi_data['data'] ?? null;
$transaction_uuid = $transaction_data['reference'] ?? null;

if (!$transaction_uuid) {
    log_payment_event("ERROR: No se encontró la 'reference' (nuestro tx_uuid) en la respuesta de Wompi.");
    $_SESSION['error_message'] = 'La respuesta de Wompi no contenía una referencia válida.';
    header("Location: dashboard.php");
    exit;
}

$redirect_url = 'transaction.php?tx_uuid=' . $transaction_uuid;
log_payment_event("Referencia (tx_uuid) encontrada: " . $transaction_uuid . ". Redirección final será a: " . $redirect_url);


if ($transaction_data && $transaction_uuid) {
    if ($transaction_data['status'] === 'APPROVED') {
        log_payment_event("Wompi reporta estado: APPROVED.");
        try {
            // Primero, verificamos el estado actual de la transacción en NUESTRA base de datos.
            log_payment_event("Consultando estado actual en nuestra BD para tx_uuid: " . $transaction_uuid);
            $stmt_check = $conn->prepare("SELECT status, seller_id FROM transactions WHERE transaction_uuid = ?");
            $stmt_check->bind_param("s", $transaction_uuid);
            $stmt_check->execute();
            $current_tx = $stmt_check->get_result()->fetch_assoc();
            $stmt_check->close();

            if ($current_tx) {
                log_payment_event("Estado actual en BD: " . $current_tx['status']);
                if ($current_tx['status'] === 'initiated') {
                    log_payment_event("Estado es 'initiated'. Procediendo a actualizar a 'funded'.");
                    $conn->begin_transaction();
                    $stmt_update = $conn->prepare("UPDATE transactions SET status = 'funded', payment_reference = ? WHERE transaction_uuid = ?");
                    $stmt_update->bind_param("ss", $wompi_tx_id, $transaction_uuid);
                    $stmt_update->execute();
                    $affected_rows = $stmt_update->affected_rows;
                    $stmt_update->close();
                    $conn->commit();
                    log_payment_event("UPDATE ejecutado. Filas afectadas: " . $affected_rows);

                    $message = '¡Pago Aprobado! Tu transacción ha sido actualizada y los fondos están en custodia.';
                    $message_type = 'success';

                    if(function_exists('send_notification')) {
                        log_payment_event("Enviando notificación al vendedor ID: " . $current_tx['seller_id']);
                        send_notification($conn, $current_tx['seller_id'], "payment_approved", ['transaction_uuid' => $transaction_uuid]);
                    }

                } elseif ($current_tx['status'] === 'funded') {
                    log_payment_event("El estado ya es 'funded'. No se necesita ninguna acción.");
                    $message = 'Este pago ya había sido verificado anteriormente.';
                    $message_type = 'info';
                } else {
                    log_payment_event("ERROR: El pago fue aprobado, pero el estado actual de la transacción es '" . $current_tx['status'] . "', no se puede actualizar.");
                    $message = 'El pago fue aprobado, pero la transacción se encuentra en un estado que no permite procesar el pago (' . htmlspecialchars($current_tx['status']) . ').';
                    $message_type = 'error';
                }
            } else {
                log_payment_event("ERROR CRÍTICO: No se encontró la transacción en nuestra BD.");
                $message = 'Error: No se encontró la transacción con la referencia proporcionada en nuestra base de datos.';
                $message_type = 'error';
            }
        } catch(Exception $e) {
            log_payment_event("EXCEPCIÓN DE PHP/BD: " . $e->getMessage());
            if ($conn->in_transaction) {
                $conn->rollback();
            }
            $message = 'Hubo un error crítico al actualizar la base de datos: ' . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        log_payment_event("Wompi reporta estado NO APROBADO: " . $transaction_data['status']);
        $message = 'Tu pago fue ' . strtolower(htmlspecialchars($transaction_data['status'])) . '. Por favor, inténtalo de nuevo.';
        $message_type = 'error';
    }
} else {
    log_payment_event("ERROR: La respuesta de Wompi no pudo ser procesada o no contenía datos válidos.");
    $message = 'La respuesta de Wompi no pudo ser procesada.';
    $message_type = 'error';
}

// Guardamos los mensajes en la sesión para mostrarlos en la página de la transacción
if ($message_type === 'success') $_SESSION['success_message'] = $message;
elseif ($message_type === 'info') $_SESSION['info_message'] = $message;
else $_SESSION['error_message'] = $message;

log_payment_event("--- FIN DE RESPUESTA DE PAGO --- Redirigiendo a: " . $redirect_url);

// --- 4. Redirigir al usuario de vuelta a la página de la transacción ---
header("Location: " . $redirect_url);
exit;

?>
