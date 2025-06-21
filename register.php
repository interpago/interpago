<?php
// register.php
require_once 'config.php';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $document_type = trim($_POST['document_type']);
    $document_number = trim($_POST['document_number']);
    $birth_date = trim($_POST['birth_date']);
    $phone_number = trim($_POST['phone_number']);

    if (!isset($_POST['agree_terms'])) {
        $error = 'Debes aceptar los Términos y Condiciones para continuar.';
    } elseif (empty($name) || empty($email) || empty($password) || empty($document_type) || empty($document_number) || empty($birth_date) || empty($phone_number)) {
        $error = 'Todos los campos son obligatorios.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El formato del correo electrónico no es válido.';
    } elseif (strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR document_number = ?");
        $stmt->bind_param("ss", $email, $document_number);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error = 'Este correo electrónico o número de documento ya está registrado.';
        } else {
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

            $insert_stmt = $conn->prepare("INSERT INTO users (name, email, password_hash, document_type, document_number, birth_date, phone_number, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insert_stmt->bind_param("sssssssss", $name, $email, $password_hash, $document_type, $document_number, $birth_date, $phone_number, $ip_address, $user_agent);

            if ($insert_stmt->execute()) {
                $success = '¡Cuenta creada con éxito! Ahora puedes iniciar sesión.';
            } else {
                $error = 'Error al crear la cuenta. Inténtalo de nuevo.';
            }
            $insert_stmt->close();
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
    <title>Registro de Usuario - Interpago</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .login-bg {
            background-image: url('https://images.unsplash.com/photo-1521788216199-28293b823e59?q=80&w=2070&auto=format&fit=crop');
            background-size: cover;
            background-position: center;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex min-h-screen">
        <div class="w-full md:w-1/2 flex items-center justify-center p-8">
            <div class="max-w-md w-full">
                <div class="text-left mb-8"><a href="index.php" class="text-slate-600 hover:text-slate-900 mb-4 block"><i class="fas fa-arrow-left mr-2"></i>Volver al Inicio</a><h2 class="text-3xl font-bold text-slate-900">Verificación de Identidad</h2><p class="text-slate-600 mt-2">Para cumplir con la regulación, necesitamos validar tu identidad. Tus datos están seguros con nosotros.</p></div>
                <?php if ($error): ?><div class="p-3 mb-4 bg-red-100 text-red-700 rounded-lg text-center"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                <?php if ($success): ?><div class="p-3 mb-4 bg-green-100 text-green-700 rounded-lg text-center"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                <form action="register.php" method="POST" class="space-y-4">
                    <input type="text" name="name" placeholder="Nombre Completo (como aparece en tu cédula)" class="w-full p-3 border border-slate-300 rounded-lg" required>
                    <div class="grid grid-cols-2 gap-4">
                        <select name="document_type" class="w-full p-3 border border-slate-300 rounded-lg bg-white" required>
                            <option value="CC">C.C.</option>
                            <option value="CE">C.E.</option>
                            <option value="Pasaporte">Pasaporte</option>
                        </select>
                        <input type="text" name="document_number" placeholder="Número de Documento" class="w-full p-3 border border-slate-300 rounded-lg" required>
                    </div>
                     <div><label class="block text-xs text-slate-500 ml-1">Fecha de Nacimiento</label><input type="date" name="birth_date" class="w-full p-3 border border-slate-300 rounded-lg" required></div>
                    <input type="text" name="phone_number" placeholder="Número de Celular" class="w-full p-3 border border-slate-300 rounded-lg" required>
                    <hr class="border-slate-200">
                    <input type="email" name="email" placeholder="Correo Electrónico" class="w-full p-3 border border-slate-300 rounded-lg" required>
                    <input type="password" name="password" placeholder="Crea una Contraseña" class="w-full p-3 border border-slate-300 rounded-lg" required>
                    <div class="flex items-start">
                        <input id="agree_terms" name="agree_terms" type="checkbox" class="h-4 w-4 text-slate-600 border-slate-300 rounded mt-1" required>
                        <div class="ml-3 text-sm">
                            <label for="agree_terms" class="text-slate-600">He leído y acepto los <a href="terms.php" target="_blank" class="font-medium text-slate-800 hover:underline">Términos</a> y la <a href="privacy.php" target="_blank" class="font-medium text-slate-800 hover:underline">Política de Privacidad</a>.</label>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-slate-800 text-white font-bold py-3 px-4 rounded-lg hover:bg-slate-900 transition-colors">Crear mi Cuenta Segura</button>
                </form>
                 <p class="text-center text-sm text-slate-600 mt-6">¿Ya tienes una cuenta? <a href="login.php" class="font-medium text-slate-800 hover:underline">Inicia sesión</a>.</p>
            </div>
        </div>
        <div class="hidden md:flex md:w-1/2 login-bg relative"><div class="absolute inset-0 bg-slate-900 bg-opacity-75"></div><div class="relative z-10 flex flex-col justify-center items-center text-white text-center p-12"><i class="fas fa-user-check text-6xl mb-4"></i><h1 class="text-4xl font-bold">La Seguridad Empieza Contigo</h1><p class="mt-4 text-lg text-slate-300">La verificación de identidad y la aceptación de nuestros términos son el primer paso para crear un ecosistema de transacciones 100% seguro.</p></div></div>
    </div>
</body>
</html>
