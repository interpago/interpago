<?php
// --- INICIO: SOLUCIÓN ANTI-CACHE ---
// Estas cabeceras aseguran que el navegador siempre cargue los datos más recientes.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
// --- FIN: SOLUCIÓN ANTI-CACHE ---

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// --- INICIO: VERIFICACIÓN DE AUTENTICACIÓN ---
if (!isset($_SESSION['user_uuid']) || empty($_SESSION['user_uuid'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit;
}
// --- FIN: VERIFICACIÓN DE AUTENTICACIÓN ---

// Cargar todas las dependencias de forma segura.
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/lib/send_notification.php')) {
    require_once __DIR__ . '/lib/send_notification.php';
}

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

// --- INICIO: FUNCIÓN DE AYUDA PARA MENSAJES DEL SISTEMA ---
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
// --- FIN: FUNCIÓN DE AYUDA ---

// ========= INICIO: LÓGICA DE VERIFICACIÓN DE PAGO WOMPI (SOLUCIÓN POP-UP) =========
if (isset($_GET['wompi_id']) && isset($_GET['tx_uuid'])) {
    $wompi_tx_id = $_GET['wompi_id'];
    $transaction_uuid_for_redirect = $_GET['tx_uuid'];
    $redirect_url_clean = rtrim(APP_URL, '/') . '/transaction.php?tx_uuid=' . $transaction_uuid_for_redirect;

    $curl_url = WOMPI_API_URL . '/transactions/' . $wompi_tx_id;
    $ch = curl_init($curl_url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WOMPI_PRIVATE_KEY]]);
    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $wompi_data = json_decode($response_body, true);
        $transaction_data = $wompi_data['data'] ?? null;
        if ($transaction_data && $transaction_data['status'] === 'APPROVED' && $transaction_data['reference'] === $transaction_uuid_for_redirect) {
            $stmt_check = $conn->prepare("SELECT id, status FROM transactions WHERE transaction_uuid = ?");
            $stmt_check->bind_param("s", $transaction_uuid_for_redirect);
            $stmt_check->execute();
            $current_tx = $stmt_check->get_result()->fetch_assoc();
            if ($current_tx && $current_tx['status'] === 'initiated') {
                $stmt_update = $conn->prepare("UPDATE transactions SET status = 'funded', payment_reference = ? WHERE transaction_uuid = ?");
                $stmt_update->bind_param("ss", $wompi_tx_id, $transaction_uuid_for_redirect);
                $stmt_update->execute();
                if ($stmt_update->affected_rows > 0) {
                    add_system_message($conn, $current_tx['id'], "El pago ha sido completado. Los fondos están en custodia.");
                }
                $_SESSION['success_message'] = "¡Pago completado! Los fondos están en custodia.";
            }
        }
    }
    header("Location: " . $redirect_url_clean);
    exit;
}
// ========= FIN: LÓGICA DE VERIFICACIÓN DE PAGO WOMPI =========

$transaction_uuid = $_GET['tx_uuid'] ?? ($_GET['id'] ?? '');
if (empty($transaction_uuid)) die("Error: No se proporcionó un ID de transacción.");

// 1. Obtener detalles completos de la transacción
$stmt = $conn->prepare("SELECT * FROM transactions WHERE transaction_uuid = ?");
$stmt->bind_param("s", $transaction_uuid);
$stmt->execute();
$transaction = $stmt->get_result()->fetch_assoc();
if (!$transaction) die("Error: Transacción no encontrada.");

// Inicializar variables
$messages = []; $user_role = 'Observador'; $error_message = ''; $success_message = '';
$current_status = $transaction['status'];
$is_finished = in_array($current_status, ['released', 'cancelled', 'dispute']);
$wompi_signature = ''; $amount_in_cents = 0; $redirect_url_wompi = '';
$last_message_id = 0; $buyer_balance = 0; $amount_to_charge_gateway = 0;
$amount_to_charge_wallet = 0; $buyer_commission_paid = 0; $seller_commission_paid = 0;
$qr_code_data_uri = '';

