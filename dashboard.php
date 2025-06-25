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

$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page > 0) ? ($page - 1) * $limit : 0;
$total_pages = 0;

try {
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

    $monthly_volume_stmt = $conn->prepare("SELECT SUM(amount) as monthly_total FROM transactions WHERE (buyer_id = ? OR seller_id = ?) AND status != 'cancelled' AND created_at >= DATE_FORMAT(NOW() ,'%Y-%m-01')");
    $monthly_volume_stmt->bind_param("ii", $user_id, $user_id);
    $monthly_volume_stmt->execute();
    $monthly_result = $monthly_volume_stmt->get_result()->fetch_assoc();
    $current_monthly_volume = $monthly_result['monthly_total'] ?? 0;
    $monthly_volume_stmt->close();

    $count_stmt = $conn->prepare("SELECT COUNT(id) as total FROM transactions WHERE buyer_id = ? OR seller_id = ?");
    $count_stmt->bind_param("ii", $user_id, $user_id);
    $count_stmt->execute();
    $total_transactions = $count_stmt->get_result()->fetch_assoc()['total'];
    $total_pages = ceil($total_transactions / $limit);
    $count_stmt->close();

    $tx_stmt = $conn->prepare("SELECT * FROM transactions WHERE buyer_id = ? OR seller_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $tx_stmt->bind_param("iiii", $user_id, $user_id, $limit, $offset);
    $tx_stmt->execute();
    $transactions = $tx_stmt->get_result();

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
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; }
        .status-pill { padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-align: center; display: inline-block;}
        .status-initiated { background-color: #e0e7ff; color: #3730a3; }
        .status-funded { background-color: #dbeafe; color: #1d4ed8; }
        .status-shipped { background-color: #fef9c3; color: #a16207; }
        .status-received { background-color: #dcfce7; color: #166534; }
        .status-released { background-color: #d1fae5; color: #059669; }
        .status-dispute, .status-cancelled { background-color: #fee2e2; color: #b91c1c; }
        .withdrawal-pending { background-color: #fef9c3; color: #a16207; }
        .withdrawal-completed { background-color: #dcfce7; color: #166534; }

        @media (max-width: 1023px) {
            .responsive-table thead { display: none; }
            .responsive-table tr {
                display: block; margin-bottom: 1rem; border-radius: 0.75rem;
                border: 1px solid #e2e8f0; box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.05);
                background-color: white;
            }
            .responsive-table td {
                display: flex; justify-content: space-between; align-items: center;
                padding: 0.75rem 1rem; text-align: right; border-bottom: 1px solid #f1f5f9;
            }
            .responsive-table td:last-child { border-bottom: none; }
            .responsive-table td::before {
                content: attr(data-label); font-weight: 600; text-align: left;
                margin-right: 1rem; color: #475569;
            }
        }

        /* --- Estilos para la Animación Global --- */
        .live-feed-container {
            position: relative;
            width: 100%;
            height: 48px;
            background-color: #1e293b; /* slate-800 */
            overflow: hidden;
            box-shadow: inset 0 -2px 5px rgba(0,0,0,0.2);
            padding: 4px 0;
        }
        .feed-track {
            position: absolute;
            width: 100%;
            height: 20px;
        }
        .feed-track-1 { top: 4px; }
        .feed-track-2 { top: 24px; }

        .feed-item {
            position: absolute;
            white-space: nowrap;
            padding: 0.125rem 1rem;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 9999px;
            color: #cbd5e1; /* slate-300 */
            font-size: 0.8rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            animation: slideAndFade 20s linear;
            will-change: transform, opacity;
        }
        .feed-item .fa-check-circle {
            color: #4ade80; /* green-400 */
            margin-right: 0.5rem;
        }
        .feed-item b {
            color: white;
            font-weight: 600;
        }

        @keyframes slideAndFade {
            0% {
                transform: translateX(100vw);
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateX(-100%);
                opacity: 0;
            }
        }
    </style>
</head>
<body class="bg-slate-100">
    <div class="flex">
        <!-- Barra lateral para Desktop -->
        <aside id="sidebar" class="w-64 bg-white text-slate-800 min-h-screen p-4 flex-col border-r border-slate-200 hidden lg:flex">
             <div class="text-center mb-10">
                 <a href="index.php" class="inline-block">
                    <svg class="h-10 w-10 text-slate-800 mx-auto" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" fill="currentColor"><path d="M 45,10 C 25,10 10,25 10,45 L 10,55 C 10,75 25,90 45,90 L 55,90 C 60,90 60,85 55,80 L 45,80 C 30,80 20,70 20,55 L 20,45 C 20,30 30,20 45,20 L 55,20 C 60,20 60,15 55,10 Z"/><path d="M 55,90 C 75,90 90,75 90,55 L 90,45 C 90,25 75,10 55,10 L 45,10 C 40,10 40,15 45,20 L 55,20 C 70,20 80,30 80,45 L 80,55 C 80,70 70,80 55,80 L 45,80 C 40,80 40,85 45,90 Z"/></svg>
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
        <div class="flex-1 w-full min-w-0">
             <!-- Header para Móvil -->
            <header class="lg:hidden bg-white/80 backdrop-blur-md sticky top-0 z-40 p-4 flex justify-between items-center shadow-sm">
                <a href="index.php" class="flex items-center space-x-2">
                     <svg class="h-8 w-8 text-slate-800" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" fill="currentColor"><path d="M 45,10 C 25,10 10,25 10,45 L 10,55 C 10,75 25,90 45,90 L 55,90 C 60,90 60,85 55,80 L 45,80 C 30,80 20,70 20,55 L 20,45 C 20,30 30,20 45,20 L 55,20 C 60,20 60,15 55,10 Z"/><path d="M 55,90 C 75,90 90,75 90,55 L 90,45 C 90,25 75,10 55,10 L 45,10 C 40,10 40,15 45,20 L 55,20 C 70,20 80,30 80,45 L 80,55 C 80,70 70,80 55,80 L 45,80 C 40,80 40,85 45,90 Z"/></svg>
                    <span class="text-xl font-bold text-slate-900">Interpago</span>
                </a>
                <button id="menu-button" class="p-2 rounded-md text-slate-600 hover:bg-slate-200">
                    <i class="fas fa-bars text-xl"></i>
                </button>
            </header>

            <!-- Animación Global -->
            <div class="live-feed-container">
                <div id="track-1" class="feed-track feed-track-1"></div>
                <div id="track-2" class="feed-track feed-track-2"></div>
            </div>

            <main class="p-4 sm:p-6 md:p-10">
                <?php if ($error_message): ?>
                     <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert"><p class="font-bold">Error Crítico</p><p><?php echo htmlspecialchars($error_message); ?></p></div>
                <?php endif; ?>

                <?php if ($user): ?>
                    <header class="mb-8">
                        <div>
                            <h1 class="text-3xl md:text-4xl font-bold text-slate-900">Hola, <?php echo htmlspecialchars(explode(' ', $user['name'])[0]); ?></h1>
                            <p class="text-slate-600">Bienvenido a tu panel de control.</p>
                        </div>
                    </header>

                    <div class="bg-slate-800 text-white p-6 md:p-8 rounded-2xl shadow-lg mb-8 flex flex-col items-center md:flex-row md:justify-between bg-gradient-to-br from-slate-800 to-slate-900 text-center md:text-left">
                        <div>
                            <p class="text-lg text-slate-300">Saldo Disponible</p>
                            <p class="text-4xl md:text-5xl font-extrabold">$<?php echo number_format($user['balance'], 2); ?> <span class="text-2xl md:text-3xl font-medium">COP</span></p>
                        </div>
                        <div class="mt-6 md:mt-0 flex flex-col sm:flex-row w-full sm:w-auto gap-3">
                             <a href="request_withdrawal.php" class="w-full sm:w-auto bg-white text-slate-800 font-bold py-3 px-6 rounded-lg hover:bg-slate-200 transition-transform transform hover:scale-105"><i class="fas fa-hand-holding-usd mr-2"></i>Solicitar Retiro</a>
                        </div>
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

                    <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">
                        <div class="xl:col-span-2">
                            <h2 class="text-2xl font-bold text-slate-800 mb-4">Mi Historial de Transacciones</h2>
                            <div class="bg-white rounded-2xl shadow-md">
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm text-left text-slate-500 responsive-table">
                                        <thead class="text-xs text-slate-700 uppercase bg-slate-50"><tr><th class="px-6 py-3">Rol</th><th class="px-6 py-3">Descripción</th><th class="px-6 py-3">Monto</th><th class="px-6 py-3">Estado</th><th class="px-6 py-3">Fecha</th><th class="px-6 py-3">Acción</th></tr></thead>
                                        <tbody>
                                            <?php if ($transactions && $transactions->num_rows > 0): ?>
                                                <?php while($tx = $transactions->fetch_assoc()): ?>
                                                    <tr class="md:border-b md:border-slate-100">
                                                        <td data-label="Rol" class="px-6 py-4 lg:py-5 align-middle">
                                                            <?php
                                                                $role = ($tx['buyer_id'] == $user_id) ? 'Comprador' : 'Vendedor';
                                                                $role_class = ($role == 'Comprador') ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800';
                                                            ?>
                                                            <span class='px-2 py-1 font-semibold rounded-full <?php echo $role_class; ?> text-xs'><?php echo $role; ?></span>
                                                        </td>
                                                        <td data-label="Descripción" class="px-6 py-4 lg:py-5 align-middle font-medium text-slate-900"><?php echo htmlspecialchars($tx['product_description']); ?></td>
                                                        <td data-label="Monto" class="px-6 py-4 lg:py-5 align-middle">$<?php echo number_format($tx['amount'], 2); ?></td>
                                                        <td data-label="Estado" class="px-6 py-4 lg:py-5 align-middle">
                                                            <span class="status-pill status-<?php echo htmlspecialchars($tx['status']); ?>">
                                                                <?php echo htmlspecialchars($status_translations[$tx['status']] ?? ucfirst($tx['status'])); ?>
                                                            </span>
                                                        </td>
                                                        <td data-label="Fecha" class="px-6 py-4 lg:py-5 align-middle text-slate-500"><?php echo date("d M, Y", strtotime($tx['created_at'])); ?></td>
                                                        <td data-label="Acción" class="px-6 py-4 lg:py-5 align-middle">
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
                                <?php if ($total_pages > 1): ?>
                                <div class="p-4 md:p-6 flex justify-between items-center border-t border-slate-100">
                                    <a href="?page=<?php echo max(1, $page - 1); ?>" class="px-4 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-100 <?php if($page <= 1){ echo 'pointer-events-none opacity-50'; } ?>">Anterior</a>
                                    <span class="text-sm text-slate-700">Página <?php echo $page; ?> de <?php echo $total_pages; ?></span>
                                    <a href="?page=<?php echo min($total_pages, $page + 1); ?>" class="px-4 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-100 <?php if($page >= $total_pages){ echo 'pointer-events-none opacity-50'; } ?>">Siguiente</a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="xl:col-span-1">
                             <h2 class="text-2xl font-bold text-slate-800 mb-4">Mis Retiros Recientes</h2>
                             <div class="bg-white rounded-2xl shadow-md p-6 space-y-4">
                                <?php if ($withdrawals && $withdrawals->num_rows > 0): ?>
                                    <?php while($wd = $withdrawals->fetch_assoc()): ?>
                                        <div class="flex justify-between items-center border-b pb-3 last:border-b-0">
                                            <div>
                                                <p class="font-bold text-slate-800">$<?php echo number_format($wd['amount'], 2); ?></p>
                                                <p class="text-sm text-slate-500"><?php echo date("d M, Y", strtotime($wd['created_at'])); ?></p>
                                            </div>
                                            <span class="px-2 py-1 font-semibold rounded-full text-xs withdrawal-<?php echo $wd['status']; ?>"><?php echo ucfirst($wd['status']); ?></span>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?><p class="text-center text-slate-500 py-6">No has realizado retiros.</p><?php endif; ?>
                             </div>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Menú móvil
            const menuButton = document.getElementById('menu-button');
            const sidebar = document.getElementById('sidebar');
            if (menuButton && sidebar) {
                menuButton.addEventListener('click', () => {
                    sidebar.classList.toggle('hidden');
                });
            }

            // Animación Global de Transacciones
            const tracks = [document.getElementById('track-1'), document.getElementById('track-2')];
            if(tracks[0] && tracks[1]) {
                let allCities = [];
                let allTransactions = [];
                let trackStatus = [true, true];

                const formatter = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 });
                const messages = [
                    "Acuerdo de <b>{product}</b>&nbsp;({amount}) completado en <b>{city}</b>",
                    "Pago de <b>{amount}</b>&nbsp;por <b>{product}</b> liberado en <b>{city}</b>",
                    "Nueva transacción por <b>{product}</b>&nbsp;(${amount}) desde <b>{city}</b>"
                ];

                const getRandomItem = (arr) => arr[Math.floor(Math.random() * arr.length)];

                function createFeedItem() {
                    if (allCities.length === 0 || allTransactions.length === 0) return;
                    const availableTrackIndex = trackStatus.findIndex(status => status === true);
                    if (availableTrackIndex === -1) return;
                    trackStatus[availableTrackIndex] = false;
                    const track = tracks[availableTrackIndex];
                    const item = document.createElement('div');
                    item.className = 'feed-item';

                    const transaction = getRandomItem(allTransactions);
                    const city = getRandomItem(allCities);
                    const amount = formatter.format(transaction.amount);
                    const product = transaction.description;

                    const messageTemplate = getRandomItem(messages);

                    item.innerHTML = `<i class="fas fa-check-circle"></i>${messageTemplate.replace('{city}', `<b>${city}</b>`).replace('{amount}', `<b>${amount}</b>`).replace('{product}', `<b>${product}</b>`)}`;
                    const animationDuration = 15 + Math.random() * 10;
                    item.style.animationDuration = `${animationDuration}s`;
                    track.appendChild(item);
                    setTimeout(() => {
                        item.remove();
                        trackStatus[availableTrackIndex] = true;
                    }, animationDuration * 1000);
                }

                function initAnimation(data) {
                    allCities = data.cities;
                    allTransactions = data.transactions;
                    setInterval(createFeedItem, 3000);
                    createFeedItem();
                    setTimeout(createFeedItem, 1500);
                }

                async function startLiveFeed() {
                    const exampleData = {
                        cities: ["Bogotá", "Medellín", "Cali", "Barranquilla"],
                        transactions: [
                            {amount: 75000, description: 'Audífonos'},
                            {amount: 1500000, description: 'Portátil'},
                            {amount: 30000, description: 'Suscripción'}
                        ]
                    };
                    initAnimation(exampleData);

                    try {
                        const response = await fetch('get_realtime_data.php');
                        const realData = await response.json();
                        if (realData.cities && realData.transactions && realData.transactions.length > 0) {
                            allCities = realData.cities;
                            allTransactions = realData.transactions;
                        }
                    } catch (error) {
                        console.error("Error al cargar datos reales para el live feed:", error);
                    }
                }
                startLiveFeed();
            }
        });
    </script>
</body>
</html>
