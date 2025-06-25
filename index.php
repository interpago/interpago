<?php
session_start();

require_once 'config.php';
if (file_exists(__DIR__ . '/lib/sms_functions.php')) {
    require_once __DIR__ . '/lib/sms_functions.php';
}

function generate_uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

$message = '';
$message_type = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_transaction'])) {

    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }

    $current_user_id = $_SESSION['user_id'];
    $current_user_name = $_SESSION['user_name'];
    $current_user_uuid = $_SESSION['user_uuid'];

    $role = $_POST['role'];
    $counterparty_email = trim($_POST['counterparty_email']);
    $product_description = trim($_POST['product_description']);
    $amount = filter_var($_POST['amount'], FILTER_VALIDATE_FLOAT);
    $commission_payer = $_POST['commission_payer'] ?? 'seller';

    if (empty($role) || empty($counterparty_email) || empty($product_description) || empty($amount) || $amount <= 0) {
        $message = "Por favor, completa todos los campos correctamente.";
        $message_type = 'error';
    } else {
        $user_stmt = $conn->prepare("SELECT id, name, user_uuid FROM users WHERE email = ?");
        $user_stmt->bind_param("s", $counterparty_email);
        $user_stmt->execute();
        $counterparty = $user_stmt->get_result()->fetch_assoc();

        if (!$counterparty) {
            $message = "El correo de la contraparte no está registrado.";
            $message_type = 'error';
        } else {
            $counterparty_uuid = $counterparty['user_uuid'];
            if ($role === 'buyer') {
                $buyer_id = $current_user_id; $seller_id = $counterparty['id'];
                $buyer_name = $current_user_name; $seller_name = $counterparty['name'];
                $buyer_uuid = $current_user_uuid; $seller_uuid = $counterparty_uuid;
            } else {
                $buyer_id = $counterparty['id']; $seller_id = $current_user_id;
                $buyer_name = $counterparty['name']; $seller_name = $current_user_name;
                $buyer_uuid = $counterparty_uuid; $seller_uuid = $current_user_uuid;
            }

            $transaction_uuid = generate_uuid();

            $service_fee = $amount * SERVICE_FEE_PERCENTAGE;
            $gateway_fee = ($amount * GATEWAY_PERCENTAGE_COST) + GATEWAY_FIXED_COST;
            $commission_before_tax = $service_fee + $gateway_fee;
            $tax_on_commission = $commission_before_tax * GATEWAY_TAX_PERCENTAGE;
            $total_commission = $commission_before_tax + $tax_on_commission;

            if ($commission_payer === 'seller') {
                $net_amount = $amount - $total_commission;
            } elseif ($commission_payer === 'split') {
                $net_amount = $amount - ($total_commission / 2);
            } else {
                $net_amount = $amount;
            }

            $stmt = $conn->prepare("INSERT INTO transactions (transaction_uuid, seller_name, buyer_name, product_description, amount, commission, net_amount, commission_payer, buyer_id, seller_id, buyer_uuid, seller_uuid, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'initiated')");
            $stmt->bind_param("ssssdddsiiss", $transaction_uuid, $seller_name, $buyer_name, $product_description, $amount, $total_commission, $net_amount, $commission_payer, $buyer_id, $seller_id, $buyer_uuid, $seller_uuid);

            if ($stmt->execute()) {
                header("Location: transaction.php?tx_uuid={$transaction_uuid}");
                exit;
            } else {
                $message = "Error al crear la transacción: " . $stmt->error;
                $message_type = 'error';
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
    <title>Interpago - Transacciones Seguras en Colombia</title>
    <link rel="icon" href="data:image/svg+xml,%3csvg viewBox='0 0 100 100' xmlns='http://www.w3.org/2000/svg' fill='currentColor'%3e%3cpath d='M 45,10 C 25,10 10,25 10,45 L 10,55 C 10,75 25,90 45,90 L 55,90 C 60,90 60,85 55,80 L 45,80 C 30,80 20,70 20,55 L 20,45 C 20,30 30,20 45,20 L 55,20 C 60,20 60,15 55,10 Z'/%3e%3cpath d='M 55,90 C 75,90 90,75 90,55 L 90,45 C 90,25 75,10 55,10 L 45,10 C 40,10 40,15 45,20 L 55,20 C 70,20 80,30 80,45 L 80,55 C 80,70 70,80 55,80 L 45,80 C 40,80 40,85 45,90 Z'/%3e%3c/svg%3e">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .form-container { background-image: url('https://images.unsplash.com/photo-1554224155-16954405a255?q=80&w=2520&auto=format&fit=crop'); background-size: cover; background-position: center; }
        .live-feed-container { position: relative; width: 100%; height: 48px; background-color: #1e293b; overflow: hidden; box-shadow: inset 0 -2px 5px rgba(0,0,0,0.2); padding: 4px 0; }
        .feed-track { position: absolute; width: 100%; height: 20px; }
        .feed-track-1 { top: 4px; }
        .feed-track-2 { top: 24px; }
        .feed-item { position: absolute; white-space: nowrap; padding: 0.125rem 1rem; background-color: rgba(255, 255, 255, 0.1); border-radius: 9999px; color: #cbd5e1; font-size: 0.8rem; font-weight: 500; display: flex; align-items: center; animation: slideAndFade 20s linear; will-change: transform, opacity; }
        .feed-item .fa-check-circle { color: #4ade80; margin-right: 0.5rem; }
        .feed-item b { color: white; font-weight: 600; }
        @keyframes slideAndFade { 0% { transform: translateX(100vw); opacity: 1; } 90% { opacity: 1; } 100% { transform: translateX(-100%); opacity: 0; } }
        #chat-bubble { position: fixed; bottom: 25px; right: 25px; width: 60px; height: 60px; background-color: #1e293b; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); cursor: pointer; transition: transform 0.2s ease, background-color 0.2s ease; z-index: 999; }
        #chat-bubble:hover { background-color: #334155; transform: scale(1.1); }
        #chat-window { position: fixed; bottom: 100px; right: 25px; width: 350px; max-width: 90vw; height: 500px; background-color: #ffffff; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); display: none; flex-direction: column; overflow: hidden; z-index: 1000; transform-origin: bottom right; }
        #chat-window.open { display: flex; animation: pop-up 0.3s ease-out; }
        @keyframes pop-up { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
        .chat-header { background-color: #1e293b; color: white; padding: 15px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
        .chat-header #close-chat { cursor: pointer; font-size: 20px; }
        .chat-messages { flex-grow: 1; padding: 15px; overflow-y: auto; background-color: #f1f5f9; display: flex; flex-direction: column; }
        .message { max-width: 80%; padding: 10px 15px; border-radius: 20px; margin-bottom: 10px; line-height: 1.4; word-wrap: break-word; }
        .message.user { background-color: #1e293b; color: white; align-self: flex-end; border-bottom-right-radius: 5px; }
        .message.admin { background-color: #e2e8f0; color: #1e293b; align-self: flex-start; border-bottom-left-radius: 5px; }
        .chat-input { display: flex; border-top: 1px solid #e2e8f0; padding: 10px; background-color: #ffffff; }
        .chat-input input { flex-grow: 1; border: 1px solid #cbd5e1; border-radius: 20px; padding: 10px 15px; font-size: 14px; outline: none; }
        .chat-input button { background: none; border: none; color: #334155; font-size: 24px; cursor: pointer; padding: 0 10px; }
    </style>
</head>
<body class="bg-slate-50">
    <div class="flex flex-col md:flex-row min-h-screen">
        <div class="w-full md:w-1/2 bg-white p-8 md:p-12 flex flex-col">
            <nav class="w-full">
                <div class="flex justify-between items-center">
                    <div class="text-2xl font-bold text-slate-900"><a href="index.php" class="flex items-center space-x-3"><svg class="h-8 w-8 text-slate-800" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" fill="currentColor"><path d="M 45,10 C 25,10 10,25 10,45 L 10,55 C 10,75 25,90 45,90 L 55,90 C 60,90 60,85 55,80 L 45,80 C 30,80 20,70 20,55 L 20,45 C 20,30 30,20 45,20 L 55,20 C 60,20 60,15 55,10 Z"/><path d="M 55,90 C 75,90 90,75 90,55 L 90,45 C 90,25 75,10 55,10 L 45,10 C 40,10 40,15 45,20 L 55,20 C 70,20 80,30 80,45 L 80,55 C 80,70 70,80 55,80 L 45,80 C 40,80 40,85 45,90 Z"/></svg><span>Interpago</span></a></div>
                    <div class="hidden md:flex items-center space-x-4">
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <a href="dashboard.php" class="text-slate-600 font-medium hover:text-blue-600">Mi Panel</a>
                            <a href="edit_profile.php" class="text-slate-600 font-medium hover:text-blue-600">Mi Perfil</a>
                            <a href="logout.php" class="bg-slate-200 text-slate-800 font-bold py-2 px-4 rounded-lg hover:bg-slate-300 text-sm">Cerrar Sesión</a>
                        <?php else: ?>
                            <a href="login.php" class="text-slate-600 font-medium hover:text-blue-600">Iniciar Sesión</a>
                            <a href="register.php" class="bg-slate-800 text-white font-bold py-2 px-4 rounded-lg hover:bg-slate-900 text-sm">Registrarse</a>
                        <?php endif; ?>
                    </div>
                    <button id="mobile-menu-button" class="md:hidden p-2 text-slate-600"><i class="fas fa-bars text-2xl"></i></button>
                </div>
            </nav>
            <div class="flex-grow flex flex-col pt-16 md:pt-24">
                <div>
                    <h1 class="text-4xl md:text-5xl font-extrabold text-slate-900 leading-tight">Compra y Vende en Línea sin Riesgos.</h1>
                    <p class="mt-4 text-lg text-slate-600">Nuestra plataforma retiene el pago de forma segura hasta que ambas partes estén satisfechas, eliminando el fraude en el comercio online.</p>
                    <div class="mt-10 space-y-8">
                        <div class="flex items-start"><div class="flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-slate-100 text-slate-600 text-2xl"><i class="fas fa-file-signature"></i></div><div class="ml-4"><h3 class="text-lg font-bold">1. Define el Acuerdo</h3><p class="text-slate-600">Registra los términos de la venta para total transparencia.</p></div></div>
                        <div class="flex items-start"><div class="flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-slate-100 text-slate-600 text-2xl"><i class="fas fa-lock"></i></div><div class="ml-4"><h3 class="text-lg font-bold">2. Deposita el Pago</h3><p class="text-slate-600">El comprador paga. Nosotros retenemos el dinero de forma segura.</p></div></div>
                        <div class="flex items-start"><div class="flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-slate-100 text-slate-600 text-2xl"><i class="fas fa-hand-holding-dollar"></i></div><div class="ml-4"><h3 class="text-lg font-bold">3. Libera los Fondos</h3><p class="text-slate-600">El comprador confirma la recepción y liberamos el pago al vendedor.</p></div></div>
                    </div>
                </div>
                <div class="mt-auto pt-12">
                    <p class="text-sm font-medium text-slate-600 mb-4">Aceptamos los principales métodos de pago</p>
                    <div class="flex items-center flex-wrap gap-x-8 gap-y-4">
                        <img src="assets/images/wompi.svg" alt="Pagos con Wompi" class="h-20 w-auto" title="Pagos seguros con Wompi">
                        <img src="assets/images/visa.svg" alt="Visa" class="h-8 w-auto" title="Visa">
                        <img src="assets/images/mastercard.svg" alt="Mastercard" class="h-8 w-auto" title="Mastercard">
                        <img src="assets/images/pse.svg" alt="PSE" class="h-8 w-auto" title="PSE">
                        <img src="assets/images/bancolombia.svg" alt="Bancolombia" class="h-8 w-auto" title="Bancolombia">
                        <img src="assets/images/Nequi.svg" alt="Nequi" class="h-8 w-auto" title="Nequi">
                    </div>
                </div>
            </div>
        </div>
        <div class="w-full md:w-1/2 p-8 md:p-12 flex items-center justify-center form-container relative">
            <div class="absolute inset-0 bg-white/80 backdrop-blur-sm"></div>
            <div class="max-w-md w-full relative">
                <div class="live-feed-container rounded-t-2xl">
                    <div id="track-1" class="feed-track feed-track-1"></div>
                    <div id="track-2" class="feed-track feed-track-2"></div>
                </div>
                <div class="bg-white/90 p-8 rounded-b-2xl shadow-2xl w-full">
                    <div class="text-center mb-6"><h2 class="text-2xl font-bold text-slate-900">Iniciar una Transacción Segura</h2><?php if (!isset($_SESSION['user_id'])): ?><p class="mt-2 text-sm text-amber-800 bg-amber-100 p-3 rounded-lg">Debes <a href="login.php" class="font-bold underline text-slate-800">iniciar sesión</a> o <a href="register.php" class="font-bold underline text-slate-800">registrarte</a> para poder crear una transacción.</p><?php endif; ?></div>
                    <?php if (!empty($message)): ?><div class="p-4 mb-4 text-sm rounded-lg <?php echo $message_type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
                    <form id="create-transaction-form" action="index.php" method="POST" class="<?php echo !isset($_SESSION['user_id']) ? 'opacity-50 pointer-events-none' : ''; ?>">
                        <input type="hidden" name="create_transaction" value="1">
                        <div class="space-y-4">
                            <div><label class="block text-sm font-medium text-slate-700 mb-1">Tu Rol</label><select name="role" class="w-full p-3 border border-slate-300 rounded-lg" required><option value="buyer">Soy el Comprador</option><option value="seller">Soy el Vendedor</option></select></div>
                            <div><label class="block text-sm font-medium text-slate-700 mb-1">Correo de la Contraparte</label><input name="counterparty_email" type="email" placeholder="email@ejemplo.com" class="w-full p-3 border border-slate-300 rounded-lg" required></div>
                            <div><label class="block text-sm font-medium text-slate-700 mb-1">Descripción del Producto</label><textarea name="product_description" placeholder="Ej: iPhone 14 Pro, 256GB" class="w-full p-3 border border-slate-300 rounded-lg" required></textarea></div>
                            <div><label class="block text-sm font-medium text-slate-700 mb-1">Monto del Acuerdo (COP)</label><input id="amount" name="amount" type="number" step="0.01" placeholder="Ej: 350000" class="w-full p-3 border border-slate-300 rounded-lg" required></div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">¿Quién asume la comisión?</label>
                                <div class="grid grid-cols-3 gap-2 rounded-lg bg-slate-200 p-1">
                                    <div><input type="radio" name="commission_payer" id="payer_seller" value="seller" class="hidden peer" checked><label for="payer_seller" class="block text-center cursor-pointer rounded-md p-2 text-sm font-medium peer-checked:bg-slate-800 peer-checked:text-white">Vendedor</label></div>
                                    <div><input type="radio" name="commission_payer" id="payer_buyer" value="buyer" class="hidden peer"><label for="payer_buyer" class="block text-center cursor-pointer rounded-md p-2 text-sm font-medium peer-checked:bg-slate-800 peer-checked:text-white">Comprador</label></div>
                                    <div><input type="radio" name="commission_payer" id="payer_split" value="split" class="hidden peer"><label for="payer_split" class="block text-center cursor-pointer rounded-md p-2 text-sm font-medium peer-checked:bg-slate-800 peer-checked:text-white">Dividir 50/50</label></div>
                                </div>
                            </div>
                            <div id="commission-breakdown" class="p-4 bg-slate-100 rounded-lg space-y-2 text-sm hidden"></div>
                            <button id="submit-button" type="submit" class="w-full bg-slate-800 text-white font-bold py-3 px-4 rounded-lg hover:bg-slate-900" <?php echo !isset($_SESSION['user_id']) ? 'disabled' : ''; ?>><i class="fas fa-lock mr-2"></i>Iniciar Acuerdo Seguro</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div id="mobile-menu" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden">
        <div class="fixed top-0 right-0 h-full w-64 bg-white shadow-lg p-6"><button id="close-menu-button" class="absolute top-4 right-4 p-2 text-slate-600"><i class="fas fa-times text-2xl"></i></button><nav class="mt-12"><ul class="space-y-4 text-lg"><?php if (isset($_SESSION['user_id'])): ?><li><a href="dashboard.php" class="block p-2 rounded-md font-medium text-slate-700 hover:bg-slate-100">Mi Panel</a></li><li><a href="edit_profile.php" class="block p-2 rounded-md font-medium text-slate-700 hover:bg-slate-100">Mi Perfil</a></li><li><a href="logout.php" class="block p-2 rounded-md font-medium text-red-600 hover:bg-red-50">Cerrar Sesión</a></li><?php else: ?><li><a href="login.php" class="block p-2 rounded-md font-medium text-slate-700 hover:bg-slate-100">Iniciar Sesión</a></li><li><a href="register.php" class="block p-2 rounded-md font-medium bg-slate-800 text-white hover:bg-slate-900 text-center">Registrarse</a></li><?php endif; ?></ul></nav></div>
    </div>

    <?php if (isset($_SESSION['user_id'])): ?>
        <div id="chat-bubble"><i class="fa-solid fa-comments"></i></div>
        <div id="chat-window"><div class="chat-header"><span>Soporte en Línea</span><span id="close-chat">&times;</span></div><div class="chat-messages"></div><div class="chat-input"><input type="text" id="chat-message-input" placeholder="Escribe tu mensaje..."><button id="send-chat-message"><i class="fa-solid fa-paper-plane"></i></button></div></div>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const closeMenuButton = document.getElementById('close-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        if (mobileMenuButton) { mobileMenuButton.addEventListener('click', () => mobileMenu.classList.remove('hidden')); }
        if (closeMenuButton) { closeMenuButton.addEventListener('click', () => mobileMenu.classList.add('hidden')); }
        if (mobileMenu) { mobileMenu.addEventListener('click', (e) => { if (e.target === mobileMenu) mobileMenu.classList.add('hidden'); }); }

        const amountInput = document.getElementById('amount');
        if (amountInput) {
            const commissionBreakdown = document.getElementById('commission-breakdown');
            const commissionPayers = document.querySelectorAll('input[name="commission_payer"]');

            function calculateAndShowFees() {
                const amount = parseFloat(amountInput.value);
                const formatter = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 });

                if (isNaN(amount) || amount <= 0) {
                    commissionBreakdown.classList.add('hidden');
                    return;
                }

                const payer = document.querySelector('input[name="commission_payer"]:checked').value;
                const serviceFee = amount * <?php echo SERVICE_FEE_PERCENTAGE; ?>;
                const gatewayFee = (amount * <?php echo GATEWAY_PERCENTAGE_COST; ?>) + <?php echo GATEWAY_FIXED_COST; ?>;
                const commissionBeforeTax = serviceFee + gatewayFee;
                const taxOnCommission = commissionBeforeTax * <?php echo GATEWAY_TAX_PERCENTAGE; ?>;
                const totalCommission = commissionBeforeTax + taxOnCommission;

                let sellerReceives = amount;
                let buyerPays = amount;
                if (payer === 'seller') {
                    sellerReceives = amount - totalCommission;
                } else if (payer === 'buyer') {
                    buyerPays = amount + totalCommission;
                } else if (payer === 'split') {
                    const splitCommission = totalCommission / 2;
                    sellerReceives = amount - splitCommission;
                    buyerPays = amount + splitCommission;
                }

                commissionBreakdown.innerHTML = `
                    <div class="flex justify-between text-xs"><span class="text-slate-600">Comisión Interpago:</span><span class="font-semibold">${formatter.format(serviceFee)}</span></div>
                    <div class="flex justify-between text-xs"><span class="text-slate-600">Costo Pasarela (Aprox.):</span><span class="font-semibold">${formatter.format(gatewayFee)}</span></div>
                    <div class="flex justify-between text-xs"><span class="text-slate-600">IVA (19%) sobre comisión:</span><span class="font-semibold">${formatter.format(taxOnCommission)}</span></div>
                    <hr class="my-1 border-slate-200">
                    <div class="flex justify-between font-bold"><span class="text-slate-800">Comisión Total:</span><span class="text-slate-800">${formatter.format(totalCommission)}</span></div>
                    <hr class="my-1 border-dashed border-slate-300">
                    <div class="flex justify-between font-bold"><span class="text-green-600">Vendedor Recibe:</span><span class="text-green-600">${formatter.format(sellerReceives)}</span></div>
                    <div class="flex justify-between font-bold"><span class="text-red-600">Comprador Paga:</span><span class="text-red-600">${formatter.format(buyerPays)}</span></div>
                `;
                commissionBreakdown.classList.remove('hidden');
            }
            amountInput.addEventListener('input', calculateAndShowFees);
            commissionPayers.forEach(radio => radio.addEventListener('change', calculateAndShowFees));
            calculateAndShowFees();
        }

        const tracks = [document.getElementById('track-1'), document.getElementById('track-2')];
        if(tracks[0] && tracks[1]) {
            let allCities = [];
            let allTransactions = [];
            let trackStatus = [true, true];

            const formatter = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 });

            const messages = [
                "Acuerdo por {product} ({amount}) completado en {city}",
                "Pago de {amount} por {product} fue liberado en {city}",
                "Nueva transacción por {product} ({amount}) desde {city}"
            ];

            const getRandomItem = (arr) => arr[Math.floor(Math.random() * arr.length)];

            function createFeedItem() {
                if (allCities.length === 0 || allTransactions.length === 0) return;
                const availableTrackIndex = trackStatus.findIndex(status => status === true);
                if (availableTrackIndex === -1) return;
                trackStatus[availableTrackIndex] = false;
                const track = tracks[availableTrackIndex];
                const item = document.createElement('div');
                item.className = 'feed-item';

                const transaction = getRandomItem(allTransactions);
                const city = `<b>${getRandomItem(allCities)}</b>`;
                const amount = `<b>${formatter.format(transaction.amount)}</b>`;
                const product = `<b>${transaction.description}</b>`;

                let messageTemplate = getRandomItem(messages);

                let finalMessage = messageTemplate
                    .replace('{product}', product)
                    .replace('{amount}', amount)
                    .replace('{city}', city);

                item.innerHTML = `<i class="fas fa-check-circle"></i>&nbsp;${finalMessage}`;

                const animationDuration = 20 + Math.random() * 10;
                item.style.animationDuration = `${animationDuration}s`;
                track.appendChild(item);
                setTimeout(() => {
                    item.remove();
                    trackStatus[availableTrackIndex] = true;
                }, animationDuration * 1000);
            }

            function initAnimation(data) {
                allCities = data.cities;
                allTransactions = data.transactions;
                setInterval(createFeedItem, 3000);
                createFeedItem();
                setTimeout(createFeedItem, 1500);
            }

            async function startLiveFeed() {
                const exampleData = {
                    cities: ["Bogotá", "Medellín", "Cali", "Barranquilla"],
                    transactions: [
                        {amount: 75000, description: 'Audífonos'},
                        {amount: 1500000, description: 'Portátil'},
                        {amount: 30000, description: 'Suscripción'}
                    ]
                };
                initAnimation(exampleData);

                try {
                    const response = await fetch('get_realtime_data.php');
                    const realData = await response.json();
                    if (realData.cities && realData.transactions && realData.transactions.length > 0) {
                        allCities = realData.cities;
                        allTransactions = realData.transactions;
                    }
                } catch (error) {
                    console.error("Error al cargar datos reales para el live feed:", error);
                }
            }
            startLiveFeed();
        }

        const chatBubble = document.getElementById('chat-bubble');
        if (chatBubble) {
           const chatWindow = document.getElementById('chat-window');
            const closeChat = document.getElementById('close-chat');
            const chatMessages = document.querySelector('.chat-messages');
            const chatInput = document.getElementById('chat-message-input');
            const sendButton = document.getElementById('send-chat-message');

            let conversationId = null;
            let chatPollInterval = null;

            function appendMessage(senderType, text) {
                const messageElem = document.createElement('div');
                messageElem.classList.add('message', senderType === 'user' ? 'user' : 'admin');
                messageElem.textContent = text;
                chatMessages.appendChild(messageElem);
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }

            async function initChat() {
                try {
                    const response = await fetch('ajax/chat_handler.php?action=getHistory');
                    const data = await response.json();

                    if (data.error || !data.success) throw new Error(data.error || 'Respuesta inválida del servidor');

                    conversationId = data.conversation_id;
                    chatMessages.innerHTML = '';

                    data.messages.forEach(msg => {
                        appendMessage(msg.sender_type, msg.message);
                    });
                    chatMessages.scrollTop = chatMessages.scrollHeight;

                    if (chatPollInterval) clearInterval(chatPollInterval);
                    chatPollInterval = setInterval(getUpdates, 3500);

                } catch (err) {
                    console.error('Error al iniciar el chat:', err);
                    appendMessage('admin', 'No se pudo conectar al chat. Por favor, recarga la página.');
                }
            }

            async function sendMessage() {
                const messageText = chatInput.value.trim();
                if (messageText === '' || !conversationId) return;

                const tempMessageText = messageText;
                appendMessage('user', tempMessageText);
                chatInput.value = '';

                const formData = new FormData();
                formData.append('action', 'sendMessage');
                formData.append('conversation_id', conversationId);
                formData.append('message', tempMessageText);

                try {
                    const response = await fetch('ajax/chat_handler.php', { method: 'POST', body: formData });
                    const data = await response.json();
                    if (!data.success) throw new Error(data.error || 'Fallo al enviar el mensaje');
                } catch (err) {
                    console.error('Error al enviar mensaje:', err);
                    appendMessage('admin', 'Error: Tu mensaje no pudo ser enviado.');
                }
            }

            async function getUpdates() {
                if (!conversationId) return;
                try {
                    const formData = new FormData();
                    formData.append('action', 'getNewMessages');
                    formData.append('conversation_id', conversationId);

                    const response = await fetch('ajax/chat_handler.php', {method: 'POST', body: formData});
                    const data = await response.json();

                    if (data.error || !data.success) throw new Error(data.error || 'Respuesta inválida del servidor');

                    if (data.messages && data.messages.length > 0) {
                        data.messages.forEach(msg => {
                            if (msg.sender_type === 'admin') {
                                appendMessage(msg.sender_type, msg.message);
                            }
                        });
                    }
                } catch (err) {
                    console.error('Error al obtener actualizaciones del chat:', err);
                }
            }

            chatBubble.addEventListener('click', () => {
                chatWindow.classList.toggle('open');
                if (chatWindow.classList.contains('open') && !conversationId) {
                    initChat();
                } else if (!chatWindow.classList.contains('open')) {
                    if (chatPollInterval) clearInterval(chatPollInterval);
                    chatPollInterval = null;
                }
            });

            closeChat.addEventListener('click', () => {
                chatWindow.classList.remove('open');
                if (chatPollInterval) clearInterval(chatPollInterval);
                chatPollInterval = null;
            });

            sendButton.addEventListener('click', sendMessage);
            chatInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    sendMessage();
                }
            });
        }
    });
    </script>
</body>
</html>
