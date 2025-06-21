<?php
// forgot_password.php
require_once 'config.php';
require_once 'send_notification.php';

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = trim($_POST['email']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Por favor, ingresa un correo electrónico válido.';
        $message_type = 'error';
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            try {
                $token = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $token = md5(uniqid(rand(), true));
            }

            $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $insert_stmt = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
            $insert_stmt->bind_param("sss", $email, $token, $expires_at);

            if ($insert_stmt->execute()) {
                $reset_link = rtrim(APP_URL, '/') . '/reset_password.php?token=' . $token;
                send_notification($conn, "password_reset_request", ['email' => $email, 'reset_link' => $reset_link]);
                $message = 'Si tu correo está registrado, hemos enviado un enlace para restablecer tu contraseña.';
                $message_type = 'success';
            } else {
                $message = 'Ocurrió un error al procesar tu solicitud.';
                $message_type = 'error';
            }
        } else {
            $message = 'Si tu correo está registrado, hemos enviado un enlace para restablecer tu contraseña.';
            $message_type = 'success';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña - Plataforma Escrow</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">
    <div class="max-w-md w-full bg-white p-8 rounded-2xl shadow-lg">
        <div class="text-center mb-6"><h1 class="text-3xl font-bold text-gray-900">Recuperar Contraseña</h1><p class="text-gray-500 mt-2">Ingresa tu correo y te enviaremos un enlace para restablecerla.</p></div>
        <?php if ($message): ?><div class="p-4 mb-6 text-sm rounded-lg text-center <?php echo $message_type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>"><?php echo $message; ?></div><?php endif; ?>
        <form action="forgot_password.php" method="POST" class="space-y-4">
            <div><label for="email" class="block mb-2 text-sm font-medium text-gray-700">Correo Electrónico</label><input type="email" name="email" id="email" class="w-full p-3 border rounded-lg" placeholder="tu-correo@ejemplo.com" required></div>
            <button type="submit" class="w-full bg-indigo-600 text-white font-bold py-3 rounded-lg hover:bg-indigo-700">Enviar Enlace de Recuperación</button>
        </form>
        <p class="text-center text-sm text-gray-600 mt-4">¿Recordaste tu contraseña? <a href="login.php" class="font-medium text-indigo-600 hover:underline">Inicia sesión aquí</a>.</p>
    </div>
</body>
</html>
