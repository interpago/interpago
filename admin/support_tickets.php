<?php
// RUTA: /admin/support_tickets.php
// ===============================================
// Propósito: Lista todos los tickets de soporte para el administrador.
// ===============================================

require_once __DIR__ . '/../config.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$status_filter = isset($_GET['status']) ? $_GET['status'] : 'abierto';

$query = "SELECT st.*, u.name as user_name FROM support_tickets st JOIN users u ON st.user_id = u.id";
if ($status_filter === 'abierto' || $status_filter === 'cerrado') {
    $query .= " WHERE st.status = '$status_filter'";
}
$query .= " ORDER BY st.admin_unread DESC, st.updated_at DESC";
$tickets = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tickets de Soporte - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-100 flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-1 p-6 md:p-10">
        <h1 class="text-3xl font-bold text-slate-900 mb-6">Tickets de Soporte</h1>

        <div class="mb-4 bg-white p-2 rounded-lg inline-flex space-x-1 shadow-sm">
            <a href="?status=abierto" class="px-4 py-2 rounded-md text-sm font-medium <?php echo $status_filter === 'abierto' ? 'bg-blue-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100'; ?>">Abiertos</a>
            <a href="?status=cerrado" class="px-4 py-2 rounded-md text-sm font-medium <?php echo $status_filter === 'cerrado' ? 'bg-blue-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100'; ?>">Cerrados</a>
            <a href="?" class="px-4 py-2 rounded-md text-sm font-medium <?php echo !in_array($status_filter, ['abierto', 'cerrado']) ? 'bg-blue-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100'; ?>">Todos</a>
        </div>

        <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
            <ul class="divide-y divide-slate-200">
                <?php while($ticket = $tickets->fetch_assoc()): ?>
                    <li class="p-4 hover:bg-slate-50">
                        <a href="view_ticket.php?id=<?php echo $ticket['id']; ?>" class="flex justify-between items-center">
                            <div>
                                <p class="font-semibold <?php echo $ticket['admin_unread'] ? 'text-blue-600' : 'text-slate-800'; ?>">
                                    <?php if($ticket['admin_unread']): ?><i class="fas fa-circle text-blue-500 text-xs mr-2"></i><?php endif; ?>
                                    <?php echo htmlspecialchars($ticket['subject']); ?>
                                </p>
                                <p class="text-sm text-slate-500 ml-6">De: <?php echo htmlspecialchars($ticket['user_name']); ?></p>
                            </div>
                            <span class="text-xs text-slate-500">Última act: <?php echo date('d/m/y H:i', strtotime($ticket['updated_at'])); ?></span>
                        </a>
                    </li>
                <?php endwhile; ?>
            </ul>
        </div>
    </main>
</body>
</html>
