<?php
// RUTA: /ajax/get_ticket_messages.php
// ===============================================
// Propósito: Endpoint para obtener los mensajes de un ticket.
// ===============================================

require_once __DIR__ . '/../config.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

if (!isset($_GET['ticket_id'])) {
    echo json_encode(['success' => false, 'error' => 'ID de ticket no proporcionado.']);
    exit;
}
$ticket_id = (int)$_GET['ticket_id'];

$stmt = $conn->prepare("SELECT sender_role, message, created_at FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC");
$stmt->bind_param("i", $ticket_id);
$stmt->execute();
$result = $stmt->get_result();
$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = $row;
}
echo json_encode(['success' => true, 'messages' => $messages]);
?>
