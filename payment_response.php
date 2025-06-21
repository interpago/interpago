<?php
// payment_response.php

ini_set('display_errors', 1); error_reporting(E_ALL);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/send_notification.php';
session_start();

$wompi_tx_id = $_GET['id'] ?? null;
$message = '';
$message_type = 'error';
$redirect_url = 'index.php';

if ($wompi_tx_id) {
    // Verificar la transacción directamente con la API de Wompi
    $ch = curl_init(WOMPI_API_URL . '/transactions/' . $wompi_tx_id);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WOMPI_PRIVATE_KEY]]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        $transaction_data = json_decode($response, true)['data'];
        $transaction_uuid = $transaction_data['reference'];
        $redirect_url = 'transaction.php?tx_uuid=' . $transaction_uuid . '&user_id=' . ($_SESSION['user_uuid'] ?? '');

        if ($transaction_data['status'] === 'APPROVED') {
            $new_status = 'funded';
            $stmt = $conn->prepare("UPDATE transactions SET status = ?, payment_reference = ? WHERE transaction_uuid = ? AND status = 'initiated'");
            $stmt->bind_param("sss", $new_status, $wompi_tx_id, $transaction_uuid);

            if ($stmt->execute() && $stmt->affected_rows > 0) {
                send_notification($conn, "payment_approved", ['transaction_uuid' => $transaction_uuid]);
                $message = '¡Pago Aprobado! Tu transacción ha sido actualizada.';
                $message_type = 'success';
            } else {
                $message = 'Pago verificado, pero la transacción ya había sido actualizada.';
                $message_type = 'success';
            }
        } else {
            $message = 'Tu pago fue ' . strtolower($transaction_data['status']) . '. Por favor, inténtalo de nuevo.';
        }
    } else {
        $message = 'No pudimos verificar el estado de tu pago en este momento.';
    }
} else {
    $message = 'No se recibió un ID de transacción para verificar.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Respuesta de Pago - Interpago</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-slate-100 flex items-center justify-center min-h-screen">
    <div class="text-center p-8 bg-white rounded-2xl shadow-lg max-w-md">
        <?php if ($message_type === 'success'): ?>
            <i class="fas fa-check-circle text-6xl text-green-500 mb-4"></i>
        <?php else: ?>
            <i class="fas fa-times-circle text-6xl text-red-500 mb-4"></i>
        <?php endif; ?>
        <h1 class="text-2xl font-bold text-slate-800"><?php echo ($message_type === 'success') ? '¡Gracias!' : 'Hubo un Problema'; ?></h1>
        <p class="text-slate-600 mt-2 mb-6"><?php echo htmlspecialchars($message); ?></p>
        <a href="<?php echo htmlspecialchars($redirect_url); ?>" class="bg-slate-800 text-white font-bold py-3 px-6 rounded-lg hover:bg-slate-900">Volver a la Transacción</a>
    </div>
</body>
</html>
