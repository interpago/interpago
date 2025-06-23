<?php
// admin/logout.php
session_start();

// Destruir solo las variables de sesión del administrador
unset($_SESSION['admin_loggedin']);
unset($_SESSION['admin_id']);
unset($_SESSION['admin_username']);

// No destruyas la sesión completa si quieres mantener otras cosas,
// pero para un logout limpio, esto es lo mejor.
// Si tienes un carrito de compras u otra cosa en la sesión,
// podrías comentar las siguientes dos líneas.
session_destroy();

// Redirigir al index principal (fuera de la carpeta /admin)
// para que puedas actuar como un usuario normal o visitante.
header("Location: ../index.php");
exit;
?>
