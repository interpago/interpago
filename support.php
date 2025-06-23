<?php
// RUTA: /support.php (Formulario Mejorado)
// ===============================================
// Propósito: Página principal para que los usuarios vean sus tickets y creen nuevos.
// ===============================================

require_once __DIR__ . '/config.php';
session_start();

// Redirige si el usuario no ha iniciado sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$user_id = $_SESSION['user_id'];

// Lógica para crear un nuevo ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_ticket'])) {
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);
    $category = trim($_POST['category']);
    $transaction_id = !empty($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : null;

    if (!empty($subject) && !empty($message) && !empty($category)) {
        $conn->begin_transaction();
        try {
            // Inserta el ticket en la base de datos
            $stmt = $conn->prepare("INSERT INTO support_tickets (user_id, transaction_id, subject, category) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiss", $user_id, $transaction_id, $subject, $category);
            $stmt->execute();
            $ticket_id = $stmt->insert_id;

            // Inserta el primer mensaje del ticket
            $msg_stmt = $conn->prepare("INSERT INTO support_messages (ticket_id, sender_role, message) VALUES (?, 'usuario', ?)");
            $msg_stmt->bind_param("is", $ticket_id, $message);
            $msg_stmt->execute();

            $conn->commit();
            header("Location: view_ticket.php?id=$ticket_id");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error al crear el ticket: " . $e->getMessage();
        }
    } else {
        $error = "Por favor, completa todos los campos requeridos.";
    }
}

// Obtiene todos los tickets del usuario actual
$tickets_stmt = $conn->prepare("SELECT * FROM support_tickets WHERE user_id = ? ORDER BY updated_at DESC");
$tickets_stmt->bind_param("i", $user_id);
$tickets_stmt->execute();
$tickets = $tickets_stmt->get_result();

