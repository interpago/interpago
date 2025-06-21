<?php
// profile.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
session_start();

$user_profile = null;
$error_message = '';
$user_id = $_GET['id'] ?? 0;

if (filter_var($user_id, FILTER_VALIDATE_INT) && $user_id > 0) {
    // 1. Obtener la información básica del usuario
    $stmt = $conn->prepare("SELECT id, name, created_at, profile_picture, verification_status FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user_profile = $result->fetch_assoc();

        // 2. Calcular la reputación como VENDEDOR
        $seller_stmt = $conn->prepare("SELECT seller_rating, seller_comment, buyer_name FROM transactions WHERE seller_id = ? AND status = 'released' AND seller_rating IS NOT NULL ORDER BY completed_at DESC");
        $seller_stmt->bind_param("i", $user_id);
        $seller_stmt->execute();
        $seller_transactions = $seller_stmt->get_result();

        $seller_total_rating = 0;
        $seller_ratings_count = $seller_transactions->num_rows;
        $seller_comments = [];
        while($row = $seller_transactions->fetch_assoc()) {
            $seller_total_rating += $row['seller_rating'];
            if(!empty($row['seller_comment'])) {
                $seller_comments[] = $row;
            }
        }
        $user_profile['seller_avg_rating'] = ($seller_ratings_count > 0) ? round($seller_total_rating / $seller_ratings_count, 1) : 0;
        $user_profile['seller_tx_count'] = $seller_ratings_count;
        $user_profile['seller_comments'] = $seller_comments;

        // 3. Calcular la reputación como COMPRADOR
        $buyer_stmt = $conn->prepare("SELECT buyer_rating, buyer_comment, seller_name FROM transactions WHERE buyer_id = ? AND status = 'released' AND buyer_rating IS NOT NULL ORDER BY completed_at DESC");
        $buyer_stmt->bind_param("i", $user_id);
        $buyer_stmt->execute();
        $buyer_transactions = $buyer_stmt->get_result();

        $buyer_total_rating = 0;
        $buyer_ratings_count = $buyer_transactions->num_rows;
        $buyer_comments = [];
        while($row = $buyer_transactions->fetch_assoc()) {
            $buyer_total_rating += $row['buyer_rating'];
            if(!empty($row['buyer_comment'])) {
                $buyer_comments[] = $row;
            }
        }
        $user_profile['buyer_avg_rating'] = ($buyer_ratings_count > 0) ? round($buyer_total_rating / $buyer_ratings_count, 1) : 0;
        $user_profile['buyer_tx_count'] = $buyer_ratings_count;
        $user_profile['buyer_comments'] = $buyer_comments;

        // 4. Calcular estadísticas adicionales
        $successful_tx_stmt = $conn->prepare("SELECT COUNT(id) as count FROM transactions WHERE (seller_id = ? OR buyer_id = ?) AND status = 'released'");
        $successful_tx_stmt->bind_param("ii", $user_id, $user_id);
        $successful_tx_stmt->execute();
        $user_profile['successful_transactions'] = $successful_tx_stmt->get_result()->fetch_assoc()['count'];

        $disputes_stmt = $conn->prepare("SELECT COUNT(id) as count FROM transactions WHERE (seller_id = ? OR buyer_id = ?) AND status = 'dispute'");
        $disputes_stmt->bind_param("ii", $user_id, $user_id);
        $disputes_stmt->execute();
        $user_profile['disputes_count'] = $disputes_stmt->get_result()->fetch_assoc()['count'];

    } else {
        $error_message = "Usuario no encontrado.";
    }
} else {
    $error_message = "ID de usuario inválido.";
}

