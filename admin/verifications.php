<?php
// admin/verifications.php

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

// **LÓGICA CORREGIDA: Procesar la acción de aprobar o rechazar**
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id']) && isset($_POST['action'])) {
    $user_id_to_update = $_POST['user_id'];
    $action = $_POST['action'];
    $new_status = '';

    if ($action === 'approve') {
        $new_status = 'verified';
    } elseif ($action === 'reject') {
        $new_status = 'unverified'; // O podrías tener un estado 'rejected' para notificar al usuario
    }

    if (!empty($new_status)) {
        $stmt = $conn->prepare("UPDATE users SET verification_status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $user_id_to_update);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: verifications.php");
    exit;
}

// Obtener todos los usuarios con estado de verificación 'pending'
$result = $conn->query("
    SELECT id, name, email, document_type, document_number, document_image_path
    FROM users
    WHERE verification_status = 'pending'
    ORDER BY created_at ASC
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificaciones Pendientes - Administración</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style> body { font-family: 'Inter', sans-serif; } </style>
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
                    <li class="mb-2"><a href="withdrawals.php" class="flex items-center p-3 rounded-lg hover:bg-indigo-700"><i class="fas fa-hand-holding-usd w-6"></i><span class="ml-3">Retiros</span></a></li>
                    <li class="mb-2"><a href="verifications.php" class="flex items-center p-3 rounded-lg bg-indigo-900"><i class="fas fa-user-check w-6"></i><span class="ml-3">Verificaciones</span></a></li>
                </ul>
            </nav>
            <div><a href="logout.php" class="flex items-center p-3 rounded-lg hover:bg-indigo-700"><i class="fas fa-sign-out-alt w-6"></i><span class="ml-3">Cerrar Sesión</span></a></div>
        </aside>

        <!-- Contenido Principal -->
        <main class="flex-1 p-6 md:p-10 overflow-y-auto">
            <header class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900">Verificaciones de Identidad Pendientes</h1>
                <p class="text-gray-600">Revisa y aprueba los documentos de los nuevos usuarios.</p>
            </header>

            <div class="bg-white rounded-2xl shadow-lg overflow-x-auto">
                <table class="w-full text-sm text-left text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                        <tr>
                            <th class="px-6 py-3">Usuario</th>
                            <th class="px-6 py-3">Documento</th>
                            <th class="px-6 py-3">Imagen del Documento</th>
                            <th class="px-6 py-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr class="bg-white border-b hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <p class="font-medium text-gray-900"><?php echo htmlspecialchars($row['name']); ?></p>
                                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($row['email']); ?></p>
                                    </td>
                                    <td class="px-6 py-4">
                                        <p><?php echo htmlspecialchars($row['document_type']); ?>: <?php echo htmlspecialchars($row['document_number']); ?></p>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if (!empty($row['document_image_path'])): ?>
                                            <a href="../<?php echo htmlspecialchars($row['document_image_path']); ?>" target="_blank" class="text-indigo-600 hover:underline">Ver Imagen</a>
                                        <?php else: ?>
                                            <span class="text-gray-400">No disponible</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 flex space-x-2">
                                        <form action="verifications.php" method="POST">
                                            <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="action" value="approve" class="text-white bg-green-600 hover:bg-green-700 font-medium rounded-lg text-sm px-4 py-2">Aprobar</button>
                                        </form>
                                        <form action="verifications.php" method="POST">
                                            <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="action" value="reject" class="text-white bg-red-600 hover:bg-red-700 font-medium rounded-lg text-sm px-4 py-2">Rechazar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center py-10 text-gray-500">No hay verificaciones pendientes en este momento.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
