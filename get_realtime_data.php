<?php
// get_realtime_data.php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

// Este script provee datos para la animación del 'live feed'.
// Combina datos reales con datos genéricos para dar una sensación de actividad.
try {
    // 1. Obtener los detalles de las últimas 10 transacciones (monto y descripción)
    $stmt = $conn->prepare("SELECT amount, product_description FROM transactions WHERE status IN ('funded', 'released', 'shipped') ORDER BY created_at DESC LIMIT 10");
    $stmt->execute();
    $result = $stmt->get_result();
    $transactions = [];
    while($row = $result->fetch_assoc()) {
        // Asegurarse de que los datos son del tipo correcto y limitar la longitud de la descripción
        $transactions[] = [
            'amount' => (float)$row['amount'],
            'description' => mb_strimwidth($row['product_description'], 0, 30, "...")
        ];
    }
    $stmt->close();

    // Si no hay transacciones, usar datos de ejemplo para que la animación no se detenga
    if (empty($transactions)) {
        $transactions = [
            ['amount' => 55000, 'description' => 'Servicio de diseño web'],
            ['amount' => 120000, 'description' => 'Teléfono móvil'],
            ['amount' => 85000, 'description' => 'Reparación de portátil'],
            ['amount' => 250000, 'description' => 'Consola de videojuegos'],
            ['amount' => 45000, 'description' => 'Clases de música online']
        ];
    }

    // 2. Cargar la lista de ciudades desde el archivo JSON
    $cities_json_path = __DIR__ . '/cities.json';
    if (file_exists($cities_json_path)) {
        $cities_json = file_get_contents($cities_json_path);
        $cities = json_decode($cities_json, true);
    } else {
        $cities = ["Bogotá", "Medellín", "Cali"]; // Ciudades de respaldo
    }

    // 3. Devolver los datos en un formato JSON claro
    echo json_encode([
        'transactions' => $transactions,
        'cities' => $cities
    ]);

} catch (Exception $e) {
    // En caso de error, devolver un JSON de error para no romper el script del cliente
    http_response_code(500);
    echo json_encode(['error' => 'No se pudieron obtener los datos de actividad.']);
}
?>
