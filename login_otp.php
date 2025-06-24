<?php
session_start();
// Si el usuario ya tiene una sesión, lo redirigimos al dashboard.
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
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
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-slate-100">
    <div class="flex min-h-screen items-center justify-center p-4">
        <div class="max-w-md w-full bg-white p-8 rounded-2xl shadow-xl">
            <div class="text-center mb-8">
                <a href="index.php" class="inline-block mb-4">
                     <svg class="h-12 w-12 text-slate-800" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" fill="currentColor"><path d="M 45,10 C 25,10 10,25 10,45 L 10,55 C 10,75 25,90 45,90 L 55,90 C 60,90 60,85 55,80 L 45,80 C 30,80 20,70 20,55 L 20,45 C 20,30 30,20 45,20 L 55,20 C 60,20 60,15 55,10 Z"/><path d="M 55,90 C 75,90 90,75 90,55 L 90,45 C 90,25 75,10 55,10 L 45,10 C 40,10 40,15 45,20 L 55,20 C 70,20 80,30 80,45 L 80,55 C 80,70 70,80 55,80 L 45,80 C 40,80 40,85 45,90 Z"/></svg>
                </a>
                <h2 class="text-3xl font-bold text-slate-900">Acceso o Registro</h2>
                <p class="text-slate-600 mt-2">Usa tu número de celular para continuar de forma segura.</p>
            </div>

            <div id="alert-box" class="p-4 mb-4 text-sm rounded-lg hidden"></div>

            <!-- Paso 1: Ingresar número de teléfono -->
            <div id="phone-step">
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-500 font-semibold">+57</span>
                    <input id="phone-number" type="tel" placeholder="300 123 4567" class="w-full p-3 pl-12 border border-slate-300 rounded-lg focus:ring-2 focus:ring-slate-500" autocomplete="tel">
                </div>
                <button id="send-otp-btn" class="mt-4 w-full bg-slate-800 text-white font-bold py-3 px-4 rounded-lg hover:bg-slate-900 transition-colors">
                    <i class="fas fa-mobile-alt mr-2"></i>Enviar código
                </button>
            </div>

            <!-- Paso 2: Ingresar código OTP -->
            <div id="otp-step" class="hidden">
                 <p class="text-center text-sm text-slate-600 mb-4">Ingresa el código de 6 dígitos que enviamos a <b id="phone-sent-to"></b>.</p>
                <input id="otp-code" type="text" placeholder="_ _ _ _ _ _" maxlength="6" class="w-full p-3 text-center tracking-[1em] font-bold text-2xl border border-slate-300 rounded-lg focus:ring-2 focus:ring-slate-500">
                <button id="verify-otp-btn" class="mt-4 w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-green-700 transition-colors">
                    <i class="fas fa-check-circle mr-2"></i>Verificar y Entrar
                </button>
                <button id="resend-otp-btn" class="mt-2 w-full text-sm text-slate-600 hover:text-slate-900">Volver a enviar código</button>
            </div>

            <!-- Contenedor para el reCAPTCHA invisible de Firebase -->
            <div id="recaptcha-container" class="mt-4"></div>
        </div>
    </div>

    <!-- SDK de Firebase -->
    <script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-auth-compat.js"></script>

    <script>
        // --- INICIO DE LA CONFIGURACIÓN DE FIREBASE ---
        // ¡IMPORTANTE! Reemplaza esto con la configuración de tu propio proyecto de Firebase.
        // La puedes encontrar en tu consola de Firebase > Configuración del proyecto.
        const firebaseConfig = {
  apiKey: "AIzaSyCDGavIlU1vqrF_h07-2XMOdGhnz5msgnk",
  authDomain: "interpago-2a6d6.firebaseapp.com",
  projectId: "interpago-2a6d6",
  storageBucket: "interpago-2a6d6.firebasestorage.app",
  messagingSenderId: "649896990773",
  appId: "1:649896990773:web:c47bef96e8bcc101b7797c",
  measurementId: "G-MCFXHGVYTL"
};
        // --- FIN DE LA CONFIGURACIÓN DE FIREBASE ---

        // Inicializar Firebase
        firebase.initializeApp(firebaseConfig);
        const auth = firebase.auth();

        // Referencias al DOM
        const phoneStep = document.getElementById('phone-step');
        const otpStep = document.getElementById('otp-step');
        const phoneNumberInput = document.getElementById('phone-number');
        const sendOtpBtn = document.getElementById('send-otp-btn');
        const verifyOtpBtn = document.getElementById('verify-otp-btn');
        const resendOtpBtn = document.getElementById('resend-otp-btn');
        const otpInput = document.getElementById('otp-code');
        const phoneSentTo = document.getElementById('phone-sent-to');
        const alertBox = document.getElementById('alert-box');

        // Configurar reCAPTCHA invisible
        window.recaptchaVerifier = new firebase.auth.RecaptchaVerifier('recaptcha-container', {
            'size': 'invisible'
        });

        const showAlert = (message, type = 'error') => {
            alertBox.textContent = message;
            alertBox.className = `p-4 mb-4 text-sm rounded-lg ${type === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'}`;
            alertBox.classList.remove('hidden');
        };

        const handleSendOtp = () => {
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
                    phoneStep.classList.add('hidden');
                    otpStep.classList.remove('hidden');
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
                // Usuario autenticado con Firebase. Ahora, obtener el token para el backend.
                const user = result.user;
                return user.getIdToken(true);
            }).then((idToken) => {
                // Enviar el token a tu backend PHP para crear la sesión.
                return fetch('ajax/verify_firebase_token.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: idToken })
                });
            }).then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('¡Verificación exitosa! Redirigiendo...', 'success');
                    window.location.href = 'dashboard.php';
                } else {
                    throw new Error(data.error || 'El servidor no pudo verificar la sesión.');
                }
            })
            .catch((error) => {
                console.error("Error al verificar OTP o en el backend:", error);
                showAlert('El código es incorrecto o ha expirado. Intenta de nuevo.');
                verifyOtpBtn.disabled = false;
                verifyOtpBtn.innerHTML = '<i class="fas fa-check-circle mr-2"></i>Verificar y Entrar';
            });
        };

        sendOtpBtn.addEventListener('click', handleSendOtp);
        resendOtpBtn.addEventListener('click', () => {
            otpStep.classList.add('hidden');
            phoneStep.classList.remove('hidden');
            alertBox.classList.add('hidden');
        });
        verifyOtpBtn.addEventListener('click', handleVerifyOtp);

    </script>
</body>
</html>
