<?php
// admin/withdrawals.php

// Muestra todos los errores para facilitar la depuración.
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Se usan rutas absolutas para mayor fiabilidad.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../send_notification.php';

// Procesar la actualización de estado de un retiro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_withdrawal'])) {
    $withdrawal_id = $_POST['withdrawal_id'];
    $new_status = 'completed';

    // Se asegura de que solo se pueda completar una solicitud pendiente.
    $update_stmt = $conn->prepare("UPDATE withdrawals SET status = ?, completed_at = NOW() WHERE id = ? AND status = 'pending'");
    $update_stmt->bind_param("si", $new_status, $withdrawal_id);
    if ($update_stmt->execute()) {
        // En una implementación futura, aquí se podría notificar al usuario por correo.
    }
    $update_stmt->close();

    header("Location: withdrawals.php");
    exit;
}

// Obtener todas las solicitudes de retiro, uniéndolas con la tabla de usuarios para obtener el nombre
$result = $conn->query("
    SELECT w.*, u.name as user_name, u.email as user_email
    FROM withdrawals w
    JOIN users u ON w.user_id = u.id
    ORDER BY w.status ASC, w.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Retiros - Administración</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .status-pill { padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: capitalize; }
        .status-pending { background-color: #fef9c3; color: #a16207; }
        .status-completed { background-color: #dcfce7; color: #166534; }
    </style>
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
                    <li><a href="index.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700"><i class="fas fa-tachometer-alt w-6"></i><span class="ml-3">Dashboard</span></a></li>
                    <li><a href="transactions.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700"><i class="fas fa-list-ul w-6"></i><span class="ml-3">Transacciones</span></a></li>
                    <li><a href="withdrawals.php" class="flex items-center p-3 rounded-lg bg-slate-900"><i class="fas fa-hand-holding-usd w-6"></i><span class="ml-3">Retiros</span></a></li>
                    <li><a href="verifications.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700"><i class="fas fa-user-check w-6"></i><span class="ml-3">Verificaciones</span></a></li>
                    <li><a href="disputes.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700"><i class="fas fa-gavel w-6"></i><span class="ml-3">Disputas</span></a></li>
                </ul>
            </nav>
            <div><a href="logout.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700"><i class="fas fa-sign-out-alt w-6"></i><span class="ml-3">Cerrar Sesión</span></a></div>
        </aside>

        <!-- Contenido Principal -->
        <main class="flex-1 p-6 md:p-10 overflow-y-auto">
            <header class="mb-8">
                <h1 class="text-3xl font-bold text-slate-900">Solicitudes de Retiro</h1>
                <p class="text-slate-600">Gestiona y procesa los pagos a los vendedores.</p>
            </header>

            <div class="bg-white rounded-2xl shadow-lg overflow-x-auto">
                <table class="w-full text-sm text-left text-slate-500">
                    <thead class="text-xs text-slate-700 uppercase bg-slate-50">
                        <tr>
                            <th class="px-6 py-3">Fecha Solicitud</th>
                            <th class="px-6 py-3">Usuario</th>
                            <th class="px-6 py-3">Monto</th>
                            <th class="px-6 py-3">Detalles Bancarios</th>
                            <th class="px-6 py-3">Estado</th>
                            <th class="px-6 py-3">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <?php $bank_details = json_decode($row['bank_details'], true); ?>
                                <tr class="bg-white border-b hover:bg-slate-50">
                                    <td class="px-6 py-4"><?php echo htmlspecialchars(date("d M, Y H:i", strtotime($row['created_at']))); ?></td>
                                    <td class="px-6 py-4">
                                        <p class="font-medium text-slate-900"><?php echo htmlspecialchars($row['user_name']); ?></p>
                                        <p class="text-xs text-slate-500"><?php echo htmlspecialchars($row['user_email']); ?></p>
                                    </td>
                                    <td class="px-6 py-4 font-bold text-green-600">$<?php echo htmlspecialchars(number_format($row['amount'], 2)); ?></td>
                                    <td class="px-6 py-4 text-xs text-slate-600">
                                        <strong>Banco:</strong> <?php echo htmlspecialchars($bank_details['bank_name'] ?? 'N/A'); ?><br>
                                        <strong>Tipo:</strong> <?php echo htmlspecialchars($bank_details['account_type'] ?? 'N/A'); ?><br>
                                        <strong>N°:</strong> <?php echo htmlspecialchars($bank_details['account_number'] ?? 'N/A'); ?><br>
                                        <strong>Titular:</strong> <?php echo htmlspecialchars($bank_details['holder_name'] ?? 'N/A'); ?><br>
                                        <strong>C.C:</strong> <?php echo htmlspecialchars($bank_details['holder_id'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="status-pill status-<?php echo htmlspecialchars($row['status']); ?>"><?php echo htmlspecialchars(ucfirst($row['status'])); ?></span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if ($row['status'] === 'pending'): ?>
                                            <form action="withdrawals.php" method="POST" onsubmit="return confirm('¿Estás seguro de que ya realizaste la transferencia y quieres marcar este retiro como completado?');">
                                                <input type="hidden" name="withdrawal_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="complete_withdrawal" class="text-white bg-green-600 hover:bg-green-700 font-medium rounded-lg text-sm px-4 py-2">
                                                    Marcar como Completado
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-slate-400 italic">Procesado el <?php echo htmlspecialchars(date("d/m/y", strtotime($row['completed_at']))); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-10 text-slate-500">No hay solicitudes de retiro todavía.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