function render_stars($rating) {
    $stars_html = '';
    for($i = 1; $i <= 5; $i++) {
        $stars_html .= '<i class="fas fa-star ' . ($i <= floor($rating) ? 'text-yellow-400' : 'text-gray-300') . '"></i>';
    }
    return $stars_html;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil de Usuario - Interpago</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style> body { font-family: 'Inter', sans-serif; background-color: #f8fafc; } </style>
</head>
<body class="p-4 md:p-8">
    <div class="container mx-auto max-w-4xl">
        <nav class="mb-8">
            <a href="index.php" class="text-slate-600 hover:text-slate-900"><i class="fas fa-arrow-left mr-2"></i>Volver al Inicio</a>
        </nav>

        <?php if ($user_profile): ?>
        <div class="bg-white rounded-2xl shadow-xl p-8">
            <!-- Encabezado del Perfil -->
            <div class="flex flex-col md:flex-row items-center text-center md:text-left">
                <?php if (!empty($user_profile['profile_picture'])): ?>
                    <img src="<?php echo htmlspecialchars($user_profile['profile_picture']); ?>" alt="Foto de perfil" class="w-24 h-24 rounded-full object-cover ring-4 ring-white shadow-lg">
                <?php else: ?>
                    <div class="bg-slate-100 text-slate-600 w-24 h-24 rounded-full flex items-center justify-center text-4xl font-bold ring-4 ring-white shadow-lg">
                        <?php echo strtoupper(substr($user_profile['name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <div class="mt-4 md:mt-0 md:ml-6">
                    <div class="flex items-center justify-center md:justify-start">
                        <h1 class="text-4xl font-extrabold text-slate-900"><?php echo htmlspecialchars($user_profile['name']); ?></h1>
                        <?php if ($user_profile['verification_status'] === 'verified'): ?>
                            <div class="ml-3 bg-green-500 text-white px-2 py-1 rounded-full text-xs font-bold flex items-center" title="Identidad Verificada">
                                <i class="fas fa-check mr-1"></i> Verificado
                            </div>
                        <?php endif; ?>
                    </div>
                    <p class="text-slate-500 mt-1">Miembro desde <?php echo date("F Y", strtotime($user_profile['created_at'])); ?></p>
                </div>
            </div>

            <!-- Estadísticas e Insignias -->
            <div class="mt-8 grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                <div class="bg-slate-100 p-4 rounded-lg">
                    <p class="text-2xl font-bold text-green-600"><?php echo $user_profile['successful_transactions']; ?></p>
                    <p class="text-sm text-slate-600">Tratos Exitosos</p>
                </div>
                <div class="bg-slate-100 p-4 rounded-lg">
                    <p class="text-2xl font-bold text-red-600"><?php echo $user_profile['disputes_count']; ?></p>
                    <p class="text-sm text-slate-600">Disputas</p>
                </div>

                <?php if ($user_profile['seller_tx_count'] > 10): ?>
                <div class="bg-yellow-100 text-yellow-800 p-4 rounded-lg flex items-center justify-center space-x-2">
                    <i class="fas fa-award"></i><span class="font-semibold text-sm">Vendedor Destacado</span>
                </div>
                <?php endif; ?>
                <?php if ($user_profile['buyer_tx_count'] > 10): ?>
                <div class="bg-blue-100 text-blue-800 p-4 rounded-lg flex items-center justify-center space-x-2">
                    <i class="fas fa-shopping-cart"></i><span class="font-semibold text-sm">Comprador Confiable</span>
                </div>
                <?php endif; ?>
                <?php if ($user_profile['successful_transactions'] == 0): ?>
                <div class="bg-slate-100 text-slate-800 p-4 rounded-lg flex items-center justify-center space-x-2">
                    <i class="fas fa-seedling"></i><span class="font-semibold text-sm">Nuevo Miembro</span>
                </div>
                <?php endif; ?>
            </div>

            <hr class="my-8">

            <!-- Sección de Reputación -->
            <h2 class="text-2xl font-bold text-slate-800 mb-6">Reputación en la Plataforma</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Reputación como Vendedor -->
                <div class="bg-blue-50 p-6 rounded-xl border border-blue-200">
                    <h3 class="text-xl font-bold text-blue-800 mb-4">Como Vendedor</h3>
                    <?php if ($user_profile['seller_tx_count'] > 0): ?>
                        <div class="flex items-center text-2xl font-bold text-gray-700">
                            <?php echo render_stars($user_profile['seller_avg_rating']); ?>
                            <span class="ml-3"><?php echo $user_profile['seller_avg_rating']; ?></span>
                            <span class="text-lg font-normal text-gray-500 ml-2">(<?php echo $user_profile['seller_tx_count']; ?> ventas)</span>
                        </div>
                        <div class="mt-4 space-y-3 max-h-48 overflow-y-auto pr-2">
                            <?php foreach($user_profile['seller_comments'] as $comment): ?>
                                <div class="p-3 bg-white rounded-lg border text-sm">
                                    <p class="text-slate-600 italic">"<?php echo htmlspecialchars($comment['seller_comment']); ?>"</p>
                                    <p class="text-xs text-right text-gray-400 mt-1">- <?php echo htmlspecialchars($comment['buyer_name']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-slate-500 italic">Aún no tiene calificaciones como vendedor.</p>
                    <?php endif; ?>
                </div>

                <!-- Reputación como Comprador -->
                <div class="bg-green-50 p-6 rounded-xl border border-green-200">
                     <h3 class="text-xl font-bold text-green-800 mb-4">Como Comprador</h3>
                     <?php if ($user_profile['buyer_tx_count'] > 0): ?>
                        <div class="flex items-center text-2xl font-bold text-gray-700">
                            <?php echo render_stars($user_profile['buyer_avg_rating']); ?>
                            <span class="ml-3"><?php echo $user_profile['buyer_avg_rating']; ?></span>
                            <span class="text-lg font-normal text-gray-500 ml-2">(<?php echo $user_profile['buyer_tx_count']; ?> compras)</span>
                        </div>
                        <div class="mt-4 space-y-3 max-h-48 overflow-y-auto pr-2">
                            <?php foreach($user_profile['buyer_comments'] as $comment): ?>
                                <div class="p-3 bg-white rounded-lg border text-sm">
                                    <p class="text-slate-600 italic">"<?php echo htmlspecialchars($comment['buyer_comment']); ?>"</p>
                                    <p class="text-xs text-right text-gray-400 mt-1">- <?php echo htmlspecialchars($comment['seller_name']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-slate-500 italic">Aún no tiene calificaciones como comprador.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
            <div class="text-center bg-white p-12 rounded-2xl shadow-xl">
                <i class="fas fa-exclamation-triangle text-5xl text-red-500 mb-4"></i>
                <h1 class="text-3xl font-bold text-gray-800">Error</h1>
                <p class="text-gray-600 mt-2"><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
