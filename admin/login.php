<?php
// admin/login.php

session_start();

// --- INICIO DE CORRECCIÓN: Evitar caché en la página de login ---
// Estas cabeceras le dicen al navegador que no guarde esta página en caché.
// Así, si un usuario logueado presiona "atrás", el navegador recargará la página
// y el código de abajo lo redirigirá correctamente al panel.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
// --- FIN DE CORRECCIÓN ---


// Si el usuario ya está logueado, redirigirlo al dashboard.
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/../config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $error = 'Por favor, completa todos los campos.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password_hash FROM admins WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $admin = $result->fetch_assoc();
            if (password_verify($password, $admin['password_hash'])) {
                $_SESSION['loggedin'] = true;
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                header("Location: index.php");
                exit;
            }
        }
        $error = 'Usuario o contraseña incorrectos.';
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Admin - Interpago</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-slate-50">
    <div class="flex min-h-screen items-center justify-center">
        <div class="max-w-md w-full bg-white p-8 rounded-2xl shadow-lg">
            <div class="text-center mb-8">
                <i class="fas fa-shield-alt text-5xl text-slate-800 mb-3"></i>
                <h2 class="text-3xl font-bold text-slate-900">Acceso Administrador</h2>
                <p class="text-slate-600 mt-2">Ingresa tus credenciales para gestionar la plataforma.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="p-4 mb-4 text-sm bg-red-100 text-red-800 rounded-lg text-center">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="space-y-6">
                <div>
                    <label for="username" class="block mb-2 text-sm font-medium text-slate-700">Usuario</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3"><i class="fas fa-user text-slate-400"></i></span>
                        <input id="username" name="username" type="text" class="w-full p-3 pl-10 border border-slate-300 rounded-lg focus:ring-2 focus:ring-slate-500" required>
                    </div>
                </div>
                <div>
                    <label for="password" class="block mb-2 text-sm font-medium text-slate-700">Contraseña</label>
                     <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3"><i class="fas fa-lock text-slate-400"></i></span>
                        <input id="password" name="password" type="password" class="w-full p-3 pl-10 border border-slate-300 rounded-lg focus:ring-2 focus:ring-slate-500" required>
                    </div>
                </div>
                <button type="submit" class="w-full bg-slate-800 text-white font-bold py-3 px-4 rounded-lg hover:bg-slate-900 transition-colors">
                    <i class="fas fa-sign-in-alt mr-2"></i>Entrar
                </button>
            </form>
        </div>
    </div>
</body>
</html>
