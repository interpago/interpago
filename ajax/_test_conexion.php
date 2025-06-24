<?php
// Este es un archivo de prueba para diagnosticar problemas de conexión y configuración.

// Habilitamos la visualización de todos los errores posibles.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Prueba de Diagnóstico del Servidor v2</h1>";
echo "<p>Paso 1: El script de prueba se está ejecutando...</p>";

// --- Prueba de Inclusión de Archivo de Configuración ---
$config_path = __DIR__ . '/../config.php';
echo "<p>Paso 2: Intentando cargar el archivo de configuración desde: <strong>" . $config_path . "</strong>...</p>";

if (file_exists($config_path)) {
    require_once $config_path;
    echo "<p style='color:green;'><strong>Éxito:</strong> El archivo config.php se ha cargado correctamente.</p>";
} else {
    echo "<p style='color:red;'><strong>ERROR CRÍTICO:</strong> No se pudo encontrar el archivo config.php en la ruta especificada.</p>";
    exit;
}

// --- Prueba de Conexión a la Base de Datos ---
echo "<p>Paso 3: Verificando la conexión a la base de datos...</p>";

if (isset($conn) && $conn->ping()) {
    echo "<p style='color:green;'><strong>Éxito:</strong> La conexión a la base de datos se ha realizado correctamente.</p>";
} else {
    $error_details = isset($conn) ? $conn->error : "La variable \$conn no está definida en config.php.";
    echo "<p style='color:red;'><strong>ERROR CRÍTICO:</strong> No se pudo establecer la conexión con la base de datos. Detalles: " . $error_details . "</p>";
    exit;
}

// --- Prueba de Inclusión de Archivo de Composer ---
$vendor_path = __DIR__ . '/../vendor/autoload.php';
echo "<p>Paso 4: Intentando cargar el autoloader de Composer desde: <strong>" . $vendor_path . "</strong>...</p>";

if (file_exists($vendor_path)) {
    require_once $vendor_path;
    echo "<p style='color:green;'><strong>Éxito:</strong> El archivo vendor/autoload.php se ha cargado correctamente.</p>";
} else {
    echo "<p style='color:red;'><strong>ERROR CRÍTICO:</strong> No se pudo encontrar el archivo vendor/autoload.php. Asegúrate de haber ejecutado 'composer require firebase/php-jwt' en la raíz de tu proyecto.</p>";
    exit;
}

// --- Prueba de Extensiones de PHP ---
echo "<p>Paso 5: Verificando extensiones de PHP necesarias...</p>";
$extensions_ok = true;

if (extension_loaded('curl')) {
    echo "<p style='color:green;'><strong>Éxito:</strong> La extensión 'curl' está habilitada.</p>";
} else {
    echo "<p style='color:red;'><strong>ERROR CRÍTICO:</strong> La extensión 'curl' está deshabilitada. Debes habilitarla en la configuración de PHP de Laragon.</p>";
    $extensions_ok = false;
}

if (extension_loaded('openssl')) {
    echo "<p style='color:green;'><strong>Éxito:</strong> La extensión 'openssl' está habilitada.</p>";
} else {
    echo "<p style='color:red;'><strong>ERROR CRÍTICO:</strong> La extensión 'openssl' está deshabilitada. Esta es vital para la seguridad.</p>";
    $extensions_ok = false;
}

if ($extensions_ok) {
    echo "<h2><strong style='color:blue;'>DIAGNÓSTICO COMPLETO: ¡Todo parece estar configurado correctamente!</strong></h2>";
    echo "<p>Si el problema persiste, es probable que se deba a un firewall o a un problema de SSL que impide la comunicación con los servidores de Google.</p>";
}

?>
