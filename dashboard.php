<?php
// dashboard.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/config.php';

$user_id = $_SESSION['user_id'];
$user = null;
$transactions = null;
$withdrawals = null;
$error_message = '';
$current_monthly_volume = 0;

try {
    // 1. Obtener datos del usuario, incluyendo los nuevos campos de seguridad
    $user_stmt = $conn->prepare("SELECT name, email, balance, created_at, last_login_at, last_login_ip FROM users WHERE id = ?");
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    if ($user_result->num_rows === 0) {
        session_destroy();
        header("Location: login.php?error=user_not_found");
        exit;
    }
    $user = $user_result->fetch_assoc();
    $user_stmt->close();

    // 2. Calcular el volumen mensual del usuario
    $monthly_volume_stmt = $conn->prepare("SELECT SUM(amount) as monthly_total FROM transactions WHERE (buyer_id = ? OR seller_id = ?) AND status != 'cancelled' AND created_at >= DATE_FORMAT(NOW() ,'%Y-%m-01')");
    $monthly_volume_stmt->bind_param("ii", $user_id, $user_id);
    $monthly_volume_stmt->execute();
    $monthly_result = $monthly_volume_stmt->get_result()->fetch_assoc();
    $current_monthly_volume = $monthly_result['monthly_total'] ?? 0;
    $monthly_volume_stmt->close();

    // 3. Obtener historial de transacciones del usuario
    $tx_stmt = $conn->prepare("SELECT * FROM transactions WHERE buyer_id = ? OR seller_id = ? ORDER BY created_at DESC");
    $tx_stmt->bind_param("ii", $user_id, $user_id);
    $tx_stmt->execute();
    $transactions = $tx_stmt->get_result();

    // 4. Obtener historial de retiros del usuario
    $wd_stmt = $conn->prepare("SELECT amount, status, created_at, completed_at FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $wd_stmt->bind_param("i", $user_id);
    $wd_stmt->execute();
    $withdrawals = $wd_stmt->get_result();

} catch (Exception $e) {
    $error_message = "Error al cargar los datos del panel: " . $e->getMessage();
}

$monthly_usage_percentage = (defined('MONTHLY_VOLUME_LIMIT') && MONTHLY_VOLUME_LIMIT > 0) ? ($current_monthly_volume / MONTHLY_VOLUME_LIMIT) * 100 : 0;
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
    <title>Mi Panel - Interpago</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; } /* slate-100 */
        .status-pill { padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
        .status-initiated { background-color: #e0e7ff; color: #3730a3; } /* indigo-100 / indigo-700 */
        .status-funded { background-color: #dbeafe; color: #1d4ed8; } /* blue-100 / blue-700 */
        .status-shipped { background-color: #fef9c3; color: #a16207; } /* yellow-100 / yellow-700 */
        .status-received { background-color: #dcfce7; color: #166534; } /* green-100 / green-800 */
        .status-released { background-color: #d1fae5; color: #059669; } /* green-200 / green-600 */
        .status-dispute, .status-cancelled { background-color: #fee2e2; color: #b91c1c; } /* red-100 / red-700 */
        .withdrawal-pending { background-color: #fef9c3; color: #a16207; }
        .withdrawal-completed { background-color: #dcfce7; color: #166534; }
    </style>
</head>
<body class="bg-slate-100">
    <div class="flex">
        <!-- Barra lateral de Usuario -->
        <aside class="w-64 bg-white text-slate-800 min-h-screen p-4 flex-shrink-0 border-r border-slate-200">
            <div class="text-center mb-10">
                 <a href="index.php" class="inline-block">
                    <svg class="h-10 w-10 text-slate-800 mx-auto" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" fill="currentColor">
                        <path d="M 45,10 C 25,10 10,25 10,45 L 10,55 C 10,75 25,90 45,90 L 55,90 C 60,90 60,85 55,80 L 45,80 C 30,80 20,70 20,55 L 20,45 C 20,30 30,20 45,20 L 55,20 C 60,20 60,15 55,10 Z"/>
                        <path d="M 55,90 C 75,90 90,75 90,55 L 90,45 C 90,25 75,10 55,10 L 45,10 C 40,10 40,15 45,20 L 55,20 C 70,20 80,30 80,45 L 80,55 C 80,70 70,80 55,80 L 45,80 C 40,80 40,85 45,90 Z"/>
                    </svg>
                    <h1 class="text-2xl font-bold mt-2 text-slate-900">Interpago</h1>
                </a>
            </div>
            <nav class="flex-grow">
                <ul class="space-y-2">
                    <li><a href="dashboard.php" class="flex items-center p-3 rounded-lg bg-slate-200 text-slate-900 font-bold"><i class="fas fa-home w-6 text-slate-600"></i><span class="ml-3">Panel Principal</span></a></li>
                    <li><a href="index.php#create-transaction-form" class="flex items-center p-3 rounded-lg hover:bg-slate-100 text-slate-600"><i class="fas fa-plus-circle w-6"></i><span class="ml-3">Nueva Transacción</span></a></li>
                    <li><a href="edit_profile.php" class="flex items-center p-3 rounded-lg hover:bg-slate-100 text-slate-600"><i class="fas fa-user-edit w-6"></i><span class="ml-3">Editar Perfil</span></a></li>
                </ul>
            </nav>
            <div class="mt-auto"><a href="logout.php" class="flex items-center p-3 rounded-lg hover:bg-red-100 text-red-700"><i class="fas fa-sign-out-alt w-6"></i><span class="ml-3">Cerrar Sesión</span></a></div>
        </aside>

        <!-- Contenido Principal -->
        <main class="flex-1 p-6 md:p-10">
            <?php if ($error_message): ?>
                 <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert"><p class="font-bold">Error Crítico</p><p><?php echo htmlspecialchars($error_message); ?></p></div>
            <?php endif; ?>

            <?php if ($user): ?>
                <header class="mb-8">
                    <div><h1 class="text-3xl font-bold text-slate-900">Hola, <?php echo htmlspecialchars(explode(' ', $user['name'])[0]); ?></h1><p class="text-slate-600">Bienvenido a tu panel de control.</p></div>
                </header>

                <div class="bg-slate-800 text-white p-8 rounded-2xl shadow-lg mb-8 flex flex-col md:flex-row justify-between items-center bg-gradient-to-br from-slate-800 to-slate-900">
                    <div><p class="text-lg text-slate-300">Saldo Disponible</p><p class="text-5xl font-extrabold">$<?php echo number_format($user['balance'], 2); ?> <span class="text-3xl font-medium">COP</span></p></div>
                    <a href="request_withdrawal.php" class="mt-4 md:mt-0 bg-white text-slate-800 font-bold py-3 px-6 rounded-lg hover:bg-slate-200 transition-transform transform hover:scale-105"><i class="fas fa-hand-holding-usd mr-2"></i>Solicitar Retiro</a>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                    <div class="bg-white p-6 rounded-2xl shadow-md">
                        <h3 class="font-bold text-slate-800">Límite de Uso Mensual</h3>
                        <p class="text-sm text-slate-500 mb-3">Volumen total de transacciones este mes.</p>
                        <div class="w-full bg-slate-200 rounded-full h-4"><div class="bg-slate-700 h-4 rounded-full" style="width: <?php echo min(100, $monthly_usage_percentage); ?>%"></div></div>
                        <div class="flex justify-between text-sm font-medium text-slate-600 mt-2">
                            <span>$<?php echo number_format($current_monthly_volume, 0); ?></span>
                            <span>$<?php echo number_format(defined('MONTHLY_VOLUME_LIMIT') ? MONTHLY_VOLUME_LIMIT : 0, 0); ?></span>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-2xl shadow-md">
                        <h3 class="font-bold text-slate-800">Información de Seguridad</h3>
                        <p class="text-sm text-slate-500 mb-3">Estos son los datos de tu último acceso.</p>
                        <div class="text-sm text-slate-600 space-y-2">
                            <div class="flex items-center"><i class="fas fa-clock w-5 text-slate-400"></i><span class="ml-2"><?php echo !empty($user['last_login_at']) ? date("d M, Y \a \l\a\s H:i", strtotime($user['last_login_at'])) : 'Primer inicio de sesión'; ?></span></div>
                            <div class="flex items-center"><i class="fas fa-map-marker-alt w-5 text-slate-400"></i><span class="ml-2">IP: <?php echo htmlspecialchars($user['last_login_ip'] ?? 'N/A'); ?></span></div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <div class="lg:col-span-2">
                        <h2 class="text-2xl font-bold text-slate-800 mb-4">Mi Historial de Transacciones</h2>
                        <div class="bg-white rounded-2xl shadow-md overflow-x-auto">
                            <table class="w-full text-sm text-left text-slate-500">
                                <thead class="text-xs text-slate-700 uppercase bg-slate-50"><tr><th class="px-6 py-3">Rol</th><th class="px-6 py-3">Descripción</th><th class="px-6 py-3">Monto</th><th class="px-6 py-3">Estado</th><th class="px-6 py-3">Fecha</th><th class="px-6 py-3">Acción</th></tr></thead>
                                <tbody>
                                    <?php if ($transactions && $transactions->num_rows > 0): ?>
                                        <?php while($tx = $transactions->fetch_assoc()): ?>
                                            <tr class="border-b">
                                                <td class="px-6 py-4">
                                                    <?php
                                                        $role = ($tx['buyer_id'] == $user_id) ? 'Comprador' : 'Vendedor';
                                                        $role_class = ($role == 'Comprador') ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800';
                                                    ?>
                                                    <span class='px-2 py-1 font-semibold rounded-full <?php echo $role_class; ?> text-xs'><?php echo $role; ?></span>
                                                </td>
                                                <td class="px-6 py-4 font-medium text-slate-900"><?php echo htmlspecialchars($tx['product_description']); ?></td>
                                                <td class="px-6 py-4">$<?php echo number_format($tx['amount'], 2); ?></td>
                                                <td class="px-6 py-4">
                                                    <span class="status-pill status-<?php echo htmlspecialchars($tx['status']); ?>">
                                                        <?php echo htmlspecialchars($status_translations[$tx['status']] ?? ucfirst($tx['status'])); ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 text-slate-500"><?php echo date("d M, Y", strtotime($tx['created_at'])); ?></td>
                                                <td class="px-6 py-4">
                                                    <a href="transaction.php?tx_uuid=<?php echo htmlspecialchars($tx['transaction_uuid']); ?>" class="text-slate-600 hover:underline font-medium">Ver Detalles</a>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="text-center py-10 text-slate-500">Aún no tienes transacciones.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="lg:col-span-1">
                         <h2 class="text-2xl font-bold text-slate-800 mb-4">Mis Retiros Recientes</h2>
                         <div class="bg-white rounded-2xl shadow-md p-6 space-y-4">
                            <?php if ($withdrawals && $withdrawals->num_rows > 0): ?>
                                <?php while($wd = $withdrawals->fetch_assoc()): ?>
                                    <div class="flex justify-between items-center border-b pb-3"><div><p class="font-bold text-slate-800">$<?php echo number_format($wd['amount'], 2); ?></p><p class="text-sm text-slate-500"><?php echo date("d M, Y", strtotime($wd['created_at'])); ?></p></div><span class="px-2 py-1 font-semibold rounded-full text-xs withdrawal-<?php echo $wd['status']; ?>"><?php echo ucfirst($wd['status']); ?></span></div>
                                <?php endwhile; ?>
                            <?php else: ?><p class="text-center text-slate-500 py-6">No has realizado retiros.</p><?php endif; ?>
                         </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
