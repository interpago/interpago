<?php
// Habilitamos la visualización de todos los errores para la depuración.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Establecemos la zona horaria al principio.
date_default_timezone_set('UTC');

// Las declaraciones 'use' deben estar en el ámbito global.
use \Firebase\JWT\JWT;
use \Firebase\JWT\JWK;

// El script principal se envuelve en un bloque try/catch para manejar cualquier error.
try {
    session_start();
    header('Content-Type: application/json');

    // Requerir dependencias
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../vendor/autoload.php';

    // Verificar que la librería se haya cargado
    if (!class_exists('\Firebase\JWT\JWT')) {
        throw new Exception('La librería Firebase JWT no se cargó correctamente. Revisa tu instalación de Composer.');
    }

    // Obtener y validar el token
    $input = json_decode(file_get_contents('php://input'), true);
    $idToken = $input['token'] ?? null;
    if (!$idToken) {
        throw new Exception('No se proporcionó token.');
    }

    // Obtener las claves públicas de Google
    $publicKeysUrl = 'https://www.googleapis.com/service_accounts/v1/jwk/securetoken@system.gserviceaccount.com';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $publicKeysUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    $keysJson = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception('Error de cURL al obtener las claves públicas: ' . curl_error($ch));
    }
    curl_close($ch);
    $publicKeys = json_decode($keysJson, true);

    // Decodificar el token usando el método correcto
    $decodedToken = JWT::decode($idToken, JWK::parseKeySet($publicKeys));

    // --- INICIO DE LA CORRECCIÓN FINAL ---
    // En las versiones modernas de los tokens de Firebase, el ID del usuario está en el campo 'sub'.
    $firebase_uid = $decodedToken->sub;
    // --- FIN DE LA CORRECCIÓN FINAL ---

    $phone_number_intl = $decodedToken->phone_number;
    $phone_number_local = preg_replace('/^\+57/', '', $phone_number_intl);

    // Buscar al usuario y su estado
    $stmt = $conn->prepare("SELECT id, status FROM users WHERE firebase_uid = ? OR phone_number = ? OR phone_number = ?");
    $stmt->bind_param("sss", $firebase_uid, $phone_number_intl, $phone_number_local);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        // Comprobar si el usuario está suspendido
        if ($user['status'] === 'suspended') {
            throw new Exception('USER_SUSPENDED');
        }

        // Si está activo, proceder con el inicio de sesión
        $user_id = $user['id'];
        $updateStmt = $conn->prepare("UPDATE users SET firebase_uid = ?, phone_number = ?, last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
        $updateStmt->bind_param("sssi", $firebase_uid, $phone_number_intl, $_SERVER['REMOTE_ADDR'], $user_id);
        $updateStmt->execute();

    } else {
        // Si el usuario no existe, lanzar el error correspondiente.
        throw new Exception('USER_NOT_FOUND');
    }

    // Obtener datos del usuario para la sesión
    $userQuery = $conn->prepare("SELECT id, name, user_uuid FROM users WHERE id = ?");
    $userQuery->bind_param("i", $user_id);
    $userQuery->execute();
    $userData = $userQuery->get_result()->fetch_assoc();

    // Crear la sesión
    $_SESSION['user_id'] = $userData['id'];
    $_SESSION['user_uuid'] = $userData['user_uuid'];
    $_SESSION['user_name'] = $userData['name'];
    $_SESSION['is_admin'] = false;

    // Si todo ha ido bien, enviar la respuesta de éxito.
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    // Si cualquier cosa falla, enviar una respuesta de error con el mensaje de la excepción.
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// Funciones auxiliares
function generate_uuid_function() { return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)); }