// Obtener transacciones del usuario para el formulario
$user_transactions_stmt = $conn->prepare("SELECT id, transaction_uuid, product_description FROM transactions WHERE buyer_id = ? OR seller_id = ? ORDER BY created_at DESC");
$user_transactions_stmt->bind_param("ii", $user_id, $user_id);
$user_transactions_stmt->execute();
$user_transactions = $user_transactions_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centro de Soporte - Interpago</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .form-input-group {
            position: relative;
        }
        .form-input-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af; /* gray-400 */
        }
        .form-input, .form-select {
            padding-left: 2.5rem;
            border-radius: 0.5rem;
            border: 1px solid #d1d5db; /* gray-300 */
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #3b82f6; /* blue-500 */
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.4);
        }
        /* Modal Animation */
        #new-ticket-modal {
            transition: opacity 0.3s ease-in-out;
        }
        #new-ticket-modal-content {
            transition: transform 0.3s ease-in-out;
        }
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
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-slate-800">Centro de Soporte</h1>
            <button id="open-modal-btn" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 shadow-md transition-all">
                <i class="fas fa-plus mr-2"></i>Nuevo Ticket
            </button>
        </div>

        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">Error:</strong>
                <span class="block sm:inline"><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <!-- Lista de Tickets -->
        <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
            <ul class="divide-y divide-slate-200">
                <?php if ($tickets->num_rows > 0): ?>
                    <?php while($ticket = $tickets->fetch_assoc()): ?>
                        <li class="p-4 hover:bg-slate-50 transition-colors">
                            <a href="view_ticket.php?id=<?php echo $ticket['id']; ?>" class="flex justify-between items-center">
                                <div>
                                    <p class="font-semibold <?php echo $ticket['user_unread'] ? 'text-blue-600' : 'text-slate-800'; ?>">
                                        <?php echo htmlspecialchars($ticket['subject']); ?>
                                    </p>
                                    <p class="text-sm text-slate-500">Ticket #<?php echo $ticket['id']; ?> - Última actualización: <?php echo date('d/m/Y H:i', strtotime($ticket['updated_at'])); ?></p>
                                </div>
                                <span class="text-xs font-semibold uppercase px-2 py-1 rounded-full <?php echo $ticket['status'] === 'abierto' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo htmlspecialchars($ticket['status']); ?>
                                </span>
                            </a>
                        </li>
                    <?php endwhile; ?>
                <?php else: ?>
                    <li class="p-6 text-center text-slate-500">No tienes tickets de soporte. ¡Crea uno para empezar!</li>
                <?php endif; ?>
            </ul>
        </div>
    </main>

    <!-- Modal para Nuevo Ticket -->
    <div id="new-ticket-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 opacity-0 pointer-events-none">
        <div id="new-ticket-modal-content" class="bg-white rounded-lg shadow-xl p-8 w-full max-w-lg transform scale-95">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-slate-800">Abrir Nuevo Ticket de Soporte</h2>
                <button id="close-modal-btn" class="text-slate-500 hover:text-slate-800">&times;</button>
            </div>
            <form action="support.php" method="POST" class="space-y-5">

                <div>
                    <label for="category" class="block text-sm font-medium text-slate-700 mb-1">Categoría</label>
                    <div class="form-input-group">
                        <i class="fas fa-tags form-input-icon"></i>
                        <select name="category" id="category" class="w-full p-3 form-select" required>
                            <option value="">Selecciona una categoría...</option>
                            <option value="duda_general">Duda General</option>
                            <option value="problema_pago">Problema con un Pago</option>
                            <option value="problema_transaccion">Problema con una Transacción</option>
                            <option value="verificacion_cuenta">Verificación de Cuenta</option>
                            <option value="disputa">Disputa o Mediación</option>
                            <option value="otro">Otro</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="transaction_id" class="block text-sm font-medium text-slate-700 mb-1">Transacción Relacionada (Opcional)</label>
                    <div class="form-input-group">
                         <i class="fas fa-receipt form-input-icon"></i>
                        <select name="transaction_id" id="transaction_id" class="w-full p-3 form-select">
                            <option value="">Ninguna</option>
                            <?php while($tx = $user_transactions->fetch_assoc()): ?>
                                <option value="<?php echo $tx['id']; ?>">
                                    #<?php echo substr($tx['transaction_uuid'], 0, 8); ?>... - <?php echo htmlspecialchars($tx['product_description']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="subject" class="block text-sm font-medium text-slate-700 mb-1">Asunto</label>
                     <div class="form-input-group">
                        <i class="fas fa-pen form-input-icon"></i>
                        <input type="text" name="subject" id="subject" class="w-full p-3 form-input" placeholder="Ej: No he recibido mi pago" required>
                    </div>
                </div>

                <div>
                    <label for="message" class="block text-sm font-medium text-slate-700 mb-1">Describe tu consulta</label>
                    <textarea name="message" id="message" rows="4" class="w-full p-3 border border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" placeholder="Por favor, incluye todos los detalles posibles..." required></textarea>
                </div>

                <div class="flex justify-end space-x-4 pt-4">
                    <button type="button" id="cancel-btn" class="bg-slate-200 text-slate-800 px-4 py-2 rounded-lg hover:bg-slate-300">Cancelar</button>
                    <button type="submit" name="create_ticket" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">Crear Ticket</button>
                </div>
            </form>
        </div>
    </div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('new-ticket-modal');
        const modalContent = document.getElementById('new-ticket-modal-content');
        const openBtn = document.getElementById('open-modal-btn');
        const closeBtn = document.getElementById('close-modal-btn');
        const cancelBtn = document.getElementById('cancel-btn');

        function openModal() {
            modal.classList.remove('opacity-0', 'pointer-events-none');
            modalContent.classList.remove('scale-95');
        }

        function closeModal() {
            modal.classList.add('opacity-0');
            modalContent.classList.add('scale-95');
            setTimeout(() => {
                modal.classList.add('pointer-events-none');
            }, 300); // Wait for animation to finish
        }

        openBtn.addEventListener('click', openModal);
        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);

        // Close modal if clicking outside the content
        modal.addEventListener('click', function(event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    });
</script>

</body>
</html>
