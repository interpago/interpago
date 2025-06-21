<?php
// verify_qr.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/send_notification.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$qr_token = $data['qr_token'] ?? null;
$transaction_uuid = $data['transaction_uuid'] ?? null;

if (!$qr_token || !$transaction_uuid) {
    echo json_encode(['success' => false, 'message' => 'Faltan datos.']);
    exit;
}

$stmt = $conn->prepare("SELECT id, status FROM transactions WHERE transaction_uuid = ? AND qr_code_token = ?");
$stmt->bind_param("ss", $transaction_uuid, $qr_token);
$stmt->execute();
$result = $stmt->get_result();

if ($transaction = $result->fetch_assoc()) {
    if ($transaction['status'] === 'shipped') {
        $new_status = 'received';

        // **NUEVO: Calcular y guardar la fecha de liberación de fondos**
        $release_time_sql = "NOW() + INTERVAL " . INSPECTION_PERIOD_MINUTES . " MINUTE";

        // Actualizar el estado y establecer el temporizador
        $update_stmt = $conn->prepare("UPDATE transactions SET status = ?, release_funds_at = ($release_time_sql) WHERE id = ?");
        $update_stmt->bind_param("si", $new_status, $transaction['id']);

        if ($update_stmt->execute()) {
            send_notification($conn, "status_update", ['transaction_uuid' => $transaction_uuid, 'new_status' => $new_status]);
            echo json_encode(['success' => true, 'message' => '¡Producto confirmado! Se ha iniciado el periodo de garantía.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error al actualizar la transacción.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'El estado de la transacción no permite esta acción.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Sello QR inválido o no corresponde a esta transacción.']);
}
?>
