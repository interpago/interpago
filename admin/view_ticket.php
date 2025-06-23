<?php
// RUTA: /admin/view_ticket.php (Rediseñado y Corregido)
// ===============================================
// Propósito: Vista de chat para que el admin responda a un ticket.
// ===============================================

require_once __DIR__ . '/../config.php';
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
$ticket_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($ticket_id === 0) die("ID de ticket no válido.");

// Lógica para cerrar o abrir ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['new_status'];
    if ($new_status === 'abierto' || $new_status === 'cerrado') {
        $update_stmt = $conn->prepare("UPDATE support_tickets SET status = ? WHERE id = ?");
        $update_stmt->bind_param("si", $new_status, $ticket_id);
        $update_stmt->execute();
        header("Location: view_ticket.php?id=$ticket_id");
        exit;
    }
}

// Unir con `users` y opcionalmente con `transactions`
$query = "
    SELECT
        st.*,
        u.name as user_name,
        u.email as user_email,
        t.transaction_uuid
    FROM support_tickets st
    JOIN users u ON st.user_id = u.id
    LEFT JOIN transactions t ON st.transaction_id = t.id
    WHERE st.id = ?
";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $ticket_id);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
if (!$ticket) die("Ticket no encontrado.");

// Marcar como leído por el admin
$conn->query("UPDATE support_tickets SET admin_unread = 0 WHERE id = $ticket_id");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ver Ticket #<?php echo $ticket['id']; ?> - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-100 flex">
    <?php include 'includes/sidebar.php'; ?>

    <div class="flex-1 flex h-screen">
        <!-- Columna de Chat -->
        <main class="flex-1 flex flex-col">
            <header class="bg-white shadow-sm p-4 border-b flex justify-between items-center">
                <div>
                    <h1 class="text-xl font-semibold text-slate-900"><?php echo htmlspecialchars($ticket['subject']); ?></h1>
                    <p class="text-sm text-slate-500">Conversación con <?php echo htmlspecialchars($ticket['user_name']); ?></p>
                </div>
                <form action="view_ticket.php?id=<?php echo $ticket['id']; ?>" method="POST">
                    <input type="hidden" name="new_status" value="<?php echo $ticket['status'] === 'abierto' ? 'cerrado' : 'abierto'; ?>">
                    <button type="submit" name="update_status" class="px-4 py-2 rounded-lg text-sm font-semibold text-white <?php echo $ticket['status'] === 'abierto' ? 'bg-red-500 hover:bg-red-600' : 'bg-green-500 hover:bg-green-600'; ?>">
                        <i class="fas <?php echo $ticket['status'] === 'abierto' ? 'fa-lock' : 'fa-lock-open'; ?> mr-2"></i>
                        <?php echo $ticket['status'] === 'abierto' ? 'Cerrar Ticket' : 'Reabrir Ticket'; ?>
                    </button>
                </form>
            </header>

            <div id="chat-box" class="flex-1 p-6 overflow-y-auto bg-slate-50 space-y-4">
                <!-- Mensajes aquí -->
            </div>

            <?php if ($ticket['status'] === 'abierto'): ?>
            <div class="p-4 bg-white border-t-2 border-slate-100">
                <form id="reply-form" class="flex items-center space-x-3">
                    <input type="hidden" id="ticket_id" value="<?php echo $ticket['id']; ?>">
                    <input type="text" id="message-input" class="w-full p-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none" placeholder="Escribe tu respuesta..." autocomplete="off">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-3 rounded-lg hover:bg-blue-700">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </form>
            </div>
            <?php else: ?>
                <div class="p-4 bg-slate-100 text-center text-slate-500 font-semibold">El ticket está cerrado. Para responder, necesitas reabrirlo.</div>
            <?php endif; ?>
        </main>

        <!-- Columna de Información del Ticket -->
        <aside class="w-80 bg-white border-l border-slate-200 h-screen flex flex-col">
            <div class="p-6 border-b">
                <h3 class="font-bold text-lg text-slate-800">Detalles del Ticket</h3>
            </div>
            <div class="p-6 space-y-4 text-sm flex-grow">
                <div>
                    <h4 class="font-semibold text-slate-500 uppercase tracking-wider text-xs mb-1">Usuario</h4>
                    <p class="text-slate-800"><?php echo htmlspecialchars($ticket['user_name']); ?></p>
                    <p class="text-slate-500"><?php echo htmlspecialchars($ticket['user_email']); ?></p>
                </div>
                <div>
                    <h4 class="font-semibold text-slate-500 uppercase tracking-wider text-xs mb-1">Estado</h4>
                    <p class="font-semibold capitalize <?php echo $ticket['status'] === 'abierto' ? 'text-green-600' : 'text-red-600'; ?>">
                        <?php echo htmlspecialchars($ticket['status']); ?>
                    </p>
                </div>
                 <div>
                    <h4 class="font-semibold text-slate-500 uppercase tracking-wider text-xs mb-1">Categoría</h4>
                    <p class="text-slate-800"><?php echo htmlspecialchars($ticket['category']); ?></p>
                </div>
                <div>
                    <h4 class="font-semibold text-slate-500 uppercase tracking-wider text-xs mb-1">Fecha de Creación</h4>
                    <p class="text-slate-800"><?php echo date('d/m/Y H:i', strtotime($ticket['created_at'])); ?></p>
                </div>
                <div>
                    <h4 class="font-semibold text-slate-500 uppercase tracking-wider text-xs mb-1">Última Actualización</h4>
                    <p class="text-slate-800"><?php echo date('d/m/Y H:i', strtotime($ticket['updated_at'])); ?></p>
                </div>

                <?php if ($ticket['transaction_uuid']): ?>
                <div>
                    <h4 class="font-semibold text-slate-500 uppercase tracking-wider text-xs mb-1">Transacción Vinculada</h4>
                    <!-- ***** LÍNEA CORREGIDA ***** -->
                    <a href="../transaction.php?tx_uuid=<?php echo $ticket['transaction_uuid']; ?>" target="_blank" class="text-blue-600 hover:underline">
                        Ver Transacción #<?php echo substr($ticket['transaction_uuid'], 0, 8); ?>... <i class="fas fa-external-link-alt ml-1"></i>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </aside>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatBox = document.getElementById('chat-box');
    const replyForm = document.getElementById('reply-form');
    const messageInput = document.getElementById('message-input');
    const ticketId = document.getElementById('ticket_id').value;

    function renderMessage(msg) {
        const isAdmin = msg.sender_role === 'admin';
        const alignClass = isAdmin ? 'items-end' : 'items-start';
        const bubbleClass = isAdmin ? 'bg-green-100 text-green-900' : 'bg-slate-200 text-slate-800';
        const senderName = isAdmin ? 'Tú (Soporte)' : 'Usuario';

        return `
            <div class="flex flex-col ${alignClass}">
                <div class="text-xs text-slate-500 mb-1">${senderName}</div>
                <div class="${bubbleClass} rounded-lg px-4 py-2 max-w-lg shadow-sm">
                    <p class="text-sm">${msg.message.replace(/</g, "&lt;").replace(/>/g, "&gt;")}</p>
                </div>
                <p class="text-xs text-slate-400 mt-1">${new Date(msg.created_at).toLocaleString()}</p>
            </div>
        `;
    }

    async function loadMessages() {
        const response = await fetch(`../ajax/get_ticket_messages.php?ticket_id=${ticketId}`);
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

            const response = await fetch('../ajax/send_ticket_message.php', { method: 'POST', body: formData });
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
    setInterval(loadMessages, 5000);
});
</script>
</body>
</html>
