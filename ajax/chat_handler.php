<?php
// ajax/chat_handler.php

// Activamos el reporte de errores para no tener fallos silenciosos
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json');

// 1. VERIFICACIÓN DE ARCHIVO DE CONFIGURACIÓN
if (!file_exists('../config.php')) {
    echo json_encode(['success' => false, 'error' => 'CRITICAL: El archivo config.php no se encuentra en la ruta esperada.']);
    exit;
}
require_once '../config.php';

// 2. VERIFICACIÓN DE CONEXIÓN A LA BASE DE DATOS
if (!isset($conn) || $conn->connect_error) {
    echo json_encode([
        'success' => false,
        'error' => 'CRITICAL: La conexión a la base de datos falló. Revisa tu archivo config.php.',
        'details' => isset($conn) ? $conn->connect_error : 'La variable $conn no está definida en config.php.'
    ]);
    exit;
}

// 3. VERIFICACIÓN DE SESIÓN Y PERMISOS
$action = $_REQUEST['action'] ?? '';
$user_id = $_SESSION['user_id'] ?? null;
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'FALLO DE SESIÓN: Usuario no autenticado (user_id no existe en la sesión).']);
    exit;
}

$admin_actions = ['getConversations', 'getAdminHistory', 'sendAdminMessage', 'closeConversation', 'getNewMessagesForAdmin'];
if (in_array($action, $admin_actions) && !$is_admin) {
    echo json_encode(['success' => false, 'error' => 'FALLO DE PERMISOS: Acceso denegado. Se requieren privilegios de administrador.']);
    exit;
}

// 4. EJECUCIÓN DE LA ACCIÓN
switch ($action) {
    case 'getHistory': get_user_chat_history($conn, $user_id); break;
    case 'sendMessage': send_user_message($conn, $user_id); break;
    case 'getNewMessages': get_new_messages_for_user($conn, $user_id); break;
    case 'getConversations': get_all_conversations($conn); break;
    case 'getAdminHistory': get_admin_chat_history($conn); break;
    case 'sendAdminMessage': send_admin_message($conn, $user_id); break;
    case 'closeConversation': close_conversation($conn); break;
    case 'getNewMessagesForAdmin': get_new_messages_for_admin($conn); break;
    default: echo json_encode(['success' => false, 'error' => "Acción inválida: '{$action}'"]);
}

$conn->close();

// ===================================
// FUNCIONES (VERSIÓN ÚNICA Y CORRECTA)
// ===================================

function get_all_conversations($conn) {
    $query = "SELECT
                c.id,
                u.name,
                u.email,
                (SELECT message FROM chat_messages cm WHERE cm.conversation_id = c.id ORDER BY cm.timestamp DESC LIMIT 1) as last_message,
                (SELECT timestamp FROM chat_messages cm WHERE cm.conversation_id = c.id ORDER BY cm.timestamp DESC LIMIT 1) as last_message_time,
                (SELECT COUNT(*) FROM chat_messages cm WHERE cm.conversation_id = c.id AND cm.is_read = 0 AND cm.sender_type = 'user') as unread_count
              FROM chat_conversations c
              JOIN users u ON c.user_id = u.id
              WHERE c.status = 'open'
              ORDER BY last_message_time DESC";

    $result = $conn->query($query);
    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'FALLO DE SQL: ' . $conn->error, 'conversations' => []]);
        return;
    }
    $conversations = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'conversations' => $conversations]);
}

function get_user_chat_history($conn, $user_id) {
    $stmt = $conn->prepare("SELECT id FROM chat_conversations WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $conversation_id = null;
    if ($result->num_rows > 0) {
        $conversation_id = $result->fetch_assoc()['id'];
    } else {
        $stmt_insert = $conn->prepare("INSERT INTO chat_conversations (user_id) VALUES (?)");
        $stmt_insert->bind_param("i", $user_id);
        $stmt_insert->execute();
        $conversation_id = $conn->insert_id;
    }
    $messages_stmt = $conn->prepare("SELECT message, sender_type, timestamp FROM chat_messages WHERE conversation_id = ? ORDER BY timestamp ASC");
    $messages_stmt->bind_param("i", $conversation_id);
    $messages_stmt->execute();
    $messages = $messages_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'conversation_id' => $conversation_id, 'messages' => $messages]);
}

