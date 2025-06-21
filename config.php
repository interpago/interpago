<?php
// config.php

// Muestra todos los errores para facilitar la depuración.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- INICIO DE CORRECCIÓN CRÍTICA: ZONA HORARIA ---
// Establecer la zona horaria para toda la aplicación. Esto previene
// conflictos entre la hora del servidor PHP y la base de datos.
date_default_timezone_set('America/Bogota');
// --- FIN DE CORRECCIÓN ---


// -- Configuración de la Base de Datos --
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'escrow_db');


// -- Configuración de la Aplicación --
define('APP_URL', 'https://e7aa-2800-e2-4a80-587-99c9-8bf-d2c3-1ab6.ngrok-free.app/pago');

// **Periodo de Garantía en Minutos**
define('INSPECTION_PERIOD_MINUTES', 60); // 1 hora de garantía para el comprador

// -- Modelo de Comisión Avanzado --
define('SERVICE_FEE_PERCENTAGE', 0.02); // Tu 2.0% de ganancia
define('GATEWAY_PERCENTAGE_COST', 0.0265); // El 2.65% que cobra la pasarela
define('GATEWAY_FIXED_COST', 900); // Costo fijo por transacción

// -- Configuración de Límites de Transacción --
define('MINIMUM_TRANSACTION_AMOUNT', 25000);
define('TRANSACTION_AMOUNT_LIMIT', 10000000);
define('MONTHLY_VOLUME_LIMIT', 20000000);

// -- Configuración de Pasarela de Pago (Wompi) --
define('WOMPI_API_URL', 'https://sandbox.wompi.co/v1');
define('WOMPI_PUBLIC_KEY', 'pub_test_1HiyNrB9fU2OZtdayrZiV1KhfShaek1U');
define('WOMPI_PRIVATE_KEY', 'prv_test_7N8qiTYLmc4o6h1QshuEKJIMGanZPOYp');
define('WOMPI_EVENTS_SECRET', 'test_events_guxupj7Ni9Pj5Uqw5N5mfpsvHpSg1uVU');
define('WOMPI_INTEGRITY_SECRET', 'test_integrity_0Al7autXe60ogIm1LFNqTwsVmw6A3REa');

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
