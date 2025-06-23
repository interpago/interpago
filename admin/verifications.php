<?php
// admin/verifications.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
// CORRECCIÓN: Usar la variable de sesión específica para el admin
if (!isset($_SESSION['admin_loggedin']) || $_SESSION['admin_loggedin'] !== true) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../send_notification.php';
// ... (toda tu lógica de POST para aprobar/rechazar verificaciones permanece igual) ...
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id']) && isset($_POST['action'])) { $user_id_to_update = $_POST['user_id']; $action = $_POST['action']; $new_status = ''; if ($action === 'approve') { $new_status = 'verified'; } elseif ($action === 'reject') { $new_status = 'unverified'; } if (!empty($new_status)) { $stmt = $conn->prepare("UPDATE users SET verification_status = ? WHERE id = ?"); $stmt->bind_param("si", $new_status, $user_id_to_update); $stmt->execute(); $stmt->close(); } header("Location: verifications.php"); exit; }
$result = $conn->query("SELECT id, name, email, document_type, document_number, document_image_path FROM users WHERE verification_status = 'pending' ORDER BY created_at ASC");
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
<body class="bg-slate-100">
    <div class="flex">
        <?php require_once __DIR__ . '/includes/sidebar.php'; // Cargar el menú lateral unificado ?>
        <main class="flex-1 p-6 md:p-10 overflow-y-auto">
            <header class="mb-8">
                <h1 class="text-3xl font-bold text-slate-900">Verificaciones de Identidad Pendientes</h1>
                <p class="text-slate-600">Revisa y aprueba los documentos de los nuevos usuarios.</p>
            </header>
            <!-- ... (el resto de tu HTML para la tabla de verificaciones) ... -->
            <div class="bg-white rounded-2xl shadow-lg overflow-x-auto">
                <table class="w-full text-sm text-left text-slate-500">
                    <thead class="text-xs text-slate-700 uppercase bg-slate-50">
                        <tr><th class="px-6 py-3">Usuario</th><th class="px-6 py-3">Documento</th><th class="px-6 py-3">Imagen del Documento</th><th class="px-6 py-3">Acciones</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): while($row = $result->fetch_assoc()): ?>
                            <tr class="bg-white border-b hover:bg-slate-50">
                                <td class="px-6 py-4"><p class="font-medium text-slate-900"><?php echo htmlspecialchars($row['name']); ?></p><p class="text-xs text-slate-500"><?php echo htmlspecialchars($row['email']); ?></p></td>
                                <td class="px-6 py-4"><p><?php echo htmlspecialchars($row['document_type']); ?>: <?php echo htmlspecialchars($row['document_number']); ?></p></td>
                                <td class="px-6 py-4"><?php if (!empty($row['document_image_path'])): ?><a href="../<?php echo htmlspecialchars($row['document_image_path']); ?>" target="_blank" class="text-blue-600 hover:underline">Ver Imagen</a><?php else: ?><span class="text-slate-400">No disponible</span><?php endif; ?></td>
                                <td class="px-6 py-4 flex space-x-2">
                                    <form action="verifications.php" method="POST"><input type="hidden" name="user_id" value="<?php echo $row['id']; ?>"><button type="submit" name="action" value="approve" class="text-white bg-green-600 hover:bg-green-700 font-medium rounded-lg text-sm px-4 py-2">Aprobar</button></form>
                                    <form action="verifications.php" method="POST"><input type="hidden" name="user_id" value="<?php echo $row['id']; ?>"><button type="submit" name="action" value="reject" class="text-white bg-red-600 hover:bg-red-700 font-medium rounded-lg text-sm px-4 py-2">Rechazar</button></form>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="4" class="text-center py-10 text-slate-500">No hay verificaciones pendientes en este momento.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
