<?php
// pay.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Carga la librería manual, que es más robusta en este entorno.
require_once __DIR__ . '/lib/MercadoPagoSDK.php';
require_once __DIR__ . '/config.php';

// **NUEVO: Autodiagnóstico de la URL de la aplicación**
if (strpos(APP_URL, 'localhost') !== false || APP_URL === 'URL_PUBLICA_DE_TU_PROYECTO') {
    die("<strong>ERROR CRÍTICO DE CONFIGURACIÓN:</strong> La 'APP_URL' en tu archivo 'config.php' apunta a un servidor local ('" . htmlspecialchars(APP_URL) . "').<br><br>
         Mercado Pago no puede acceder a 'localhost'. Debes usar una URL pública.<br><br>
         <strong>Solución:</strong><br>
         1. Inicia Ngrok con el comando: <code>ngrok http 80</code><br>
         2. Copia la URL pública que te da Ngrok (la que empieza con 'https://').<br>
         3. Pega esa URL en la constante 'APP_URL' de tu archivo <strong>config.php</strong>.<br>
         Ejemplo: <code>define('APP_URL', 'https://1a2b-3c4d.ngrok-free.app/pago');</code>");
}

session_start();

if (!isset($_GET['tx_uuid']) || !isset($_SESSION['user_id'])) {
    die("Error: Faltan parámetros para iniciar el pago.");
}

$transaction_uuid = $_GET['tx_uuid'];
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM transactions WHERE transaction_uuid = ? AND buyer_id = ? AND status = 'initiated'");
$stmt->bind_param("si", $transaction_uuid, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    die("Error: Transacción no válida o no tienes permiso para pagarla.");
}
$transaction = $result->fetch_assoc();

// Configurar el SDK de Mercado Pago
MercadoPago_SDK::setAccessToken(MERCADO_PAGO_ACCESS_TOKEN);

// Crear una preferencia de pago
$preference = new MercadoPago_Preference();
$item = new MercadoPago_Item();
$item->title = $transaction['product_description'];
$item->quantity = 1;
$item->unit_price = (float)$transaction['amount'];
$item->currency_id = "COP";
$preference->items = array($item);

$base_url = rtrim(APP_URL, '/');
$preference->back_urls = [
    "success" => "{$base_url}/payment_response.php?status=approved&tx_uuid={$transaction_uuid}",
    "failure" => "{$base_url}/payment_response.php?status=failure&tx_uuid={$transaction_uuid}",
    "pending" => "{$base_url}/payment_response.php?status=pending&tx_uuid={$transaction_uuid}"
];
$preference->auto_return = "approved";

$preference->notification_url = "{$base_url}/mercadopago_webhook.php";
$preference->external_reference = $transaction_uuid;

try {
    $preference->save();
    if ($preference->init_point) {
        header("Location: " . $preference->init_point);
        exit;
    } else {
        die("Error: Mercado Pago no devolvió una URL de pago (init_point).");
    }
} catch (Exception $e) {
    die("Error al crear la preferencia de pago: " . $e->getMessage());
}
?>
