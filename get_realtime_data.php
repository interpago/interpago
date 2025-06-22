<?php
// get_realtime_data.php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

// Este script provee datos para la animación del 'live feed'.
// Combina datos reales (montos) con datos genéricos (ciudades)
// para dar una sensación de actividad constante de forma segura y privada.

try {
    // 1. Obtener los montos de las últimas 5 transacciones para dar realismo a la animación
    $stmt = $conn->prepare("SELECT amount FROM transactions WHERE status IN ('funded', 'released', 'shipped') ORDER BY id DESC LIMIT 5");
    $stmt->execute();
    $result = $stmt->get_result();
    $amounts = [];
    while($row = $result->fetch_assoc()) {
        // Asegurarse que los montos son números para JSON
        $amounts[] = (float)$row['amount'];
    }
    $stmt->close();

    // Si no hay transacciones, usar montos de ejemplo
    if (empty($amounts)) {
        $amounts = [55000, 120000, 85000, 250000, 45000];
    }

    // 2. Cargar la lista de ciudades desde el archivo JSON
    $cities_json_path = __DIR__ . '/cities.json';
    if (file_exists($cities_json_path)) {
        $cities_json = file_get_contents($cities_json_path);
        $cities = json_decode($cities_json, true);
    } else {
        $cities = ["Bogotá", "Medellín", "Cali"]; // Fallback por si el archivo no existe
    }

    // 3. Devolver los datos en formato JSON
    echo json_encode([
        'amounts' => $amounts,
        'cities' => $cities
    ]);

} catch (Exception $e) {
    // En caso de error, devolver un JSON vacío para no romper el script del cliente
    http_response_code(500);
    echo json_encode(['error' => 'No se pudieron obtener los datos de actividad.']);
}
?>
