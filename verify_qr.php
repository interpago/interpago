<?php
// verify_qr.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/config.php';

// Respuesta por defecto
$response = ['success' => false, 'message' => 'Petición inválida.'];

// Verificar que el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Acceso denegado. Debes iniciar sesión.';
    echo json_encode($response);
    exit;
}

// Obtener datos del POST
$data = json_decode(file_get_contents('php://input'), true);
$qr_token = $data['qr_token'] ?? null;
$transaction_uuid = $data['transaction_uuid'] ?? null;

if (!$qr_token || !$transaction_uuid) {
    $response['message'] = 'Faltan datos (token QR o UUID de transacción).';
    echo json_encode($response);
    exit;
}

try {
    // Buscar la transacción por UUID y token QR
    $stmt = $conn->prepare("SELECT id, status, buyer_id FROM transactions WHERE transaction_uuid = ? AND qr_code_token = ?");
    $stmt->bind_param("ss", $transaction_uuid, $qr_token);
    $stmt->execute();
    $transaction = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$transaction) {
        $response['message'] = 'El código QR es inválido o no corresponde a esta transacción.';
    } elseif ($transaction['status'] !== 'shipped') {
        $response['message'] = 'Esta transacción no está en estado "Enviado".';
    } elseif ($transaction['buyer_id'] != $_SESSION['user_id']) {
        $response['message'] = 'No tienes permiso para confirmar la recepción de esta transacción.';
    } else {
        // Todo es correcto, actualizamos el estado a 'received'
        $inspection_period = (defined('INSPECTION_PERIOD_MINUTES') && is_numeric(INSPECTION_PERIOD_MINUTES)) ? INSPECTION_PERIOD_MINUTES : 10;
        $update_stmt = $conn->prepare("UPDATE transactions SET status = 'received', received_at = NOW(), release_funds_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?");
        $update_stmt->bind_param("ii", $inspection_period, $transaction['id']);

        if ($update_stmt->execute()) {
            $response['success'] = true;
            $response['message'] = '¡Recepción confirmada! El periodo de garantía ha comenzado.';
        } else {
            $response['message'] = 'Error de base de datos al confirmar la recepción.';
        }
        $update_stmt->close();
    }

} catch (Exception $e) {
    error_log("Error en verify_qr.php: " . $e->getMessage());
    $response['message'] = 'Error interno del servidor.';
}

$conn->close();
echo json_encode($response);
?>
