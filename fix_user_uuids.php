<?php
// fix_user_uuids.php
// Este script se debe ejecutar UNA SOLA VEZ para arreglar los usuarios existentes.

require_once 'config.php';

function generate_uuid_fix() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

echo "<h1>Actualizando UUIDs de Usuarios</h1>";

$result = $conn->query("SELECT id, user_uuid, name, email FROM users WHERE user_uuid IS NULL OR user_uuid = ''");

if ($result->num_rows === 0) {
    echo "<p style='color: green;'>¡Excelente! Todos los usuarios ya tienen un UUID asignado. No se necesita hacer nada.</p>";
} else {
    echo "<p>Se encontraron " . $result->num_rows . " usuarios sin UUID. Actualizando...</p>";

    $update_stmt = $conn->prepare("UPDATE users SET user_uuid = ? WHERE id = ?");

    while ($user = $result->fetch_assoc()) {
        $new_uuid = generate_uuid_fix();
        $user_id = $user['id'];

        $update_stmt->bind_param("si", $new_uuid, $user_id);

        if ($update_stmt->execute()) {
            echo "<p style='color: blue;'>Usuario '" . htmlspecialchars($user['name']) . "' (ID: " . $user_id . ") actualizado con el UUID: " . $new_uuid . "</p>";
        } else {
            echo "<p style='color: red;'>Error al actualizar el usuario con ID: " . $user_id . " - " . $update_stmt->error . "</p>";
        }
    }

    echo "<h2 style='color: green;'>¡Proceso completado!</h2>";
    $update_stmt->close();
}

$conn->close();

?>
