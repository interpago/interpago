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
<body class="bg-gray-100">
    <div class="flex">
        <!-- Barra lateral -->
        <aside class="w-64 bg-indigo-800 text-white min-h-screen p-4 flex-shrink-0 flex flex-col">
            <div class="text-center mb-10">
                <a href="index.php" class="flex items-center justify-center space-x-2">
                    <svg class="h-10 w-10 text-white" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" fill="currentColor">
                        <path d="M 45,10 C 25,10 10,25 10,45 L 10,55 C 10,75 25,90 45,90 L 55,90 C 60,90 60,85 55,80 L 45,80 C 30,80 20,70 20,55 L 20,45 C 20,30 30,20 45,20 L 55,20 C 60,20 60,15 55,10 Z"/>
                        <path d="M 55,90 C 75,90 90,75 90,55 L 90,45 C 90,25 75,10 55,10 L 45,10 C 40,10 40,15 45,20 L 55,20 C 70,20 80,30 80,45 L 80,55 C 80,70 70,80 55,80 L 45,80 C 40,80 40,85 45,90 Z"/>
                    </svg>
                    <h1 class="text-2xl font-bold">Admin Interpago</h1>
                </a>
            </div>
            <nav class="flex-grow">
                <ul>
                    <li class="mb-2"><a href="index.php" class="flex items-center p-3 rounded-lg hover:bg-indigo-700"><i class="fas fa-tachometer-alt w-6"></i><span class="ml-3">Dashboard</span></a></li>
                    <li class="mb-2"><a href="transactions.php" class="flex items-center p-3 rounded-lg hover:bg-indigo-700"><i class="fas fa-list-ul w-6"></i><span class="ml-3">Transacciones</span></a></li>
                    <li class="mb-2"><a href="withdrawals.php" class="flex items-center p-3 rounded-lg bg-indigo-900"><i class="fas fa-hand-holding-usd w-6"></i><span class="ml-3">Retiros</span></a></li>
                    <li class="mb-2"><a href="verifications.php" class="flex items-center p-3 rounded-lg hover:bg-indigo-700"><i class="fas fa-user-check w-6"></i><span class="ml-3">Verificaciones</span></a></li>
                </ul>
            </nav>
            <div><a href="logout.php" class="flex items-center p-3 rounded-lg hover:bg-indigo-700"><i class="fas fa-sign-out-alt w-6"></i><span class="ml-3">Cerrar Sesión</span></a></div>
        </aside>

        <!-- Contenido Principal -->
        <main class="flex-1 p-6 md:p-10 overflow-y-auto">
            <header class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900">Solicitudes de Retiro</h1>
                <p class="text-gray-600">Gestiona y procesa los pagos a los vendedores.</p>
            </header>

            <div class="bg-white rounded-2xl shadow-lg overflow-x-auto">
                <table class="w-full text-sm text-left text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50">
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
                                <tr class="bg-white border-b hover:bg-gray-50">
                                    <td class="px-6 py-4"><?php echo date("d M, Y H:i", strtotime($row['created_at'])); ?></td>
                                    <td class="px-6 py-4">
                                        <p class="font-medium text-gray-900"><?php echo htmlspecialchars($row['user_name']); ?></p>
                                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($row['user_email']); ?></p>
                                    </td>
                                    <td class="px-6 py-4 font-bold text-green-600">$<?php echo number_format($row['amount'], 2); ?></td>
                                    <td class="px-6 py-4 text-xs text-gray-600">
                                        <strong>Banco:</strong> <?php echo htmlspecialchars($bank_details['bank_name']); ?><br>
                                        <strong>Tipo:</strong> <?php echo htmlspecialchars($bank_details['account_type']); ?><br>
                                        <strong>N°:</strong> <?php echo htmlspecialchars($bank_details['account_number']); ?><br>
                                        <strong>Titular:</strong> <?php echo htmlspecialchars($bank_details['holder_name']); ?><br>
                                        <strong>C.C:</strong> <?php echo htmlspecialchars($bank_details['holder_id']); ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="status-pill status-<?php echo htmlspecialchars($row['status']); ?>"><?php echo ucfirst($row['status']); ?></span>
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
                                            <span class="text-gray-400 italic">Procesado el <?php echo date("d/m/y", strtotime($row['completed_at'])); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-10 text-gray-500">No hay solicitudes de retiro todavía.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