// 2. Determinar el rol del usuario actual
$logged_in_user_uuid = $_SESSION['user_uuid'];
if (trim($logged_in_user_uuid) === trim($transaction['buyer_uuid'])) $user_role = 'Comprador';
elseif (trim($logged_in_user_uuid) === trim($transaction['seller_uuid'])) $user_role = 'Vendedor';
$_SESSION['user_role'] = $user_role;

// 3. Lógica de liberación automática de fondos
if ($current_status === 'received' && !empty($transaction['release_funds_at']) && new DateTime() >= new DateTime($transaction['release_funds_at'])) {
    $conn->begin_transaction();
    try {
        $update_tx_stmt = $conn->prepare("UPDATE transactions SET status = 'released', completed_at = NOW() WHERE id = ? AND status = 'received'");
        $update_tx_stmt->bind_param("i", $transaction['id']);
        $update_tx_stmt->execute();
        if ($update_tx_stmt->affected_rows > 0) {
            $update_balance_stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $update_balance_stmt->bind_param("di", $transaction['net_amount'], $transaction['seller_id']);
            $update_balance_stmt->execute();
            add_system_message($conn, $transaction['id'], "El periodo de garantía ha finalizado. Los fondos han sido liberados al vendedor.");
        }
        $conn->commit();
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error al liberar los fondos automáticamente: " . $e->getMessage();
    }
}

