<?php
// admin/login.php

// Iniciar la sesión para recordar si el usuario ha iniciado sesión.
session_start();

// Si el usuario ya está logueado, redirigirlo al dashboard.
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header("Location: index.php");
    exit;
}

// Se usa una ruta absoluta para mayor fiabilidad al incluir el archivo
require_once __DIR__ . '/../config.php';

$error = '';

// Procesar el formulario de login cuando se envía
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $error = 'Por favor, completa todos los campos.';
    } else {
        // Buscar al administrador en la base de datos
        $stmt = $conn->prepare("SELECT id, username, password_hash FROM admins WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $admin = $result->fetch_assoc();

            // Verificar la contraseña hasheada
            if (password_verify($password, $admin['password_hash'])) {
                // Credenciales correctas, iniciar sesión.
                $_SESSION['loggedin'] = true;
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                header("Location: index.php");
                exit;
            } else {
                $error = 'Usuario o contraseña incorrectos.';
            }
        } else {
            $error = 'Usuario o contraseña incorrectos.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Admin - Plataforma Escrow</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .login-bg {
            background-image: url('https://images.unsplash.com/photo-1556740738-b6a63e27c4df?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=MnwzNTc5fDB8MXxzZWFyY2h8N3x8cGF5bWVudHxlbnwwfHx8fDE2Mzg4MDQwNTM&ixlib=rb-1.2.1&q=80&w=1080');
            background-size: cover;
            background-position: center;
        }
    </style>
</head>
<body class="bg-gray-50">

    <div class="flex min-h-screen">
        <!-- Columna de Branding (Izquierda) -->
        <div class="hidden md:flex md:w-1/2 login-bg relative">
            <div class="absolute inset-0 bg-indigo-800 bg-opacity-70"></div>
            <div class="relative z-10 flex flex-col justify-center items-center text-white text-center p-12">
                <i class="fas fa-shield-alt text-6xl mb-4"></i>
                <h1 class="text-4xl font-bold">Plataforma Segura de Escrow</h1>
                <p class="mt-4 text-lg text-indigo-200">Tu intermediario de confianza para transacciones seguras en Colombia.</p>
            </div>
        </div>

        <!-- Columna de Formulario (Derecha) -->
        <div class="w-full md:w-1/2 flex items-center justify-center p-8">
            <div class="max-w-md w-full">
                <div class="text-center mb-8">
                    <h2 class="text-3xl font-bold text-gray-900">Bienvenido, Administrador</h2>
                    <p class="text-gray-600 mt-2">Ingresa tus credenciales para acceder al panel.</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="p-4 mb-4 text-sm bg-red-100 text-red-800 rounded-lg text-center">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="POST" class="space-y-6">
                    <div>
                        <label for="username" class="block mb-2 text-sm font-medium text-gray-700">Usuario</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                                <i class="fas fa-user text-gray-400"></i>
                            </span>
                            <input id="username" name="username" type="text" class="w-full p-3 pl-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500" required>
                        </div>
                    </div>
                    <div>
                        <label for="password" class="block mb-2 text-sm font-medium text-gray-700">Contraseña</label>
                         <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                                <i class="fas fa-lock text-gray-400"></i>
                            </span>
                            <input id="password" name="password" type="password" class="w-full p-3 pl-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500" required>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-indigo-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-indigo-700 transition-transform transform hover:scale-105">
                        <i class="fas fa-sign-in-alt mr-2"></i>Entrar
                    </button>
                </form>
            </div>
        </div>
    </div>

</body>
</html>
