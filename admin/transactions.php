<?php
// admin/transactions.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../send_notification.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transaction_id']) && isset($_POST['new_status'])) {
    $transaction_id = $_POST['transaction_id'];
    $new_status = $_POST['new_status'];
    $transaction_uuid_for_notif = '';

    if ($new_status === 'released') {
        $conn->begin_transaction();
        try {
            $tx_stmt = $conn->prepare("SELECT seller_id, net_amount, status, transaction_uuid FROM transactions WHERE id = ? FOR UPDATE");
            $tx_stmt->bind_param("i", $transaction_id);
            $tx_stmt->execute();
            $tx = $tx_stmt->get_result()->fetch_assoc();
            $transaction_uuid_for_notif = $tx['transaction_uuid'];
            if ($tx && $tx['status'] !== 'released' && $tx['seller_id']) {
                $balance_stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $balance_stmt->bind_param("di", $tx['net_amount'], $tx['seller_id']);
                $balance_stmt->execute();
                $status_stmt = $conn->prepare("UPDATE transactions SET status = ?, completed_at = NOW() WHERE id = ?");
                $status_stmt->bind_param("si", $new_status, $transaction_id);
                $status_stmt->execute();
                $conn->commit();
            } else {
                $conn->rollback();
            }
        } catch (Exception $e) {
            $conn->rollback();
            die("Error al procesar la liberación: " . $e->getMessage());
        }
    } else {
        $uuid_stmt = $conn->prepare("SELECT transaction_uuid FROM transactions WHERE id = ?");
        $uuid_stmt->bind_param("i", $transaction_id);
        $uuid_stmt->execute();
        $uuid_res = $uuid_stmt->get_result()->fetch_assoc();
        $transaction_uuid_for_notif = $uuid_res['transaction_uuid'];

        $update_stmt = $conn->prepare("UPDATE transactions SET status = ? WHERE id = ?");
        $update_stmt->bind_param("si", $new_status, $transaction_id);
        $update_stmt->execute();
    }

    if (!empty($transaction_uuid_for_notif)) {
        send_notification($conn, "status_update", ['transaction_uuid' => $transaction_uuid_for_notif, 'new_status' => $new_status]);
    }

    $query_params = http_build_query(['search' => $_GET['search'] ?? '', 'status_filter' => $_GET['status_filter'] ?? '']);
    header("Location: transactions.php?" . $query_params);
    exit;
}

