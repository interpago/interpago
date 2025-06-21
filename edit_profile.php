<?php
// edit_profile.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config.php';
$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Obtener los datos actuales del usuario
$stmt = $conn->prepare("SELECT name, email, document_type, document_number, profile_picture, password_hash FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) { die("Error: Usuario no encontrado."); }

// Procesar el formulario de actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Lógica para cambiar la contraseña
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if (!password_verify($current_password, $user['password_hash'])) {
            $message = 'La contraseña actual es incorrecta.';
            $message_type = 'error';
        } elseif ($new_password !== $confirm_password) {
            $message = 'Las nuevas contraseñas no coinciden.';
            $message_type = 'error';
        } elseif (strlen($new_password) < 6) {
             $message = 'La nueva contraseña debe tener al menos 6 caracteres.';
             $message_type = 'error';
        } else {
            $new_password_hash = password_hash($new_password, PASSWORD_BCRYPT);
            $update_pass_stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $update_pass_stmt->bind_param("si", $new_password_hash, $user_id);
            if ($update_pass_stmt->execute()) {
                $message = '¡Contraseña actualizada con éxito!';
                $message_type = 'success';
                $user['password_hash'] = $new_password_hash;
            } else {
                $message = 'Error al actualizar la contraseña.';
                $message_type = 'error';
            }
        }
    }
    // Lógica para actualizar foto de perfil
    elseif (isset($_POST['update_picture'])) {
        $image_path = $user['profile_picture'];
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/profiles/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }

            $file_name = $user_id . '_' . uniqid() . '.' . pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $target_file = $upload_dir . $file_name;

            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_file)) {
                if (!empty($image_path) && file_exists(__DIR__ . '/' . $image_path)) {
                    unlink(__DIR__ . '/' . $image_path);
                }
                $image_path = 'uploads/profiles/' . $file_name;

                $update_pic_stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                $update_pic_stmt->bind_param("si", $image_path, $user_id);
                if ($update_pic_stmt->execute()) {
                    $message = 'Foto de perfil actualizada con éxito.';
                    $message_type = 'success';
                    $user['profile_picture'] = $image_path;
                } else {
                    $message = 'Error al guardar la foto en la base de datos.';
                    $message_type = 'error';
                }
            } else { $message = 'Error al mover la imagen subida.'; $message_type = 'error'; }
        } else {
            $message = 'No se seleccionó ninguna imagen o hubo un error en la subida.';
            $message_type = 'error';
        }
    }
}

// Obtener historial de inicios de sesión
$history_stmt = $conn->prepare("SELECT * FROM login_history WHERE user_id = ? ORDER BY login_time DESC LIMIT 5");
$history_stmt->bind_param("i", $user_id);
$history_stmt->execute();
$login_history = $history_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centro de Cuenta y Seguridad - Interpago</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-slate-100">
    <div class="container mx-auto p-6 md:p-10 max-w-6xl">
         <header class="text-center mb-8">
            <a href="dashboard.php" class="text-slate-600 hover:text-slate-900 mb-4 inline-block"><i class="fas fa-arrow-left mr-2"></i>Volver a mi Panel</a>
            <h1 class="text-4xl font-extrabold text-slate-900">Centro de Cuenta y Seguridad</h1>
            <p class="text-slate-600 mt-2">Gestiona tu perfil, contraseña y revisa la actividad de tu cuenta.</p>
        </header>

        <?php if ($message): ?>
        <div class="p-4 mb-6 text-sm rounded-lg text-center <?php echo $message_type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1 space-y-8">
                <!-- Tarjeta de Perfil -->
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-slate-800 mb-6">Mi Perfil</h2>
                    <form action="edit_profile.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                        <div class="flex items-center space-x-4">
                            <?php if (!empty($user['profile_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Foto de perfil" class="w-20 h-20 rounded-full object-cover ring-4 ring-slate-200">
                            <?php else: ?>
                                <div class="w-20 h-20 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center text-3xl font-bold"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></div>
                            <?php endif; ?>
                            <div>
                                <label for="profile_picture" class="cursor-pointer bg-white border border-slate-300 text-slate-700 font-semibold py-2 px-3 rounded-lg hover:bg-slate-50 text-xs">
                                    <span>Cambiar Foto</span>
                                    <input type="file" name="profile_picture" id="profile_picture" class="hidden">
                                </label>
                            </div>
                        </div>
                         <button type="submit" name="update_picture" class="w-full text-center bg-slate-200 text-slate-800 font-bold py-2 px-4 rounded-lg hover:bg-slate-300 text-sm">Guardar Foto</button>
                    </form>
                    <hr class="my-6">
                    <div class="space-y-3 text-sm">
                        <p><strong class="text-slate-500">Nombre:</strong><br><span class="text-slate-800"><?php echo htmlspecialchars($user['name']); ?></span></p>
                        <p><strong class="text-slate-500">Email:</strong><br><span class="text-slate-800"><?php echo htmlspecialchars($user['email']); ?></span></p>
                        <p><strong class="text-slate-500">Documento:</strong><br><span class="text-slate-800"><?php echo htmlspecialchars($user['document_type'] . ' - ' . $user['document_number']); ?></span></p>
                    </div>
                </div>
                 <!-- Tarjeta de cambio de contraseña -->
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-slate-800 mb-6">Cambiar Contraseña</h2>
                    <form action="edit_profile.php" method="POST" class="space-y-4">
                        <div><label for="current_password" class="block text-sm font-medium text-slate-700">Contraseña Actual</label><input type="password" name="current_password" id="current_password" class="mt-1 w-full p-3 border border-slate-300 rounded-lg" required></div>
                        <div><label for="new_password" class="block text-sm font-medium text-slate-700">Nueva Contraseña</label><input type="password" name="new_password" id="new_password" class="mt-1 w-full p-3 border border-slate-300 rounded-lg" required></div>
                        <div><label for="confirm_password" class="block text-sm font-medium text-slate-700">Confirmar Nueva</label><input type="password" name="confirm_password" id="confirm_password" class="mt-1 w-full p-3 border border-slate-300 rounded-lg" required></div>
                        <button type="submit" name="change_password" class="w-full bg-slate-800 text-white font-bold py-3 rounded-lg hover:bg-slate-900">Actualizar Contraseña</button>
                    </form>
                </div>
            </div>

            <!-- Columna de historial de sesiones -->
            <div class="lg:col-span-2 bg-white p-8 rounded-2xl shadow-lg">
                <h2 class="text-2xl font-bold text-slate-800 mb-6">Historial de Inicios de Sesión Recientes</h2>
                <div class="space-y-4">
                    <?php if (empty($login_history)): ?>
                        <p class="text-slate-500 italic">No hay historial de inicios de sesión.</p>
                    <?php else: ?>
                        <?php foreach($login_history as $session): ?>
                        <div class="flex items-center p-4 bg-slate-50 rounded-lg">
                            <i class="fas fa-desktop text-2xl text-slate-400"></i>
                            <!-- **CORRECCIÓN: Se añade min-w-0 para que truncate funcione correctamente en un contenedor flex.** -->
                            <div class="ml-4 flex-grow min-w-0">
                                <p class="font-semibold text-slate-700 truncate text-sm"><?php echo htmlspecialchars($session['user_agent']); ?></p>
                                <p class="text-xs text-slate-500">
                                    <?php echo date("d M Y, H:i", strtotime($session['login_time'])); ?> - IP: <?php echo htmlspecialchars($session['ip_address']); ?>
                                </p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
