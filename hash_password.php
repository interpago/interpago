<?php
// hash_password.php
// Este script es una herramienta de un solo uso para generar un hash seguro para una nueva contraseña.

// --- PASO 1: CAMBIA ESTA CONTRASEÑA ---
// Escribe aquí la nueva contraseña que quieres usar para tu cuenta de administrador.
$password = '19612705';

// --- NO CAMBIES NADA DEBAJO DE ESTA LÍNEA ---

// Se utiliza el algoritmo BCRYPT, que es el estándar de seguridad recomendado en PHP.
$hash = password_hash($password, PASSWORD_BCRYPT);

// Se muestra el resultado en pantalla de forma clara.
echo "<div style='font-family: monospace; padding: 20px; border: 1px solid #ccc; background-color: #f5f5f5;'>";
echo "<h1>Generador de Hash de Contraseña</h1>";
echo "<p><strong>Contraseña en texto plano:</strong> " . htmlspecialchars($password) . "</p>";
echo "<p><strong>Hash seguro generado (cópialo completo):</strong></p>";
echo "<p style='background-color: #ddd; padding: 10px; border-radius: 5px; word-wrap: break-word;'>" . htmlspecialchars($hash) . "</p>";
echo "<hr><p style='color: #888;'><em>Después de copiar el hash, elimina este archivo de tu servidor por seguridad.</em></p>";
echo "</div>";

?>