$status_translations = [
    'initiated' => 'Iniciado', 'funded' => 'En Custodia', 'shipped' => 'Enviado',
    'received' => 'Recibido', 'released' => 'Liberado', 'dispute' => 'En Disputa', 'cancelled' => 'Cancelado'
];
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';
$where_clauses = [];
$params = [];
$types = '';
if (!empty($search)) {
    $where_clauses[] = "(t.transaction_uuid LIKE ? OR t.seller_name LIKE ? OR t.buyer_name LIKE ?)";
    $search_term = "%{$search}%";
    array_push($params, $search_term, $search_term, $search_term);
    $types .= 'sss';
}
if (!empty($status_filter)) {
    $where_clauses[] = "t.status = ?";
    array_push($params, $status_filter);
    $types .= 's';
}
$result = null;
$query_error = '';
try {
    $query = "SELECT t.*, COUNT(m.id) AS message_count FROM transactions t LEFT JOIN messages m ON t.id = m.transaction_id";
    if (!empty($where_clauses)) { $query .= " WHERE " . implode(" AND ", $where_clauses); }
    $query .= " GROUP BY t.id ORDER BY t.created_at DESC";
    $stmt = $conn->prepare($query);
    if (!empty($params)) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $result = $stmt->get_result();
} catch (Exception $e) {
    $query_error = "Error al consultar la base de datos: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Todas las Transacciones - Administración</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .status-pill { padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
        .status-initiated { background-color: #e0e7ff; color: #3730a3; }
        .status-funded { background-color: #dbeafe; color: #1d4ed8; }
        .status-shipped { background-color: #fef3c7; color: #92400e; }
        .status-received { background-color: #dcfce7; color: #15803d; }
        .status-released { background-color: #d1fae5; color: #059669; }
        .status-dispute, .status-cancelled { background-color: #fee2e2; color: #b91c1c; }
        .dispute-row { background-color: #fff1f2 !important; border-left: 4px solid #ef4444; }
    </style>
</head>
<body class="bg-slate-100">
    <div class="flex">
        <!-- INICIO DE LA CORRECCIÓN: Barra lateral unificada -->
        <aside class="w-64 bg-slate-800 text-white min-h-screen p-4 flex-shrink-0 flex flex-col">
            <div class="text-center mb-10">
                <a href="index.php" class="flex items-center justify-center space-x-2">
                    <i class="fas fa-shield-alt text-3xl"></i>
                    <h1 class="text-2xl font-bold">Admin Interpago</h1>
                </a>
            </div>
            <nav class="flex-grow">
                <ul class="space-y-2">
                    <li><a href="index.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700"><i class="fas fa-tachometer-alt w-6"></i><span class="ml-3">Dashboard</span></a></li>
                    <li><a href="transactions.php" class="flex items-center p-3 rounded-lg bg-slate-900"><i class="fas fa-list-ul w-6"></i><span class="ml-3">Transacciones</span></a></li>
                    <li><a href="withdrawals.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700"><i class="fas fa-hand-holding-usd w-6"></i><span class="ml-3">Retiros</span></a></li>
                    <li><a href="verifications.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700"><i class="fas fa-user-check w-6"></i><span class="ml-3">Verificaciones</span></a></li>
                    <li><a href="disputes.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700"><i class="fas fa-gavel w-6"></i><span class="ml-3">Disputas</span></a></li>
                </ul>
            </nav>
            <div><a href="logout.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700"><i class="fas fa-sign-out-alt w-6"></i><span class="ml-3">Cerrar Sesión</span></a></div>
        </aside>
        <!-- FIN DE LA CORRECCIÓN -->

        <main class="flex-1 p-6 md:p-10 overflow-y-auto">
            <header class="mb-8"><h1 class="text-3xl font-bold text-slate-900">Todas las Transacciones</h1><p class="text-slate-600">Busca, filtra y gestiona todas las operaciones de la plataforma.</p></header>
            <div class="bg-white p-4 rounded-2xl shadow-md mb-8">
                <form action="transactions.php" method="GET" class="grid md:grid-cols-3 gap-4 items-end">
                    <div><label for="search" class="block text-sm font-medium text-slate-700">Buscar</label><input type="text" name="search" id="search" class="mt-1 block w-full p-2 border-slate-300 rounded-lg shadow-sm" value="<?php echo htmlspecialchars($search); ?>" placeholder="ID, vendedor, comprador..."></div>
                    <div><label for="status_filter" class="block text-sm font-medium text-slate-700">Filtrar por Estado</label><select name="status_filter" id="status_filter" class="mt-1 block w-full p-2 border-slate-300 rounded-lg shadow-sm"><option value="">Todos los estados</option><?php foreach ($status_translations as $key => $value): ?><option value="<?php echo $key; ?>" <?php echo ($status_filter === $key) ? 'selected' : ''; ?>><?php echo $value; ?></option><?php endforeach; ?></select></div>
                    <div class="flex space-x-2"><button type="submit" class="w-full text-white bg-slate-800 hover:bg-slate-900 font-medium rounded-lg text-sm px-4 py-2">Filtrar</button><a href="transactions.php" class="w-full text-center text-slate-700 bg-slate-200 hover:bg-slate-300 font-medium rounded-lg text-sm px-4 py-2">Limpiar</a></div>
                </form>
            </div>
            <div class="bg-white rounded-2xl shadow-md overflow-x-auto">
                <table class="w-full text-sm text-left text-slate-500">
                    <thead class="text-xs text-slate-700 uppercase bg-slate-50">
                        <tr><th class="px-6 py-3">ID</th><th class="px-6 py-3">Vendedor</th><th class="px-6 py-3">Comprador</th><th class="px-6 py-3">Monto Total</th><th class="px-6 py-3">Comisión</th><th class="px-6 py-3">Monto Neto</th><th class="px-6 py-3">Estado</th><th class="px-6 py-3">Mensajes</th><th class="px-6 py-3" style="min-width: 250px;">Acción</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($query_error): ?>
                            <tr><td colspan="9" class="text-center py-8 text-red-600 font-semibold"><?php echo $query_error; ?></td></tr>
                        <?php elseif ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr class="bg-white border-b hover:bg-slate-50 <?php echo $row['status'] === 'dispute' ? 'dispute-row' : ''; ?>">
                                    <td class="px-6 py-4"><a href="../transaction.php?tx_uuid=<?php echo htmlspecialchars($row['transaction_uuid']); ?>" class="font-medium text-blue-600 hover:underline" title="Ver transacción"><span class="font-mono text-xs"><?php echo substr($row['transaction_uuid'], 0, 8); ?>...</span></a></td>
                                    <td class="px-6 py-4"><?php echo htmlspecialchars($row['seller_name']); ?></td>
                                    <td class="px-6 py-4"><?php echo htmlspecialchars($row['buyer_name']); ?></td>
                                    <td class="px-6 py-4">$<?php echo number_format($row['amount'], 2); ?></td>
                                    <td class="px-6 py-4 text-red-500">$<?php echo number_format($row['commission'], 2); ?></td>
                                    <td class="px-6 py-4 text-green-600 font-bold">$<?php echo number_format($row['net_amount'], 2); ?></td>
                                    <td class="px-6 py-4"><span class="status-pill status-<?php echo htmlspecialchars($row['status']); ?>"><?php echo htmlspecialchars($status_translations[$row['status']] ?? $row['status']); ?></span></td>
                                    <td class="px-6 py-4 text-center"><span class="inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-slate-100 bg-slate-700 rounded-full"><?php echo $row['message_count']; ?></span></td>
                                    <td class="px-6 py-4">
                                        <form action="transactions.php?search=<?php echo htmlspecialchars($search); ?>&status_filter=<?php echo htmlspecialchars($status_filter); ?>" method="POST" class="flex items-center space-x-2">
                                            <input type="hidden" name="transaction_id" value="<?php echo $row['id']; ?>">
                                            <select name="new_status" class="block w-full p-2 text-sm border-slate-300 rounded-lg bg-slate-50">
                                                <?php foreach ($status_translations as $status_key => $status_value): ?>
                                                    <option value="<?php echo $status_key; ?>" <?php echo ($row['status'] === $status_key) ? 'selected' : ''; ?>><?php echo $status_value; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="text-white bg-slate-800 hover:bg-slate-900 font-medium rounded-lg text-sm px-4 py-2">Guardar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-center py-8">No se encontraron transacciones con los filtros aplicados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
