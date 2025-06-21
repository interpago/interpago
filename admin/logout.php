<?php
// admin/logout.php

// Se añade el reporte de errores para depuración.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Iniciar la sesión para poder acceder a ella.
session_start();

// 1. Desasigna todas las variables de la sesión.
$_SESSION = array();

// 2. Si se está usando una cookie de sesión, se elimina.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Finalmente, se destruye la sesión en el servidor.
session_destroy();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cerrando Sesión - Administración</title>
    <meta http-equiv="refresh" content="3;url=login.php">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="max-w-md w-full bg-white p-8 rounded-2xl shadow-lg text-center">
        <div class="text-green-500 mb-4">
            <i class="fas fa-check-circle text-5xl"></i>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">Has cerrado sesión del panel de administración.</h1>
        <p class="text-gray-600 mt-2">Serás redirigido a la página de inicio de sesión en unos segundos.</p>
        <div class="mt-6">
            <a href="login.php" class="font-medium text-indigo-600 hover:underline">Ir a la página de login ahora</a>
        </div>
    </div>
</body>
</html>
