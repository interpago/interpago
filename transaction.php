<?php
// Muestra todos los errores para facilitar la depuración.
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// Cargar todas las dependencias de forma segura.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/lib/send_notification.php')) {
    require_once __DIR__ . '/lib/send_notification.php';
}

// Obtener el ID de la transacción desde la URL para todas las operaciones.
$transaction_uuid = $_GET['tx_uuid'] ?? ($_GET['id'] ?? '');

if (empty($transaction_uuid)) {
    die("Error: No se proporcionó un ID de transacción.");
}

// ========= INICIO: Manejador de Peticiones AJAX =========
// Esta sección maneja las solicitudes en segundo plano (AJAX) desde el frontend.
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'check_status') {
        header('Content-Type: application/json');

        // Es crucial que la conexión a la base de datos '$conn' esté disponible aquí.
        // Asegúrate de que config.php la define correctamente.
        $stmt_check = $conn->prepare("SELECT status FROM transactions WHERE transaction_uuid = ?");
        $stmt_check->bind_param("s", $transaction_uuid);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        $transaction_status = $result_check->fetch_assoc();

        if ($transaction_status) {
            echo json_encode(['status' => $transaction_status['status']]);
        } else {
            echo json_encode(['status' => 'not_found']);
        }
        exit; // Termina el script para no renderizar el HTML.
    }
     if ($_GET['action'] === 'get_messages') {
        // Lógica para obtener nuevos mensajes (si la implementas en el futuro)
        exit;
    }
}
// ========= FIN: Manejador de Peticiones AJAX =========


// 1. Obtener detalles completos de la transacción
$stmt = $conn->prepare("SELECT * FROM transactions WHERE transaction_uuid = ?");
$stmt->bind_param("s", $transaction_uuid);
$stmt->execute();
$result = $stmt->get_result();
$transaction = $result->fetch_assoc();

if (!$transaction) {
    die("Error: Transacción no encontrada.");
}

// Inicializar todas las variables para evitar errores.
$messages = [];
$user_role = 'Observador';
$error_message = '';
$current_status = $transaction['status'];
$is_finished = in_array($current_status, ['released', 'cancelled', 'dispute']);
$wompi_signature = '';
$amount_in_cents = 0;
$redirect_url_wompi = '';
$last_message_id = 0;
$buyer_balance = 0;
$amount_to_charge_gateway = 0;
$amount_to_charge_wallet = 0;
$buyer_commission_paid = 0;
$seller_commission_paid = 0;

// 2. Determinar el rol del usuario actual
$logged_in_user_uuid = $_SESSION['user_uuid'] ?? '';
if (!empty($logged_in_user_uuid)) {
    if ($logged_in_user_uuid === $transaction['buyer_uuid']) {
        $user_role = 'Comprador';
    } elseif ($logged_in_user_uuid === $transaction['seller_uuid']) {
        $user_role = 'Vendedor';
    }
}

// 3. Lógica de liberación automática de fondos si el tiempo de garantía ha pasado
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
            if(function_exists('send_notification')) {
                send_notification($conn, $transaction['seller_id'], "status_update", ['transaction_uuid' => $transaction_uuid, 'new_status' => 'released']);
            }
        }
        $conn->commit();
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error al liberar los fondos automáticamente: " . $e->getMessage();
    }
}

// 4. Calcular montos y comisiones
$amount_to_charge_gateway = $transaction['amount'];
if($transaction['commission_payer'] === 'buyer'){ $amount_to_charge_gateway += $transaction['commission']; }
elseif($transaction['commission_payer'] === 'split'){ $amount_to_charge_gateway += ($transaction['commission'] / 2); }
$amount_in_cents = round($amount_to_charge_gateway * 100);

$amount_to_charge_wallet = $transaction['amount'];
if ($transaction['commission_payer'] === 'buyer') {
    $amount_to_charge_wallet += $transaction['commission'];
} elseif ($transaction['commission_payer'] === 'split') {
    $amount_to_charge_wallet += ($transaction['commission'] / 2);
}

if ($transaction['commission_payer'] === 'buyer') {
    $buyer_commission_paid = $transaction['commission'];
} elseif ($transaction['commission_payer'] === 'seller') {
    $seller_commission_paid = $transaction['commission'];
} elseif ($transaction['commission_payer'] === 'split') {
    $buyer_commission_paid = $transaction['commission'] / 2;
    $seller_commission_paid = $transaction['commission'] / 2;
}

