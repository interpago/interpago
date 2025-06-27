<?php
// dispute.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/lib/send_notification.php')) {
    require_once __DIR__ . '/send_notification.php';
}
session_start();

if (!isset($_SESSION['user_uuid'])) {
    header("Location: login.php");
    exit;
}

// --- INICIO: FUNCIÓN DE AYUDA PARA MENSAJES DEL SISTEMA ---
/**
 * Agrega un mensaje automático al chat de la transacción.
 *
 * @param mysqli $conn Conexión a la base de datos.
 * @param int $transaction_id ID de la transacción.
 * @param string $message El mensaje a enviar.
 */
function add_system_message($conn, $transaction_id, $message) {
    try {
        $stmt = $conn->prepare("INSERT INTO messages (transaction_id, sender_role, message) VALUES (?, 'System', ?)");
        $stmt->bind_param("is", $transaction_id, $message);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // En un entorno de producción, esto debería registrarse en un archivo de log.
        error_log("Error al agregar mensaje del sistema para tx_id $transaction_id: " . $e->getMessage());
    }
}
// --- FIN: FUNCIÓN DE AYUDA ---

$error = '';
$success = '';
$transaction = null;
$transaction_uuid = $_GET['tx_uuid'] ?? '';

if (empty($transaction_uuid)) {
    die("ID de transacción no proporcionado.");
}

// Verificar que la transacción pertenece al usuario y está en un estado válido para disputa
$stmt = $conn->prepare("SELECT * FROM transactions WHERE transaction_uuid = ? AND buyer_uuid = ? AND status IN ('funded', 'shipped', 'received')");
$stmt->bind_param("ss", $transaction_uuid, $_SESSION['user_uuid']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    die("No tienes permiso para abrir una disputa para esta transacción o el estado no es válido.");
}
$transaction = $result->fetch_assoc();


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim($_POST['reason']);
    $evidence_path = null;

    if (empty($reason)) {
        $error = 'Debes explicar el motivo de la disputa.';
    } else {
        if (isset($_FILES['evidence']) && $_FILES['evidence']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/disputes/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
            $file_name = 'dispute_' . $transaction['id'] . '_' . uniqid() . '.' . pathinfo($_FILES['evidence']['name'], PATHINFO_EXTENSION);
            $target_file = $upload_dir . $file_name;
            if (move_uploaded_file($_FILES['evidence']['tmp_name'], $target_file)) {
                $evidence_path = 'uploads/disputes/' . $file_name;
            } else {
                $error = 'Error al subir el archivo de evidencia.';
            }
        }

        if (empty($error)) {
            $new_status = 'dispute';
            $update_stmt = $conn->prepare("UPDATE transactions SET status = ?, dispute_reason = ?, dispute_evidence = ? WHERE id = ?");
            $update_stmt->bind_param("sssi", $new_status, $reason, $evidence_path, $transaction['id']);

            if ($update_stmt->execute()) {
                // --- INICIO: AÑADIR MENSAJE DE DISPUTA AL CHAT ---
                $dispute_message = "Se ha abierto una disputa en la transacción. El proceso queda en pausa hasta que nuestro equipo de soporte pueda mediar. Se contactará a ambas partes por los canales oficiales.";
                add_system_message($conn, $transaction['id'], $dispute_message);
                // --- FIN: AÑADIR MENSAJE ---

                if(function_exists('send_notification')) {
                    send_notification($conn, "status_update", ['transaction_uuid' => $transaction_uuid, 'new_status' => $new_status]);
                }

                $_SESSION['success_message'] = "La disputa ha sido abierta. Nuestro equipo se pondrá en contacto pronto.";
                header("Location: transaction.php?tx_uuid=" . $transaction_uuid);
                exit;
            } else {
                $error = "Error al registrar la disputa en la base de datos.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Abrir Disputa - TuPacto</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style> body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; } </style>
</head>
<body class="p-8">
    <div class="max-w-2xl mx-auto">
        <header class="text-center mb-8">
            <h1 class="text-3xl font-extrabold text-slate-900">Iniciar una Disputa</h1>
            <p class="text-slate-600 mt-2">Lamentamos que tengas un problema. Por favor, detalla lo sucedido.</p>
        </header>

        <div class="bg-white p-8 rounded-2xl shadow-lg">
            <?php if ($error): ?><div class="p-3 mb-4 bg-red-100 text-red-700 rounded-lg text-center"><?php echo $error; ?></div><?php endif; ?>
            <form action="dispute.php?tx_uuid=<?php echo htmlspecialchars($transaction_uuid); ?>" method="POST" enctype="multipart/form-data" class="space-y-6">
                <div>
                    <label for="reason" class="block text-sm font-medium text-slate-700">Motivo de la disputa</label>
                    <textarea name="reason" id="reason" rows="6" class="mt-1 w-full p-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="Ej: El producto llegó dañado, no es el artículo que pedí, etc." required></textarea>
                    <p class="text-xs text-slate-500 mt-1">Sé lo más detallado posible. Esto nos ayudará a resolver el caso rápidamente.</p>
                </div>
                <div>
                    <label for="evidence" class="block text-sm font-medium text-slate-700">Adjuntar Evidencia (Opcional)</label>
                    <input type="file" name="evidence" id="evidence" class="mt-1 w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-slate-50 file:text-slate-700 hover:file:bg-slate-100">
                    <p class="text-xs text-slate-500 mt-1">Puedes subir fotos o un video corto del producto.</p>
                </div>
                <div class="flex justify-between items-center pt-4 border-t">
                    <a href="transaction.php?tx_uuid=<?php echo htmlspecialchars($transaction_uuid); ?>" class="text-slate-600 hover:underline">Cancelar</a>
                    <button type="submit" class="bg-red-600 text-white font-bold py-3 px-6 rounded-lg hover:bg-red-700 transition-transform transform hover:scale-105"><i class="fas fa-exclamation-triangle mr-2"></i>Confirmar y Abrir Disputa</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
