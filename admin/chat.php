

<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header("Location: login.php");
    exit;
}
$admin_id = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat en Vivo - Administración</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; scrollbar-width: thin; scrollbar-color: #94a3b8 #e2e8f0; }
        .conversation-item.active { background-color: #e2e8f0; }
        .chat-messages { display: flex; flex-direction: column; gap: 0.75rem; }
        .message { max-width: 75%; padding: 0.75rem 1rem; border-radius: 1.25rem; line-height: 1.4; word-wrap: break-word; position: relative; }
        .message .time { font-size: 0.7rem; color: #64748b; margin-top: 4px; }
        .message.user { background-color: #ffffff; color: #1e293b; align-self: flex-start; border: 1px solid #e2e8f0; border-bottom-left-radius: 0.5rem; }
        .message.user .time { text-align: left; }
        .message.admin { background-color: #1e293b; color: white; align-self: flex-end; border-bottom-right-radius: 0.5rem; }
        .message.admin .time { text-align: right; color: #94a3b8;}
        .dropdown:hover .dropdown-menu { display: block; }
        .dropdown-menu { display: none; }
    </style>
</head>
<body class="bg-slate-100">
    <div class="flex h-screen overflow-hidden">

        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <main class="flex-1 flex flex-col bg-white">
            <div class="flex flex-grow overflow-hidden">
                <div class="w-full md:w-1/3 lg:w-1/4 border-r flex flex-col">
                    <div class="p-4 border-b">
                         <h1 class="text-xl font-bold text-slate-900">Mensajes</h1>
                         <div class="relative mt-2">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input type="text" id="search-conversations" placeholder="Buscar por nombre..." class="w-full pl-10 pr-4 py-2 border rounded-lg text-sm">
                         </div>
                    </div>
                    <div id="conversations-list" class="flex-grow overflow-y-auto">
                        <div class="p-4 text-center text-slate-400">Cargando...</div>
                    </div>
                </div>

                <div class="w-full md:w-2/3 lg:w-3/4 flex flex-col bg-slate-50">
                    <div id="chat-view" class="flex-grow flex flex-col h-full">
                        <div class="m-auto text-center text-slate-400">
                            <i class="fas fa-comments text-6xl mb-4"></i>
                            <h2 class="text-xl font-bold">Bienvenido al Centro de Mensajes</h2>
                            <p>Selecciona una conversación para empezar a chatear.</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Referencias a Elementos del DOM ---
    const conversationsList = document.getElementById('conversations-list');
    const chatView = document.getElementById('chat-view');
    const searchInput = document.getElementById('search-conversations');

    // --- Variables de Estado ---
    let activeConversationId = null;
    let listPollingInterval = null;
    let activeChatPollingInterval = null;
    let allConversations = [];

    // --- Funciones de Ayuda (Helpers) ---
    const getInitials = (name) => {
        if (!name) return '?';
        const names = name.split(' ');
        let initials = names[0].substring(0, 1).toUpperCase();
        if (names.length > 1) {
            initials += names[names.length - 1].substring(0, 1).toUpperCase();
        }
        return initials;
    };

    const timeAgo = (date) => {
        if (!date) return '';
        const seconds = Math.floor((new Date() - new Date(date)) / 1000);
        let interval = seconds / 31536000;
        if (interval > 1) return `hace ${Math.floor(interval)} año${Math.floor(interval) > 1 ? 's' : ''}`;
        interval = seconds / 2592000;
        if (interval > 1) return `hace ${Math.floor(interval)} mes${Math.floor(interval) > 1 ? 'es' : ''}`;
        interval = seconds / 86400;
        if (interval > 1) return `hace ${Math.floor(interval)} día${Math.floor(interval) > 1 ? 's' : ''}`;
        interval = seconds / 3600;
        if (interval > 1) return `hace ${Math.floor(interval)} hora${Math.floor(interval) > 1 ? 's' : ''}`;
        interval = seconds / 60;
        if (interval > 1) return `hace ${Math.floor(interval)} min`;
        return "Ahora mismo";
    };

    const formatMessageTime = (date) => new Date(date).toLocaleTimeString('es-CO', { hour: 'numeric', minute: '2-digit', hour12: true });

    // --- Funciones Principales de Renderizado y Lógica ---

    const renderConversations = (conversations) => {
        const filter = searchInput.value.toLowerCase();
        const filteredConversations = conversations.filter(c => c.name && c.name.toLowerCase().includes(filter));

        conversationsList.innerHTML = ''; // Limpiar antes de renderizar

        if (filteredConversations.length === 0) {
            conversationsList.innerHTML = `<p class="p-4 text-slate-500 text-center">No se encontraron conversaciones.</p>`;
            return;
        }

        filteredConversations.forEach(conv => {
            const initials = getInitials(conv.name);
            const div = document.createElement('div');
            div.className = `conversation-item flex items-center p-3 cursor-pointer hover:bg-slate-100 border-b space-x-3 ${conv.id === activeConversationId ? 'active' : ''}`;
            div.dataset.id = conv.id;

            div.innerHTML = `
                <div class="relative flex-shrink-0">
                    <div class="w-12 h-12 rounded-full bg-slate-700 text-white flex items-center justify-center font-bold text-lg">${initials}</div>
                    <span class="absolute bottom-0 right-0 block h-3 w-3 rounded-full bg-green-500 border-2 border-white"></span>
                </div>
                <div class="flex-grow overflow-hidden">
                    <div class="flex justify-between items-center">
                        <span class="font-bold text-slate-800 truncate">${conv.name}</span>
                        <span class="text-xs text-slate-500 flex-shrink-0">${timeAgo(conv.last_message_time)}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <p class="text-sm text-slate-500 truncate">${conv.last_message || 'Nueva conversación'}</p>
                        ${parseInt(conv.unread_count) > 0 ? `<span class="bg-red-500 text-white text-xs font-bold rounded-full h-5 w-5 flex items-center justify-center flex-shrink-0">${conv.unread_count}</span>` : ''}
                    </div>
                </div>
            `;
            div.addEventListener('click', () => {
                if(activeConversationId !== conv.id){
                     loadConversation(conv);
                }
            });
            conversationsList.appendChild(div);
        });
    };

    const addMessageToScreen = (container, msg) => {
        if (!container) return;
        const messageDiv = document.createElement('div');
        messageDiv.classList.add('message', msg.sender_type);
        messageDiv.innerHTML = `<p>${msg.message.replace(/</g, "&lt;").replace(/>/g, "&gt;")}</p><div class="time">${formatMessageTime(msg.timestamp)}</div>`;
        container.appendChild(messageDiv);
        container.scrollTop = container.scrollHeight;
    };

    const loadConversation = async (convData) => {
        activeConversationId = convData.id;
        if (activeChatPollingInterval) clearInterval(activeChatPollingInterval);

        renderConversations(allConversations);

        chatView.innerHTML = `
            <div class="p-4 border-b flex justify-between items-center bg-white">
                <div class="flex items-center space-x-3">
                    <div class="relative flex-shrink-0"><div class="w-10 h-10 rounded-full bg-slate-700 text-white flex items-center justify-center font-bold">${getInitials(convData.name)}</div><span class="absolute bottom-0 right-0 block h-2.5 w-2.5 rounded-full bg-green-500 border-2 border-white"></span></div>
                    <div><h2 class="text-lg font-bold text-slate-800">${convData.name}</h2><p class="text-xs text-green-600 font-semibold">Activo ahora</p></div>
                </div>
                <div class="relative dropdown"><button class="text-slate-500 hover:text-slate-800 p-2 rounded-full"><i class="fas fa-ellipsis-v"></i></button><div class="dropdown-menu absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg z-10 border"><a href="users.php?search=${convData.email}" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-100">Ver Perfil</a><button data-id="${convData.id}" class="close-conversation-btn block w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-slate-100">Cerrar Chat</button></div></div>
            </div>
            <div class="chat-messages flex-grow p-4 lg:p-6 overflow-y-auto"></div>
            <div class="p-4 bg-white border-t"><div class="flex items-center bg-slate-100 rounded-lg p-2"><input type="text" id="admin-message-input" class="w-full bg-transparent px-2 text-slate-800 focus:outline-none" placeholder="Escribe tu respuesta..."><button class="p-2 text-slate-500 hover:text-slate-800"><i class="fas fa-paperclip"></i></button><button class="p-2 text-slate-500 hover:text-slate-800"><i class="fas fa-smile"></i></button><button id="send-admin-message" class="bg-slate-800 text-white font-bold w-10 h-10 rounded-lg hover:bg-slate-900 ml-2 flex-shrink-0"><i class="fas fa-paper-plane"></i></button></div></div>`;

        const messagesContainer = chatView.querySelector('.chat-messages');
        const messageInput = document.getElementById('admin-message-input');
        const sendButton = document.getElementById('send-admin-message');

        chatView.querySelector('.close-conversation-btn').addEventListener('click', closeConversation);

        try {
            const formData = new FormData();
            formData.append('action', 'getAdminHistory');
            formData.append('conversation_id', convData.id);
            const response = await fetch('../ajax/chat_handler.php', { method: 'POST', body: formData });
            const result = await response.json();

            messagesContainer.innerHTML = '';
            if (result.success && result.messages.length > 0) {
                result.messages.forEach(msg => addMessageToScreen(messagesContainer, msg));
            } else {
                 messagesContainer.innerHTML = '<p class="text-center text-slate-400">No hay mensajes. ¡Sé el primero en saludar!</p>';
            }
        } catch (error) { console.error("Error loading messages:", error); }
        finally {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        messageInput.focus();
        sendButton.addEventListener('click', () => sendAdminReply(convData.id, messageInput, messagesContainer));
        messageInput.addEventListener('keypress', (e) => { if (e.key === 'Enter') sendAdminReply(convData.id, messageInput, messagesContainer); });

        activeChatPollingInterval = setInterval(() => pollForActiveChatMessages(activeConversationId, messagesContainer), 3000);
    };

    const pollForActiveChatMessages = async (conversationId, container) => {
        if (!conversationId || !document.contains(container)) {
            if(activeChatPollingInterval) clearInterval(activeChatPollingInterval);
            return;
        }
        try {
            const formData = new FormData();
            formData.append('action', 'getNewMessagesForAdmin');
            formData.append('conversation_id', conversationId);
            const response = await fetch('../ajax/chat_handler.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (result.success && result.messages.length > 0) {
                result.messages.forEach(msg => addMessageToScreen(container, msg));
            }
        } catch (error) { console.error("Error polling for active chat:", error); }
    };

    const sendAdminReply = async (conversationId, input, container) => {
        const messageText = input.value.trim();
        if (messageText === '') return;

        const tempMsg = { message: messageText, sender_type: 'admin', timestamp: new Date().toISOString() };
        addMessageToScreen(container, tempMsg);
        input.value = '';
        input.focus();

        try {
            const formData = new FormData();
            formData.append('action', 'sendAdminMessage');
            formData.append('message', messageText);
            formData.append('conversation_id', conversationId);
            await fetch('../ajax/chat_handler.php', { method: 'POST', body: formData });
        } catch (error) { console.error("Error sending reply:", error); }
    };

    const closeConversation = async (event) => {
        const conversationId = event.target.dataset.id;
        if (!confirm('¿Estás seguro de que quieres cerrar esta conversación?')) return;
        try {
            const formData = new FormData();
            formData.append('action', 'closeConversation');
            formData.append('conversation_id', conversationId);
            const response = await fetch('../ajax/chat_handler.php', { method: 'POST', body: formData });
            const result = await response.json();
            if(result.success) {
                chatView.innerHTML = `<div class="m-auto text-center text-slate-400"><i class="fas fa-check-circle text-6xl mb-4 text-green-500"></i><h2 class="text-xl font-bold">Conversación Cerrada</h2></div>`;
                activeConversationId = null;
                fetchUpdates();
            }
        } catch(error) { console.error("Error closing conversation:", error); }
    };

    const fetchUpdates = async () => {
        try {
            const response = await fetch(`../ajax/chat_handler.php?action=getConversations&t=${new Date().getTime()}`);
            const result = await response.json();
            if (result.success) {
                allConversations = result.conversations;
                renderConversations(allConversations);
            } else {
                console.error("Error from server:", result.error, result.details || '');
                conversationsList.innerHTML = `<div class="p-4 text-red-500"><strong>Error del Servidor:</strong><p>${result.error}</p></div>`;
            }
        } catch (error) {
            console.error("Error fetching updates:", error);
            conversationsList.innerHTML = `<p class="p-4 text-red-500">Error de red o respuesta inválida del servidor. Revisa la consola para más detalles.</p>`;
        }
    };

    // --- Inicialización del Script ---
    searchInput.addEventListener('input', () => renderConversations(allConversations));
    fetchUpdates();
    if (listPollingInterval) clearInterval(listPollingInterval);
    listPollingInterval = setInterval(fetchUpdates, 5000);
});
</script>
</body>
</html>
