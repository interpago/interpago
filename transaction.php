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

// INICIO: Función para agregar mensajes del sistema al chat
function add_system_message($conn, $transaction_id, $message) {
    try {
        $stmt = $conn->prepare("INSERT INTO messages (transaction_id, sender_role, message) VALUES (?, 'System', ?)");
        $stmt->bind_param("is", $transaction_id, $message);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // En un entorno de producción, sería bueno registrar este error.
        error_log("Error al agregar mensaje del sistema para tx_id $transaction_id: " . $e->getMessage());
    }
}
// FIN: Función para agregar mensajes

// Inicializar todas las variables para evitar errores.
$transaction = null;
$messages = [];
$agreement_details = []; // Array para guardar los detalles del acuerdo
$user_role = 'Observador';
$error_message = '';
$is_finished = true;
$current_status = '';
$last_message_id = 0;
$buyer_balance = 0;
$amount_to_charge_wallet = 0;
$amount_to_charge_gateway = 0; // Para Wompi
$buyer_commission_paid = 0;
$seller_commission_paid = 0;
$wompi_signature = ''; // Para Wompi
$redirect_url_wompi = ''; // Para Wompi

// Obtener el ID de la transacción desde la URL.
$transaction_uuid = $_GET['tx_uuid'] ?? '';

// Manejar mensajes de estado del pago
if(isset($_GET['payment_status'])){
    if($_GET['payment_status'] === 'declined'){
        $error_message = "El pago fue declinado por la entidad financiera.";
    } elseif ($_GET['payment_status'] === 'error_db' || $_GET['payment_status'] === 'error_critical'){
        $error_message = "Hubo un error al procesar la confirmación del pago. Contacta a soporte.";
    }
}


