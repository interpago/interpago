<?php
session_start();
// Cargar la configuración al principio para que las constantes estén disponibles.
if (file_exists('config.php')) {
    require_once 'config.php';
}

// Si el usuario ya está logueado, redirigir al panel.
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

// Procesar el formulario de login con EMAIL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_with_email'])) {
    // Asegurarse que la conexión a la BD existe
    if (!isset($conn)) {
        $error = "Error de configuración: No se pudo conectar a la base de datos.";
    } else {
        $email = trim($_POST['email']);
        $password = $_POST['password'];

        if (empty($email) || empty($password)) {
            $error = 'Todos los campos son obligatorios.';
        } else {
            $stmt = $conn->prepare("SELECT id, user_uuid, name, password_hash, status FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($user = $result->fetch_assoc()) {
                if (password_verify($password, $user['password_hash'])) {
                    if ($user['status'] === 'suspended') {
                        $error = 'Tu cuenta ha sido suspendida. Por favor, contacta a soporte.';
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_uuid'] = $user['user_uuid'];
                        $_SESSION['user_name'] = $user['name'];
                        $_SESSION['is_admin'] = false; // Asumiendo que no hay lógica de admin aquí

                        $current_ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
                        $history_stmt = $conn->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
                        $history_stmt->bind_param("si", $current_ip, $user['id']);
                        $history_stmt->execute();

                        header("Location: dashboard.php");
                        exit;
                    }
                }
            }

            if (empty($error)) {
                $error = 'Correo o contraseña incorrectos.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Seguro - Interpago</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .tab-button.active { border-color: #334155; background-color: #334155; color: white; }
    </style>
</head>
<body class="bg-slate-100">
    <div class="flex min-h-screen items-center justify-center p-4">
        <div class="max-w-md w-full bg-white p-8 rounded-2xl shadow-xl">
            <div class="text-center mb-8">
                <a href="index.php" class="inline-block mb-4">
                     <svg class="h-12 w-12 text-slate-800" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" fill="currentColor"><path d="M 45,10 C 25,10 10,25 10,45 L 10,55 C 10,75 25,90 45,90 L 55,90 C 60,90 60,85 55,80 L 45,80 C 30,80 20,70 20,55 L 20,45 C 20,30 30,20 45,20 L 55,20 C 60,20 60,15 55,10 Z"/><path d="M 55,90 C 75,90 90,75 90,55 L 90,45 C 90,25 75,10 55,10 L 45,10 C 40,10 40,15 45,20 L 55,20 C 70,20 80,30 80,45 L 80,55 C 80,70 70,80 55,80 L 45,80 C 40,80 40,85 45,90 Z"/></svg>
                </a>
                <h2 class="text-3xl font-bold text-slate-900">Bienvenido</h2>
                <p class="text-slate-600 mt-2">Elige tu método de acceso preferido.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="p-4 mb-4 text-sm bg-red-100 text-red-800 rounded-lg text-center" id="error-box-php">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            <div id="alert-box" class="p-4 mb-4 text-sm rounded-lg hidden"></div>

            <div class="grid grid-cols-2 gap-2 rounded-lg bg-slate-200 p-1 mb-6">
                <div><button id="tab-email" class="tab-button w-full text-center rounded-md p-2 text-sm font-medium active">Con Correo</button></div>
                <div><button id="tab-otp" class="tab-button w-full text-center rounded-md p-2 text-sm font-medium">Con Celular</button></div>
            </div>

            <!-- Contenido para login con Email -->
            <div id="content-email" class="">
                <form action="login.php" method="POST" class="space-y-6">
                    <input type="hidden" name="login_with_email" value="1">
                    <div>
                        <label for="email" class="block mb-2 text-sm font-medium text-slate-700">Correo Electrónico</label>
                        <div class="relative"><span class="absolute inset-y-0 left-0 flex items-center pl-3"><i class="fas fa-envelope text-slate-400"></i></span><input id="email" name="email" type="email" class="w-full p-3 pl-10 border border-slate-300 rounded-lg" required></div>
                    </div>
                    <div>
                        <div class="flex justify-between items-center mb-2"><label for="password" class="block text-sm font-medium text-slate-700">Contraseña</label><a href="forgot_password.php" class="text-sm text-slate-600 hover:underline">¿Olvidaste?</a></div>
                        <div class="relative"><span class="absolute inset-y-0 left-0 flex items-center pl-3"><i class="fas fa-lock text-slate-400"></i></span><input id="password" name="password" type="password" class="w-full p-3 pl-10 border border-slate-300 rounded-lg" required></div>
                    </div>
                    <button type="submit" class="w-full bg-slate-800 text-white font-bold py-3 px-4 rounded-lg hover:bg-slate-900"><i class="fas fa-sign-in-alt mr-2"></i>Entrar</button>
                </form>
            </div>

            <!-- Contenido para login con Celular (OTP) -->
            <div id="content-otp" class="hidden">
                <div id="phone-step">
                    <div class="relative"><span class="absolute inset-y-0 left-0 flex items-center pl-3 font-semibold text-slate-500">+57</span><input id="phone-number" type="tel" placeholder="300 123 4567" class="w-full p-3 pl-12 border border-slate-300 rounded-lg"></div>
                    <button id="send-otp-btn" class="mt-4 w-full bg-slate-800 text-white font-bold py-3 px-4 rounded-lg hover:bg-slate-900"><i class="fas fa-mobile-alt mr-2"></i>Enviar código</button>
                </div>
                <div id="otp-step" class="hidden">
                    <p class="text-center text-sm text-slate-600 mb-4">Ingresa el código de 6 dígitos enviado a <b id="phone-sent-to"></b>.</p>
                    <input id="otp-code" type="text" placeholder="_ _ _ _ _ _" maxlength="6" class="w-full p-3 text-center tracking-[1em] font-bold text-2xl border rounded-lg">
                    <button id="verify-otp-btn" class="mt-4 w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-green-700"><i class="fas fa-check-circle mr-2"></i>Verificar y Entrar</button>
                    <button id="resend-otp-btn" class="mt-2 w-full text-sm text-slate-600 hover:text-slate-900">Volver a enviar código</button>
                </div>
            </div>

            <div id="recaptcha-container" class="mt-4"></div>
            <p class="text-center text-sm text-slate-600 mt-6">¿Aún no tienes una cuenta? <a href="register.php" class="font-medium text-slate-800 hover:underline">Regístrate ahora</a>.</p>
        </div>
    </div>

    <script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-auth-compat.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // --- INICIO DE LA CONFIGURACIÓN DE FIREBASE ---
            const firebaseConfig = {
                apiKey: "<?php echo defined('FIREBASE_API_KEY') ? FIREBASE_API_KEY : ''; ?>",
                authDomain: "<?php echo defined('FIREBASE_AUTH_DOMAIN') ? FIREBASE_AUTH_DOMAIN : ''; ?>",
                projectId: "<?php echo defined('FIREBASE_PROJECT_ID') ? FIREBASE_PROJECT_ID : ''; ?>",
                storageBucket: "<?php echo defined('FIREBASE_STORAGE_BUCKET') ? FIREBASE_STORAGE_BUCKET : ''; ?>",
                messagingSenderId: "<?php echo defined('FIREBASE_MESSAGING_SENDER_ID') ? FIREBASE_MESSAGING_SENDER_ID : ''; ?>",
                appId: "<?php echo defined('FIREBASE_APP_ID') ? FIREBASE_APP_ID : ''; ?>"
            };

            if (firebaseConfig.apiKey) {
                firebase.initializeApp(firebaseConfig);
            } else {
                console.error("Configuración de Firebase no encontrada. El login por celular no funcionará.");
            }
            const auth = firebase.auth();
            // --- FIN DE LA CONFIGURACIÓN DE FIREBASE ---

            // Referencias al DOM para Pestañas
            const tabEmail = document.getElementById('tab-email');
            const tabOtp = document.getElementById('tab-otp');
            const contentEmail = document.getElementById('content-email');
            const contentOtp = document.getElementById('content-otp');
            const errorBoxPhp = document.getElementById('error-box-php');
            const alertBox = document.getElementById('alert-box');

            // Referencias al DOM para Flujo OTP
            const phoneStep = document.getElementById('phone-step');
            const otpStep = document.getElementById('otp-step');
            const phoneNumberInput = document.getElementById('phone-number');
            const sendOtpBtn = document.getElementById('send-otp-btn');
            const verifyOtpBtn = document.getElementById('verify-otp-btn');
            const resendOtpBtn = document.getElementById('resend-otp-btn');
            const otpInput = document.getElementById('otp-code');
            const phoneSentTo = document.getElementById('phone-sent-to');

            // --- INICIO DE LA CORRECCIÓN ---
            // --- LÓGICA PARA MANEJAR PESTAÑAS ---
            function showTab(tabName) {
                console.log("Cambiando a la pestaña:", tabName); // Mensaje para depuración
                if (tabName === 'email') {
                    // Activar Pestaña de Email
                    tabEmail.classList.add('active');
                    contentEmail.classList.remove('hidden');

                    // Desactivar Pestaña de Celular
                    tabOtp.classList.remove('active');
                    contentOtp.classList.add('hidden');

                    // Ocultar alertas de OTP
                    alertBox.classList.add('hidden');
                } else if (tabName === 'otp') {
                    // Activar Pestaña de Celular
                    tabOtp.classList.add('active');
                    contentOtp.classList.remove('hidden');

                    // Desactivar Pestaña de Email
                    tabEmail.classList.remove('active');
                    contentEmail.classList.add('hidden');

                    // Ocultar errores de PHP (email) si existen
                    if (errorBoxPhp) {
                        errorBoxPhp.style.display = 'none';
                    }
                }
            }
            // --- FIN DE LA CORRECCIÓN ---

            tabEmail.addEventListener('click', () => showTab('email'));
            tabOtp.addEventListener('click', () => showTab('otp'));

            // --- LÓGICA PARA LOGIN CON CELULAR (OTP) ---
            if (firebase.apps.length) { // Solo configurar si Firebase se inicializó
                window.recaptchaVerifier = new firebase.auth.RecaptchaVerifier('recaptcha-container', { 'size': 'invisible' });
            }

            const showAlert = (message, type = 'error') => {
                alertBox.innerHTML = message;
                alertBox.className = `p-4 mb-4 text-sm rounded-lg ${type === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'}`;
                alertBox.classList.remove('hidden');
            };

            const handleSendOtp = () => {
                if (!firebase.apps.length) {
                    showAlert('El inicio de sesión por celular no está configurado.');
                    return;
                }
                const phoneNumber = "+57" + phoneNumberInput.value.replace(/\s/g, '');
                if (phoneNumber.length !== 13) {
                    showAlert('Por favor, ingresa un número de celular válido de 10 dígitos.');
                    return;
                }

                sendOtpBtn.disabled = true;
                sendOtpBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Enviando...';

                auth.signInWithPhoneNumber(phoneNumber, window.recaptchaVerifier)
                    .then((confirmationResult) => {
                        window.confirmationResult = confirmationResult;
                        phoneStep.style.display = 'none';
                        otpStep.style.display = 'block';
                        phoneSentTo.textContent = phoneNumber;
                        showAlert('Código enviado con éxito.', 'success');
                    }).catch((error) => {
                        console.error("Error al enviar OTP:", error);
                         showAlert('No se pudo enviar el código. Revisa el número o intenta más tarde.');
                    }).finally(() => {
                        sendOtpBtn.disabled = false;
                        sendOtpBtn.innerHTML = '<i class="fas fa-mobile-alt mr-2"></i>Enviar código';
                    });
            };

            const handleVerifyOtp = () => {
                const code = otpInput.value;
                if (code.length !== 6) {
                    showAlert('El código debe tener 6 dígitos.');
                    return;
                }

                verifyOtpBtn.disabled = true;
                verifyOtpBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Verificando...';

                window.confirmationResult.confirm(code).then((result) => {
                    return result.user.getIdToken(true);
                }).then((idToken) => {
                    return fetch('ajax/verify_firebase_token.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ token: idToken })
                    });
                }).then(response => {
                    if (!response.ok) {
                       return response.json().then(err => { throw err; });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        showAlert('¡Verificación exitosa! Redirigiendo...', 'success');
                        window.location.href = data.redirect_url || 'dashboard.php';
                    } else {
                        throw data; // Lanzar el objeto de error para el catch
                    }
                })
                .catch((errorData) => {
                    console.error("Error al verificar OTP o en el backend:", errorData);
                    let userMessage = errorData.error || 'El código es incorrecto o ha expirado. Intenta de nuevo.';
                    showAlert(userMessage);
                })
                .finally(() => {
                    verifyOtpBtn.disabled = false;
                    verifyOtpBtn.innerHTML = '<i class="fas fa-check-circle mr-2"></i>Verificar y Entrar';
                });
            };

            sendOtpBtn.addEventListener('click', handleSendOtp);
            resendOtpBtn.addEventListener('click', () => {
                otpStep.classList.add('hidden');
                phoneStep.style.display = 'block';
                alertBox.classList.add('hidden');
            });
            verifyOtpBtn.addEventListener('click', handleVerifyOtp);
        });
    </script>
</body>
</html>
