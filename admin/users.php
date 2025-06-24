<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

$users_data = [];
$error_message = '';

try {
    // --- CONSULTA ACTUALIZADA: Se añade u.phone_number ---
    $query = "
        SELECT
            u.id,
            u.name,
            u.email,
            u.phone_number,
            u.balance,
            u.verification_status,
            u.created_at,
            COUNT(t.id) AS transaction_count,
            COALESCE(SUM(CASE WHEN t.status = 'released' THEN t.amount ELSE 0 END), 0) AS total_volume,
            COALESCE(AVG(t.seller_rating), 0) AS average_rating
        FROM
            users u
        LEFT JOIN
            transactions t ON u.id = t.buyer_id OR u.id = t.seller_id
        GROUP BY
            u.id, u.name, u.email, u.phone_number, u.balance, u.verification_status, u.created_at
        ORDER BY
            u.created_at DESC
    ";

    $result = $conn->query($query);
    if ($result) {
        while($row = $result->fetch_assoc()) {
            $users_data[] = $row;
        }
    }
} catch (Exception $e) {
    $error_message = "Error al cargar los datos de los usuarios: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - Administración</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .tooltip { position: relative; display: inline-block; }
        .tooltip .tooltiptext { visibility: hidden; width: 140px; background-color: #555; color: #fff; text-align: center; border-radius: 6px; padding: 5px; position: absolute; z-index: 1; bottom: 125%; left: 50%; margin-left: -70px; opacity: 0; transition: opacity 0.3s; }
        .tooltip:hover .tooltiptext { visibility: visible; opacity: 1; }
    </style>
</head>
<body class="bg-slate-100">
    <div class="flex">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="flex-1 p-6 md:p-10">
            <header class="mb-8">
                <h1 class="text-3xl font-bold text-slate-900">Gestión de Usuarios</h1>
                <p class="text-slate-600 mt-1">Busca, filtra y visualiza la información de todos los usuarios registrados.</p>
            </header>

            <div class="bg-white p-6 rounded-2xl shadow-md">
                <div class="mb-4">
                    <input type="text" id="user-search" placeholder="Buscar por nombre, correo o teléfono..." class="w-full md:w-1/3 p-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-slate-500">
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left text-slate-500">
                        <thead class="text-xs text-slate-700 uppercase bg-slate-50">
                            <tr>
                                <th class="px-6 py-3">Usuario</th>
                                <th class="px-6 py-3">Contacto</th>
                                <th class="px-6 py-3">Transacciones</th>
                                <th class="px-6 py-3">Volumen Transado</th>
                                <th class="px-6 py-3 text-center">Estado</th>
                                <th class="px-6 py-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="users-table-body">
                            <?php if (!empty($users_data)): ?>
                                <?php foreach($users_data as $user): ?>
                                    <tr class="border-b user-row" data-search-term="<?php echo strtolower(htmlspecialchars($user['name']) . ' ' . htmlspecialchars($user['email']) . ' ' . htmlspecialchars($user['phone_number'])); ?>">
                                        <td class="px-6 py-4 font-medium text-slate-900"><?php echo htmlspecialchars($user['name']); ?></td>
                                        <td class="px-6 py-4">
                                            <div class="text-slate-700"><?php echo htmlspecialchars($user['email']); ?></div>
                                            <div class="text-slate-500 font-mono"><?php echo htmlspecialchars($user['phone_number'] ?? 'N/A'); ?></div>
                                        </td>
                                        <td class="px-6 py-4 text-center"><?php echo $user['transaction_count']; ?></td>
                                        <td class="px-6 py-4 font-semibold text-slate-700">$<?php echo number_format($user['total_volume'], 0); ?></td>
                                        <td class="px-6 py-4 text-center">
                                            <?php if ($user['verification_status'] === 'verified'): ?>
                                                <div class="tooltip"><i class="fas fa-check-circle text-green-500 text-xl"></i><span class="tooltiptext">Verificado</span></div>
                                            <?php else: ?>
                                                <div class="tooltip"><i class="fas fa-exclamation-triangle text-amber-500 text-xl"></i><span class="tooltiptext">Sin Verificar</span></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <a href="user_profile.php?id=<?php echo $user['id']; ?>" class="bg-slate-200 text-slate-700 hover:bg-slate-300 text-xs font-bold py-2 px-3 rounded-lg">
                                                Ver Perfil
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center py-10 text-slate-500">No se encontraron usuarios.</td></tr>
                            <?php endif; ?>
                            <tr id="no-results-row" class="hidden"><td colspan="6" class="text-center py-10 text-slate-500">No hay resultados para tu búsqueda.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('user-search');
        const tableBody = document.getElementById('users-table-body');
        const userRows = tableBody.querySelectorAll('.user-row');
        const noResultsRow = document.getElementById('no-results-row');

        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            let visibleRows = 0;

            userRows.forEach(row => {
                const rowData = row.dataset.searchTerm;
                if (rowData.includes(searchTerm)) {
                    row.style.display = '';
                    visibleRows++;
                } else {
                    row.style.display = 'none';
                }
            });

            noResultsRow.style.display = (visibleRows === 0) ? '' : 'none';
        });
    });
    </script>
</body>
</html>