if ($user_role === 'Comprador') {
    $balance_stmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
    $balance_stmt->bind_param("i", $transaction['buyer_id']);
    $balance_stmt->execute();
    $balance_result = $balance_stmt->get_result();
    $buyer_balance_assoc = $balance_result->fetch_assoc();
    $buyer_balance = $buyer_balance_assoc['balance'] ?? 0;
}


// 5. Generar firma de Wompi si es necesario
if ($current_status === 'initiated' && defined('WOMPI_INTEGRITY_SECRET')) {
    $redirect_url_wompi = rtrim(APP_URL, '/') . '/payment_response.php';
    $concatenation = $transaction['transaction_uuid'] . $amount_in_cents . 'COP' . WOMPI_INTEGRITY_SECRET;
    $wompi_signature = hash('sha256', $concatenation);
}

// 6. Procesar acciones del formulario POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $should_redirect = true;

    // --- ACCIÓN: PAGAR CON BILLETERA (LÓGICA CORREGIDA) ---
    if (isset($_POST['pay_with_wallet']) && $user_role === 'Comprador' && $current_status === 'initiated') {
        if ($buyer_balance >= $amount_to_charge_wallet) {
            $conn->begin_transaction();
            try {
                // 1. Debitar el saldo del comprador
                $new_balance = $buyer_balance - $amount_to_charge_wallet;
                $stmt_debit = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
                $stmt_debit->bind_param("di", $new_balance, $transaction['buyer_id']);
                $stmt_debit->execute();

                // 2. Actualizar el estado de la transacción a 'funded' (CORREGIDO FINAL)
                $stmt_fund = $conn->prepare("UPDATE transactions SET status = 'funded' WHERE id = ? AND status = 'initiated'");
                $stmt_fund->bind_param("i", $transaction['id']);
                $stmt_fund->execute();

                if ($stmt_fund->affected_rows > 0) {
                    // 3. (Opcional) Enviar notificación al vendedor
                    if(function_exists('send_notification')) {
                         send_notification($conn, $transaction['seller_id'], "status_update", ['transaction_uuid' => $transaction_uuid, 'new_status' => 'funded']);
                    }
                     // 4. (Opcional) Registrar un mensaje automático en el chat
                    $system_message = "El pago ha sido completado con el saldo de la billetera y los fondos están en custodia.";
                    $stmt_msg = $conn->prepare("INSERT INTO messages (transaction_id, sender_role, message) VALUES (?, 'System', ?)");
                    $stmt_msg->bind_param("is", $transaction['id'], $system_message);
                    $stmt_msg->execute();

                    $conn->commit();
                } else {
                    // Si no se actualizó ninguna fila, la transacción ya no estaba en 'initiated'.
                    throw new Exception("La transacción ya no estaba pendiente de pago.");
                }
            } catch (Exception $e) {
                $conn->rollback();
                // Guardar el mensaje de error para mostrarlo al usuario
                $_SESSION['error_message'] = "Error al procesar el pago con la billetera: " . $e->getMessage();
            }
        } else {
            $_SESSION['error_message'] = "Saldo insuficiente para completar la transacción.";
        }
    } elseif (isset($_POST['send_message']) && in_array($user_role, ['Comprador', 'Vendedor']) && !$is_finished) {
        // ... (código para enviar mensajes) ...
    } elseif (isset($_POST['new_status'])) {
        // ... (lógica de cambio de estado) ...
    } else {
        $should_redirect = false;
    }

    if ($should_redirect) {
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Recuperar y limpiar mensaje de error de la sesión
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}


// 7. Obtener mensajes del chat
$msg_stmt = $conn->prepare("SELECT * FROM messages WHERE transaction_id = ? ORDER BY created_at ASC");
$msg_stmt->bind_param("i", $transaction['id']);
$msg_stmt->execute();
$messages_result = $msg_stmt->get_result();
$messages = $messages_result->fetch_all(MYSQLI_ASSOC);
if(!empty($messages)) {
    $last_message_id = end($messages)['id'];
}