if (!empty($transaction_uuid)) {
    $stmt = $conn->prepare("SELECT * FROM transactions WHERE transaction_uuid = ?");
    $stmt->bind_param("s", $transaction_uuid);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($transaction = $result->fetch_assoc()) {
        $transaction_id = $transaction['id'];

        // Obtener los detalles del acuerdo de la nueva tabla
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
            if ($logged_in_user_uuid === $transaction['buyer_uuid']) {
                $user_role = 'Comprador';
            } elseif ($logged_in_user_uuid === $transaction['seller_uuid']) {
                $user_role = 'Vendedor';
            }
        }

        $current_status = $transaction['status'];

        if ($user_role === 'Comprador') {
            $balance_stmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
            $balance_stmt->bind_param("i", $transaction['buyer_id']);
            $balance_stmt->execute();
            $balance_result = $balance_stmt->get_result()->fetch_assoc();
            $buyer_balance = $balance_result['balance'] ?? 0;
            $balance_stmt->close();
        }

        if ($current_status === 'received') {
            $check_stmt = $conn->prepare("SELECT id FROM transactions WHERE id = ? AND release_funds_at IS NOT NULL AND NOW() >= release_funds_at");
            $check_stmt->bind_param("i", $transaction['id']);
            $check_stmt->execute();
            $release_now = $check_stmt->get_result()->num_rows > 0;
            $check_stmt->close();

            if($release_now) {
                $new_status = 'released';
                $conn->begin_transaction();
                try {
                    $status_stmt = $conn->prepare("UPDATE transactions SET status = ?, completed_at = NOW() WHERE id = ?");
                    $status_stmt->bind_param("si", $new_status, $transaction['id']);
                    $status_stmt->execute();
                    $balance_stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                    $balance_stmt->bind_param("di", $transaction['net_amount'], $transaction['seller_id']);
                    $balance_stmt->execute();
                    $conn->commit();

                    add_system_message($conn, $transaction['id'], 'Los fondos han sido liberados automáticamente al finalizar el período de garantía.');

                    send_notification($conn, "status_update", ['transaction_uuid' => $transaction_uuid, 'new_status' => $new_status]);
                    header("Location: " . $_SERVER['REQUEST_URI']);
                    exit;
                } catch (Exception $e) {
                    $conn->rollback();
                    error_log("Auto-release error: " . $e->getMessage());
                    $error_message = "Error al liberar los fondos automáticamente.";
                }
            }
        }

        $is_finished = in_array($current_status, ['released', 'cancelled', 'dispute']);

        $platform_commission = $transaction['commission'];

        // Monto para pagar con billetera
        $amount_to_charge_wallet = $transaction['amount'];
        if ($transaction['commission_payer'] === 'buyer') {
            $amount_to_charge_wallet += $platform_commission;
        } elseif ($transaction['commission_payer'] === 'split') {
            $amount_to_charge_wallet += ($platform_commission / 2);
        }

        // --- LÓGICA PARA WOMPI ---
        $amount_to_charge_gateway = $transaction['amount'];
        if ($transaction['commission_payer'] === 'buyer') {
            $amount_to_charge_gateway += $platform_commission;
        } elseif ($transaction['commission_payer'] === 'split') {
            $amount_to_charge_gateway += ($platform_commission / 2);
        }

        if ($user_role === 'Comprador' && $current_status === 'initiated') {
            $amount_in_cents = round($amount_to_charge_gateway * 100);
            $redirect_url_wompi = rtrim(APP_URL, '/') . '/payment_response.php';

            if (defined('WOMPI_INTEGRITY_SECRET')) {
                $concatenation = $transaction_uuid . $amount_in_cents . 'COP' . WOMPI_INTEGRITY_SECRET;
                $wompi_signature = hash('sha256', $concatenation);
            }
        }
        // --- FIN LÓGICA PARA WOMPI ---

        if ($transaction['commission_payer'] === 'buyer') {
            $buyer_commission_paid = $transaction['commission'];
        } elseif ($transaction['commission_payer'] === 'seller') {
            $seller_commission_paid = $transaction['commission'];
        } elseif ($transaction['commission_payer'] === 'split') {
            $buyer_commission_paid = $transaction['commission'] / 2;
            $seller_commission_paid = $transaction['commission'] / 2;
        }


        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Array para mapear estados a mensajes amigables
            $status_messages = [
                'funded'   => 'El pago ha sido completado. Los fondos están en custodia en Interpago.',
                'shipped'  => 'El vendedor ha marcado la transacción como \'Enviado\'.',
                'received' => 'El comprador ha confirmado la recepción del producto. Inicia el período de garantía.',
                'released' => 'Los fondos han sido liberados al vendedor. La transacción ha finalizado.',
                'dispute'  => 'El comprador ha reportado un problema. La transacción está en disputa.'
            ];

            if (isset($_POST['generate_qr']) && $user_role === 'Vendedor' && $current_status === 'funded') {
                $qr_token = "INTERPAGO_TX_" . $transaction['id'] . "_" . bin2hex(random_bytes(16));
                $update_stmt = $conn->prepare("UPDATE transactions SET qr_code_token = ? WHERE id = ?");
                $update_stmt->bind_param("si", $qr_token, $transaction['id']);
                if ($update_stmt->execute()) { header("Location: " . $_SERVER['REQUEST_URI']); exit; }
            }

            if (isset($_POST['send_message']) && in_array($user_role, ['Comprador', 'Vendedor']) && !$is_finished) {
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

            if (isset($_POST['pay_with_wallet']) && $user_role === 'Comprador' && $current_status === 'initiated') {
                if ($buyer_balance >= $amount_to_charge_wallet) {
                    $conn->begin_transaction();
                    try {
                        $new_balance = $buyer_balance - $amount_to_charge_wallet;
                        $update_balance_stmt = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
                        $update_balance_stmt->bind_param("di", $new_balance, $transaction['buyer_id']);
                        $update_balance_stmt->execute();

                        $update_tx_stmt = $conn->prepare("UPDATE transactions SET status = 'funded' WHERE id = ?");
                        $update_tx_stmt->bind_param("i", $transaction['id']);
                        $update_tx_stmt->execute();

                        $conn->commit();

                        if (isset($status_messages['funded'])) {
                           add_system_message($conn, $transaction['id'], $status_messages['funded']);
                        }

                        send_notification($conn, "status_update", ['transaction_uuid' => $transaction_uuid, 'new_status' => 'funded']);
                        header("Location: " . $_SERVER['REQUEST_URI']);
                        exit;

                    } catch (Exception $e) {
                        $conn->rollback();
                        error_log("Wallet Payment Error: " . $e->getMessage());
                        $error_message = "Error al procesar el pago desde la billetera.";
                    }
                } else {
                    $error_message = "Saldo insuficiente para realizar esta operación.";
                }
            }

            if (isset($_POST['new_status'])) {
                $new_status = $_POST['new_status'];
                $allowed = false;

                $transitions = [
                    'Vendedor' => ['funded' => 'shipped'],
                    'Comprador' => ['shipped' => 'received', 'received' => 'released']
                ];

                if (isset($transitions[$user_role][$current_status]) && $new_status === $transitions[$user_role][$current_status]) {
                    $allowed = true;
                }

                if ($allowed) {
                    $conn->begin_transaction();
                    try {
                        if ($new_status === 'received') {
                            $inspection_period = (defined('INSPECTION_PERIOD_MINUTES') && INSPECTION_PERIOD_MINUTES > 0) ? INSPECTION_PERIOD_MINUTES : 10;
                            $update_stmt = $conn->prepare("UPDATE transactions SET status = 'received', release_funds_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE transaction_uuid = ?");
                            $update_stmt->bind_param("is", $inspection_period, $transaction_uuid);
                            $update_stmt->execute();
                        } else {
                            $sql_update = "UPDATE transactions SET status = ?";
                            $params_update = [$new_status];
                            $types_update = "s";

                            if ($new_status === 'released') {
                                $sql_update .= ", completed_at = NOW()";
                                $balance_stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                                $balance_stmt->bind_param("di", $transaction['net_amount'], $transaction['seller_id']);
                                $balance_stmt->execute();
                            }

                            $sql_update .= " WHERE transaction_uuid = ?";
                            $params_update[] = $transaction_uuid;
                            $types_update .= "s";

                            $update_stmt = $conn->prepare($sql_update);
                            $update_stmt->bind_param($types_update, ...$params_update);
                            $update_stmt->execute();
                        }

                        $conn->commit();

                        if (isset($status_messages[$new_status])) {
                            add_system_message($conn, $transaction['id'], $status_messages[$new_status]);
                        }

                        send_notification($conn, "status_update", ['transaction_uuid' => $transaction_uuid, 'new_status' => $new_status]);
                        header("Location: " . $_SERVER['REQUEST_URI']);
                        exit;

                    } catch (Exception $e) {
                        $conn->rollback();
                        error_log("Error al actualizar estado: " . $e->getMessage());
                        $error_message = "Hubo un error al procesar tu solicitud.";
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
        if(!empty($messages)) {
            $last_message_id = end($messages)['id'];
        }
    } else {
        $error_message = "No se encontró ninguna transacción con el ID: " . htmlspecialchars($transaction_uuid);
    }
}
function get_status_class($s, $c) { if(in_array($c,['cancelled', 'dispute'])) return 'timeline-step-special'; $st=['initiated','funded','shipped','received','released']; $ci=array_search($c,$st); $si=array_search($s,$st); if($c==='released'||$si<$ci) return 'timeline-step-complete'; if($si===$ci) return 'timeline-step-active'; return 'timeline-step-incomplete'; }
function get_line_class($s, $c) { $st=['initiated','funded','shipped','received']; $ci=array_search($c,$st); $si=array_search($s,$st); if($si<$ci&&!in_array($c,['cancelled', 'dispute'])) return 'timeline-line-complete'; return 'timeline-line'; }

// Para mapear las claves a etiquetas legibles
$agreement_labels = [
    'estado_estetico' => 'Estado Estético',
    'accesorios_incluidos' => 'Accesorios Incluidos',
    'garantia_ofrecida' => 'Garantía Ofrecida',
    'condiciones_devolucion' => 'Condiciones de Devolución'
];
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
    <style> body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; } .timeline-step { transition: all 0.3s ease; } .timeline-step-active { background-color: #1e293b; color: white; box-shadow: 0 0 15px rgba(30, 41, 59, 0.5); } .timeline-step-complete { background-color: #16a34a; color: white; } .timeline-step-incomplete { background-color: #e2e8f0; color: #475569; } .timeline-step-special { background-color: #ef4444; color: white; } .timeline-line { background-color: #e2e8f0; height: 4px; flex: 1; } .timeline-line-complete { background-color: #16a34a; } .chat-bubble-me { background-color: #1e293b; border-radius: 20px 20px 5px 20px; } .chat-bubble-other { background-color: #e2e8f0; border-radius: 20px 20px 20px 5px; } .card { background-color: white; border-radius: 1.5rem; box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.1); } .rating { display: inline-block; } .rating input { display: none; } .rating label { float: right; cursor: pointer; color: #d1d5db; transition: color 0.2s; font-size: 2rem; } .rating label:before { content: '\f005'; font-family: 'Font Awesome 5 Free'; font-weight: 900; } .rating input:checked ~ label, .rating label:hover, .rating label:hover ~ label { color: #f59e0b; } </style>
</head>
<body class="p-4 md:p-8">
    <div class="max-w-7xl mx-auto">
        <header class="text-center mb-12"><a href="dashboard.php" class="text-slate-600 hover:text-slate-900 mb-4 inline-block"><i class="fas fa-arrow-left mr-2"></i>Volver al Panel</a><h1 class="text-4xl md:text-5xl font-extrabold text-slate-900">Detalle de la Transacción</h1><?php if ($transaction): ?><p class="text-slate-500 mt-2">ID: <span id="transaction-id" class="font-mono bg-slate-200 text-slate-700 px-2 py-1 rounded-md text-sm" data-uuid="<?php echo htmlspecialchars($transaction['transaction_uuid']); ?>" data-status="<?php echo htmlspecialchars($current_status); ?>"><?php echo htmlspecialchars($transaction['transaction_uuid']); ?></span></p><?php endif; ?></header>
        <?php if (!empty($error_message)): ?><div class="card p-6 mb-8 text-center bg-red-50 text-red-700"><i class="fas fa-exclamation-triangle text-3xl mb-3"></i><p class="font-semibold"><?php echo htmlspecialchars($error_message); ?></p></div><?php endif; ?>
        <?php if ($transaction): ?>
            <div id="main-content">
                <div class="card p-6 md:p-8 mb-8">
                    <h2 class="text-2xl font-bold text-slate-800 mb-6">Línea de Tiempo del Proceso</h2>
                    <div class="flex items-center">
                        <?php
                        $statuses = ['initiated' => 'Inicio', 'funded' => 'En Custodia', 'shipped' => 'Enviado', 'received' => 'Recibido', 'released' => 'Liberado', 'dispute' => 'En Disputa'];
                        $keys = array_keys($statuses);
                        foreach($statuses as $status_key => $status_name):
                            if ($status_key === 'dispute' && $transaction['status'] !== 'dispute') continue;
                            if ($transaction['status'] === 'dispute' && !in_array($status_key, ['initiated', 'funded', 'shipped', 'received', 'dispute'])) continue;

                            $step_class = get_status_class($status_key, $transaction['status']);
                            $line_class = get_line_class($status_key, $transaction['status']);
                        ?>
                            <div class="flex-1 flex flex-col items-center text-center"><div class="w-12 h-12 rounded-full flex items-center justify-center font-bold text-lg timeline-step <?php echo $step_class; ?>"><?php echo ($step_class == 'timeline-step-complete') ? '<i class="fas fa-check"></i>' : (($status_key === 'dispute') ? '<i class="fas fa-exclamation"></i>' : array_search($status_key, $keys) + 1); ?></div><p class="mt-2 text-sm font-medium text-slate-700"><?php echo htmlspecialchars($status_name); ?></p></div>
                            <?php if (!in_array($status_key, ['released', 'dispute']) && ($next_status_key = next($statuses)) && ($status_key !== 'received' || $transaction['status'] !== 'dispute')): ?><div class="timeline-line <?php echo $line_class; ?>"></div><?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="grid lg:grid-cols-12 gap-8">
                    <div class="lg:col-span-4 card p-6 md:p-8"><h2 class="text-2xl font-bold text-slate-800 mb-6">Chat de la Transacción</h2><div id="chat-box" class="h-[32rem] overflow-y-auto space-y-6 pr-4" data-last-message-id="<?php echo $last_message_id; ?>"><?php if (empty($messages)): ?><div id="no-messages" class="text-center py-10 text-slate-400"><i class="fas fa-comments text-4xl mb-3"></i><p>Aún no hay mensajes.</p></div><?php else: foreach ($messages as $msg): ?><div class="flex <?php echo ($msg['sender_role'] === 'System') ? 'justify-center' : (($msg['sender_role'] === $user_role) ? 'justify-end' : 'justify-start'); ?> w-full"><div class="p-4 max-w-md <?php echo ($msg['sender_role'] === 'System') ? 'bg-slate-200 text-slate-600 rounded-xl' : (($msg['sender_role'] === $user_role) ? 'chat-bubble-me' : 'chat-bubble-other'); ?>"><?php if (!empty($msg['image_path'])): ?><a href="<?php echo htmlspecialchars($msg['image_path']); ?>" target="_blank" class="block mb-2"><img src="<?php echo htmlspecialchars($msg['image_path']); ?>" alt="Imagen adjunta" class="rounded-lg max-h-48 w-full object-cover"></a><?php endif; ?><?php if (!empty($msg['message'])): ?><p class="text-md <?php echo ($msg['sender_role'] === $user_role) ? 'text-white' : 'text-slate-800'; ?>"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p><?php endif; ?><p class="text-xs <?php echo ($msg['sender_role'] === 'System') ? 'text-slate-500' : (($msg['sender_role'] === $user_role) ? 'text-slate-400' : 'text-slate-500'); ?> mt-2 text-right"><?php echo htmlspecialchars($msg['sender_role']); ?> - <?php echo htmlspecialchars(date("d/m H:i", strtotime($msg['created_at']))); ?></p></div></div><?php endforeach; endif; ?></div><?php if (in_array($user_role, ['Comprador', 'Vendedor']) && !$is_finished): ?><form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST" enctype="multipart/form-data" class="pt-6 border-t mt-4"><div class="flex items-start space-x-3"><div class="relative flex-grow"><textarea id="message-input" name="message_content" placeholder="Escribe tu mensaje..." class="w-full p-3 pr-12 border border-slate-300 rounded-xl resize-none overflow-hidden" rows="1"></textarea><label for="image-upload" class="absolute right-3 top-3 cursor-pointer p-1 text-slate-500 hover:text-slate-800"><i class="fas fa-paperclip"></i><input id="image-upload" name="product_image" type="file" class="hidden" accept="image/*"></label></div><button type="submit" name="send_message" class="bg-slate-800 text-white font-bold h-12 w-12 rounded-full flex items-center justify-center hover:bg-slate-900 transition-colors"><i class="fas fa-paper-plane"></i></button></div></form><?php endif; ?></div>
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
                                <div class="space-y-8">
                                    <div><h3 class="font-semibold text-lg mb-2">Calificación para el Vendedor</h3><?php if ($user_role === 'Comprador' && is_null($transaction['seller_rating'])): ?><form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>"><div class="rating"><input type="radio" id="seller-star5" name="rating" value="5" /><label for="seller-star5"></label><input type="radio" id="seller-star4" name="rating" value="4" /><label for="seller-star4"></label><input type="radio" id="seller-star3" name="rating" value="3" /><label for="seller-star3"></label><input type="radio" id="seller-star2" name="rating" value="2" /><label for="seller-star2"></label><input type="radio" id="seller-star1" name="rating" value="1" /><label for="seller-star1"></label></div><textarea name="comment" class="w-full p-3 mt-4 border rounded-lg" rows="3" placeholder="Deja un comentario..."></textarea><button type="submit" name="submit_rating" class="mt-4 w-full bg-slate-800 text-white font-bold py-3 rounded-lg">Enviar Calificación</button></form><?php elseif (!is_null($transaction['seller_rating'])): ?><div class="p-4 bg-slate-50 rounded-lg"><?php for($i=0; $i<$transaction['seller_rating']; $i++) { echo '<i class="fas fa-star text-yellow-400"></i>'; } for($i=$transaction['seller_rating']; $i<5; $i++) { echo '<i class="fas fa-star text-gray-300"></i>'; } ?><p class="mt-2 text-slate-600 italic">"<?php echo htmlspecialchars($transaction['seller_comment']); ?>"</p><p class="text-xs text-right text-slate-500 mt-2">- Calificación del Comprador</p></div><?php else: ?><p class="text-slate-500 italic p-4 text-center">Esperando calificación del Comprador.</p><?php endif; ?></div>
                                    <hr class="border-dashed">
                                    <div><h3 class="font-semibold text-lg mb-2">Calificación para el Comprador</h3><?php if ($user_role === 'Vendedor' && is_null($transaction['buyer_rating'])): ?><form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>"><div class="rating"><input type="radio" id="buyer-star5" name="rating" value="5" /><label for="buyer-star5"></label><input type="radio" id="buyer-star4" name="rating" value="4" /><label for="buyer-star4"></label><input type="radio" id="buyer-star3" name="rating" value="3" /><label for="buyer-star3"></label><input type="radio" id="buyer-star2" name="rating" value="2" /><label for="buyer-star2"></label><input type="radio" id="buyer-star1" name="rating" value="1" /><label for="buyer-star1"></label></div><textarea name="comment" class="w-full p-3 mt-4 border rounded-lg" rows="3" placeholder="Deja un comentario..."></textarea><button type="submit" name="submit_rating" class="mt-4 w-full bg-slate-800 text-white font-bold py-3 rounded-lg">Enviar Calificación</button></form><?php elseif (!is_null($transaction['buyer_rating'])): ?><div class="p-4 bg-slate-50 rounded-lg"><?php for($i=0; $i<$transaction['buyer_rating']; $i++) { echo '<i class="fas fa-star text-yellow-400"></i>'; } for($i=$transaction['buyer_rating']; $i<5; $i++) { echo '<i class="fas fa-star text-gray-300"></i>'; } ?><p class="mt-2 text-slate-600 italic">"<?php echo htmlspecialchars($transaction['buyer_comment']); ?>"</p><p class="text-xs text-right text-slate-500 mt-2">- Calificación del Vendedor</p></div><?php else: ?><p class="text-slate-500 italic p-4 text-center">Esperando calificación del Vendedor.</p><?php endif; ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="lg:col-span-3"><div class="card p-6 md:p-8 sticky top-8"><h2 class="text-2xl font-bold text-slate-800 mb-6">Panel de Acciones</h2><div class="text-center"><p class="mb-4 text-slate-600">Tu rol: <span class="font-bold text-slate-800"><?php echo htmlspecialchars($user_role); ?></span></p>
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
                                    <input type="hidden" name="public-key" value="<?php echo htmlspecialchars(WOMPI_PUBLIC_KEY); ?>">
                                    <input type="hidden" name="currency" value="COP">
                                    <input type="hidden" name="amount-in-cents" value="<?php echo htmlspecialchars($amount_in_cents); ?>">
                                    <input type="hidden" name="reference" value="<?php echo htmlspecialchars($transaction_uuid); ?>">
                                    <input type="hidden" name="signature:integrity" value="<?php echo htmlspecialchars($wompi_signature); ?>">
                                    <input type="hidden" name="redirect-url" value="<?php echo htmlspecialchars($redirect_url_wompi); ?>">
                                    <input type="hidden" name="customer-data:email" value="<?php echo htmlspecialchars($transaction['email'] ?? ''); ?>" />
                                    <input type="hidden" name="customer-data:full-name" value="<?php echo htmlspecialchars($transaction['buyer_name'] ?? 'Comprador'); ?>" />
                                    <button type="submit" class="w-full bg-slate-800 text-white font-bold py-3 px-4 rounded-lg hover:bg-slate-900 transition-colors">
                                        Pagar con Wompi ($<?php echo number_format($amount_to_charge_gateway, 2); ?>)
                                    </button>
                                </form>
                            </div>
                        <?php elseif ($user_role === 'Vendedor' && $current_status === 'funded'): ?><div class="p-4 bg-blue-50 border-t-4 border-blue-400 rounded-b space-y-4"><h3 class="font-bold text-lg text-gray-800">Gestionar Entrega</h3><div><h4 class="font-semibold text-gray-700">Sello de Entrega QR</h4><?php if (is_null($transaction['qr_code_token'])): ?><p class="text-xs text-gray-600 mt-1 mb-3">Genera el sello para confirmar la entrega.</p><form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST"><input type="hidden" name="generate_qr" value="1"><button type="submit" class="w-full bg-slate-700 text-white font-bold py-2 px-4 rounded-lg text-sm"><i class="fas fa-qrcode mr-2"></i>Generar Sello QR</button></form><?php else: ?><p class="text-xs text-gray-600 mt-1 mb-3">Sello generado. Imprímelo e inclúyelo en el paquete.</p><div class="p-2 bg-white border rounded-lg mb-2"><img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($transaction['qr_code_token']); ?>" alt="Sello QR" class="w-full h-auto mx-auto"></div><form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST"><input type="hidden" name="new_status" value="shipped"><button type="submit" class="w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-green-700"><i class="fas fa-truck mr-2"></i>Marcar como Enviado</button></form><?php endif; ?></div></div>
                        <?php elseif ($user_role === 'Comprador' && $current_status === 'shipped'): ?><div class="p-4 bg-green-50 border-t-4 border-green-400 rounded-b"><h3 class="font-bold text-lg text-gray-800">Acción Requerida</h3><p class="text-sm text-gray-600 mt-2 mb-4">Confirma que recibiste el producto para iniciar el período de garantía.</p><button id="scan-qr-btn" class="w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg"><i class="fas fa-camera mr-2"></i>Escanear Sello de Entrega</button><div id="qr-scanner-container" class="hidden mt-4" data-token="<?php echo htmlspecialchars($transaction['qr_code_token'] ?? ''); ?>"><div id="qr-reader" class="w-full"></div><button id="stop-scan-btn" class="mt-2 w-full text-xs text-white bg-red-600/80 py-1 rounded-md">Cancelar</button></div><div id="scan-result" class="mt-4 text-sm font-semibold"></div><p class="text-xs text-gray-500 mt-4">¿Problemas? <button id="manual-confirm-btn" class="underline">Confirmar recepción manualmente</button>.</p><form id="confirm-reception-form" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST" class="hidden"><input type="hidden" name="new_status" value="received"></form></div>
                        <?php elseif ($current_status === 'received'): ?>
                            <div class="p-4 bg-yellow-50 border-t-4 border-yellow-400 rounded-b"><h3 class="font-bold text-lg text-slate-800">Periodo de Garantía Activo</h3>
                                <?php if ($user_role === 'Vendedor'): ?>
                                    <p class="text-sm text-slate-600 mt-2 mb-4">El comprador está inspeccionando el producto. Los fondos se liberarán automáticamente.</p>
                                <?php elseif ($user_role === 'Comprador'): ?>
                                    <p class="text-sm text-slate-600 mt-2 mb-2">Inspecciona el producto y confirma que todo esté en orden.</p>
                                    <form id="release-funds-form" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST" onsubmit="return confirm('¿Estás seguro de que quieres liberar los fondos? Esta acción es irreversible.')" class="mt-4"><input type="hidden" name="new_status" value="released"><button type="submit" class="w-full bg-green-600 text-white font-bold py-2 px-4 rounded-lg text-sm hover:bg-green-700 mb-4"><i class="fas fa-check-circle mr-2"></i>Todo OK, Liberar Fondos</button></form>

                                    <!-- ***** INICIO DE LA CORRECCIÓN ***** -->
                                    <a href="dispute.php?tx_uuid=<?php echo htmlspecialchars($transaction_uuid); ?>" class="w-full bg-red-600 text-white font-bold py-2 px-4 rounded-lg text-sm hover:bg-red-700 inline-block text-center">
                                        <i class="fas fa-exclamation-triangle mr-2"></i>Reportar un Problema
                                    </a>
                                    <!-- ***** FIN DE LA CORRECCIÓN ***** -->
                                <?php endif; ?>
                                <?php if (!empty($transaction['release_funds_at'])): ?>
                                <div class="mt-4"><p class="text-xs text-slate-500">Tiempo restante:</p><?php $inspection_period_minutes = (defined('INSPECTION_PERIOD_MINUTES') && INSPECTION_PERIOD_MINUTES > 0) ? INSPECTION_PERIOD_MINUTES : 10; ?><div id="countdown-timer" class="text-2xl font-bold text-slate-700" data-release-time="<?php echo strtotime($transaction['release_funds_at']) * 1000; ?>" data-total-duration="<?php echo $inspection_period_minutes * 60; ?>">--:--</div><div class="w-full bg-slate-200 rounded-full h-2.5 mt-1"><div id="progress-bar" class="bg-yellow-500 h-2.5 rounded-full" style="width: 100%"></div></div></div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?><p class="text-slate-500 italic py-6">Esperando a la otra parte.</p><?php endif; ?>
                    <?php else: ?><div class="p-4 bg-green-50 border-t-4 border-green-400 rounded-b"><h3 class="font-bold text-lg text-green-800"><i class="fas fa-check-circle mr-2"></i>Transacción Finalizada</h3></div><?php endif; ?></div></div></div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Lógica de polling para el chat y estado
            const transactionIdElement = document.getElementById('transaction-id');
            if (!transactionIdElement) return;

            const transactionUUID = transactionIdElement.dataset.uuid;
            let currentUserRole = '<?php echo $user_role; ?>';
            let pollingInterval;

            const checkForUpdates = async () => {
                try {
                    const chatBox = document.getElementById('chat-box');
                    const lastMessageId = chatBox.dataset.lastMessageId || 0;
                    const currentStatus = document.getElementById('transaction-id').dataset.status;

                    if (currentStatus === 'released' || currentStatus === 'cancelled' || currentStatus === 'dispute') {
                        if(pollingInterval) clearInterval(pollingInterval);
                        return;
                    }

                    const response = await fetch(`get_transaction_updates.php?tx_uuid=${transactionUUID}&last_message_id=${lastMessageId}&current_status=${currentStatus}`);
                    if (!response.ok) return;

                    const data = await response.json();

                    if (data.error) {
                        console.error('Error del servidor:', data.error);
                        if(pollingInterval) clearInterval(pollingInterval);
                        return;
                    }

                    if (data.status_changed) {
                        window.location.reload();
                        return;
                    }

                    if (data.new_messages && data.new_messages.length > 0) {
                        const noMessagesEl = document.getElementById('no-messages');
                        if(noMessagesEl) noMessagesEl.style.display = 'none';

                        data.new_messages.forEach(msg => {
                            const messageIsMine = msg.sender_role === currentUserRole;
                            const isSystem = msg.sender_role === 'System';
                            const justifyClass = isSystem ? 'justify-center' : (messageIsMine ? 'justify-end' : 'justify-start');
                            const bubbleClasses = isSystem ? 'bg-slate-200 text-slate-600 rounded-xl' : (messageIsMine ? 'chat-bubble-me' : 'chat-bubble-other');
                            const textColor = messageIsMine ? 'text-white' : 'text-slate-800';
                            const metaColor = isSystem ? 'text-slate-500' : (messageIsMine ? 'text-slate-400' : 'text-slate-500');

                            let imageHtml = '';
                            if (msg.image_path) {
                                imageHtml = `<a href="${msg.image_path}" target="_blank" class="block mb-2"><img src="${msg.image_path}" alt="Imagen adjunta" class="rounded-lg max-h-48 w-full object-cover"></a>`;
                            }

                            let msgText = msg.message;

                            const msgHtml = `
                                <div class="flex ${justifyClass} w-full">
                                    <div class="p-4 max-w-md ${bubbleClasses}">
                                        ${imageHtml}
                                        <p class="text-md ${textColor}">${msgText}</p>
                                        <p class="text-xs ${metaColor} mt-2 text-right">${msg.sender_role} - ${msg.created_at}</p>
                                    </div>
                                </div>
                            `;
                            chatBox.innerHTML += msgHtml;
                        });

                        chatBox.dataset.lastMessageId = data.new_messages[data.new_messages.length - 1].id;
                        chatBox.scrollTop = chatBox.scrollHeight;
                    }

                } catch (error) {
                    console.error('Error al buscar actualizaciones:', error);
                }
            };

            pollingInterval = setInterval(checkForUpdates, 4000);
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Solo ejecuta este código si estamos en el estado 'shipped' para el comprador
            const scanBtn = document.getElementById('scan-qr-btn');
            if (!scanBtn) return;

            const manualConfirmBtn = document.getElementById('manual-confirm-btn');
            const confirmReceptionForm = document.getElementById('confirm-reception-form');

            const qrScannerContainer = document.getElementById('qr-scanner-container');
            const stopScanBtn = document.getElementById('stop-scan-btn');
            const scanResultEl = document.getElementById('scan-result');
            let html5QrCode;

            // --- Lógica para la confirmación manual ---
            if (manualConfirmBtn && confirmReceptionForm) {
                manualConfirmBtn.addEventListener('click', function() {
                    if (confirm('¿Estás seguro de que quieres confirmar la recepción manualmente?')) {
                        confirmReceptionForm.submit();
                    }
                });
            }

            // --- Lógica para el escáner QR ---
            function onScanSuccess(decodedText, decodedResult) {
                const expectedToken = qrScannerContainer.dataset.token;
                scanResultEl.style.color = 'red';

                if (decodedText === expectedToken) {
                    scanResultEl.textContent = '¡Sello verificado correctamente! Confirmando recepción...';
                    scanResultEl.style.color = 'green';

                    if(html5QrCode && html5QrCode.isScanning) {
                        html5QrCode.stop().then(ignore => {
                            confirmReceptionForm.submit();
                        }).catch(err => {
                            console.error("Error al detener el escáner al tener éxito:", err);
                            confirmReceptionForm.submit(); // Intenta enviar de todos modos
                        });
                    } else {
                         confirmReceptionForm.submit();
                    }

                } else {
                    scanResultEl.textContent = 'Error: El código QR no coincide con esta transacción.';
                }
            }

            function onScanFailure(error) {
                // No es necesario mostrar un error, el usuario puede seguir intentando escanear.
            }

            if (scanBtn && qrScannerContainer) {
                 html5QrCode = new Html5Qrcode("qr-reader");

                scanBtn.addEventListener('click', () => {
                    qrScannerContainer.style.display = 'block';
                    scanResultEl.textContent = '';

                    const config = { fps: 10, qrbox: { width: 250, height: 250 } };

                    try {
                        html5QrCode.start({ facingMode: "environment" }, config, onScanSuccess, onScanFailure)
                            .catch(err => {
                                scanResultEl.textContent = "Error al iniciar la cámara. Asegúrate de dar permisos.";
                                console.error("Error al iniciar la cámara:", err);
                            });
                    } catch (err) {
                        scanResultEl.textContent = "No se pudo iniciar el escáner QR en este navegador.";
                        console.error("Error en Html5Qrcode:", err);
                    }
                });

                stopScanBtn.addEventListener('click', () => {
                     if(html5QrCode && html5QrCode.isScanning) {
                        html5QrCode.stop().then(ignore => {
                            qrScannerContainer.style.display = 'none';
                        }).catch(err => {
                            console.error("Error al detener el escáner:", err);
                             qrScannerContainer.style.display = 'none';
                        });
                    } else {
                        qrScannerContainer.style.display = 'none';
                    }
                });
            }
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const countdownTimerEl = document.getElementById('countdown-timer');
            const progressBarEl = document.getElementById('progress-bar');

            if (countdownTimerEl && progressBarEl) {
                const releaseTime = parseInt(countdownTimerEl.dataset.releaseTime, 10);
                const totalDuration = parseInt(countdownTimerEl.dataset.totalDuration, 10) * 1000;

                if (isNaN(releaseTime) || isNaN(totalDuration)) return;

                const updateCountdown = () => {
                    const now = Date.now();
                    const remainingTime = releaseTime - now;

                    if (remainingTime <= 0) {
                        countdownTimerEl.textContent = '00:00';
                        if(progressBarEl) progressBarEl.style.width = '100%';
                        clearInterval(countdownInterval);
                        // Recargar la página para que el servidor procese la liberación de fondos
                        setTimeout(() => window.location.reload(), 1500);
                        return;
                    }

                    const minutes = Math.floor((remainingTime % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((remainingTime % (1000 * 60)) / 1000);

                    countdownTimerEl.textContent =
                        `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;

                    const elapsedTime = totalDuration - remainingTime;
                    const progressPercentage = Math.min(100, (elapsedTime / totalDuration) * 100);

                    if(progressBarEl) progressBarEl.style.width = `${progressPercentage}%`;
                };

                const countdownInterval = setInterval(updateCountdown, 1000);
                updateCountdown();
            }
        });
    </script>
</body>
</html>
