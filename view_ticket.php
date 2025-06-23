<?php
// RUTA: /view_ticket.php (CORREGIDO)
// ===============================================
// Propósito: Muestra la conversación de un ticket específico para el usuario.
// ===============================================

require_once __DIR__ . '/config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$user_id = $_SESSION['user_id'];
$ticket_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($ticket_id === 0) {
    header('Location: support.php');
    exit;
}

// Obtener detalles del ticket y verificar permisos
$stmt = $conn->prepare("SELECT * FROM support_tickets WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $ticket_id, $user_id);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();

if (!$ticket) {
    die("Ticket no encontrado o no tienes permiso para verlo.");
}

// Marcar ticket como leído para el usuario
$conn->query("UPDATE support_tickets SET user_unread = 0 WHERE id = $ticket_id");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viendo Ticket #<?php echo $ticket['id']; ?> - Interpago</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-100">
    <header class="bg-white shadow-sm">
        <div class="container mx-auto px-4">
            <nav class="flex justify-between items-center py-4">
                <div class="text-2xl font-bold text-slate-900">
                    <a href="index.php" class="flex items-center space-x-3">
                        <svg class="h-8 w-8 text-slate-800" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" fill="currentColor"><path d="M 45,10 C 25,10 10,25 10,45 L 10,55 C 10,75 25,90 45,90 L 55,90 C 60,90 60,85 55,80 L 45,80 C 30,80 20,70 20,55 L 20,45 C 20,30 30,20 45,20 L 55,20 C 60,20 60,15 55,10 Z"/><path d="M 55,90 C 75,90 90,75 90,55 L 90,45 C 90,25 75,10 55,10 L 45,10 C 40,10 40,15 45,20 L 55,20 C 70,20 80,30 80,45 L 80,55 C 80,70 70,80 55,80 L 45,80 C 40,80 40,85 45,90 Z"/></svg>
                        <span>Interpago</span>
                    </a>
                </div>
                <div class="hidden md:flex items-center space-x-6">
                    <a href="dashboard.php" class="text-slate-600 font-medium hover:text-blue-600">Mi Panel</a>
                    <a href="support.php" class="text-blue-600 font-bold">Soporte</a>
                    <a href="edit_profile.php" class="text-slate-600 font-medium hover:text-blue-600">Mi Perfil</a>
                    <a href="logout.php" class="bg-slate-200 text-slate-800 font-bold py-2 px-4 rounded-lg hover:bg-slate-300 text-sm">Cerrar Sesión</a>
                </div>
            </nav>
        </div>
    </header>

    <main class="container mx-auto px-4 py-8">
        <a href="support.php" class="text-blue-600 hover:underline mb-4 inline-block"><i class="fas fa-arrow-left mr-2"></i>Volver a mis tickets</a>
        <h1 class="text-2xl font-bold text-slate-800">Ticket #<?php echo $ticket['id']; ?>: <?php echo htmlspecialchars($ticket['subject']); ?></h1>

        <!-- Interfaz de Chat -->
        <div class="mt-6 bg-white rounded-2xl shadow-lg">
            <div id="chat-box" class="h-96 p-4 overflow-y-auto bg-slate-50 space-y-4">
                <!-- Los mensajes se cargarán aquí -->
            </div>
            <?php if ($ticket['status'] === 'abierto'): ?>
            <div class="p-4 bg-white border-t">
                <form id="reply-form" class="flex items-center space-x-3">
                    <input type="hidden" id="ticket_id" value="<?php echo $ticket['id']; ?>">
                    <input type="text" id="message-input" class="w-full p-2 border rounded-lg" placeholder="Escribe tu respuesta..." autocomplete="off">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">Enviar</button>
                </form>
            </div>
            <?php else: ?>
            <div class="p-4 text-center bg-slate-100 text-slate-600 font-semibold">Este ticket ha sido cerrado por nuestro equipo de soporte.</div>
            <?php endif; ?>
        </div>
    </main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatBox = document.getElementById('chat-box');
    const replyForm = document.getElementById('reply-form');
    const messageInput = document.getElementById('message-input');
    const ticketId = document.getElementById('ticket_id').value;

    function renderMessage(msg) {
        const isUser = msg.sender_role === 'usuario';
        const alignClass = isUser ? 'items-end' : 'items-start';
        const bubbleClass = isUser ? 'bg-blue-500 text-white' : 'bg-slate-200 text-slate-800';
        const senderName = isUser ? 'Tú' : 'Soporte';

        return `
            <div class="flex flex-col ${alignClass}">
                <div class="text-xs text-slate-500 mb-1">${senderName}</div>
                <div class="${bubbleClass} rounded-lg px-4 py-2 max-w-lg">
                    <p>${msg.message.replace(/</g, "&lt;").replace(/>/g, "&gt;")}</p>
                </div>
                <p class="text-xs text-slate-400 mt-1">${new Date(msg.created_at).toLocaleString()}</p>
            </div>
        `;
    }

    async function loadMessages() {
        const response = await fetch(`ajax/get_ticket_messages.php?ticket_id=${ticketId}`);
        const data = await response.json();
        if (data.success) {
            chatBox.innerHTML = data.messages.map(renderMessage).join('');
            chatBox.scrollTop = chatBox.scrollHeight;
        }
    }

    if (replyForm) {
        replyForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const message = messageInput.value.trim();
            if (message === '') return;

            const formData = new FormData();
            formData.append('ticket_id', ticketId);
            formData.append('message', message);

            const response = await fetch('ajax/send_ticket_message.php', { method: 'POST', body: formData });
            const data = await response.json();

            if (data.success) {
                messageInput.value = '';
                loadMessages();
            } else {
                alert('Error al enviar el mensaje.');
            }
        });
    }

    loadMessages();
    setInterval(loadMessages, 5000); // Recarga mensajes cada 5 segundos
});
</script>

</body>
</html>
