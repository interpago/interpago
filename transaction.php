<?php
// transaction.php

// Muestra todos los errores para facilitar la depuración.
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// Cargar todas las dependencias.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/send_notification.php';

use chillerlan\QRCode\{QRCode, QROptions};

// Se define aquí para que esté disponible globalmente en este script.
function add_system_message($conn, $transaction_id, $message) {
    try {
        $stmt = $conn->prepare("INSERT INTO messages (transaction_id, sender_role, message) VALUES (?, 'System', ?)");
        $stmt->bind_param("is", $transaction_id, $message);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error al agregar mensaje del sistema para tx_id $transaction_id: " . $e->getMessage());
    }
}

// Inicializar todas las variables para evitar errores.
$transaction = null;
$messages = [];
$agreement_details = [];
$user_role = 'Observador';
$error_message = '';
$is_finished = true;
$current_status = '';
$last_message_id = 0;
$buyer_balance = 0;
$amount_to_charge_wallet = 0;
$amount_to_charge_gateway = 0;
$buyer_commission_paid = 0;
$seller_commission_paid = 0;
$wompi_signature = '';
$redirect_url_wompi = '';
$amount_in_cents = 0;

$transaction_uuid = $_GET['tx_uuid'] ?? '';

if(isset($_SESSION['error_message'])){
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
if(isset($_SESSION['success_message'])){
    unset($_SESSION['success_message']);
}


if (!empty($transaction_uuid)) {
    $stmt = $conn->prepare("SELECT * FROM transactions WHERE transaction_uuid = ?");
    $stmt->bind_param("s", $transaction_uuid);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($transaction = $result->fetch_assoc()) {
        $transaction_id = $transaction['id'];

        $details_stmt = $conn->prepare("SELECT field_key, field_value FROM transaction_agreement_details WHERE transaction_id = ?");
        $details_stmt->bind_param("i", $transaction_id);
        $details_stmt->execute();
        $details_result = $details_stmt->get_result();
        while($row = $details_result->fetch_assoc()){
            $agreement_details[$row['field_key']] = $row['field_value'];
        }
        $details_stmt->close();

        $logged_in_user_uuid = $_SESSION['user_uuid'] ?? '';
        if (!empty($logged_in_user_uuid)) {
            if (trim($logged_in_user_uuid) === trim($transaction['buyer_uuid'])) {
                $user_role = 'Comprador';
            } elseif (trim($logged_in_user_uuid) === trim($transaction['seller_uuid'])) {
                $user_role = 'Vendedor';
            }
        }

        $current_status = $transaction['status'];

        if ($user_role === 'Comprador') {
            $balance_stmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
            $balance_stmt->bind_param("i", $transaction['buyer_id']);
            $balance_stmt->execute();
            $buyer_balance = $balance_stmt->get_result()->fetch_assoc()['balance'] ?? 0;
            $balance_stmt->close();
        }

        if ($current_status === 'received' && !empty($transaction['release_funds_at']) && new DateTime() >= new DateTime($transaction['release_funds_at'])) {
            $new_status = 'released';
            $conn->begin_transaction();
            try {
                $status_stmt = $conn->prepare("UPDATE transactions SET status = ?, completed_at = NOW() WHERE id = ? AND status = 'received'");
                $status_stmt->bind_param("si", $new_status, $transaction['id']);
                $status_stmt->execute();
                if($status_stmt->affected_rows > 0) {
                    $balance_stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                    $balance_stmt->bind_param("di", $transaction['net_amount'], $transaction['seller_id']);
                    $balance_stmt->execute();
                    add_system_message($conn, $transaction_id, "El periodo de garantía ha finalizado. Los fondos han sido liberados al vendedor.");
                    $conn->commit();
                    send_notification($conn, "status_update", ['transaction_uuid' => $transaction_uuid, 'new_status' => $new_status]);
                } else {
                    $conn->rollback();
                }
                header("Location: " . $_SERVER['REQUEST_URI']);
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Auto-release error: " . $e->getMessage());
                $error_message = "Error al liberar los fondos automáticamente.";
            }
        }

        $is_finished = in_array($current_status, ['released', 'cancelled', 'dispute']);
        $platform_commission = $transaction['commission'];

        $amount_to_charge_wallet = $transaction['amount'];
        $amount_to_charge_gateway = $transaction['amount'];
        if ($transaction['commission_payer'] === 'buyer') {
            $amount_to_charge_wallet += $platform_commission;
            $amount_to_charge_gateway += $platform_commission;
            $buyer_commission_paid = $transaction['commission'];
        } elseif ($transaction['commission_payer'] === 'seller') {
            $seller_commission_paid = $transaction['commission'];
        } elseif ($transaction['commission_payer'] === 'split') {
            $amount_to_charge_wallet += ($platform_commission / 2);
            $amount_to_charge_gateway += ($platform_commission / 2);
            $buyer_commission_paid = $transaction['commission'] / 2;
            $seller_commission_paid = $transaction['commission'] / 2;
        }

        if ($user_role === 'Comprador' && $current_status === 'initiated') {
            $amount_in_cents = round($amount_to_charge_gateway * 100);
            $redirect_url_wompi = rtrim(APP_URL, '/') . '/verify_transaction_payment.php';
            if (defined('WOMPI_INTEGRITY_SECRET')) {
                $concatenation = $transaction_uuid . $amount_in_cents . 'COP' . WOMPI_INTEGRITY_SECRET;
                $wompi_signature = hash('sha256', $concatenation);
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $redirect_after_post = true;

            if (isset($_POST['generate_qr']) && $user_role === 'Vendedor' && $current_status === 'funded') {
                $qr_token = "INTERPAGO_TX_" . $transaction['id'] . "_" . bin2hex(random_bytes(16));
                $update_stmt = $conn->prepare("UPDATE transactions SET qr_code_token = ? WHERE id = ?");
                $update_stmt->bind_param("si", $qr_token, $transaction['id']);
                if ($update_stmt->execute()) {
                    add_system_message($conn, $transaction_id, "El vendedor ha generado el Sello QR para la entrega.");
                }
            }
            elseif (isset($_POST['new_status']) && $_POST['new_status'] === 'shipped' && $user_role === 'Vendedor' && $current_status === 'funded') {
                $stmt = $conn->prepare("UPDATE transactions SET status = 'shipped', shipped_at = NOW() WHERE id = ?");
                $stmt->bind_param("i", $transaction_id);
                if($stmt->execute()){
                     add_system_message($conn, $transaction_id, "El vendedor ha marcado el producto como 'Enviado'.");
                     send_notification($conn, "status_update", ['transaction_uuid' => $transaction_uuid, 'new_status' => 'shipped']);
                }
            }
            elseif (isset($_POST['new_status']) && $_POST['new_status'] === 'received' && $user_role === 'Comprador' && $current_status === 'shipped') {
                $inspection_period = (defined('INSPECTION_PERIOD_MINUTES') && is_numeric(INSPECTION_PERIOD_MINUTES)) ? INSPECTION_PERIOD_MINUTES : 10;
                $stmt = $conn->prepare("UPDATE transactions SET status = 'received', received_at = NOW(), release_funds_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?");
                $stmt->bind_param("ii", $inspection_period, $transaction_id);
                if($stmt->execute()){
                     add_system_message($conn, $transaction_id, "El comprador ha confirmado la recepción. El periodo de garantía ha comenzado.");
                     send_notification($conn, "status_update", ['transaction_uuid' => $transaction_uuid, 'new_status' => 'received']);
                }
            }
            // ***** INICIO: LÓGICA DE LIBERACIÓN DE FONDOS RESTAURADA *****
            elseif (isset($_POST['new_status']) && $_POST['new_status'] === 'released' && $user_role === 'Comprador' && $current_status === 'received') {
                $conn->begin_transaction();
                try {
                    $status_stmt = $conn->prepare("UPDATE transactions SET status = 'released', completed_at = NOW() WHERE id = ? AND status = 'received'");
                    $status_stmt->bind_param("i", $transaction['id']);
                    $status_stmt->execute();
                    if($status_stmt->affected_rows > 0) {
                        $balance_stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                        $balance_stmt->bind_param("di", $transaction['net_amount'], $transaction['seller_id']);
                        $balance_stmt->execute();
                        add_system_message($conn, $transaction_id, "El comprador ha aprobado la transacción y los fondos han sido liberados al vendedor.");
                        $conn->commit();
                        send_notification($conn, "status_update", ['transaction_uuid' => $transaction_uuid, 'new_status' => 'released']);
                    } else {
                        $conn->rollback();
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    error_log("Manual release error: " . $e->getMessage());
                    $_SESSION['error_message'] = "Error al liberar los fondos manualmente.";
                }
            }
            // ***** FIN: LÓGICA DE LIBERACIÓN DE FONDOS RESTAURADA *****
            elseif (isset($_POST['send_message']) && !$is_finished) {
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
                }
            } else {
                $redirect_after_post = false;
            }

            if($redirect_after_post){
                header("Location: " . $_SERVER['REQUEST_URI']);
                exit;
            }
        }

        $msg_stmt = $conn->prepare("SELECT * FROM messages WHERE transaction_id = ? ORDER BY created_at ASC");
        $msg_stmt->bind_param("i", $transaction_id);
        $msg_stmt->execute();
        $messages = $msg_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if(!empty($messages)) {
            $last_message_id = end($messages)['id'];
        }

    } else {
        $error_message = "No se encontró ninguna transacción con el ID: " . htmlspecialchars($transaction_uuid);
    }
}

function get_status_class($s, $c) { if(in_array($c,['cancelled', 'dispute'])) return 'timeline-step-special'; $st=['initiated','funded','shipped','received','released']; $ci=array_search($c,$st); $si=array_search($s,$st); if($c==='released'||$si<$ci) return 'timeline-step-complete'; if($si===$ci) return 'timeline-step-active'; return 'timeline-step-incomplete'; }
function get_line_class($s, $c) { $st=['initiated','funded','shipped','received']; $ci=array_search($c,$st); $si=array_search($s,$st); if($si<$ci&&!in_array($c,['cancelled', 'dispute'])) return 'timeline-line-complete'; return 'timeline-line'; }
$agreement_labels = ['estado_estetico' => 'Estado Estético', 'accesorios_incluidos' => 'Accesorios Incluidos', 'garantia_ofrecida' => 'Garantía Ofrecida', 'condiciones_devolucion' => 'Condiciones de Devolución'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle de la Transacción - TuPacto</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js" type="text/javascript"></script>
    <style> body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; } .timeline-step { transition: all 0.3s ease; } .timeline-step-active { background-color: #1e293b; color: white; box-shadow: 0 0 15px rgba(30, 41, 59, 0.5); } .timeline-step-complete { background-color: #16a34a; color: white; } .timeline-step-incomplete { background-color: #e2e8f0; color: #475569; } .timeline-step-special { background-color: #ef4444; color: white; } .timeline-line { background-color: #e2e8f0; height: 4px; flex: 1; } .timeline-line-complete { background-color: #16a34a; } .chat-bubble-me { background-color: #1e293b; border-radius: 20px 20px 5px 20px; } .chat-bubble-other { background-color: #e2e8f0; border-radius: 20px 20px 20px 5px; } .chat-bubble-system { background-color: #e0f2fe; border-radius: 20px; } .card { background-color: white; border-radius: 1.5rem; box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.1); } .rating { display: inline-block; } .rating input { display: none; } .rating label { float: right; cursor: pointer; color: #d1d5db; transition: color 0.2s; font-size: 2rem; } .rating label:before { content: '\f005'; font-family: 'Font Awesome 5 Free'; font-weight: 900; } .rating input:checked ~ label, .rating label:hover, .rating label:hover ~ label { color: #f59e0b; } </style>
</head>
<body class="p-4 md:p-8">
    <div class="max-w-7xl mx-auto">
        <header class="text-center mb-12">
            <a href="dashboard.php" class="text-slate-600 hover:text-slate-900 mb-4 inline-block"><i class="fas fa-arrow-left mr-2"></i>Volver al Panel</a>
            <h1 class="text-4xl md:text-5xl font-extrabold text-slate-900">Detalle de la Transacción</h1>
            <?php if ($transaction): ?>
                <p class="text-slate-500 mt-2">ID: <span id="transaction-id" class="font-mono bg-slate-200 text-slate-700 px-2 py-1 rounded-md text-sm" data-uuid="<?php echo htmlspecialchars($transaction['transaction_uuid']); ?>" data-status="<?php echo htmlspecialchars($current_status); ?>"><?php echo htmlspecialchars($transaction['transaction_uuid']); ?></span></p>
            <?php endif; ?>
        </header>

        <?php if (!empty($error_message)): ?>
            <div class="card p-6 mb-8 text-center bg-red-50 text-red-700"><i class="fas fa-exclamation-triangle text-3xl mb-3"></i><p class="font-semibold"><?php echo htmlspecialchars($error_message); ?></p></div>
        <?php endif; ?>

        <?php if ($transaction): ?>
            <div id="main-content">
                <div class="card p-6 md:p-8 mb-8">
                    <h2 class="text-2xl font-bold text-slate-800 mb-6">Línea de Tiempo del Proceso</h2>
                    <div class="flex items-center">
                        <?php $statuses = ['initiated' => 'Inicio', 'funded' => 'En Custodia', 'shipped' => 'Enviado', 'received' => 'Recibido', 'released' => 'Liberado']; $keys = array_keys($statuses); foreach($statuses as $status_key => $status_name): $step_class = get_status_class($status_key, $transaction['status']); $line_class = get_line_class($status_key, $transaction['status']); ?>
                            <div class="flex-1 flex flex-col items-center text-center"><div class="w-12 h-12 rounded-full flex items-center justify-center font-bold text-lg timeline-step <?php echo $step_class; ?>"><?php echo ($step_class == 'timeline-step-complete') ? '<i class="fas fa-check"></i>' : array_search($status_key, $keys) + 1; ?></div><p class="mt-2 text-sm font-medium text-slate-700"><?php echo htmlspecialchars($status_name); ?></p></div>
                            <?php if ($status_key !== 'released'): ?><div class="timeline-line <?php echo $line_class; ?>"></div><?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="grid lg:grid-cols-12 gap-8">

                    <div class="lg:col-span-4 card p-6 md:p-8">
                        <h2 class="text-2xl font-bold text-slate-800 mb-6">Chat de la Transacción</h2>
                        <div id="chat-box" class="h-[32rem] overflow-y-auto space-y-6 pr-4" data-last-message-id="<?php echo $last_message_id; ?>">
                            <?php if (empty($messages)): ?>
                                <div id="no-messages" class="text-center py-10 text-slate-400"><i class="fas fa-comments text-4xl mb-3"></i><p>Aún no hay mensajes.</p></div>
                            <?php else:
                                foreach ($messages as $msg):
                                    $is_system_message = $msg['sender_role'] === 'System';
                                    $bubble_class = $is_system_message ? 'chat-bubble-system text-slate-600' : (($msg['sender_role'] === $user_role) ? 'chat-bubble-me' : 'chat-bubble-other');
                                    $justify_class = $is_system_message ? 'justify-center' : (($msg['sender_role'] === $user_role) ? 'justify-end' : 'justify-start');
                                ?>
                                <div class="flex <?php echo $justify_class; ?>">
                                    <div class="p-4 max-w-md <?php echo $bubble_class; ?>">
                                        <?php if (!empty($msg['image_path'])): ?>
                                            <a href="<?php echo htmlspecialchars($msg['image_path']); ?>" target="_blank" class="block mb-2"><img src="<?php echo htmlspecialchars($msg['image_path']); ?>" alt="Imagen adjunta" class="rounded-lg max-h-48 w-full object-cover"></a>
                                        <?php endif; ?>
                                        <?php if (!empty($msg['message'])): ?>
                                            <p class="text-md <?php echo ($msg['sender_role'] === $user_role && !$is_system_message) ? 'text-white' : 'text-slate-800'; ?>"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p>
                                        <?php endif; ?>
                                        <?php if(!$is_system_message): ?>
                                            <p class="text-xs <?php echo ($msg['sender_role'] === $user_role) ? 'text-slate-400' : 'text-slate-500'; ?> mt-2 text-right"><?php echo htmlspecialchars($msg['sender_role']); ?> - <?php echo htmlspecialchars(date("d/m H:i", strtotime($msg['created_at']))); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                        <?php if (in_array($user_role, ['Comprador', 'Vendedor']) && !$is_finished): ?>
                            <form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST" enctype="multipart/form-data" class="pt-6 border-t mt-4">
                                <div class="flex items-start space-x-3">
                                    <div class="relative flex-grow">
                                        <textarea id="message-input" name="message_content" placeholder="Escribe tu mensaje..." class="w-full p-3 pr-12 border border-slate-300 rounded-xl resize-none overflow-hidden" rows="1"></textarea>
                                        <label for="image-upload" class="absolute right-3 top-3 cursor-pointer p-1 text-slate-500 hover:text-slate-800">
                                            <i class="fas fa-paperclip"></i>
                                            <input id="image-upload" name="product_image" type="file" class="hidden" accept="image/*">
                                        </label>
                                    </div>
                                    <button type="submit" name="send_message" class="bg-slate-800 text-white font-bold h-12 w-12 rounded-full flex items-center justify-center hover:bg-slate-900 transition-colors">
                                        <i class="fas fa-paper-plane"></i>
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="lg:col-span-5 space-y-8">
                        <div class="card p-6 md:p-8">
                           <h2 class="text-2xl font-bold text-slate-800 mb-6">Detalles de la Transacción</h2>
                            <div class="space-y-4">
                                <div class="flex justify-between items-baseline"><span class="text-slate-500">Producto/Servicio:</span><span class="font-semibold text-right text-slate-900"><?php echo htmlspecialchars($transaction['product_description']); ?></span></div><hr>
                                <div class="flex justify-between items-baseline"><span class="text-slate-500">Comprador:</span><a href="profile.php?id=<?php echo $transaction['buyer_id']; ?>" class="font-semibold text-right text-slate-600 hover:underline"><?php echo htmlspecialchars($transaction['buyer_name']); ?></a></div>
                                <div class="flex justify-between items-baseline"><span class="text-slate-500">Vendedor:</span><a href="profile.php?id=<?php echo $transaction['seller_id']; ?>" class="font-semibold text-right text-slate-600 hover:underline"><?php echo htmlspecialchars($transaction['seller_name']); ?></a></div><hr>
                                <div class="flex justify-between items-baseline"><span class="text-slate-500">Monto del Acuerdo:</span><span class="font-semibold text-right text-slate-900">$<?php echo htmlspecialchars(number_format($transaction['amount'], 2)); ?> COP</span></div>
                                <div class="flex justify-between items-baseline text-xs"><span class="text-slate-500">Comisión pagada por Comprador:</span><span class="font-semibold text-right text-red-600">- $<?php echo htmlspecialchars(number_format($buyer_commission_paid, 2)); ?> COP</span></div>
                                <div class="flex justify-between items-baseline text-xs"><span class="text-slate-500">Comisión pagada por Vendedor:</span><span class="font-semibold text-right text-red-600">- $<?php echo htmlspecialchars(number_format($seller_commission_paid, 2)); ?> COP</span></div>
                                <div class="flex justify-between items-baseline pt-1"><span class="text-slate-500 font-bold">Comisión Total:</span><span class="font-bold text-right text-red-700">- $<?php echo htmlspecialchars(number_format($transaction['commission'], 2)); ?> COP</span></div>
                                <hr class="border-dashed">
                                <div class="flex justify-between items-baseline"><span class="font-bold text-lg text-slate-800">El Vendedor Recibirá:</span><span class="font-bold text-lg text-right text-green-600">$<?php echo htmlspecialchars(number_format($transaction['net_amount'], 2)); ?> COP</span></div>
                            </div>
                        </div>
                         <?php if (!empty($agreement_details)): ?>
                        <div class="card p-6 md:p-8">
                            <h2 class="text-2xl font-bold text-slate-800 mb-6">Términos del Acuerdo</h2>
                            <div class="space-y-4">
                                <?php foreach ($agreement_details as $key => $value): ?>
                                    <div class="flex flex-col border-b pb-3 last:border-b-0">
                                        <span class="text-slate-500 text-sm"><?php echo htmlspecialchars($agreement_labels[$key] ?? ucfirst(str_replace('_', ' ', $key))); ?>:</span>
                                        <p class="font-semibold text-slate-900 mt-1"><?php echo nl2br(htmlspecialchars($value)); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                         <?php if ($current_status === 'released'): ?>
                            <div class="card p-6 md:p-8">
                                <h2 class="text-2xl font-bold text-slate-800 mb-6">Calificaciones</h2>
                                <!-- Lógica de calificaciones -->
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="lg:col-span-3">
                        <div class="card p-6 md:p-8 sticky top-8">
                            <h2 class="text-2xl font-bold text-slate-800 mb-6">Panel de Acciones</h2>
                            <div class="text-center">
                                <p class="mb-4 text-slate-600">Tu rol: <span class="font-bold text-slate-800"><?php echo htmlspecialchars($user_role); ?></span></p>
                                <?php if (!$is_finished): ?>
                                    <?php if ($user_role === 'Comprador' && $current_status === 'initiated'): ?>
                                        <div class="p-4 bg-slate-50 border-t-4 border-slate-200 rounded-b space-y-4">
                                            <h3 class="font-bold text-lg text-slate-800">Acción Requerida</h3><p class="text-sm text-slate-600">Deposita los fondos de forma segura.</p>
                                            <div class="text-xs text-left p-3 bg-blue-50 rounded-lg">Tu Saldo Disponible: <span class="font-bold text-blue-800">$<?php echo number_format($buyer_balance, 2); ?> COP</span></div>
                                            <?php if ($buyer_balance >= $amount_to_charge_wallet): ?>
                                                <form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST" onsubmit="return confirm('Se descontará $<?php echo number_format($amount_to_charge_wallet, 2); ?> de tu billetera. ¿Estás seguro?');"><input type="hidden" name="pay_with_wallet" value="1"><button type="submit" class="w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-green-700 transition-colors mb-2"><i class="fas fa-wallet mr-2"></i>Pagar con Billetera ($<?php echo number_format($amount_to_charge_wallet, 2); ?>)</button></form>
                                                <p class="text-xs text-slate-500 my-2">O usa otro método de pago:</p>
                                            <?php else: ?>
                                                 <p class="text-xs text-red-600 my-2">No tienes saldo suficiente para pagar desde tu billetera.</p>
                                            <?php endif; ?>
                                            <form action="https://checkout.wompi.co/p/" method="GET">
                                                <input type="hidden" name="public-key" value="<?php echo defined('WOMPI_PUBLIC_KEY') ? WOMPI_PUBLIC_KEY : ''; ?>">
                                                <input type="hidden" name="currency" value="COP">
                                                <input type="hidden" name="amount-in-cents" value="<?php echo $amount_in_cents; ?>">
                                                <input type="hidden" name="reference" value="<?php echo htmlspecialchars($transaction_uuid); ?>">
                                                <?php if(!empty($wompi_signature)): ?>
                                                <input type="hidden" name="signature:integrity" value="<?php echo htmlspecialchars($wompi_signature); ?>">
                                                <?php endif; ?>
                                                <input type="hidden" name="redirect-url" value="<?php echo htmlspecialchars($redirect_url_wompi); ?>">
                                                <button type="submit" class="w-full bg-slate-800 text-white font-bold py-3 px-4 rounded-lg hover:bg-slate-900 transition-colors">Pagar con Wompi ($<?php echo number_format($amount_to_charge_gateway, 2); ?>)</button>
                                            </form>
                                        </div>
                                    <?php elseif ($user_role === 'Vendedor' && $current_status === 'funded'): ?>
                                        <div class="p-4 bg-blue-50 border-t-4 border-blue-400 rounded-b space-y-4">
                                            <h3 class="font-bold text-lg text-gray-800">Gestionar Entrega</h3>
                                            <div>
                                                <h4 class="font-semibold text-gray-700">Sello de Entrega QR</h4>
                                                <?php if (is_null($transaction['qr_code_token'] ?? null)): ?>
                                                    <p class="text-xs text-gray-600 mt-1 mb-3">Genera el sello para confirmar la entrega.</p>
                                                    <form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST"><input type="hidden" name="generate_qr" value="1"><button type="submit" class="w-full bg-slate-700 text-white font-bold py-2 px-4 rounded-lg text-sm"><i class="fas fa-qrcode mr-2"></i>Generar Sello QR</button></form>
                                                <?php else: ?>
                                                    <p class="text-xs text-gray-600 mt-1 mb-3">Sello generado. Muéstralo al comprador para confirmar la entrega.</p>
                                                    <div class="p-2 bg-white border rounded-lg mb-2">
                                                        <?php
                                                            $qr_options = new QROptions([
                                                                'outputType' => QRCode::OUTPUT_IMAGE_PNG,
                                                                'eccLevel' => QRCode::ECC_L,
                                                                'scale' => 5,
                                                                'imageBase64' => true,
                                                            ]);
                                                            $qrcode_image_data = (new QRCode($qr_options))->render($transaction['qr_code_token']);
                                                            echo '<img src="' . $qrcode_image_data . '" alt="Sello QR de la transacción">';
                                                        ?>
                                                    </div>
                                                    <form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST"><input type="hidden" name="new_status" value="shipped"><button type="submit" class="w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-green-700"><i class="fas fa-truck mr-2"></i>Marcar como Enviado</button></form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php elseif ($user_role === 'Comprador' && $current_status === 'shipped'): ?>
                                        <div class="p-4 bg-green-50 border-t-4 border-green-400 rounded-b">
                                            <h3 class="font-bold text-lg text-gray-800">Acción Requerida</h3>
                                            <p class="text-sm text-gray-600 mt-2 mb-4">Confirma que recibiste el producto para iniciar el período de garantía.</p>
                                            <button id="scan-qr-btn" class="w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg"><i class="fas fa-camera mr-2"></i>Escanear Sello de Entrega</button>
                                            <div id="qr-scanner-container" class="hidden mt-4" data-token="<?php echo htmlspecialchars($transaction['qr_code_token'] ?? ''); ?>"><div id="qr-reader" class="w-full"></div><button id="stop-scan-btn" class="mt-2 w-full text-xs text-white bg-red-600/80 py-1 rounded-md">Cancelar</button></div>
                                            <div id="scan-result" class="mt-4 text-sm font-semibold"></div>
                                            <p class="text-xs text-gray-500 mt-4">¿Problemas? <button id="manual-confirm-btn" class="underline">Confirmar recepción manualmente</button>.</p>
                                            <form id="confirm-reception-form" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST" class="hidden"><input type="hidden" name="new_status" value="received"></form>
                                        </div>
                                    <?php elseif ($current_status === 'received'): ?>
                                        <div class="p-4 bg-yellow-50 border-t-4 border-yellow-400 rounded-b">
                                            <h3 class="font-bold text-lg text-slate-800">Periodo de Garantía Activo</h3>
                                            <?php if ($user_role === 'Comprador'): ?>
                                                <p class="text-sm text-slate-600 mt-2 mb-2">Inspecciona el producto y confirma que todo esté en orden.</p>
                                                <form id="release-funds-form" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST" class="mt-4"><input type="hidden" name="new_status" value="released"><button type="submit" class="w-full bg-green-600 text-white font-bold py-2 px-4 rounded-lg text-sm hover:bg-green-700 mb-2"><i class="fas fa-check-circle mr-2"></i>Todo OK, Liberar Fondos</button></form>
                                                <a href="dispute.php?tx_uuid=<?php echo htmlspecialchars($transaction_uuid); ?>" class="w-full block mt-2 text-center bg-red-100 text-red-700 font-bold py-2 px-4 rounded-lg hover:bg-red-200 transition-colors text-sm">
                                                    <i class="fas fa-exclamation-triangle mr-2"></i>Informar un problema
                                                </a>
                                            <?php else: ?>
                                                <p class="text-sm text-slate-600 mt-2 mb-4">El comprador está inspeccionando el producto. Los fondos se liberarán automáticamente.</p>
                                            <?php endif; ?>
                                            <?php if (!empty($transaction['release_funds_at'])): ?>
                                                <div class="mt-4">
                                                    <p class="text-xs text-slate-500">Tiempo restante:</p>
                                                    <?php $inspection_period_minutes = (defined('INSPECTION_PERIOD_MINUTES') && is_numeric(INSPECTION_PERIOD_MINUTES)) ? INSPECTION_PERIOD_MINUTES : 10; ?>
                                                    <div id="countdown-timer" class="text-2xl font-bold text-slate-700" data-release-time="<?php echo strtotime($transaction['release_funds_at']) * 1000; ?>" data-total-duration="<?php echo $inspection_period_minutes * 60; ?>">--:--</div>
                                                    <div class="w-full bg-slate-200 rounded-full h-2.5 mt-1"><div id="progress-bar" class="bg-yellow-500 h-2.5 rounded-full" style="width: 0%"></div></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-slate-500 italic py-6">Esperando a la otra parte.</p>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="p-4 bg-green-50 border-t-4 border-green-400 rounded-b"><h3 class="font-bold text-lg text-green-800"><i class="fas fa-check-circle mr-2"></i>Transacción Finalizada</h3></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Lógica para el escáner QR
            const scanBtn = document.getElementById('scan-qr-btn');
            const scannerContainer = document.getElementById('qr-scanner-container');
            const stopScanBtn = document.getElementById('stop-scan-btn');
            const scanResultEl = document.getElementById('scan-result');
            let html5QrCode;

            if (scanBtn) {
                scanBtn.addEventListener('click', () => {
                    scannerContainer.classList.remove('hidden');
                    html5QrCode = new Html5Qrcode("qr-reader");
                    const qrCodeSuccessCallback = (decodedText, decodedResult) => {
                        scanResultEl.innerHTML = `<span class="text-green-600">Código detectado. Verificando...</span>`;
                        if (html5QrCode.isScanning) {
                            html5QrCode.stop();
                        }
                        fetch('verify_qr.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ qr_token: decodedText, transaction_uuid: '<?php echo $transaction_uuid; ?>' })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert(data.message);
                                window.location.reload();
                            } else {
                                alert('Error: ' + data.message);
                                scanResultEl.innerHTML = `<span class="text-red-600">Error: ${data.message}</span>`;
                            }
                        });
                    };
                    const config = { fps: 10, qrbox: { width: 250, height: 250 } };
                    html5QrCode.start({ facingMode: "environment" }, config, qrCodeSuccessCallback);
                });

                stopScanBtn.addEventListener('click', () => {
                    if (html5QrCode && html5QrCode.isScanning) {
                        html5QrCode.stop();
                        scannerContainer.classList.add('hidden');
                    }
                });
            }

            // Lógica para confirmación manual
            const manualConfirmBtn = document.getElementById('manual-confirm-btn');
            if (manualConfirmBtn) {
                manualConfirmBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    if(confirm('¿Estás seguro de que quieres confirmar la recepción manualmente? Esta acción no se puede deshacer.')) {
                        document.getElementById('confirm-reception-form').submit();
                    }
                });
            }

            // Lógica para el temporizador de garantía
            const countdownTimerEl = document.getElementById('countdown-timer');
            const progressBarEl = document.getElementById('progress-bar');
            if (countdownTimerEl && progressBarEl) {
                const releaseTime = parseInt(countdownTimerEl.dataset.releaseTime, 10);
                const totalDuration = parseInt(countdownTimerEl.dataset.totalDuration, 10) * 1000;

                if (!isNaN(releaseTime) && totalDuration > 0) {
                    const updateCountdown = () => {
                        const now = Date.now();
                        const remainingTime = releaseTime - now;

                        if (remainingTime <= 0) {
                            countdownTimerEl.textContent = '00:00';
                            progressBarEl.style.width = '100%';
                            clearInterval(countdownInterval);
                            setTimeout(() => window.location.reload(), 1500);
                            return;
                        }
                        const minutes = Math.floor((remainingTime % (1000 * 60 * 60)) / (1000 * 60));
                        const seconds = Math.floor((remainingTime % (1000 * 60)) / 1000);
                        countdownTimerEl.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;

                        const elapsedTime = totalDuration - remainingTime;
                        const progressPercentage = Math.min(100, (elapsedTime / totalDuration) * 100);
                        progressBarEl.style.width = `${progressPercentage}%`;
                    };
                    const countdownInterval = setInterval(updateCountdown, 1000);
                    updateCountdown();
                }
            }
        });
    </script>
</body>
</html>
