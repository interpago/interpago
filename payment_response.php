<?php
// Muestra todos los errores para facilitar la depuración.
ini_set('display_errors', 1);
error_reporting(E_ALL);

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
    $_SESSION['error_message'] = 'No se recibió un ID de transacción para verificar.';
    header("Location: " . $redirect_url);
    exit;
}

// --- 2. Consultar la transacción directamente a la API de Wompi ---
$curl_url = WOMPI_API_URL . '/transactions/' . $wompi_tx_id;
$ch = curl_init($curl_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WOMPI_PRIVATE_KEY]
]);
$response_body = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    $_SESSION['error_message'] = 'No pudimos verificar el estado de tu pago con Wompi en este momento.';
    $fallback_url = isset($_SESSION['last_tx_uuid']) ? 'transaction.php?tx_uuid=' . $_SESSION['last_tx_uuid'] : $redirect_url;
    header("Location: " . $fallback_url);
    exit;
}

// --- 3. Procesar la respuesta de Wompi con lógica mejorada ---
$wompi_data = json_decode($response_body, true);
$transaction_data = $wompi_data['data'] ?? null;
$transaction_uuid = $transaction_data['reference'] ?? null;
$redirect_url = 'transaction.php?tx_uuid=' . $transaction_uuid;

if ($transaction_data && $transaction_uuid) {
    if ($transaction_data['status'] === 'APPROVED') {
        try {
            // Primero, verificamos el estado actual de la transacción en NUESTRA base de datos.
            $stmt_check = $conn->prepare("SELECT status, seller_id FROM transactions WHERE transaction_uuid = ?");
            $stmt_check->bind_param("s", $transaction_uuid);
            $stmt_check->execute();
            $current_tx = $stmt_check->get_result()->fetch_assoc();
            $stmt_check->close();

            if ($current_tx) {
                if ($current_tx['status'] === 'initiated') {
                    // El estado es el correcto, procedemos a actualizar.
                    $conn->begin_transaction();
                    $stmt_update = $conn->prepare("UPDATE transactions SET status = 'funded', payment_reference = ? WHERE transaction_uuid = ?");
                    $stmt_update->bind_param("ss", $wompi_tx_id, $transaction_uuid);
                    $stmt_update->execute();
                    $stmt_update->close();
                    $conn->commit();

                    $message = '¡Pago Aprobado! Tu transacción ha sido actualizada y los fondos están en custodia.';
                    $message_type = 'success';

                    if(function_exists('send_notification')) {
                        send_notification($conn, $current_tx['seller_id'], "payment_approved", ['transaction_uuid' => $transaction_uuid]);
                    }

                } elseif ($current_tx['status'] === 'funded') {
                    // La transacción ya estaba fondeada. No hacemos nada.
                    $message = 'Este pago ya había sido verificado anteriormente.';
                    $message_type = 'info';
                } else {
                    // La transacción está en otro estado (cancelada, liberada, etc.)
                    $message = 'El pago fue aprobado, pero la transacción se encuentra en un estado que no permite procesar el pago (' . htmlspecialchars($current_tx['status']) . ').';
                    $message_type = 'error';
                }
            } else {
                $message = 'Error: No se encontró la transacción con la referencia proporcionada en nuestra base de datos.';
                $message_type = 'error';
            }
        } catch(Exception $e) {
            if ($conn->in_transaction) {
                $conn->rollback();
            }
            $message = 'Hubo un error crítico al actualizar la base de datos: ' . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        // El pago fue RECHAZADO, ANULADO, etc.
        $message = 'Tu pago fue ' . strtolower(htmlspecialchars($transaction_data['status'])) . '. Por favor, inténtalo de nuevo.';
        $message_type = 'error';
    }
} else {
    $message = 'La respuesta de Wompi no pudo ser procesada.';
    $message_type = 'error';
}

// Guardamos los mensajes en la sesión para mostrarlos en la página de la transacción
if ($message_type === 'success') $_SESSION['success_message'] = $message;
elseif ($message_type === 'info') $_SESSION['info_message'] = $message;
else $_SESSION['error_message'] = $message;

// --- 4. Redirigir al usuario de vuelta a la página de la transacción ---
header("Location: " . $redirect_url);
exit;

?>
