<?php
// RUTA: /ajax/send_ticket_message.php
// ===============================================
// Propósito: Endpoint para guardar un nuevo mensaje en un ticket.
// ===============================================

require_once __DIR__ . '/../config.php';
session_start();

header('Content-Type: application/json');
$response = ['success' => false];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit;
}

$ticket_id = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

if (empty($ticket_id) || empty($message)) {
    $response['error'] = 'Faltan datos.';
    echo json_encode($response);
    exit;
}

$sender_role = '';
if (isset($_SESSION['admin_id'])) {
    $sender_role = 'admin';
} elseif (isset($_SESSION['user_id'])) {
    $sender_role = 'usuario';
}

if (empty($sender_role)) {
    $response['error'] = 'No autorizado.';
    echo json_encode($response);
    exit;
}

$conn->begin_transaction();
try {
    // Insertar el nuevo mensaje
    $stmt = $conn->prepare("INSERT INTO support_messages (ticket_id, sender_role, message) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $ticket_id, $sender_role, $message);
    $stmt->execute();

    // Actualizar el estado 'unread' del ticket y la fecha de actualización
    $update_ticket_sql = "UPDATE support_tickets SET updated_at = NOW(), status = 'abierto', ";
    if ($sender_role === 'usuario') {
        $update_ticket_sql .= "admin_unread = 1, user_unread = 0";
    } else { // admin
        $update_ticket_sql .= "user_unread = 1, admin_unread = 0";
    }
    $update_ticket_sql .= " WHERE id = ?";

    $update_stmt = $conn->prepare($update_ticket_sql);
    $update_stmt->bind_param("i", $ticket_id);
    $update_stmt->execute();

    $conn->commit();
    $response['success'] = true;

} catch (Exception $e) {
    $conn->rollback();
    $response['error'] = "Error de base de datos.";
}

echo json_encode($response);
?>