// 4. Procesar acciones del formulario POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $should_redirect = true;

    if (isset($_POST['mark_as_shipped']) && $user_role === 'Vendedor' && $current_status === 'funded') {
        $qr_token = 'INTERPAGO_TX_' . $transaction['id'] . '_' . bin2hex(random_bytes(16));
        $update_stmt = $conn->prepare("UPDATE transactions SET status = 'shipped', qr_code_token = ? WHERE id = ?");
        $update_stmt->bind_param("si", $qr_token, $transaction['id']);
        if ($update_stmt->execute()) {
             add_system_message($conn, $transaction['id'], "El vendedor ha marcado el producto como enviado. El comprador ahora debe confirmar la recepción.");
        } else {
             $_SESSION['error_message'] = "Error al actualizar el estado.";
        }
    }
    elseif (isset($_POST['confirm_reception_manual']) && $user_role === 'Comprador' && $current_status === 'shipped') {
        $release_time_sql = "NOW() + INTERVAL " . INSPECTION_PERIOD_MINUTES . " MINUTE";
        $update_stmt = $conn->prepare("UPDATE transactions SET status = 'received', release_funds_at = ($release_time_sql) WHERE id = ?");
        $update_stmt->bind_param("i", $transaction['id']);
        if ($update_stmt->execute()) {
             add_system_message($conn, $transaction['id'], "El comprador ha confirmado la recepción del producto. El periodo de garantía ha comenzado.");
             $_SESSION['success_message'] = "Recepción confirmada. Inicia el periodo de garantía.";
        } else {
             $_SESSION['error_message'] = "Error al confirmar la recepción.";
        }
    }
    elseif (isset($_POST['pay_with_wallet']) && $user_role === 'Comprador' && $current_status === 'initiated') {
        $balance_stmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
        $balance_stmt->bind_param("i", $transaction['buyer_id']);
        $balance_stmt->execute();
        $buyer_balance = $balance_stmt->get_result()->fetch_assoc()['balance'] ?? 0;

        $amount_to_charge_wallet_calc = $transaction['amount'];
        if ($transaction['commission_payer'] === 'buyer') $amount_to_charge_wallet_calc += $transaction['commission'];
        elseif ($transaction['commission_payer'] === 'split') $amount_to_charge_wallet_calc += ($transaction['commission'] / 2);

        if ($buyer_balance >= $amount_to_charge_wallet_calc) {
            $conn->begin_transaction();
            try {
                $new_balance = $buyer_balance - $amount_to_charge_wallet_calc;
                $stmt_debit = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
                $stmt_debit->bind_param("di", $new_balance, $transaction['buyer_id']);
                $stmt_debit->execute();

                $stmt_fund = $conn->prepare("UPDATE transactions SET status = 'funded' WHERE id = ? AND status = 'initiated'");
                $stmt_fund->bind_param("i", $transaction['id']);
                $stmt_fund->execute();

                if ($stmt_fund->affected_rows > 0) {
                    add_system_message($conn, $transaction['id'], "El pago ha sido completado con el saldo de la billetera. Los fondos están en custodia.");
                    $conn->commit();
                    $_SESSION['success_message'] = "Pago con billetera completado.";
                } else {
                    throw new Exception("La transacción ya no estaba pendiente de pago.");
                }
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_message'] = "Error al procesar el pago con la billetera: " . $e->getMessage();
            }
        } else {
            $_SESSION['error_message'] = "Saldo insuficiente para completar la transacción.";
        }
    }
    elseif (isset($_POST['send_message']) && in_array($user_role, ['Comprador', 'Vendedor']) && !$is_finished) {
        $message_content = trim($_POST['message']);
        $image_path = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/chat/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
            $file_info = pathinfo($_FILES['image']['name']);
            $file_extension = $file_info['extension'];
            $file_name = 'chat_' . $transaction['id'] . '_' . uniqid() . '.' . $file_extension;
            $target_file = $upload_dir . $file_name;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                $image_path = 'uploads/chat/' . $file_name;
            } else {
                $_SESSION['error_message'] = 'Error al subir la imagen.';
            }
        }
        if (!empty($message_content) || $image_path) {
            $stmt_msg = $conn->prepare("INSERT INTO messages (transaction_id, sender_role, message, image_path) VALUES (?, ?, ?, ?)");
            $stmt_msg->bind_param("isss", $transaction['id'], $user_role, $message_content, $image_path);
            if (!$stmt_msg->execute()) {
                $_SESSION['error_message'] = 'Error al enviar el mensaje.';
            }
        }
    }
    else {
        $should_redirect = false;
    }

    if ($should_redirect) {
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// 5. Calcular montos y comisiones
$amount_to_charge_gateway = $transaction['amount'];
if($transaction['commission_payer'] === 'buyer'){ $amount_to_charge_gateway += $transaction['commission']; }
elseif($transaction['commission_payer'] === 'split'){ $amount_to_charge_gateway += ($transaction['commission'] / 2); }
$amount_in_cents = round($amount_to_charge_gateway * 100);

if ($transaction['commission_payer'] === 'buyer') $buyer_commission_paid = $transaction['commission'];
elseif ($transaction['commission_payer'] === 'seller') $seller_commission_paid = $transaction['commission'];
elseif ($transaction['commission_payer'] === 'split') {
    $buyer_commission_paid = $transaction['commission'] / 2;
    $seller_commission_paid = $transaction['commission'] / 2;
}

if ($user_role === 'Comprador') {
    $balance_stmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
    $balance_stmt->bind_param("i", $transaction['buyer_id']);
    $balance_stmt->execute();
    $buyer_balance = $balance_stmt->get_result()->fetch_assoc()['balance'] ?? 0;
}

// 6. Generar QR si ya existe el token
if (!empty($transaction['qr_code_token'])) {
    $qr_options = new QROptions(['outputType' => QRCode::OUTPUT_IMAGE_PNG, 'eccLevel' => QRCode::ECC_L]);
    $qrcode = new QRCode($qr_options);
    $qr_code_data_uri = $qrcode->render($transaction['qr_code_token']);
}

// 7. Firma para Wompi
if ($current_status === 'initiated' && defined('WOMPI_INTEGRITY_SECRET')) {
    $redirect_url_wompi = rtrim(APP_URL, '/') . '/payment_response.php';
    $concatenation = $transaction['transaction_uuid'] . $amount_in_cents . 'COP' . WOMPI_INTEGRITY_SECRET;
    $wompi_signature = hash('sha256', $concatenation);
}

// 8. Recuperar y limpiar mensajes de sesión
if (isset($_SESSION['error_message'])) { $error_message = $_SESSION['error_message']; unset($_SESSION['error_message']); }
if (isset($_SESSION['success_message'])) { $success_message = $_SESSION['success_message']; unset($_SESSION['success_message']); }

// 9. Obtener mensajes del chat
$msg_stmt = $conn->prepare("SELECT * FROM messages WHERE transaction_id = ? ORDER BY created_at ASC");
$msg_stmt->bind_param("i", $transaction['id']);
$msg_stmt->execute();
$messages = $msg_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
if(!empty($messages)) {
    $last_message_id = end($messages)['id'];
}

// 10. Funciones de ayuda para la vista
function get_status_class($s, $c) { if(in_array($c,['cancelled', 'dispute'])) return 'timeline-step-special'; $st=['initiated','funded','shipped','received','released']; $ci=array_search($c,$st); $si=array_search($s,$st); if($c==='released'||$si<$ci) return 'timeline-step-complete'; if($si===$ci) return 'timeline-step-active'; return 'timeline-step-incomplete'; }
function get_line_class($s, $c) { $st=['initiated','funded','shipped','received']; $ci=array_search($c,$st); $si=array_search($s,$st); if($si<$ci&&!in_array($c,['cancelled', 'dispute'])) return 'timeline-line-complete'; return 'timeline-line'; }
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
    <script type="text/javascript" src="https://checkout.wompi.co/widget.js"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; }
        .card { background-color: white; border-radius: 1.5rem; box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.1); }
        .timeline-step { transition: all 0.3s ease; }
        .timeline-step-active { background-color: #1e293b; color: white; }
        .timeline-step-complete { background-color: #16a34a; color: white; }
        .timeline-step-incomplete { background-color: #e2e8f0; color: #475569; }
        .timeline-step-special { background-color: #ef4444; color: white; }
        .timeline-line { background-color: #e2e8f0; height: 4px; flex: 1; }
        .timeline-line-complete { background-color: #16a34a; }
        #qr-reader { border: 2px dashed #cbd5e1; border-radius: 1rem; overflow: hidden; }
        #qr-reader-results { color: #16a34a; font-weight: bold; }
    </style>
</head>
<body class="p-4 md:p-8">
    <div class="max-w-7xl mx-auto">
        <header class="text-center mb-12">
             <a href="dashboard.php" class="text-slate-600 hover:text-slate-900 mb-4 inline-block"><i class="fas fa-arrow-left mr-2"></i>Volver al Panel</a>
             <h1 class="text-4xl md:text-5xl font-extrabold text-slate-900">Detalle de la Transacción</h1>
             <p class="text-slate-500 mt-2">ID: <span class="font-mono bg-slate-200 text-slate-700 px-2 py-1 rounded-md text-sm"><?php echo htmlspecialchars($transaction['transaction_uuid']); ?></span></p>
        </header>

        <?php if (!empty($error_message)): ?>
            <div class="card p-6 mb-8 text-center bg-red-100 border border-red-400 text-red-700"><p class="font-semibold"><?php echo htmlspecialchars($error_message); ?></p></div>
        <?php endif; ?>
        <?php if (!empty($success_message)): ?>
            <div class="card p-6 mb-8 text-center bg-green-100 border border-green-400 text-green-700"><p class="font-semibold"><?php echo htmlspecialchars($success_message); ?></p></div>
        <?php endif; ?>

        <?php if ($transaction): ?>
            <div id="main-content">
                <div class="card p-6 md:p-8 mb-8">
                    <h2 class="text-2xl font-bold text-slate-800 mb-6">Línea de Tiempo del Proceso</h2>
                    <div class="flex items-center">
                        <?php $statuses = ['initiated' => 'Inicio', 'funded' => 'En Custodia', 'shipped' => 'Enviado', 'received' => 'Recibido', 'released' => 'Liberado']; if(in_array($current_status,['dispute','cancelled'])){$statuses[$current_status] = ucfirst($current_status);} $keys = array_keys($statuses); foreach($statuses as $status_key => $status_name): if(!in_array($status_key, $keys)) continue; if(!in_array($current_status, ['dispute', 'cancelled']) && in_array($status_key, ['dispute', 'cancelled'])) continue; $step_class = get_status_class($status_key, $transaction['status']); $line_class = get_line_class($status_key, $transaction['status']); ?>
                            <div class="flex-1 flex flex-col items-center text-center"><div class="w-12 h-12 rounded-full flex items-center justify-center font-bold text-lg timeline-step <?php echo $step_class; ?>"><?php echo ($step_class == 'timeline-step-complete' || $step_class == 'timeline-step-special') ? '<i class="fas fa-check"></i>' : array_search($status_key, $keys) + 1; ?></div><p class="mt-2 text-sm font-medium text-slate-700"><?php echo htmlspecialchars($status_name); ?></p></div>
                            <?php if ($status_key !== 'released' && $status_key !== 'dispute' && $status_key !== 'cancelled' && $status_key !== end($keys)): ?><div class="timeline-line <?php echo $line_class; ?>"></div><?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid lg:grid-cols-12 gap-8">
                    <div class="lg:col-span-4 card p-6 md:p-8">
                        <h2 class="text-2xl font-bold text-slate-800 mb-6">Chat de la Transacción</h2>
                        <div id="chat-box" class="h-[32rem] overflow-y-auto space-y-6 pr-4" data-last-message-id="<?php echo $last_message_id; ?>">
                            <?php if (empty($messages)): ?>
                                <div id="no-messages" class="text-center py-10 text-slate-400"><i class="fas fa-comments text-4xl mb-3"></i><p>Aún no hay mensajes.</p></div>
                            <?php else: foreach ($messages as $msg): ?>
                                <div class="flex <?php echo ($msg['sender_role'] === $user_role || $msg['sender_role'] === 'System') ? 'justify-end' : 'justify-start'; ?>">
                                    <div class="p-4 max-w-md <?php if ($msg['sender_role'] === 'System') echo 'bg-blue-100 text-blue-800 rounded-lg'; else echo (($msg['sender_role'] === $user_role) ? 'bg-slate-800 text-white rounded-l-2xl rounded-tr-2xl' : 'bg-slate-200 text-slate-800 rounded-r-2xl rounded-tl-2xl'); ?>">
                                        <?php if (!empty($msg['image_path'])): ?><a href="<?php echo htmlspecialchars($msg['image_path']); ?>" target="_blank" class="block mb-2"><img src="<?php echo htmlspecialchars($msg['image_path']); ?>" alt="Imagen adjunta" class="rounded-lg max-h-48 w-full object-cover"></a><?php endif; ?>
                                        <?php if (!empty($msg['message'])): ?><p class="text-md"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p><?php endif; ?>
                                        <p class="text-xs <?php echo ($msg['sender_role'] === $user_role) ? 'text-slate-400' : 'text-slate-500'; ?> mt-2 text-right"><?php echo htmlspecialchars($msg['sender_role']); ?> - <?php echo htmlspecialchars(date("d/m H:i", strtotime($msg['created_at']))); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                        <?php if (in_array($user_role, ['Comprador', 'Vendedor']) && !$is_finished): ?>
                            <form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST" enctype="multipart/form-data" class="pt-6 border-t mt-4">
                                <div class="flex items-start space-x-3">
                                    <div class="relative flex-grow">
                                        <textarea name="message" placeholder="Escribe tu mensaje..." class="w-full p-3 pr-12 border border-slate-300 rounded-xl resize-none overflow-hidden" rows="1"></textarea>
                                        <label for="image-upload" class="absolute right-3 top-3 cursor-pointer p-1 text-slate-500 hover:text-slate-800"><i class="fas fa-paperclip"></i><input id="image-upload" name="image" type="file" class="hidden" accept="image/*"></label>
                                    </div>
                                    <button type="submit" name="send_message" class="bg-slate-800 text-white font-bold h-12 w-12 rounded-full flex items-center justify-center hover:bg-slate-900 transition-colors"><i class="fas fa-paper-plane"></i></button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="lg:col-span-5 space-y-8">
                        <div class="card p-6 md:p-8">
                            <h2 class="text-2xl font-bold text-slate-800 mb-6">Detalles del Acuerdo</h2>
                            <div class="space-y-4">
                                <div class="flex justify-between items-baseline"><span class="text-slate-500">Producto/Servicio:</span><span class="font-semibold text-right text-slate-900"><?php echo htmlspecialchars($transaction['product_description']); ?></span></div><hr>
                                <div class="flex justify-between items-baseline"><span class="text-slate-500">Comprador:</span><span class="font-semibold text-right text-slate-600"><?php echo htmlspecialchars($transaction['buyer_name']); ?></span></div>
                                <div class="flex justify-between items-baseline"><span class="text-slate-500">Vendedor:</span><span class="font-semibold text-right text-slate-600"><?php echo htmlspecialchars($transaction['seller_name']); ?></span></div><hr>
                                <div class="flex justify-between items-baseline"><span class="text-slate-500">Monto del Acuerdo:</span><span class="font-semibold text-right text-slate-900">$<?php echo htmlspecialchars(number_format($transaction['amount'], 2)); ?> COP</span></div>
                                <div class="flex justify-between items-baseline text-xs"><span class="text-slate-500">Comisión pagada por Comprador:</span><span class="font-semibold text-right text-red-600">- $<?php echo htmlspecialchars(number_format($buyer_commission_paid, 2)); ?> COP</span></div>
                                <div class="flex justify-between items-baseline text-xs"><span class="text-slate-500">Comisión pagada por Vendedor:</span><span class="font-semibold text-right text-red-600">- $<?php echo htmlspecialchars(number_format($seller_commission_paid, 2)); ?> COP</span></div>
                                <div class="flex justify-between items-baseline pt-1"><span class="text-slate-500 font-bold">Comisión Total:</span><span class="font-bold text-right text-red-700">- $<?php echo htmlspecialchars(number_format($transaction['commission'], 2)); ?> COP</span></div><hr class="border-dashed">
                                <div class="flex justify-between items-baseline"><span class="font-bold text-lg text-slate-800">El Vendedor Recibirá:</span><span class="font-bold text-lg text-right text-green-600">$<?php echo htmlspecialchars(number_format($transaction['net_amount'], 2)); ?> COP</span></div>
                            </div>
                        </div>
                    </div>

                    <div class="lg:col-span-3">
                        <div class="card p-6 md:p-8 sticky top-8">
                            <h2 class="text-2xl font-bold text-slate-800 mb-6 text-center">Panel de Acciones</h2>
                            <div class="space-y-4 text-center">
                                <p class="text-slate-600">Tu rol: <span class="font-bold text-slate-800"><?php echo htmlspecialchars($user_role); ?></span></p>

                                <?php if (!$is_finished): ?>
                                    <!-- ESTADO: INICIADO (COMPRADOR) -->
                                    <?php if ($user_role === 'Comprador' && $current_status === 'initiated'): ?>
                                        <h3 class="font-bold text-lg text-slate-800 pt-4">Acción Requerida</h3>
                                        <p class="text-sm text-slate-600">Deposita los fondos de forma segura.</p>
                                        <button type="button" id="wompi-button" class="w-full bg-slate-800 text-white font-bold py-3 px-4 rounded-lg hover:bg-slate-900 transition-colors">Pagar con Wompi</button>
                                    <?php endif; ?>

                                    <!-- ESTADO: EN CUSTODIA (VENDEDOR) -->
                                    <?php if ($user_role === 'Vendedor' && $current_status === 'funded'): ?>
                                         <h3 class="font-bold text-lg text-slate-800 pt-4">Acción Requerida</h3>
                                        <?php if(empty($transaction['qr_code_token'])): ?>
                                            <p class="text-sm text-slate-600">El pago está en custodia. Envía el producto y genera el sello QR para que el comprador confirme.</p>
                                            <form method="POST"><button type="submit" name="mark_as_shipped" class="w-full bg-blue-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-blue-700 transition-colors"><i class="fas fa-qrcode mr-2"></i>Generar Sello QR y Marcar como Enviado</button></form>
                                        <?php else: ?>
                                            <p class="text-sm text-slate-600">Muestra este Sello QR al comprador para que confirme la recepción.</p>
                                            <img src="<?php echo $qr_code_data_uri; ?>" alt="Código QR de la transacción" class="mx-auto mt-4 rounded-lg shadow-md">
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <!-- ESTADO: ENVIADO (COMPRADOR) -->
                                    <?php if ($user_role === 'Comprador' && $current_status === 'shipped'): ?>
                                        <h3 class="font-bold text-lg text-slate-800 pt-4">Acción Requerida</h3>
                                        <p class="text-sm text-slate-600">Confirma que has recibido el producto.</p>
                                        <div id="qr-reader" class="my-4 w-full"></div>
                                        <div id="qr-reader-results" class="my-2 text-center"></div>
                                        <p class="text-xs text-slate-500 my-2">O si no puedes escanear:</p>
                                        <form method="POST" onsubmit="return confirm('¿Estás seguro que recibiste el producto? Esta acción no se puede deshacer.')">
                                            <button type="submit" name="confirm_reception_manual" class="w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-green-700 transition-colors"><i class="fas fa-check-circle mr-2"></i>Confirmar Recepción Manual</button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- ESTADO: RECIBIDO (PERIODO DE GARANTÍA) -->
                                    <?php if ($current_status === 'received'): ?>
                                        <h3 class="font-bold text-lg text-slate-800 pt-4">Periodo de Garantía</h3>
                                        <p class="text-sm text-slate-600">Tienes tiempo para revisar el producto. Si todo está bien, no necesitas hacer nada.</p>
                                        <div class="text-4xl font-mono font-extrabold text-slate-900 my-4" id="countdown-timer">00:00</div>
                                        <?php if($user_role === 'Comprador'): ?>
                                            <a href="dispute.php?tx_uuid=<?php echo $transaction_uuid; ?>" class="w-full block bg-red-100 text-red-700 font-bold py-3 px-4 rounded-lg hover:bg-red-200 transition-colors"><i class="fas fa-exclamation-triangle mr-2"></i>Tengo un problema</a>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                <?php else: ?>
                                    <div class="p-4 bg-green-50 border-t-4 border-green-400 rounded-b">
                                        <h3 class="font-bold text-lg text-green-800"><i class="fas fa-check-circle mr-2"></i>Transacción Finalizada</h3>
                                    </div>
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
            // ... Tu lógica de Wompi ...

            // --- INICIO: SCRIPT DE ACTUALIZACIÓN EN TIEMPO REAL ---
            const txData = {
                uuid: '<?php echo $transaction_uuid; ?>',
                status: '<?php echo $current_status; ?>'
            };
            const currentUserRole = '<?php echo $user_role; ?>';
            const chatBox = document.getElementById('chat-box');

            function pollForUpdates() {
                const lastMessageId = chatBox.dataset.lastMessageId || 0;
                const url = `get_transaction_updates.php?tx_uuid=${txData.uuid}&last_message_id=${lastMessageId}&current_status=${txData.status}`;

                fetch(url)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            console.error('Error de sondeo:', data.error);
                            return;
                        }
                        if (data.status_changed) {
                            window.location.reload();
                            return;
                        }
                        if (data.new_messages && data.new_messages.length > 0) {
                            const noMessagesDiv = document.getElementById('no-messages');
                            if (noMessagesDiv) noMessagesDiv.remove();
                            data.new_messages.forEach(msg => {
                                appendMessage(msg);
                                chatBox.dataset.lastMessageId = msg.id;
                            });
                            chatBox.scrollTop = chatBox.scrollHeight;
                        }
                    })
                    .catch(error => console.error('Error en la petición de sondeo:', error));
            }

            function appendMessage(msg) {
                const messageDiv = document.createElement('div');
                let justifyClass = (msg.is_system || msg.sender_role === currentUserRole) ? 'justify-end' : 'justify-start';
                let bubbleClasses = 'bg-slate-200 text-slate-800 rounded-r-2xl rounded-tl-2xl';
                if(msg.is_system) {
                    bubbleClasses = 'bg-blue-100 text-blue-800 rounded-lg';
                } else if (msg.sender_role === currentUserRole) {
                    bubbleClasses = 'bg-slate-800 text-white rounded-l-2xl rounded-tr-2xl';
                }
                let imageHtml = msg.image_path ? `<a href="${msg.image_path}" target="_blank" class="block mb-2"><img src="${msg.image_path}" alt="Imagen adjunta" class="rounded-lg max-h-48 w-full object-cover"></a>` : '';
                let senderClasses = (msg.sender_role === currentUserRole) ? 'text-slate-400' : 'text-slate-500';

                messageDiv.className = `flex ${justifyClass}`;
                messageDiv.innerHTML = `<div class="p-4 max-w-md ${bubbleClasses}">${imageHtml}<p class="text-md">${msg.message}</p><p class="text-xs ${senderClasses} mt-2 text-right">${msg.sender_role} - ${msg.created_at}</p></div>`;
                chatBox.appendChild(messageDiv);
            }

            if (!<?php echo json_encode($is_finished); ?>) {
                setInterval(pollForUpdates, 3000);
            }

            // --- LÓGICA DE ESCÁNER QR ---
            if (document.getElementById('qr-reader')) {
                const qrReader = new Html5Qrcode("qr-reader");
                const onScanSuccess = (decodedText, decodedResult) => {
                    document.getElementById('qr-reader-results').innerText = `Verificando...`;
                    qrReader.stop().then(ignore => {
                         fetch('verify_qr.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ qr_token: decodedText, transaction_uuid: '<?php echo $transaction_uuid; ?>' })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if(data.success) {
                                window.location.reload();
                            } else {
                                alert('Error: ' + data.message);
                                qrReader.start({ facingMode: "environment" }, { fps: 10, qrbox: { width: 250, height: 250 } }, onScanSuccess).catch(err => console.error(err));
                            }
                        });
                    }).catch(err => console.error("Error al detener el escáner", err));
                };
                const config = { fps: 10, qrbox: { width: 250, height: 250 } };
                qrReader.start({ facingMode: "environment" }, config, onScanSuccess).catch(err => console.error("No se pudo iniciar el escáner QR", err));
            }

            // --- LÓGICA DE CUENTA REGRESIVA ---
            const countdownElement = document.getElementById('countdown-timer');
            if (countdownElement && '<?php echo $transaction["release_funds_at"]; ?>') {
                const releaseTime = new Date('<?php echo $transaction["release_funds_at"]; ?>').getTime();
                const interval = setInterval(() => {
                    const now = new Date().getTime();
                    const distance = releaseTime - now;
                    if (distance < 0) {
                        clearInterval(interval);
                        countdownElement.innerHTML = "Liberando...";
                        window.location.reload();
                        return;
                    }
                    const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((distance % (1000 * 60)) / 1000);
                    countdownElement.innerHTML = ('0' + minutes).slice(-2) + ":" + ('0' + seconds).slice(-2);
                }, 1000);
            }
        });
    </script>
</body>
</html>
