<?php
// admin/index.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

// Inicializar variables de estadísticas
$stats = [
    'total_users' => 0,
    'total_transactions' => 0,
    'total_volume' => 0,
    'total_commission' => 0,
    'pending_withdrawals' => 0
];
$recent_transactions = [];
$pending_withdrawals_list = [];
$error_message = '';

try {
    // Obtener estadísticas generales
    $stats_result = $conn->query("
        SELECT
            (SELECT COUNT(*) FROM users) as total_users,
            (SELECT COUNT(*) FROM transactions) as total_transactions,
            (SELECT SUM(amount) FROM transactions WHERE status = 'released') as total_volume,
            (SELECT SUM(commission) FROM transactions WHERE status = 'released') as total_commission,
            (SELECT COUNT(*) FROM withdrawals WHERE status = 'pending') as pending_withdrawals
    ");
    if ($stats_result) {
        $stats = $stats_result->fetch_assoc();
    }

    // Obtener últimas 5 transacciones
    $recent_tx_result = $conn->query("SELECT * FROM transactions ORDER BY created_at DESC LIMIT 5");
    if ($recent_tx_result) {
        while($row = $recent_tx_result->fetch_assoc()) {
            $recent_transactions[] = $row;
        }
    }

    // Obtener solicitudes de retiro pendientes
    $pending_wd_result = $conn->query("
        SELECT w.*, u.name as user_name
        FROM withdrawals w
        JOIN users u ON w.user_id = u.id
        WHERE w.status = 'pending'
        ORDER BY w.created_at ASC
    ");
    if ($pending_wd_result) {
        while($row = $pending_wd_result->fetch_assoc()) {
            $pending_withdrawals_list[] = $row;
        }
    }

} catch (Exception $e) {
    $error_message = "Error al cargar los datos del panel: " . $e->getMessage();
}

$status_translations = [
    'initiated' => 'Iniciado', 'funded' => 'En Custodia', 'shipped' => 'Enviado',
    'received' => 'Recibido', 'released' => 'Liberado', 'dispute' => 'En Disputa', 'cancelled' => 'Cancelado'
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Administración</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-slate-100">
    <div class="flex">
        <!-- Barra lateral -->
        <aside class="w-64 bg-slate-800 text-white min-h-screen p-4 flex-shrink-0 flex flex-col">
            <div class="text-center mb-10">
                <a href="index.php" class="flex items-center justify-center space-x-2">
                    <i class="fas fa-shield-alt text-3xl"></i>
                    <h1 class="text-2xl font-bold">Admin Interpago</h1>
                </a>
            </div>
            <nav class="flex-grow">
                <ul class="space-y-2">
                    <li><a href="index.php" class="flex items-center p-3 rounded-lg bg-slate-900"><i class="fas fa-tachometer-alt w-6"></i><span class="ml-3">Dashboard</span></a></li>
                    <li><a href="transactions.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700"><i class="fas fa-list-ul w-6"></i><span class="ml-3">Transacciones</span></a></li>
                    <li><a href="withdrawals.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700"><i class="fas fa-hand-holding-usd w-6"></i><span class="ml-3">Retiros</span></a></li>
                    <li><a href="verifications.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700"><i class="fas fa-user-check w-6"></i><span class="ml-3">Verificaciones</span></a></li>
                    <li><a href="disputes.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700"><i class="fas fa-gavel w-6"></i><span class="ml-3">Disputas</span></a></li>
                </ul>
            </nav>
            <div><a href="logout.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700"><i class="fas fa-sign-out-alt w-6"></i><span class="ml-3">Cerrar Sesión</span></a></div>
        </aside>

        <!-- Contenido Principal -->
        <main class="flex-1 p-6 md:p-10 overflow-y-auto">
            <header class="mb-8">
                <h1 class="text-3xl font-bold text-slate-900">Dashboard General</h1>
                <p class="text-slate-600">Resumen de la actividad en la plataforma.</p>
            </header>

            <?php if ($error_message): ?>
                 <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p class="font-bold">Error Crítico</p>
                    <p><?php echo htmlspecialchars($error_message); ?></p>
                </div>
            <?php endif; ?>

            <!-- Tarjetas de Estadísticas -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white p-6 rounded-2xl shadow-md"><p class="text-sm text-slate-500">Usuarios Totales</p><p class="text-3xl font-bold text-slate-800"><?php echo $stats['total_users']; ?></p></div>
                <div class="bg-white p-6 rounded-2xl shadow-md"><p class="text-sm text-slate-500">Transacciones Totales</p><p class="text-3xl font-bold text-slate-800"><?php echo $stats['total_transactions']; ?></p></div>
                <div class="bg-white p-6 rounded-2xl shadow-md"><p class="text-sm text-slate-500">Volumen Transado</p><p class="text-3xl font-bold text-slate-800">$<?php echo number_format($stats['total_volume'], 0); ?></p></div>
                <div class="bg-white p-6 rounded-2xl shadow-md"><p class="text-sm text-slate-500">Comisiones Generadas</p><p class="text-3xl font-bold text-green-600">$<?php echo number_format($stats['total_commission'], 0); ?></p></div>
            </div>

            <!-- Transacciones Recientes -->
            <h2 class="text-2xl font-bold text-slate-800 mb-4">Transacciones Recientes</h2>
            <div class="bg-white rounded-2xl shadow-md overflow-x-auto">
                 <table class="w-full text-sm text-left text-slate-500">
                    <thead class="text-xs text-slate-700 uppercase bg-slate-50"><tr><th class="px-6 py-3">ID</th><th class="px-6 py-3">Vendedor</th><th class="px-6 py-3">Comprador</th><th class="px-6 py-3">Monto</th><th class="px-6 py-3">Estado</th></tr></thead>
                    <tbody>
                        <?php if (!empty($recent_transactions)): ?>
                            <?php foreach($recent_transactions as $tx): ?>
                                <tr class="border-b"><td class="px-6 py-4"><a href="../transaction.php?tx_uuid=<?php echo $tx['transaction_uuid']; ?>&user_id=admin" class="font-medium text-blue-600 hover:underline"><?php echo substr($tx['transaction_uuid'], 0, 8); ?>...</a></td><td class="px-6 py-4"><?php echo htmlspecialchars($tx['seller_name']); ?></td><td class="px-6 py-4"><?php echo htmlspecialchars($tx['buyer_name']); ?></td><td class="px-6 py-4 font-semibold">$<?php echo number_format($tx['amount']); ?></td><td class="px-6 py-4"><span class="px-2 py-1 font-semibold leading-tight text-xs rounded-full bg-slate-100 text-slate-700"><?php echo $status_translations[$tx['status']] ?? ucfirst($tx['status']); ?></span></td></tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-10 text-slate-500">No hay transacciones recientes.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>
</body>
</html>
