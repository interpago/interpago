<?php
// login.php

session_start();
// Si el usuario ya está logueado, redirigir al panel.
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

require_once 'config.php';
$error = '';

// Procesar el formulario cuando se envía
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Validaciones básicas
    if (empty($email) || empty($password)) {
        $error = 'Todos los campos son obligatorios.';
    } else {
        // CORRECCIÓN: Seleccionar también el user_uuid
        $stmt = $conn->prepare("SELECT id, user_uuid, name, password_hash FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            // Verificar si la contraseña coincide con el hash almacenado
            if (password_verify($password, $user['password_hash'])) {

                // Regenerar el ID de la sesión para mayor seguridad
                session_regenerate_id(true);

                // CORRECCIÓN: Guardar TODOS los datos necesarios del usuario en la sesión
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_uuid'] = $user['user_uuid']; // ¡Línea clave que faltaba!
                $_SESSION['user_name'] = $user['name'];

                // Registrar el inicio de sesión en el historial
                $current_ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

                $history_stmt = $conn->prepare("INSERT INTO login_history (user_id, ip_address, user_agent) VALUES (?, ?, ?)");
                $history_stmt->bind_param("iss", $user['id'], $current_ip, $user_agent);
                $history_stmt->execute();
                $history_stmt->close();

                // Redirigir al panel principal
                header("Location: dashboard.php");
                exit;
            }
        }
        // Mensaje de error genérico para no dar pistas a atacantes
        $error = 'Correo o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Interpago</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .login-bg {
            background-image: url('https://images.unsplash.com/photo-1604964432806-254d07c11f32?ixid=MnwxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8&ixlib=rb-1.2.1&auto=format&fit=crop&w=1080&q=80');
            background-size: cover;
            background-position: center;
        }
    </style>
</head>
<body class="bg-slate-50">
    <div class="flex min-h-screen">
        <!-- Columna de Formulario (Izquierda) -->
        <div class="w-full md:w-1/2 flex items-center justify-center p-8">
            <div class="max-w-md w-full">
                <div class="text-left mb-8">
                    <a href="index.php" class="text-slate-600 hover:text-slate-900 mb-4 block"><i class="fas fa-arrow-left mr-2"></i>Volver al Inicio</a>
                    <h2 class="text-3xl font-bold text-slate-900">Bienvenido de Vuelta</h2>
                    <p class="text-slate-600 mt-2">Accede a tu cuenta para gestionar tus transacciones.</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="p-4 mb-4 text-sm bg-red-100 text-red-800 rounded-lg text-center">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="POST" class="space-y-6">
                    <div>
                        <label for="email" class="block mb-2 text-sm font-medium text-slate-700">Correo Electrónico</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3"><i class="fas fa-envelope text-slate-400"></i></span>
                            <input id="email" name="email" type="email" class="w-full p-3 pl-10 border border-slate-300 rounded-lg focus:ring-2 focus:ring-slate-500" required>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between items-center mb-2">
                             <label for="password" class="block text-sm font-medium text-slate-700">Contraseña</label>
                             <a href="forgot_password.php" class="text-sm text-slate-600 hover:underline">¿Olvidaste tu contraseña?</a>
                        </div>
                         <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3"><i class="fas fa-lock text-slate-400"></i></span>
                            <input id="password" name="password" type="password" class="w-full p-3 pl-10 border border-slate-300 rounded-lg focus:ring-2 focus:ring-slate-500" required>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-slate-800 text-white font-bold py-3 px-4 rounded-lg hover:bg-slate-900 transition-colors">
                        <i class="fas fa-sign-in-alt mr-2"></i>Entrar
                    </button>
                </form>
                 <p class="text-center text-sm text-slate-600 mt-6">¿Aún no tienes una cuenta? <a href="register.php" class="font-medium text-slate-800 hover:underline">Regístrate ahora</a>.</p>
            </div>
        </div>

        <!-- Columna de Branding (Derecha) -->
        <div class="hidden md:flex md:w-1/2 login-bg relative">
            <div class="absolute inset-0 bg-slate-900 bg-opacity-75"></div>
            <div class="relative z-10 flex flex-col justify-center items-center text-white text-center p-12">
                <svg class="h-16 w-16 mb-4" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" fill="currentColor">
                    <path d="M 45,10 C 25,10 10,25 10,45 L 10,55 C 10,75 25,90 45,90 L 55,90 C 60,90 60,85 55,80 L 45,80 C 30,80 20,70 20,55 L 20,45 C 20,30 30,20 45,20 L 55,20 C 60,20 60,15 55,10 Z"/>
                    <path d="M 55,90 C 75,90 90,75 90,55 L 90,45 C 90,25 75,10 55,10 L 45,10 C 40,10 40,15 45,20 L 55,20 C 70,20 80,30 80,45 L 80,55 C 80,70 70,80 55,80 L 45,80 C 40,80 40,85 45,90 Z"/>
                </svg>
                <h1 class="text-4xl font-bold">Interpago</h1>
                <p class="mt-4 text-lg text-slate-300">Compra y vende con la tranquilidad de que tu dinero está protegido hasta que el acuerdo se complete.</p>
            </div>
        </div>
    </div>
</body>
</html>
