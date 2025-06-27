<?php
// verify_transaction_payment.php

// Muestra todos los errores para facilitar la depuración.
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/send_notification.php';

// ***** INICIO DE LA CORRECCIÓN *****
// Se añade la función de ayuda que faltaba.
function add_system_message($conn, $transaction_id, $message) {
    try {
        $stmt = $conn->prepare("INSERT INTO messages (transaction_id, sender_role, message) VALUES (?, 'System', ?)");
        $stmt->bind_param("is", $transaction_id, $message);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Registrar el error sin detener el flujo principal
        error_log("Error al agregar mensaje del sistema para tx_id $transaction_id: " . $e->getMessage());
    }
}
// ***** FIN DE LA CORRECCIÓN *****


$wompi_transaction_id = $_GET['id'] ?? null;
$transaction_uuid_fallback = $_GET['reference'] ?? null; // Fallback por si el id no viene
$final_redirect_url = 'dashboard.php'; // Redirección por defecto si algo falla

if (!$wompi_transaction_id) {
    // Si no hay ID de Wompi, no podemos hacer nada.
    $_SESSION['error_message'] = "No se recibió una referencia de pago válida desde la pasarela.";
    header('Location: ' . $final_redirect_url);
    exit;
}

try {
    // 1. CONSULTAR LA TRANSACCIÓN EN LA API DE WOMPI
    // ----------------------------------------------------
    $wompi_api_url = (defined('WOMPI_API_URL') ? WOMPI_API_URL : 'https://sandbox.wompi.co/v1') . '/transactions/' . $wompi_transaction_id;

    $ch = curl_init($wompi_api_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . (defined('WOMPI_PRIVATE_KEY') ? WOMPI_PRIVATE_KEY : '')
        ]
    ]);

    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        throw new Exception("Error al consultar la API de Wompi. Código: " . $http_code);
    }

    $wompi_data = json_decode($response_body, true);
    $transaction_data = $wompi_data['data'] ?? null;

    if (!$transaction_data) {
        throw new Exception("La respuesta de la API de Wompi no contenía datos de transacción válidos.");
    }

    $transaction_uuid = $transaction_data['reference'] ?? $transaction_uuid_fallback;
    $final_redirect_url = 'transaction.php?tx_uuid=' . $transaction_uuid;


    // 2. VERIFICAR EL ESTADO Y ACTUALIZAR LA BASE DE DATOS
    // ----------------------------------------------------
    if ($transaction_data['status'] === 'APPROVED') {

        $conn->begin_transaction();

        try {
            // Busca la transacción para asegurarse de que no ha sido procesada
            $stmt_check = $conn->prepare("SELECT id, status FROM transactions WHERE transaction_uuid = ?");
            $stmt_check->bind_param("s", $transaction_uuid);
            $stmt_check->execute();
            $local_transaction = $stmt_check->get_result()->fetch_assoc();

            if ($local_transaction && $local_transaction['status'] === 'initiated') {
                // Actualiza el estado a 'funded' solo si estaba en 'initiated'
                // Se añade la actualización de `funded_at` que faltaba y que corregimos en la DB
                $stmt_update = $conn->prepare("UPDATE transactions SET status = 'funded', funded_at = NOW(), payment_reference = ? WHERE id = ?");
                $stmt_update->bind_param("si", $wompi_transaction_id, $local_transaction['id']);
                $stmt_update->execute();

                if ($stmt_update->affected_rows > 0) {
                    // Solo si la actualización fue exitosa
                    add_system_message($conn, $local_transaction['id'], "Pago completado y verificado vía Wompi. Fondos en custodia.");
                    send_notification($conn, "payment_approved", ['transaction_uuid' => $transaction_uuid]);
                    $_SESSION['success_message'] = "¡Pago aprobado y verificado! Los fondos están en custodia.";
                } else {
                     $_SESSION['error_message'] = "El pago fue aprobado, pero no se pudo actualizar el estado de la transacción local.";
                }

            } elseif($local_transaction) {
                 $_SESSION['success_message'] = "El pago para esta transacción ya había sido procesado.";
            } else {
                 throw new Exception("Transacción local no encontrada con la referencia: " . $transaction_uuid);
            }

            $conn->commit();

        } catch (Exception $db_e) {
            $conn->rollback();
            error_log("DB Error during Wompi verification: " . $db_e->getMessage());
            $_SESSION['error_message'] = "Error crítico de base de datos al confirmar el pago.";
        }

    } else {
        // El pago no fue aprobado (DECLINED, ERROR, etc.)
        $_SESSION['error_message'] = "El pago fue '" . strtolower($transaction_data['status_message'] ?? $transaction_data['status']) . "' por la pasarela de pagos.";
    }

} catch (Exception $e) {
    error_log("Wompi Verification Error: " . $e->getMessage());
    $_SESSION['error_message'] = "Ocurrió un error inesperado durante la verificación del pago.";
    if (isset($transaction_uuid)) {
        $final_redirect_url = 'transaction.php?tx_uuid=' . $transaction_uuid;
    }
}

// 3. REDIRIGIR AL USUARIO
// ----------------------------------------------------
header('Location: ' . $final_redirect_url);
exit;

?>