// 8. Funciones de ayuda para la vista
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
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js" type="text/javascript"></script>
    <!-- SCRIPT DEL WIDGET DE WOMPI -->
    <script type="text/javascript" src="https://checkout.wompi.co/widget.js"></script>
    <style> body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; } .timeline-step { transition: all 0.3s ease; } .timeline-step-active { background-color: #1e293b; color: white; box-shadow: 0 0 15px rgba(30, 41, 59, 0.5); } .timeline-step-complete { background-color: #16a34a; color: white; } .timeline-step-incomplete { background-color: #e2e8f0; color: #475569; } .timeline-step-special { background-color: #ef4444; color: white; } .timeline-line { background-color: #e2e8f0; height: 4px; flex: 1; } .timeline-line-complete { background-color: #16a34a; } .chat-bubble-me { background-color: #1e293b; border-radius: 20px 20px 5px 20px; } .chat-bubble-other { background-color: #e2e8f0; border-radius: 20px 20px 20px 5px; } .card { background-color: white; border-radius: 1.5rem; box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.1); } .rating { display: inline-block; } .rating input { display: none; } .rating label { float: right; cursor: pointer; color: #d1d5db; transition: color 0.2s; font-size: 2rem; } .rating label:before { content: '\f005'; font-family: 'Font Awesome 5 Free'; font-weight: 900; } .rating input:checked ~ label, .rating label:hover, .rating label:hover ~ label { color: #f59e0b; } </style>
</head>
<body class="p-4 md:p-8">
    <div class="max-w-7xl mx-auto">
        <header class="text-center mb-12"><a href="dashboard.php" class="text-slate-600 hover:text-slate-900 mb-4 inline-block"><i class="fas fa-arrow-left mr-2"></i>Volver al Panel</a><h1 class="text-4xl md:text-5xl font-extrabold text-slate-900">Detalle de la Transacción</h1><?php if ($transaction): ?><p class="text-slate-500 mt-2">ID: <span id="transaction-id" class="font-mono bg-slate-200 text-slate-700 px-2 py-1 rounded-md text-sm" data-uuid="<?php echo htmlspecialchars($transaction['transaction_uuid']); ?>" data-status="<?php echo htmlspecialchars($current_status); ?>"><?php echo htmlspecialchars($transaction['transaction_uuid']); ?></span></p><?php endif; ?></header>
        <?php if (!empty($error_message)): ?><div class="card p-6 mb-8 text-center bg-red-100 border border-red-400 text-red-700 rounded-2xl"><i class="fas fa-exclamation-triangle text-3xl mb-3"></i><p class="font-semibold"><?php echo htmlspecialchars($error_message); ?></p></div><?php endif; ?>
        <?php if ($transaction): ?>
            <div id="main-content">
                 <div class="card p-6 md:p-8 mb-8">
                    <h2 class="text-2xl font-bold text-slate-800 mb-6">Línea de Tiempo del Proceso</h2>
                    <div class="flex items-center">
                        <?php $statuses = ['initiated' => 'Inicio', 'funded' => 'En Custodia', 'shipped' => 'Enviado', 'received' => 'Recibido', 'released' => 'Liberado']; if($current_status === 'dispute') $statuses['dispute'] = 'En Disputa'; if($current_status === 'cancelled') $statuses['cancelled'] = 'Cancelado'; $keys = array_keys($statuses); foreach($statuses as $status_key => $status_name): if(!in_array($current_status, ['dispute', 'cancelled']) && in_array($status_key, ['dispute', 'cancelled'])) continue; $step_class = get_status_class($status_key, $transaction['status']); $line_class = get_line_class($status_key, $transaction['status']); ?>
                            <div class="flex-1 flex flex-col items-center text-center"><div class="w-12 h-12 rounded-full flex items-center justify-center font-bold text-lg timeline-step <?php echo $step_class; ?>"><?php echo ($step_class == 'timeline-step-complete' || $step_class == 'timeline-step-special') ? '<i class="fas fa-check"></i>' : array_search($status_key, $keys) + 1; ?></div><p class="mt-2 text-sm font-medium text-slate-700"><?php echo htmlspecialchars($status_name); ?></p></div>
                            <?php if ($status_key !== 'released' && $status_key !== 'dispute' && $status_key !== 'cancelled' && $status_key !== end($keys)): ?><div class="timeline-line <?php echo $line_class; ?>"></div><?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="grid lg:grid-cols-12 gap-8">
                    <div class="lg:col-span-4 card p-6 md:p-8"><h2 class="text-2xl font-bold text-slate-800 mb-6">Chat de la Transacción</h2><div id="chat-box" class="h-[32rem] overflow-y-auto space-y-6 pr-4" data-last-message-id="<?php echo $last_message_id; ?>"><?php if (empty($messages)): ?><div id="no-messages" class="text-center py-10 text-slate-400"><i class="fas fa-comments text-4xl mb-3"></i><p>Aún no hay mensajes.</p></div><?php else: foreach ($messages as $msg): ?><div class="flex <?php echo ($msg['sender_role'] === $user_role || $msg['sender_role'] === 'System') ? 'justify-end' : 'justify-start'; ?>"><div class="p-4 max-w-md <?php if ($msg['sender_role'] === 'System') echo 'bg-blue-100 text-blue-800 rounded-lg'; else echo (($msg['sender_role'] === $user_role) ? 'chat-bubble-me' : 'chat-bubble-other'); ?>"><?php if (!empty($msg['image_path'])): ?><a href="<?php echo htmlspecialchars($msg['image_path']); ?>" target="_blank" class="block mb-2"><img src="<?php echo htmlspecialchars($msg['image_path']); ?>" alt="Imagen adjunta" class="rounded-lg max-h-48 w-full object-cover"></a><?php endif; ?><?php if (!empty($msg['message'])): ?><p class="text-md <?php echo ($msg['sender_role'] === $user_role) ? 'text-white' : 'text-slate-800'; ?>"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p><?php endif; ?><p class="text-xs <?php echo ($msg['sender_role'] === $user_role) ? 'text-slate-400' : 'text-slate-500'; ?> mt-2 text-right"><?php echo htmlspecialchars($msg['sender_role']); ?> - <?php echo htmlspecialchars(date("d/m H:i", strtotime($msg['created_at']))); ?></p></div></div><?php endforeach; endif; ?></div><?php if (in_array($user_role, ['Comprador', 'Vendedor']) && !in_array($current_status, ['released', 'cancelled'])): ?><form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST" enctype="multipart/form-data" class="pt-6 border-t mt-4"><div class="flex items-start space-x-3"><div class="relative flex-grow"><textarea id="message-input" name="message_content" placeholder="Escribe tu mensaje..." class="w-full p-3 pr-12 border border-slate-300 rounded-xl resize-none overflow-hidden" rows="1"></textarea><label for="image-upload" class="absolute right-3 top-3 cursor-pointer p-1 text-slate-500 hover:text-slate-800"><i class="fas fa-paperclip"></i><input id="image-upload" name="product_image" type="file" class="hidden" accept="image/*"></label></div><button type="submit" name="send_message" class="bg-slate-800 text-white font-bold h-12 w-12 rounded-full flex items-center justify-center hover:bg-slate-900 transition-colors"><i class="fas fa-paper-plane"></i></button></div></form><?php endif; ?></div>
                    <div class="lg:col-span-5 space-y-8"><div class="card p-6 md:p-8"><h2 class="text-2xl font-bold text-slate-800 mb-6">Detalles del Acuerdo</h2><div class="space-y-4">
                        <div class="flex justify-between items-baseline"><span class="text-slate-500">Producto/Servicio:</span><span class="font-semibold text-right text-slate-900"><?php echo htmlspecialchars($transaction['product_description']); ?></span></div><hr>
                        <div class="flex justify-between items-baseline"><span class="text-slate-500">Comprador:</span><a href="profile.php?id=<?php echo $transaction['buyer_id']; ?>" class="font-semibold text-right text-slate-600 hover:underline"><?php echo htmlspecialchars($transaction['buyer_name']); ?></a></div><div class="flex justify-between items-baseline"><span class="text-slate-500">Vendedor:</span><a href="profile.php?id=<?php echo $transaction['seller_id']; ?>" class="font-semibold text-right text-slate-600 hover:underline"><?php echo htmlspecialchars($transaction['seller_name']); ?></a></div><hr>
                        <div class="flex justify-between items-baseline"><span class="text-slate-500">Monto del Acuerdo:</span><span class="font-semibold text-right text-slate-900">$<?php echo htmlspecialchars(number_format($transaction['amount'], 2)); ?> COP</span></div>
                        <div class="flex justify-between items-baseline text-xs"><span class="text-slate-500">Comisión pagada por Comprador:</span><span class="font-semibold text-right text-red-600">- $<?php echo htmlspecialchars(number_format($buyer_commission_paid, 2)); ?> COP</span></div>
                        <div class="flex justify-between items-baseline text-xs"><span class="text-slate-500">Comisión pagada por Vendedor:</span><span class="font-semibold text-right text-red-600">- $<?php echo htmlspecialchars(number_format($seller_commission_paid, 2)); ?> COP</span></div>
                        <div class="flex justify-between items-baseline pt-1"><span class="text-slate-500 font-bold">Comisión Total:</span><span class="font-bold text-right text-red-700">- $<?php echo htmlspecialchars(number_format($transaction['commission'], 2)); ?> COP</span></div>
                        <hr class="border-dashed">
                        <div class="flex justify-between items-baseline"><span class="font-bold text-lg text-slate-800">El Vendedor Recibirá:</span><span class="font-bold text-lg text-right text-green-600">$<?php echo htmlspecialchars(number_format($transaction['net_amount'], 2)); ?> COP</span></div>
                    </div></div>
                    <?php if ($current_status === 'released'): ?><!-- Lógica de Calificaciones --> <?php endif; ?>
                    </div>
                    <div class="lg:col-span-3"><div class="card p-6 md:p-8 sticky top-8"><h2 class="text-2xl font-bold text-slate-800 mb-6">Panel de Acciones</h2><div class="text-center"><p class="mb-4 text-slate-600">Tu rol: <span class="font-bold text-slate-800"><?php echo htmlspecialchars($user_role); ?></span></p>
                    <?php if (!$is_finished): ?>
                        <?php if ($user_role === 'Comprador' && $current_status === 'initiated'): ?>
                            <div class="p-4 bg-slate-50 border-t-4 border-slate-200 rounded-b space-y-4">
                                <h3 class="font-bold text-lg text-slate-800">Acción Requerida</h3><p class="text-sm text-slate-600">Deposita los fondos de forma segura.</p><div class="text-xs text-left p-3 bg-blue-50 rounded-lg">Tu Saldo Disponible: <span class="font-bold text-blue-800">$<?php echo number_format($buyer_balance, 2); ?> COP</span></div>
                                <?php if ($buyer_balance >= $amount_to_charge_wallet): ?>
                                    <form method="POST" onsubmit="return confirm('Se descontará $<?php echo number_format($amount_to_charge_wallet, 2); ?> de tu billetera. ¿Estás seguro?');"><input type="hidden" name="pay_with_wallet" value="1"><button type="submit" class="w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-green-700 transition-colors mb-2"><i class="fas fa-wallet mr-2"></i>Pagar con Billetera ($<?php echo number_format($amount_to_charge_wallet, 2); ?>)</button></form><p class="text-xs text-slate-500 my-2">O usa otro método de pago:</p>
                                <?php else: ?>
                                     <p class="text-xs text-red-600 my-2">No tienes saldo suficiente para pagar desde tu billetera.</p>
                                <?php endif; ?>
                                <button type="button" id="wompi-button" class="w-full bg-slate-800 text-white font-bold py-3 px-4 rounded-lg hover:bg-slate-900 transition-colors">Pagar con Wompi</button>
                            </div>
                        <?php elseif ($user_role === 'Vendedor' && $current_status === 'funded'): ?><!-- Código para vendedor -->
                        <?php elseif ($user_role === 'Comprador' && $current_status === 'shipped'): ?><!-- Código para comprador -->
                        <?php elseif ($current_status === 'received'): ?><!-- Código para período de garantía -->
                        <?php else: ?><p class="text-slate-500 italic py-6">Esperando a la otra parte.</p><?php endif; ?>
                    <?php else: ?><div class="p-4 bg-green-50 border-t-4 border-green-400 rounded-b"><h3 class="font-bold text-lg text-green-800"><i class="fas fa-check-circle mr-2"></i>Transacción Finalizada</h3></div><?php endif; ?>
                    </div></div></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const txData = document.getElementById('transaction-id');
            const txUuid = txData.dataset.uuid;
            const currentStatus = txData.dataset.status;
            let statusPoller = null; // Variable para guardar el intervalo

            function checkTransactionStatus() {
                const url = `transaction.php?tx_uuid=${txUuid}&action=check_status`;
                fetch(url)
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'funded' && currentStatus === 'initiated') {
                            console.log('¡Pago detectado! Recargando página...');
                            if (statusPoller) clearInterval(statusPoller);
                            window.location.reload();
                        }
                    })
                    .catch(error => console.error('Error al verificar el estado:', error));
            }

            if (currentStatus === 'initiated') {
                statusPoller = setInterval(checkTransactionStatus, 3000);
            }

            const wompiButton = document.getElementById('wompi-button');
            if (wompiButton) {
                wompiButton.addEventListener('click', function() {
                    var checkout = new WidgetCheckout({
                        currency: 'COP',
                        amountInCents: <?php echo $amount_in_cents; ?>,
                        reference: '<?php echo $transaction['transaction_uuid']; ?>',
                        publicKey: '<?php echo defined('WOMPI_PUBLIC_KEY') ? WOMPI_PUBLIC_KEY : ''; ?>',
                        signature: {
                           integrity: '<?php echo $wompi_signature; ?>'
                        },
                        redirectUrl: '<?php echo $redirect_url_wompi; ?>'
                    });

                    checkout.open(function (result) {
                        console.log('Widget de Wompi cerrado. Forzando una verificación de estado.');
                        checkTransactionStatus();
                    })
                });
            }

            const textarea = document.getElementById('message-input');
            if(textarea){
                textarea.addEventListener('input', function () {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                });
            }
        });
    </script>
</body>
</html>
