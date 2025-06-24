<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

$user_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$user_id) {
    header("Location: users.php");
    exit;
}

$user = null;
$error_message = '';

try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
    } else {
        $error_message = "Usuario no encontrado.";
    }
} catch (Exception $e) {
    $error_message = "Error al cargar los datos del usuario: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil de Usuario - Administración</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .modal-overlay { transition: opacity 0.3s ease; }
    </style>
</head>
<body class="bg-slate-100">
    <div class="flex">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="flex-1 p-6 md:p-10">
            <header class="mb-8">
                <a href="users.php" class="text-slate-600 hover:text-slate-900 mb-4 block"><i class="fas fa-arrow-left mr-2"></i>Volver a Usuarios</a>
                <h1 class="text-3xl font-bold text-slate-900">Perfil de Usuario</h1>
            </header>

            <?php if ($user): ?>
            <div id="status-badge-container" class="mb-4">
                <?php if ($user['status'] === 'suspended'): ?>
                    <div class="p-4 bg-red-100 text-red-800 rounded-lg font-semibold"><i class="fas fa-ban mr-2"></i>Este usuario está actualmente suspendido.</div>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Columna de Información Principal -->
                <div class="md:col-span-1">
                    <div class="bg-white p-6 rounded-2xl shadow-md text-center">
                        <img src="../<?php echo htmlspecialchars($user['profile_picture'] ?? 'assets/images/default_avatar.png'); ?>" alt="Foto de perfil" class="w-32 h-32 rounded-full mx-auto mb-4 border-4 border-slate-200">
                        <h2 class="text-2xl font-bold text-slate-800"><?php echo htmlspecialchars($user['name']); ?></h2>
                        <p class="text-slate-500"><?php echo htmlspecialchars($user['email']); ?></p>
                        <p class="text-slate-500 font-mono mt-1"><?php echo htmlspecialchars($user['phone_number'] ?? 'N/A'); ?></p>
                        <div class="mt-4">
                            <?php if ($user['verification_status'] === 'verified'): ?>
                                <span class="px-3 py-1 bg-green-100 text-green-700 text-sm font-semibold rounded-full"><i class="fas fa-check-circle mr-1"></i> Verificado</span>
                            <?php else: ?>
                                <span class="px-3 py-1 bg-amber-100 text-amber-700 text-sm font-semibold rounded-full"><i class="fas fa-exclamation-triangle mr-1"></i> Sin Verificar</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Columna de Detalles Adicionales -->
                <div class="md:col-span-2">
                    <div class="bg-white p-6 rounded-2xl shadow-md">
                        <h3 class="text-xl font-bold text-slate-800 mb-4 border-b pb-2">Información Detallada</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                            <div><p class="text-slate-500">Saldo Actual</p><p class="font-bold text-lg text-blue-600">$<?php echo number_format($user['balance'], 2); ?></p></div>
                            <div><p class="text-slate-500">Miembro Desde</p><p class="font-semibold text-slate-700"><?php echo date("d M, Y", strtotime($user['created_at'])); ?></p></div>
                             <div><p class="text-slate-500">Documento de Identidad</p><p class="font-semibold text-slate-700"><?php echo htmlspecialchars($user['document_type'] ?? 'N/A'); ?> - <?php echo htmlspecialchars($user['document_number'] ?? 'N/A'); ?></p></div>
                             <div><p class="text-slate-500">Fecha de Nacimiento</p><p class="font-semibold text-slate-700"><?php echo htmlspecialchars($user['birth_date'] ?? 'N/A'); ?></p></div>
                            <div><p class="text-slate-500">Último Inicio de Sesión</p><p class="font-semibold text-slate-700"><?php echo $user['last_login_at'] ? date("d M, Y H:i", strtotime($user['last_login_at'])) : 'N/A'; ?></p></div>
                            <div><p class="text-slate-500">IP de Último Login</p><p class="font-semibold text-slate-700 font-mono"><?php echo htmlspecialchars($user['last_login_ip'] ?? 'N/A'); ?></p></div>
                        </div>
                        <div class="mt-6 pt-4 border-t">
                            <h4 class="font-bold text-slate-600 mb-2">Acciones Rápidas</h4>
                            <div class="flex space-x-2">
                                <a href="transactions.php?user_id=<?php echo $user['id']; ?>" class="bg-slate-200 text-slate-700 hover:bg-slate-300 text-xs font-bold py-2 px-3 rounded-lg">Ver Transacciones</a>
                                <button id="suspend-btn" data-userid="<?php echo $user['id']; ?>" data-status="<?php echo $user['status']; ?>" class="<?php echo $user['status'] === 'suspended' ? 'bg-green-100 text-green-700 hover:bg-green-200' : 'bg-red-100 text-red-700 hover:bg-red-200'; ?> text-xs font-bold py-2 px-3 rounded-lg">
                                    <?php echo $user['status'] === 'suspended' ? 'Reactivar Usuario' : 'Suspender Usuario'; ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php elseif ($error_message): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert"><strong class="font-bold">Error:</strong><span class="block sm:inline"><?php echo $error_message; ?></span></div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Modal de Confirmación -->
    <div id="confirmation-modal" class="fixed inset-0 bg-slate-900 bg-opacity-50 hidden items-center justify-center modal-overlay">
        <div class="bg-white p-8 rounded-2xl shadow-xl max-w-sm w-full">
            <h2 id="modal-title" class="text-xl font-bold text-slate-800 mb-4"></h2>
            <p id="modal-text" class="text-slate-600 mb-6"></p>
            <div class="flex justify-end space-x-4">
                <button id="modal-cancel-btn" class="bg-slate-200 text-slate-700 font-bold py-2 px-4 rounded-lg hover:bg-slate-300">Cancelar</button>
                <button id="modal-confirm-btn" class="bg-red-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-red-700"></button>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const suspendBtn = document.getElementById('suspend-btn');
        const modal = document.getElementById('confirmation-modal');
        if (!suspendBtn || !modal) return;

        const modalTitle = document.getElementById('modal-title');
        const modalText = document.getElementById('modal-text');
        const modalConfirmBtn = document.getElementById('modal-confirm-btn');
        const modalCancelBtn = document.getElementById('modal-cancel-btn');

        suspendBtn.addEventListener('click', function() {
            const userId = this.dataset.userid;
            const currentStatus = this.dataset.status;
            const action = currentStatus === 'suspended' ? 'reactivate' : 'suspend';

            if (action === 'suspend') {
                modalTitle.textContent = '¿Suspender Usuario?';
                modalText.textContent = 'Esta acción impedirá que el usuario inicie sesión y participe en transacciones. ¿Estás seguro?';
                modalConfirmBtn.textContent = 'Sí, Suspender';
                modalConfirmBtn.className = 'bg-red-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-red-700';
            } else {
                modalTitle.textContent = '¿Reactivar Usuario?';
                modalText.textContent = 'El usuario podrá volver a acceder a la plataforma. ¿Estás seguro?';
                modalConfirmBtn.textContent = 'Sí, Reactivar';
                modalConfirmBtn.className = 'bg-green-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-green-700';
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');

            modalConfirmBtn.onclick = () => {
                performUserAction(userId, action);
            };
        });

        modalCancelBtn.addEventListener('click', () => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        });

        async function performUserAction(userId, action) {
            modalConfirmBtn.disabled = true;
            modalConfirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('action', action);

            try {
                const response = await fetch('../ajax/user_actions.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    window.location.reload(); // Recargar la página para ver los cambios
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Ocurrió un error de red. Intenta de nuevo.');
            } finally {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                modalConfirmBtn.disabled = false;
            }
        }
    });
    </script>
</body>
</html>