function send_user_message($conn, $user_id) {
    $message = trim($_POST['message'] ?? '');
    $conversation_id = filter_var($_POST['conversation_id'], FILTER_VALIDATE_INT);
    if (empty($message) || !$conversation_id) exit;
    $stmt = $conn->prepare("INSERT INTO chat_messages (conversation_id, sender_id, sender_type, message, is_read) VALUES (?, ?, 'user', ?, 0)");
    $stmt->bind_param("iis", $conversation_id, $user_id, $message);
    $stmt->execute();
    echo json_encode(['success' => true]);
}

function get_new_messages_for_user($conn, $user_id) {
    $conversation_id = filter_var($_POST['conversation_id'], FILTER_VALIDATE_INT);
    if (!$conversation_id) exit;
    $stmt = $conn->prepare("SELECT id, message, sender_type, timestamp FROM chat_messages WHERE conversation_id = ? AND sender_type = 'admin' AND is_read = 0 ORDER BY timestamp ASC");
    $stmt->bind_param("i", $conversation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = [];
    $message_ids = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
        $message_ids[] = $row['id'];
    }
    if (!empty($message_ids)) {
        $ids_placeholder = implode(',', array_fill(0, count($message_ids), '?'));
        $types = str_repeat('i', count($message_ids));
        $update_stmt = $conn->prepare("UPDATE chat_messages SET is_read = 1 WHERE id IN ($ids_placeholder)");
        $update_stmt->bind_param($types, ...$message_ids);
        $update_stmt->execute();
    }
    echo json_encode(['success' => true, 'messages' => $messages]);
}

function get_admin_chat_history($conn) {
    $conversation_id = filter_var($_POST['conversation_id'], FILTER_VALIDATE_INT);
    if (!$conversation_id) exit;
    $stmt_update = $conn->prepare("UPDATE chat_messages SET is_read = 1 WHERE conversation_id = ? AND sender_type = 'user'");
    $stmt_update->bind_param("i", $conversation_id);
    $stmt_update->execute();
    $messages_stmt = $conn->prepare("SELECT message, sender_type, timestamp FROM chat_messages WHERE conversation_id = ? ORDER BY timestamp ASC");
    $messages_stmt->bind_param("i", $conversation_id);
    $messages_stmt->execute();
    $messages = $messages_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'messages' => $messages]);
}

function send_admin_message($conn, $admin_id) {
    $message = trim($_POST['message'] ?? '');
    $conversation_id = filter_var($_POST['conversation_id'], FILTER_VALIDATE_INT);
    if (empty($message) || !$conversation_id) { echo json_encode(['success' => false, 'error' => 'Invalid data']); return; }
    $stmt = $conn->prepare("INSERT INTO chat_messages (conversation_id, sender_id, sender_type, message, is_read) VALUES (?, ?, 'admin', ?, 0)");
    $stmt->bind_param("iis", $conversation_id, $admin_id, $message);
    $stmt->execute();
    echo json_encode(['success' => true]);
}

function close_conversation($conn) {
    $conversation_id = filter_var($_POST['conversation_id'], FILTER_VALIDATE_INT);
    if (!$conversation_id) { echo json_encode(['success' => false, 'error' => 'Invalid ID']); return; }
    $stmt = $conn->prepare("UPDATE chat_conversations SET status = 'closed' WHERE id = ?");
    $stmt->bind_param("i", $conversation_id);
    $stmt->execute();
    echo json_encode(['success' => true]);
}

function get_new_messages_for_admin($conn) {
    $conversation_id = filter_var($_POST['conversation_id'], FILTER_VALIDATE_INT);
    if (!$conversation_id) exit;
    $stmt = $conn->prepare("SELECT id, message, sender_type, timestamp FROM chat_messages WHERE conversation_id = ? AND sender_type = 'user' AND is_read = 0 ORDER BY timestamp ASC");
    $stmt->bind_param("i", $conversation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = [];
    $message_ids = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
        $message_ids[] = $row['id'];
    }
    if (!empty($message_ids)) {
        $ids_placeholder = implode(',', array_fill(0, count($message_ids), '?'));
        $types = str_repeat('i', count($message_ids));
        $update_stmt = $conn->prepare("UPDATE chat_messages SET is_read = 1 WHERE id IN ($ids_placeholder)");
        $update_stmt->bind_param($types, ...$message_ids);
        $update_stmt->execute();
    }
    echo json_encode(['success' => true, 'messages' => $messages]);
}

?>
