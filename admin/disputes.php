<?php
// admin/disputes.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
// CORRECCIÓN: Usar la variable de sesión de admin
if (!isset($_SESSION['admin_loggedin']) || $_SESSION['admin_loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../send_notification.php';

// Procesar la resolución de una disputa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_dispute'])) {
    $transaction_id = $_POST['transaction_id'];
    $final_status = $_POST['final_status'];
    $resolution_comment = trim($_POST['resolution_comment']);

    if (!in_array($final_status, ['released', 'cancelled'])) {
        die("Estado de resolución no válido.");
    }

    $conn->begin_transaction();
    try {
        $tx_stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ? AND status = 'dispute' FOR UPDATE");
        $tx_stmt->bind_param("i", $transaction_id);
        $tx_stmt->execute();
        $tx = $tx_stmt->get_result()->fetch_assoc();

        if ($tx) {
            if ($final_status === 'released') {
                $balance_stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $balance_stmt->bind_param("di", $tx['net_amount'], $tx['seller_id']);
                $balance_stmt->execute();
            }

            $update_stmt = $conn->prepare("UPDATE transactions SET status = ?, dispute_resolution = ? WHERE id = ?");
            $update_stmt->bind_param("ssi", $final_status, $resolution_comment, $transaction_id);
            $update_stmt->execute();

            $conn->commit();

            send_notification($conn, "dispute_resolved", ['transaction_uuid' => $tx['transaction_uuid'], 'resolution' => $resolution_comment]);

        } else {
            $conn->rollback();
        }
    } catch (Exception $e) {
        $conn->rollback();
        die("Error al resolver la disputa: " . $e->getMessage());
    }

    header("Location: disputes.php");
    exit;
}


// Obtener todas las transacciones en disputa
$result = $conn->query("SELECT * FROM transactions WHERE status = 'dispute' ORDER BY created_at ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Disputas - Administración</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100">
    <div class="flex">
        <?php require_once __DIR__ . '/includes/sidebar.php'; // Cargar el menú lateral unificado ?>
        <main class="flex-1 p-6 md:p-10 overflow-y-auto">
            <header class="mb-8">
                <h1 class="text-3xl font-bold text-slate-900">Gestión de Disputas</h1>
                <p class="text-slate-600">Revisa los casos abiertos y toma una decisión final.</p>
            </header>

            <div class="bg-white rounded-2xl shadow-lg overflow-x-auto">
                <table class="w-full text-sm text-left text-slate-500">
                    <thead class="text-xs text-slate-700 uppercase bg-slate-50">
                        <tr>
                            <th class="px-6 py-3">Transacción</th>
                            <th class="px-6 py-3">Motivo de la Disputa</th>
                            <th class="px-6 py-3">Evidencia</th>
                            <th class="px-6 py-3" style="min-width: 300px;">Resolver Disputa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr class="bg-white border-b hover:bg-slate-50">
                                    <td class="px-6 py-4">
                                        <a href="../transaction.php?tx_uuid=<?php echo htmlspecialchars($row['transaction_uuid']); ?>&user_id=admin" target="_blank" class="font-medium text-blue-600 hover:underline"><?php echo substr($row['transaction_uuid'], 0, 8); ?>...</a>
                                        <p class="text-xs text-slate-500"><?php echo htmlspecialchars($row['buyer_name']); ?> vs <?php echo htmlspecialchars($row['seller_name']); ?></p>
                                    </td>
                                    <td class="px-6 py-4 text-xs italic text-slate-600 max-w-xs">"<?php echo nl2br(htmlspecialchars($row['dispute_reason'])); ?>"</td>
                                    <td class="px-6 py-4">
                                        <?php if (!empty($row['dispute_evidence'])): ?>
                                            <a href="../<?php echo htmlspecialchars($row['dispute_evidence']); ?>" target="_blank" class="text-blue-600 hover:underline">Ver Archivo</a>
                                        <?php else: ?>
                                            <span class="text-slate-400">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <form action="disputes.php" method="POST">
                                            <input type="hidden" name="transaction_id" value="<?php echo $row['id']; ?>">
                                            <div class="space-y-2">
                                                <textarea name="resolution_comment" rows="2" class="w-full p-2 border border-slate-300 rounded-lg text-xs" placeholder="Comentario de resolución (será enviado al usuario)..." required></textarea>
                                                <select name="final_status" class="w-full p-2 border border-slate-300 rounded-lg text-xs" required>
                                                    <option value="">Tomar Decisión...</option>
                                                    <option value="released">Liberar fondos al Vendedor</option>
                                                    <option value="cancelled">Cancelar y Reembolsar al Comprador</option>
                                                </select>
                                                <button type="submit" name="resolve_dispute" class="w-full bg-slate-800 text-white font-bold py-2 px-4 rounded-lg text-sm hover:bg-slate-900">Resolver Caso</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center py-10 text-slate-500">No hay disputas pendientes.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
