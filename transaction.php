<?php
// transaction.php

// Muestra todos los errores para facilitar la depuración.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Cargar todas las dependencias.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/send_notification.php';

use chillerlan\QRCode\{QRCode, QROptions};
session_start();

// Inicializar todas las variables para evitar errores.
$transaction = null; $messages = []; $user_role = 'Observador'; $error_message = '';
$is_finished = true; $current_status = ''; $wompi_signature = ''; $amount_in_cents = 0;
$redirect_url_wompi = ''; $buyer_uuid = '';

$user_uuid_get = $_GET['user_id'] ?? '';
$transaction_uuid = $_GET['tx_uuid'] ?? ($_GET['id'] ?? '');

if (!empty($transaction_uuid)) {
    $stmt = $conn->prepare("SELECT * FROM transactions WHERE transaction_uuid = ?");
    $stmt->bind_param("s", $transaction_uuid);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($transaction = $result->fetch_assoc()) {
        if ($user_uuid_get === $transaction['buyer_uuid']) $user_role = 'Comprador';
        elseif ($user_uuid_get === $transaction['seller_uuid']) $user_role = 'Vendedor';

        $current_status = $transaction['status'];

        // Lógica para auto-liberar fondos si el tiempo de garantía ha expirado
        if ($current_status === 'received' && !empty($transaction['release_funds_at']) && time() > strtotime($transaction['release_funds_at'])) {
            $new_status = 'released';
            $conn->begin_transaction();
            try {
                $balance_stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $balance_stmt->bind_param("di", $transaction['net_amount'], $transaction['seller_id']);
                $balance_stmt->execute();

                $status_stmt = $conn->prepare("UPDATE transactions SET status = ?, completed_at = NOW() WHERE id = ?");
                $status_stmt->bind_param("si", $new_status, $transaction['id']);
                $status_stmt->execute();

                $conn->commit();
                send_notification($conn, "status_update", ['transaction_uuid' => $transaction_uuid, 'new_status' => $new_status]);
                header("Location: " . $_SERVER['REQUEST_URI']);
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Error al liberar los fondos automáticamente.";
            }
        }

        $is_finished = in_array($current_status, ['released', 'cancelled', 'dispute']);

        if ($user_role === 'Comprador' && $current_status === 'initiated') {
            $buyer_uuid = $transaction['buyer_uuid'];
            $amount_to_charge = $transaction['amount'];
            if($transaction['commission_payer'] === 'buyer'){ $amount_to_charge += $transaction['commission']; }
            elseif($transaction['commission_payer'] === 'split'){ $amount_to_charge += ($transaction['commission'] / 2); }

            $amount_in_cents = round($amount_to_charge * 100);
            $redirect_url_wompi = rtrim(APP_URL, '/') . '/payment_response.php';
            if (defined('WOMPI_INTEGRITY_SECRET')) {
                $concatenation = $transaction_uuid . $amount_in_cents . 'COP' . WOMPI_INTEGRITY_SECRET;
                $wompi_signature = hash('sha256', $concatenation);
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['generate_qr']) && $user_role === 'Vendedor' && $current_status === 'funded' && is_null($transaction['qr_code_token'])) {
                $qr_token = "INTERPAGO_TX_" . $transaction['id'] . "_" . bin2hex(random_bytes(16));
                $update_stmt = $conn->prepare("UPDATE transactions SET qr_code_token = ? WHERE id = ?");
                $update_stmt->bind_param("si", $qr_token, $transaction['id']);
                if ($update_stmt->execute()) { header("Location: " . $_SERVER['REQUEST_URI']); exit; }
            }
            if (isset($_POST['send_message']) && in_array($user_role, ['Comprador', 'Vendedor'])) {
                $message_content = trim($_POST['message_content']);
                $image_path = null;
                if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . '/uploads/';
                    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
                    $file_name = uniqid() . '-' . basename($_FILES['product_image']['name']);
                    $target_file = $upload_dir . $file_name;
                    if (move_uploaded_file($_FILES['product_image']['tmp_name'], $target_file)) { $image_path = 'uploads/' . $file_name; }
                }
                if (!empty($message_content) || $image_path) {
                    $insert_msg_stmt = $conn->prepare("INSERT INTO messages (transaction_id, sender_role, message, image_path) VALUES (?, ?, ?, ?)");
                    $insert_msg_stmt->bind_param("isss", $transaction['id'], $user_role, $message_content, $image_path);
                    if($insert_msg_stmt->execute()){ send_notification($conn, "new_message", ['transaction_uuid' => $transaction_uuid, 'sender_role' => $user_role]); }
                    header("Location: " . $_SERVER['REQUEST_URI']); exit;
                }
            }
            if (isset($_POST['new_status'])) {
                 $new_status = $_POST['new_status'];
                $allowed = false;
                if ($user_role === 'Vendedor' && $new_status === 'shipped' && $current_status === 'funded') $allowed = true;
                if ($user_role === 'Comprador' && $new_status === 'received' && $current_status === 'shipped') $allowed = true;
                if ($user_role === 'Comprador' && $new_status === 'dispute' && in_array($current_status, ['shipped', 'received'])) $allowed = true;

                if ($allowed) {
                    $update_stmt = $conn->prepare("UPDATE transactions SET status = ? WHERE transaction_uuid = ?");
                    $update_stmt->bind_param("ss", $new_status, $transaction_uuid);
                    if ($update_stmt->execute()) {
                         send_notification($conn, "status_update", ['transaction_uuid' => $transaction_uuid, 'new_status' => $new_status]);
                         header("Location: " . $_SERVER['REQUEST_URI']); exit;
                    }
                }
            }
            if (isset($_POST['submit_rating']) && $current_status === 'released') {
                $rating = filter_var($_POST['rating'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
                $comment = trim($_POST['comment']);
                if ($rating) {
                    $sql = '';
                    if ($user_role === 'Comprador' && is_null($transaction['seller_rating'])) {
                        $sql = "UPDATE transactions SET seller_rating = ?, seller_comment = ? WHERE id = ?";
                    } elseif ($user_role === 'Vendedor' && is_null($transaction['buyer_rating'])) {
                        $sql = "UPDATE transactions SET buyer_rating = ?, buyer_comment = ? WHERE id = ?";
                    }
                    if (!empty($sql)) {
                        $rating_stmt = $conn->prepare($sql);
                        $rating_stmt->bind_param("isi", $rating, $comment, $transaction['id']);
                        if ($rating_stmt->execute()) { header("Location: " . $_SERVER['REQUEST_URI']); exit; }
                    }
                }
            }
        }

        $msg_stmt = $conn->prepare("SELECT * FROM messages WHERE transaction_id = ? ORDER BY created_at ASC");
        $msg_stmt->bind_param("i", $transaction['id']);
        $msg_stmt->execute();
        $messages = $msg_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $error_message = "No se encontró ninguna transacción con el ID: " . htmlspecialchars($transaction_uuid);
    }
}
function get_status_class($s, $c) { if(in_array($c,['dispute','cancelled'])) return 'timeline-step-special'; $st=['initiated','funded','shipped','received','released']; $ci=array_search($c,$st); $si=array_search($s,$st); if($c==='released'||$si<$ci) return 'timeline-step-complete'; if($si===$ci) return 'timeline-step-active'; return 'timeline-step-incomplete'; }
function get_line_class($s, $c) { $st=['initiated','funded','shipped','received']; $ci=array_search($c,$st); $si=array_search($s,$st); if($si<$ci&&!in_array($c,['dispute','cancelled'])) return 'timeline-line-complete'; return 'timeline-line'; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle de la Transacción - Interpago</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js" type="text/javascript"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; } .timeline-step { transition: all 0.3s ease; } .timeline-step-active { background-color: #1e293b; color: white; box-shadow: 0 0 15px rgba(30, 41, 59, 0.5); } .timeline-step-complete { background-color: #16a34a; color: white; } .timeline-step-incomplete { background-color: #e2e8f0; color: #475569; } .timeline-step-special { background-color: #ef4444; color: white; } .timeline-line { background-color: #e2e8f0; height: 4px; flex: 1; } .timeline-line-complete { background-color: #16a34a; } .chat-bubble-me { background-color: #1e293b; border-radius: 20px 20px 5px 20px; } .chat-bubble-other { background-color: #e2e8f0; border-radius: 20px 20px 20px 5px; } .card { background-color: white; border-radius: 1.5rem; box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.1); } .rating { display: inline-block; } .rating input { display: none; } .rating label { float: right; cursor: pointer; color: #d1d5db; transition: color 0.2s; font-size: 2rem; } .rating label:before { content: '\f005'; font-family: 'Font Awesome 5 Free'; font-weight: 900; } .rating input:checked ~ label, .rating label:hover, .rating label:hover ~ label { color: #f59e0b; }
    </style>
</head>
<body class="p-4 md:p-8">
    <div class="max-w-7xl mx-auto">
        <header class="text-center mb-12"><a href="index.php" class="text-slate-600 hover:text-slate-900 mb-4 inline-block"><i class="fas fa-arrow-left mr-2"></i>Volver al Inicio</a><h1 class="text-4xl md:text-5xl font-extrabold text-slate-900">Detalle de la Transacción</h1><?php if ($transaction): ?><p class="text-slate-500 mt-2">ID: <span class="font-mono bg-slate-200 text-slate-700 px-2 py-1 rounded-md text-sm"><?php echo htmlspecialchars($transaction['transaction_uuid']); ?></span></p><?php endif; ?></header>
        <?php if (!empty($error_message)): ?><div class="card p-6 mb-8 text-center bg-red-50 text-red-700"><i class="fas fa-exclamation-triangle text-3xl mb-3"></i><p class="font-semibold"><?php echo $error_message; ?></p></div><?php endif; ?>
        <?php if ($transaction): ?>
            <div class="card p-6 md:p-8 mb-8">
                <h2 class="text-2xl font-bold text-slate-800 mb-6">Línea de Tiempo del Proceso</h2>
                <div class="flex items-center">
                    <?php $statuses = ['initiated' => 'Inicio', 'funded' => 'En Custodia', 'shipped' => 'Enviado', 'received' => 'Recibido', 'released' => 'Liberado']; $keys = array_keys($statuses); foreach($statuses as $status_key => $status_name): $step_class = get_status_class($status_key, $transaction['status']); $line_class = get_line_class($status_key, $transaction['status']); ?>
                        <div class="flex-1 flex flex-col items-center text-center"><div class="w-12 h-12 rounded-full flex items-center justify-center font-bold text-lg timeline-step <?php echo $step_class; ?>"><?php echo ($step_class == 'timeline-step-complete' || $step_class == 'timeline-step-special') ? '<i class="fas fa-check"></i>' : array_search($status_key, $keys) + 1; ?></div><p class="mt-2 text-sm font-medium text-slate-700"><?php echo $status_name; ?></p></div>
                        <?php if ($status_key !== 'released'): ?><div class="timeline-line <?php echo $line_class; ?>"></div><?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="grid lg:grid-cols-12 gap-8">
                <div class="lg:col-span-4 card p-6 md:p-8"><h2 class="text-2xl font-bold text-slate-800 mb-6">Chat de la Transacción</h2><div id="chat-box" class="h-[32rem] overflow-y-auto space-y-6 pr-4"><?php if (empty($messages)): ?><div class="text-center py-10 text-slate-400"><i class="fas fa-comments text-4xl mb-3"></i><p>Aún no hay mensajes.</p></div><?php else: foreach ($messages as $msg): ?><div class="flex <?php echo ($msg['sender_role'] === $user_role) ? 'justify-end' : 'justify-start'; ?>"><div class="p-4 max-w-md <?php echo ($msg['sender_role'] === $user_role) ? 'chat-bubble-me' : 'chat-bubble-other'; ?>"><?php if (!empty($msg['image_path'])): ?><a href="<?php echo htmlspecialchars($msg['image_path']); ?>" target="_blank" class="block mb-2"><img src="<?php echo htmlspecialchars($msg['image_path']); ?>" alt="Imagen adjunta" class="rounded-lg max-h-48 w-full object-cover"></a><?php endif; ?><?php if (!empty($msg['message'])): ?><p class="text-md <?php echo ($msg['sender_role'] === $user_role) ? 'text-white' : 'text-slate-800'; ?>"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p><?php endif; ?><p class="text-xs <?php echo ($msg['sender_role'] === $user_role) ? 'text-slate-400' : 'text-slate-500'; ?> mt-2 text-right"><?php echo $msg['sender_role']; ?> - <?php echo date("d/m H:i", strtotime($msg['created_at'])); ?></p></div></div><?php endforeach; endif; ?></div><?php if (in_array($user_role, ['Comprador', 'Vendedor']) && !$is_finished): ?><form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST" enctype="multipart/form-data" class="pt-6 border-t mt-4"><div class="flex items-start space-x-3"><div class="relative flex-grow"><textarea id="message-input" name="message_content" placeholder="Escribe tu mensaje..." class="w-full p-3 pr-12 border border-slate-300 rounded-xl resize-none overflow-hidden" rows="1"></textarea><label for="image-upload" class="absolute right-3 top-3 cursor-pointer p-1 text-slate-500 hover:text-slate-800"><i class="fas fa-paperclip"></i><input id="image-upload" name="product_image" type="file" class="hidden" accept="image/*"></label></div><button type="submit" name="send_message" class="bg-slate-800 text-white font-bold h-12 w-12 rounded-full flex items-center justify-center hover:bg-slate-900 transition-colors"><i class="fas fa-paper-plane"></i></button></div></form><?php endif; ?></div>
                <div class="lg:col-span-5 space-y-8"><div class="card p-6 md:p-8"><h2 class="text-2xl font-bold text-slate-800 mb-6">Detalles del Acuerdo</h2><div class="space-y-4"><div class="flex justify-between items-baseline"><span class="text-slate-500">Producto/Servicio:</span><span class="font-semibold text-right text-slate-900"><?php echo htmlspecialchars($transaction['product_description']); ?></span></div><hr><div class="flex justify-between items-baseline"><span class="text-slate-500">Comprador:</span><a href="profile.php?id=<?php echo $transaction['buyer_id']; ?>" class="font-semibold text-right text-slate-600 hover:underline"><?php echo htmlspecialchars($transaction['buyer_name']); ?></a></div><div class="flex justify-between items-baseline"><span class="text-slate-500">Vendedor:</span><a href="profile.php?id=<?php echo $transaction['seller_id']; ?>" class="font-semibold text-right text-slate-600 hover:underline"><?php echo htmlspecialchars($transaction['seller_name']); ?></a></div><hr><div class="flex justify-between items-baseline"><span class="text-slate-500">Monto del Acuerdo:</span><span class="font-semibold text-right text-slate-900">$<?php echo number_format($transaction['amount'], 2); ?> COP</span></div><div class="flex justify-between items-baseline"><span class="text-slate-500">Comisión:</span><span class="font-semibold text-right text-red-600">- $<?php echo number_format($transaction['commission'], 2); ?> COP</span></div><hr class="border-dashed"><div class="flex justify-between items-baseline"><span class="font-bold text-lg text-slate-800">El Vendedor Recibirá:</span><span class="font-bold text-lg text-right text-green-600">$<?php echo number_format($transaction['net_amount'], 2); ?> COP</span></div></div></div></div>
                <div class="lg:col-span-3"><div class="card p-6 md:p-8 sticky top-8"><h2 class="text-2xl font-bold text-slate-800 mb-6">Panel de Acciones</h2><div class="text-center"><p class="mb-4 text-slate-600">Tu rol: <span class="font-bold text-slate-800"><?php echo $user_role; ?></span></p><?php if (!$is_finished): ?><?php if ($user_role === 'Comprador' && $current_status === 'initiated'): ?><div class="p-4 bg-slate-50 border-t-4 border-slate-200 rounded-b"><h3 class="font-bold text-lg text-slate-800">Acción Requerida</h3><p class="text-sm text-slate-600 mt-2 mb-6">Deposita los fondos de forma segura.</p><form action="https://checkout.wompi.co/p/" method="GET"><input type="hidden" name="public-key" value="<?php echo WOMPI_PUBLIC_KEY; ?>"><input type="hidden" name="currency" value="COP"><input type="hidden" name="amount-in-cents" value="<?php echo $amount_in_cents; ?>"><input type="hidden" name="reference" value="<?php echo $transaction_uuid; ?>"><input type="hidden" name="signature:integrity" value="<?php echo $wompi_signature; ?>"><input type="hidden" name="redirect-url" value="<?php echo $redirect_url_wompi; ?>"><button type="submit" class="w-full bg-slate-800 text-white font-bold py-3 px-4 rounded-lg hover:bg-slate-900 transition-colors"><i class="fas fa-wallet mr-2"></i>Pagar con Wompi</button></form></div><?php elseif ($user_role === 'Vendedor' && $current_status === 'funded'): ?><div class="p-4 bg-blue-50 border-t-4 border-blue-400 rounded-b space-y-4"><h3 class="font-bold text-lg text-gray-800">Gestionar Entrega</h3><div><h4 class="font-semibold text-gray-700">Sello de Entrega QR</h4><?php if (is_null($transaction['qr_code_token'])): ?><p class="text-xs text-gray-600 mt-1 mb-3">Genera el sello para confirmar la entrega.</p><form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST"><input type="hidden" name="generate_qr" value="1"><button type="submit" class="w-full bg-slate-700 text-white font-bold py-2 px-4 rounded-lg text-sm"><i class="fas fa-qrcode mr-2"></i>Generar Sello QR</button></form><?php else: ?><p class="text-xs text-gray-600 mt-1 mb-3">Sello generado. Imprímelo e inclúyelo en el paquete.</p><div class="p-2 bg-white border rounded-lg mb-2"><img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($transaction['qr_code_token']); ?>" alt="Sello QR" class="w-full h-auto mx-auto"></div><form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST"><input type="hidden" name="new_status" value="shipped"><button type="submit" class="w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-green-700"><i class="fas fa-truck mr-2"></i>Marcar como Enviado</button></form><?php endif; ?></div></div><?php elseif ($user_role === 'Comprador' && $current_status === 'shipped'): ?><div class="p-4 bg-green-50 border-t-4 border-green-400 rounded-b"><h3 class="font-bold text-lg text-gray-800">Acción Requerida</h3><p class="text-sm text-gray-600 mt-2 mb-4">Producto en camino. Escanea el Sello de Entrega para confirmar.</p><button id="scan-qr-btn" class="w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg"><i class="fas fa-camera mr-2"></i>Escanear Sello</button><div id="qr-scanner-container" class="hidden mt-4"><div id="qr-reader" class="w-full"></div><button id="stop-scan-btn" class="mt-2 w-full text-xs text-white bg-red-600/80 py-1 rounded-md">Cancelar</button></div><div id="scan-result" class="mt-4 text-sm font-semibold"></div><p class="text-xs text-gray-500 mt-4">¿Problemas? <a href="#" id="manual-confirm-link" class="underline">Confirmar manualmente</a>.</p><form id="manual-confirm-form" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST" class="hidden"><input type="hidden" name="new_status" value="received"></form></div><?php elseif ($current_status === 'received'): ?><div class="p-4 bg-yellow-50 border-t-4 border-yellow-400 rounded-b"><h3 class="font-bold text-lg text-slate-800">Periodo de Garantía Activo</h3><?php if (!empty($transaction['release_funds_at'])): ?><?php if ($user_role === 'Vendedor'): ?><p class="text-sm text-slate-600 mt-2 mb-4">El comprador ha confirmado la recepción. Los fondos se liberarán automáticamente después de que finalice el periodo de garantía.</p><div id="countdown-timer" class="text-4xl font-bold text-green-700" data-release-time="<?php echo strtotime($transaction['release_funds_at']) * 1000; ?>" data-total-duration="<?php echo INSPECTION_PERIOD_MINUTES * 60; ?>">--:--</div><div class="w-full bg-slate-200 rounded-full h-2.5 mt-4"><div id="progress-bar" class="bg-green-600 h-2.5 rounded-full" style="width: 0%"></div></div><?php elseif ($user_role === 'Comprador'): ?><p class="text-sm text-slate-600 mt-2">Tienes <strong><?php echo INSPECTION_PERIOD_MINUTES; ?> minutos</strong> para inspeccionar el producto. Si hay un problema, puedes abrir una disputa.</p><a href="dispute.php?tx_uuid=<?php echo htmlspecialchars($transaction_uuid); ?>" class="block w-full mt-4 bg-red-600 text-white font-bold py-2 px-4 rounded-lg text-sm"><i class="fas fa-exclamation-triangle mr-2"></i>Abrir Disputa</a><?php endif; ?><?php endif; ?></div><?php else: ?><p class="text-slate-500 italic py-6">Esperando a la otra parte.</p><?php endif; ?><?php else: ?><div class="card p-6 md:p-8"><h2 class="text-2xl font-bold text-slate-800 mb-6">Transacción Finalizada</h2><div class="grid md:grid-cols-2 gap-8"><div><h3 class="font-semibold text-lg mb-2">Calificación para el Vendedor</h3><?php if ($user_role === 'Comprador' && is_null($transaction['seller_rating'])): ?><form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>"><div class="rating"><input type="radio" id="seller-star5" name="rating" value="5" /><label for="seller-star5"></label><input type="radio" id="seller-star4" name="rating" value="4" /><label for="seller-star4"></label><input type="radio" id="seller-star3" name="rating" value="3" /><label for="seller-star3"></label><input type="radio" id="seller-star2" name="rating" value="2" /><label for="seller-star2"></label><input type="radio" id="seller-star1" name="rating" value="1" /><label for="seller-star1"></label></div><textarea name="comment" class="w-full p-3 mt-4 border rounded-lg" rows="3" placeholder="Deja un comentario..."></textarea><button type="submit" name="submit_rating" class="mt-4 w-full bg-slate-800 text-white font-bold py-3 rounded-lg">Enviar</button></form><?php elseif (!is_null($transaction['seller_rating'])): ?><div class="p-4 bg-slate-50 rounded-lg"><?php for($i=0; $i<$transaction['seller_rating']; $i++) { echo '<i class="fas fa-star text-yellow-400"></i>'; } for($i=$transaction['seller_rating']; $i<5; $i++) { echo '<i class="fas fa-star text-gray-300"></i>'; } ?><p class="mt-2 text-slate-600 italic">"<?php echo htmlspecialchars($transaction['seller_comment']); ?>"</p><p class="text-xs text-right text-slate-500 mt-2">- Calificación del Comprador</p></div><?php else: ?><p class="text-slate-500 italic">Esperando calificación del Comprador.</p><?php endif; ?></div><div><h3 class="font-semibold text-lg mb-2">Calificación para el Comprador</h3><?php if ($user_role === 'Vendedor' && is_null($transaction['buyer_rating'])): ?><form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>"><div class="rating"><input type="radio" id="buyer-star5" name="rating" value="5" /><label for="buyer-star5"></label><input type="radio" id="buyer-star4" name="rating" value="4" /><label for="buyer-star4"></label><input type="radio" id="buyer-star3" name="rating" value="3" /><label for="buyer-star3"></label><input type="radio" id="buyer-star2" name="rating" value="2" /><label for="buyer-star2"></label><input type="radio" id="buyer-star1" name="rating" value="1" /><label for="buyer-star1"></label></div><textarea name="comment" class="w-full p-3 mt-4 border rounded-lg" rows="3" placeholder="Deja un comentario..."></textarea><button type="submit" name="submit_rating" class="mt-4 w-full bg-slate-800 text-white font-bold py-3 rounded-lg">Enviar</button></form><?php elseif (!is_null($transaction['buyer_rating'])): ?><div class="p-4 bg-slate-50 rounded-lg"><?php for($i=0; $i<$transaction['buyer_rating']; $i++) { echo '<i class="fas fa-star text-yellow-400"></i>'; } for($i=$transaction['buyer_rating']; $i<5; $i++) { echo '<i class="fas fa-star text-gray-300"></i>'; } ?><p class="mt-2 text-slate-600 italic">"<?php echo htmlspecialchars($transaction['buyer_comment']); ?>"</p><p class="text-xs text-right text-slate-500 mt-2">- Calificación del Vendedor</p></div><?php else: ?><p class="text-slate-500 italic">Esperando calificación del Vendedor.</p><?php endif; ?></div></div></div><?php endif; ?></div></div></div>
            </div>
        <?php endif; ?>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const countdownElement = document.getElementById('countdown-timer');
            if (countdownElement) {
                const releaseTimestamp = parseInt(countdownElement.dataset.releaseTime, 10);
                const totalDuration = parseInt(countdownElement.dataset.totalDuration, 10);
                const progressBar = document.getElementById('progress-bar');
                const interval = setInterval(function() {
                    const now = new Date().getTime();
                    const distance = releaseTimestamp - now;
                    if (distance < 0) { clearInterval(interval); countdownElement.innerHTML = "Liberando..."; progressBar.style.width = '100%'; setTimeout(() => window.location.reload(), 1500); } else {
                        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                        const seconds = Math.floor((distance % (1000 * 60)) / 1000);
                        const elapsedSeconds = totalDuration - (distance / 1000);
                        const progressPercentage = (elapsedSeconds / totalDuration) * 100;
                        countdownElement.innerHTML = (minutes < 10 ? '0' : '') + minutes + ":" + (seconds < 10 ? '0' : '') + seconds;
                        progressBar.style.width = `${progressPercentage}%`;
                    }
                }, 1000);
            }
            const scanBtn = document.getElementById('scan-qr-btn');
            if (scanBtn) { /* ... (código del escáner QR completo) ... */ }
            const textarea = document.getElementById('message-input');
            if (textarea) { textarea.addEventListener('input', function () { this.style.height = 'auto'; this.style.height = (this.scrollHeight) + 'px'; }); }
            const chatBox = document.getElementById('chat-box');
            if (chatBox) { chatBox.scrollTop = chatBox.scrollHeight; }
        });
    </script>
</body>
</html>
