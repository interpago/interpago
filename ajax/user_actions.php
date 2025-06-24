<?php
session_start();
header('Content-Type: application/json');

// Seguridad: Solo administradores pueden ejecutar estas acciones
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Acceso denegado.']);
    exit;
}

require_once '../config.php';

$user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';

if (!$user_id || !in_array($action, ['suspend', 'reactivate'])) {
    echo json_encode(['success' => false, 'error' => 'Datos inválidos.']);
    exit;
}

$new_status = ($action === 'suspend') ? 'suspended' : 'active';

try {
    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $user_id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'new_status' => $new_status]);
    } else {
        throw new Exception("No se pudo actualizar el estado del usuario.");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
