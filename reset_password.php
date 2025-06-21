<?php
// reset_password.php

require_once 'config.php';
require_once 'send_notification.php';

$message = '';
$message_type = '';
$token = $_GET['token'] ?? '';
$token_is_valid = false;
$user_email = '';

if (empty($token)) {
    $message = 'Token no proporcionado o inválido.';
    $message_type = 'error';
} else {
    // Buscar el token en la base de datos
    $stmt = $conn->prepare("SELECT email, expires_at FROM password_resets WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $expires_at = strtotime($row['expires_at']);

        // Verificar si el token ha expirado
        if ($expires_at > time()) {
            $token_is_valid = true;
            $user_email = $row['email'];
        } else {
            $message = 'Este enlace de recuperación ha expirado. Por favor, solicita uno nuevo.';
            $message_type = 'error';
        }
    } else {
        $message = 'Token inválido. Por favor, asegúrate de usar el enlace correcto.';
        $message_type = 'error';
    }
    $stmt->close();
}

// Procesar el formulario de cambio de contraseña
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_is_valid) {
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];

    if (empty($password) || empty($password_confirm)) {
        $message = 'Ambos campos de contraseña son obligatorios.';
        $message_type = 'error';
    } elseif (strlen($password) < 6) {
        $message = 'La nueva contraseña debe tener al menos 6 caracteres.';
        $message_type = 'error';
    } elseif ($password !== $password_confirm) {
        $message = 'Las contraseñas no coinciden.';
        $message_type = 'error';
    } else {
        // Todo es válido, actualizar la contraseña del usuario
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $update_stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
        $update_stmt->bind_param("ss", $password_hash, $user_email);

        if ($update_stmt->execute()) {
            // Eliminar el token para que no pueda ser reutilizado
            $delete_stmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
            $delete_stmt->bind_param("s", $user_email);
            $delete_stmt->execute();
            $delete_stmt->close();

            $message = '¡Tu contraseña ha sido actualizada con éxito! Ya puedes iniciar sesión.';
            $message_type = 'success';
            $token_is_valid = false; // Deshabilitar el formulario después del éxito
        } else {
            $message = 'Ocurrió un error al actualizar tu contraseña.';
            $message_type = 'error';
        }
        $update_stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer Contraseña - Plataforma Escrow</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">
    <div class="max-w-md w-full bg-white p-8 rounded-2xl shadow-lg">
        <div class="text-center mb-6">
            <h1 class="text-3xl font-bold text-gray-900">Restablecer Contraseña</h1>
        </div>

        <?php if ($message): ?>
            <div class="p-4 mb-6 text-sm rounded-lg text-center <?php echo $message_type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <?php if ($token_is_valid): ?>
            <p class="text-gray-600 mt-2 mb-6 text-center">Estás restableciendo la contraseña para <strong><?php echo htmlspecialchars($user_email); ?></strong>.</p>
            <form action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" method="POST" class="space-y-4">
                <div>
                    <label for="password" class="block mb-2 text-sm font-medium text-gray-700">Nueva Contraseña</label>
                    <input type="password" name="password" id="password" class="w-full p-3 border rounded-lg" required>
                </div>
                <div>
                    <label for="password_confirm" class="block mb-2 text-sm font-medium text-gray-700">Confirmar Nueva Contraseña</label>
                    <input type="password" name="password_confirm" id="password_confirm" class="w-full p-3 border rounded-lg" required>
                </div>
                <button type="submit" class="w-full bg-indigo-600 text-white font-bold py-3 rounded-lg hover:bg-indigo-700">
                    Guardar Nueva Contraseña
                </button>
            </form>
        <?php else: ?>
            <div class="text-center">
                <a href="login.php" class="font-medium text-indigo-600 hover:underline">Volver a Iniciar Sesión</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
