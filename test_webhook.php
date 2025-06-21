<?php
// test_webhook.php

// Función simple para registrar cualquier visita.
function log_test_visit($message) {
    $log_file = __DIR__ . '/test_log.txt';
    $content = date('Y-m-d H:i:s') . " - " . $message . "\n\n";

    // Obtener el cuerpo de la petición
    $body = file_get_contents('php://input');

    // Obtener las cabeceras
    $headers = json_encode(getallheaders(), JSON_PRETTY_PRINT);

    $content .= "CUERPO DE LA PETICIÓN:\n" . $body . "\n\n";
    $content .= "CABECERAS:\n" . $headers . "\n";
    $content .= "--------------------------------------\n\n";

    file_put_contents($log_file, $content, FILE_APPEND);
}

log_test_visit("¡El webhook de prueba fue contactado con éxito!");

// Responder a Wompi que todo está bien.
http_response_code(200);
echo "OK";
?>
