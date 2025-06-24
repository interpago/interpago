<?php
// get_transaction_updates.php

// Este script devuelve el estado actual y los mensajes nuevos de una transacción en formato JSON.

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

// Validar que el usuario ha iniciado sesión
if (!isset($_SESSION['user_uuid']) || empty($_SESSION['user_uuid'])) {
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$transaction_uuid = $_GET['tx_uuid'] ?? '';
$last_message_id = (int)($_GET['last_message_id'] ?? 0);
$client_status = $_GET['current_status'] ?? '';

if (empty($transaction_uuid)) {
    echo json_encode(['error' => 'ID de transacción no proporcionado']);
    exit;
}

$response = [
    'status_changed' => false,
    'new_status' => '',
    'new_messages' => []
];

try {
    // 1. Obtener transacción y verificar si el usuario actual es parte de ella
    $stmt = $conn->prepare("SELECT * FROM transactions WHERE transaction_uuid = ?");
    $stmt->bind_param("s", $transaction_uuid);
    $stmt->execute();
    $transaction = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$transaction) {
        echo json_encode(['error' => 'Transacción no encontrada']);
        exit;
    }

    $current_user_uuid = $_SESSION['user_uuid'];
    if ($current_user_uuid !== $transaction['buyer_uuid'] && $current_user_uuid !== $transaction['seller_uuid']) {
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }

    // 2. Comprobar si el estado ha cambiado
    if ($client_status !== $transaction['status']) {
        $response['status_changed'] = true;
        $response['new_status'] = $transaction['status'];
    }

    // 3. Buscar mensajes más nuevos que el último que tiene el cliente
    $msg_stmt = $conn->prepare("SELECT * FROM messages WHERE transaction_id = ? AND id > ? ORDER BY created_at ASC");
    $msg_stmt->bind_param("ii", $transaction['id'], $last_message_id);
    $msg_stmt->execute();
    $messages_result = $msg_stmt->get_result();

    while ($msg = $messages_result->fetch_assoc()) {
        $response['new_messages'][] = [
            'id' => $msg['id'],
            'sender_role' => $msg['sender_role'],
            'message' => nl2br(htmlspecialchars($msg['message'])),
            'image_path' => $msg['image_path'] ? htmlspecialchars($msg['image_path']) : null,
            'created_at' => date("d/m H:i", strtotime($msg['created_at'])),
            'is_system' => $msg['sender_role'] === 'System',
            'is_current_user' => $msg['sender_role'] === ($_SESSION['user_role'] ?? '') // Necesitamos el rol en la sesión
        ];
    }
    $msg_stmt->close();

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error del servidor: ' . $e->getMessage()]);
}
?>
