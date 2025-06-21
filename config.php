<?php
// config.php



// Muestra todos los errores para facilitar la depuración.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// -- Configuración de la Base de Datos --
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'escrow_db');



// -- Configuración de la Aplicación --
define('APP_URL', 'https://730d-191-95-17-121.ngrok-free.app/pago'); // Usando la URL de Laragon, más estable

// **NUEVO: Periodo de Garantía en Minutos**
define('INSPECTION_PERIOD_MINUTES', 60); // 1 hora de garantía para el comprador

// -- NUEVO: Modelo de Comisión Avanzado --
// Esta es tu ganancia neta sobre el valor de la transacción.
define('SERVICE_FEE_PERCENTAGE', 0.02); // Tu 2.0% de ganancia

// Estos son los costos que te cobra Wompi y que le pasaremos al usuario.
define('GATEWAY_PERCENTAGE_COST', 0.0265); // El 2.85% que cobra Wompi
define('GATEWAY_FIXED_COST', 900); // El costo fijo por transacción de Wompi (ej. $700 + IVA)

// -- Configuración de Límites de Transacción --
define('MINIMUM_TRANSACTION_AMOUNT', 25000);
define('TRANSACTION_AMOUNT_LIMIT', 10000000); // Podemos subir el límite ahora
define('MONTHLY_VOLUME_LIMIT', 20000000);

// -- Configuración de Pasarela de Pago (Wompi) --
define('WOMPI_API_URL', 'https://sandbox.wompi.co/v1');
define('WOMPI_PUBLIC_KEY', 'pub_test_1HiyNrB9fU2OZtdayrZiV1KhfShaek1U');
define('WOMPI_PRIVATE_KEY', 'prv_test_7N8qiTYLmc4o6h1QshuEKJIMGanZPOYp');
define('WOMPI_EVENTS_SECRET', 'test_events_guxupj7Ni9Pj5Uqw5N5mfpsvHpSg1uVU');
define('WOMPI_INTEGRITY_SECRET', 'test_integrity_0Al7autXe60ogIm1LFNqTwsVmw6A3REa'); // **NUEVO Y CRUCIAL**
// -- Configuración de Correo SMTP --
define('SMTP_HOST', 'smtp.tuservidor.com');
define('SMTP_USERNAME', 'notificaciones@interpago.com');
define('SMTP_PASSWORD', 'tu-contraseña-smtp');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl');
define('SMTP_FROM_EMAIL', 'notificaciones@interpago.com');
define('SMTP_FROM_NAME', 'Interpago');

// Conexión a la BD
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    die("Error crítico de conexión a la base de datos: " . $e->getMessage());
}
?>
