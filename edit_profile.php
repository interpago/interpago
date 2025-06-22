<?php
// edit_profile.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config.php';
$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Obtener los datos actuales del usuario para mostrarlos en el formulario
$stmt = $conn->prepare("SELECT name, email, document_type, document_number, profile_picture, password_hash, verification_status, user_uuid, bio FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) { die("Error: Usuario no encontrado."); }

// Procesar el formulario de actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Lógica para cambiar la contraseña
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if (!password_verify($current_password, $user['password_hash'])) {
            $message = 'La contraseña actual es incorrecta.';
            $message_type = 'error';
        } elseif ($new_password !== $confirm_password) {
            $message = 'Las contraseñas nuevas no coinciden.';
            $message_type = 'error';
        } elseif (strlen($new_password) < 8) {
             $message = 'La nueva contraseña debe tener al menos 8 caracteres.';
            $message_type = 'error';
        } else {
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $update_stmt->bind_param("si", $new_password_hash, $user_id);
            if ($update_stmt->execute()) {
                $message = 'Contraseña actualizada con éxito.';
                $message_type = 'success';
                $user['password_hash'] = $new_password_hash; // Actualizar localmente para la sesión
            } else {
                $message = 'Error al actualizar la contraseña.';
                $message_type = 'error';
            }
            $update_stmt->close();
        }
    }
    // Lógica para subir foto de perfil
    elseif (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/uploads/profiles/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
        $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
        $file_name = $user['user_uuid'] . '.' . $file_extension;
        $target_file = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_file)) {
            $db_path = 'uploads/profiles/' . $file_name;
            $update_pic_stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
            $update_pic_stmt->bind_param("si", $db_path, $user_id);
            if ($update_pic_stmt->execute()) {
                 $message = 'Foto de perfil actualizada.';
                 $message_type = 'success';
                 $user['profile_picture'] = $db_path;
            } else {
                $message = 'Error al guardar la ruta de la imagen en la base de datos.';
                $message_type = 'error';
            }
        } else {
            $message = 'Error al subir la imagen.';
            $message_type = 'error';
        }
    }
    // Lógica para enviar documentos de verificación
    elseif (isset($_POST['submit_verification'])) {
        $document_type = $_POST['document_type'];
        $document_number = trim($_POST['document_number']);

        if (empty($document_type) || empty($document_number) || !isset($_FILES['document_image']) || $_FILES['document_image']['error'] !== UPLOAD_ERR_OK) {
            $message = 'Por favor, completa todos los campos y sube una imagen de tu documento.';
            $message_type = 'error';
        } else {
            $upload_dir = __DIR__ . '/uploads/documents/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
            $file_extension = pathinfo($_FILES['document_image']['name'], PATHINFO_EXTENSION);
            $file_name = 'doc_' . $user['user_uuid'] . '.' . $file_extension;
            $target_file = $upload_dir . $file_name;

            if (move_uploaded_file($_FILES['document_image']['tmp_name'], $target_file)) {
                $db_path = 'uploads/documents/' . $file_name;
                $new_status = 'pending';
                $update_doc_stmt = $conn->prepare("UPDATE users SET document_type = ?, document_number = ?, document_image_path = ?, verification_status = ? WHERE id = ?");
                $update_doc_stmt->bind_param("ssssi", $document_type, $document_number, $db_path, $new_status, $user_id);

                // --- INICIO DE LA CORRECCIÓN ---
                try {
                    $update_doc_stmt->execute(); // Esta es la línea que causaba el error
                    $message = '¡Documentos enviados! Serán revisados por nuestro equipo pronto.';
                    $message_type = 'success';
                    $user['verification_status'] = 'pending'; // Actualizar estado para reflejar el cambio en la página
                } catch (mysqli_sql_exception $e) {
                    if ($e->getCode() == 1062) { // Código de error para 'Entrada duplicada'
                        $message = 'Error: El número de documento que ingresaste ya está registrado por otro usuario. Por favor, verifica la información.';
                        $message_type = 'error';
                    } else {
                        // Para cualquier otro error de base de datos
                        $message = 'Ocurrió un error en la base de datos. Por favor, intenta de nuevo más tarde.';
                        $message_type = 'error';
                        error_log('Error en edit_profile.php al subir documento: ' . $e->getMessage()); // Para tu registro de errores
                    }
                }
                $update_doc_stmt->close();
                // --- FIN DE LA CORRECCIÓN ---

            } else {
                $message = 'Error al subir la imagen de tu documento.';
                $message_type = 'error';
            }
        }
    }
}

// Obtener datos de reputación
$reputation_data = [
    'completed_transactions' => 0,
    'disputed_transactions' => 0,
    'avg_seller_rating' => 0,
    'seller_ratings_count' => 0,
    'avg_buyer_rating' => 0,
    'buyer_ratings_count' => 0,
    'recent_reviews' => []
];

$rep_stmt = $conn->prepare("SELECT (SELECT COUNT(*) FROM transactions WHERE (buyer_id = ? OR seller_id = ?) AND status = 'released') as completed_count, (SELECT COUNT(*) FROM transactions WHERE (buyer_id = ? OR seller_id = ?) AND status = 'dispute') as disputed_count, (SELECT AVG(seller_rating) FROM transactions WHERE seller_id = ? AND seller_rating IS NOT NULL) as avg_seller, (SELECT COUNT(seller_rating) FROM transactions WHERE seller_id = ? AND seller_rating IS NOT NULL) as count_seller, (SELECT AVG(buyer_rating) FROM transactions WHERE buyer_id = ? AND buyer_rating IS NOT NULL) as avg_buyer, (SELECT COUNT(buyer_rating) FROM transactions WHERE buyer_id = ? AND buyer_rating IS NOT NULL) as count_buyer");
$rep_stmt->bind_param("iiiiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
$rep_stmt->execute();
$rep_result = $rep_stmt->get_result()->fetch_assoc();
if ($rep_result) {
    $reputation_data['completed_transactions'] = $rep_result['completed_count'];
    $reputation_data['disputed_transactions'] = $rep_result['disputed_count'];
    $reputation_data['avg_seller_rating'] = $rep_result['avg_seller'] ?? 0;
    $reputation_data['seller_ratings_count'] = $rep_result['count_seller'];
    $reputation_data['avg_buyer_rating'] = $rep_result['avg_buyer'] ?? 0;
    $reputation_data['buyer_ratings_count'] = $rep_result['count_buyer'];
}
$rep_stmt->close();

$reviews_seller_stmt = $conn->prepare("SELECT seller_rating as rating, seller_comment as comment, buyer_name as author_name FROM transactions WHERE seller_id = ? AND seller_rating IS NOT NULL ORDER BY completed_at DESC LIMIT 3");
$reviews_seller_stmt->bind_param("i", $user_id);
$reviews_seller_stmt->execute();
$reviews_seller = $reviews_seller_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$reviews_seller_stmt->close();

$reviews_buyer_stmt = $conn->prepare("SELECT buyer_rating as rating, buyer_comment as comment, seller_name as author_name FROM transactions WHERE buyer_id = ? AND buyer_rating IS NOT NULL ORDER BY completed_at DESC LIMIT 3");
$reviews_buyer_stmt->bind_param("i", $user_id);
$reviews_buyer_stmt->execute();
$reviews_buyer = $reviews_buyer_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$reviews_buyer_stmt->close();

$reputation_data['recent_reviews'] = array_merge($reviews_seller, $reviews_buyer);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Perfil y Reputación - Interpago</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-slate-100">
    <div class="max-w-7xl mx-auto p-4 md:p-8">
        <header class="mb-12">
            <a href="dashboard.php" class="text-slate-600 hover:text-slate-900 mb-4 inline-block"><i class="fas fa-arrow-left mr-2"></i>Volver al Panel</a>
            <h1 class="text-4xl md:text-5xl font-extrabold text-slate-900">Mi Perfil y Reputación</h1>
            <p class="text-slate-600 mt-2">Gestiona tus datos personales, seguridad y reputación en la plataforma.</p>
        </header>

        <?php if (!empty($message)): ?>
            <div class="mb-6 p-4 text-sm rounded-lg <?php echo $message_type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="space-y-8">
            <!-- Tarjeta de Perfil Principal (Ancho Completo) -->
            <div class="bg-white p-6 rounded-2xl shadow-lg flex flex-col md:flex-row items-center space-y-4 md:space-y-0 md:space-x-6">
                <form action="edit_profile.php" method="POST" enctype="multipart/form-data" class="group relative flex-shrink-0">
                    <img src="<?php echo htmlspecialchars($user['profile_picture'] ?? 'assets/images/default-avatar.png'); ?>" alt="Foto de perfil" class="w-24 h-24 rounded-full object-cover border-4 border-slate-200">
                    <label for="profile_picture_upload" class="absolute inset-0 flex items-center justify-center bg-black bg-opacity-50 text-white opacity-0 group-hover:opacity-100 rounded-full cursor-pointer transition-opacity">
                        <i class="fas fa-camera text-2xl"></i>
                        <input type="file" name="profile_picture" id="profile_picture_upload" class="hidden" onchange="this.form.submit()">
                    </label>
                </form>
                <div class="text-center md:text-left">
                    <h2 class="text-3xl font-bold text-slate-800"><?php echo htmlspecialchars($user['name']); ?></h2>
                    <p class="text-slate-500"><?php echo htmlspecialchars($user['email']); ?></p>
                </div>
                <div class="flex-grow text-center md:text-right">
                    <?php
                        $status_text = 'No Verificado';
                        $status_class = 'bg-red-100 text-red-800';
                        if ($user['verification_status'] === 'pending') {
                            $status_text = 'Pendiente';
                            $status_class = 'bg-yellow-100 text-yellow-800';
                        } elseif ($user['verification_status'] === 'verified') {
                             $status_text = 'Verificado';
                             $status_class = 'bg-green-100 text-green-800';
                        }
                    ?>
                    <span class="font-medium py-2 px-4 rounded-full <?php echo $status_class; ?>"><i class="fas fa-shield-alt mr-2"></i><?php echo htmlspecialchars($status_text); ?></span>
                </div>
            </div>

            <!-- Grid de 3 Columnas -->
            <div class="grid lg:grid-cols-3 gap-8">
                <!-- Columna 1: Verificación de Identidad -->
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-slate-800 mb-6">Verificación de Identidad</h2>
                    <?php if ($user['verification_status'] === 'verified'): ?>
                         <div class="p-4 text-center bg-green-50 text-green-800 rounded-lg">
                            <i class="fas fa-check-circle text-2xl mb-2"></i>
                            <p class="font-semibold">Tu cuenta ya está verificada.</p>
                        </div>
                    <?php elseif ($user['verification_status'] === 'pending'): ?>
                        <div class="p-4 text-center bg-blue-50 text-blue-800 rounded-lg">
                            <i class="fas fa-info-circle text-2xl mb-2"></i>
                            <p class="font-semibold">Tus documentos están en revisión.</p>
                        </div>
                    <?php else: ?>
                        <p class="text-sm text-slate-600 mb-4">Para aumentar la seguridad, por favor verifica tu identidad.</p>
                        <form action="edit_profile.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                            <input type="hidden" name="submit_verification" value="1">
                            <div>
                                <label for="document_type" class="block text-sm font-medium text-slate-700">Tipo de Documento</label>
                                <select name="document_type" id="document_type" class="mt-1 w-full p-3 border border-slate-300 rounded-lg" required>
                                    <option value="C.C.">Cédula de Ciudadanía</option>
                                    <option value="C.E.">Cédula de Extranjería</option>
                                    <option value="Pasaporte">Pasaporte</option>
                                </select>
                            </div>
                            <div>
                                <label for="document_number" class="block text-sm font-medium text-slate-700">Número de Documento</label>
                                <input type="text" name="document_number" id="document_number" class="mt-1 w-full p-3 border border-slate-300 rounded-lg" required>
                            </div>
                             <div>
                                <label for="document_image" class="block text-sm font-medium text-slate-700">Foto del Documento</label>
                                <input type="file" name="document_image" id="document_image" class="mt-1 w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200" required>
                            </div>
                            <button type="submit" class="w-full bg-blue-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-blue-700">Enviar Documentos</button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Columna 2: Mi Reputación -->
                 <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-slate-800 mb-6">Mi Reputación</h2>

                    <div class="grid grid-cols-2 gap-6 text-center mb-8">
                        <div class="bg-green-50 p-6 rounded-xl border border-green-200">
                            <p class="text-sm font-medium text-green-700">Completados</p>
                            <p class="text-4xl font-extrabold text-green-600 mt-1"><?php echo $reputation_data['completed_transactions']; ?></p>
                        </div>
                        <div class="bg-red-50 p-6 rounded-xl border border-red-200">
                             <p class="text-sm font-medium text-red-700">En Disputa</p>
                             <p class="text-4xl font-extrabold text-red-600 mt-1"><?php echo $reputation_data['disputed_transactions']; ?></p>
                        </div>
                    </div>

                    <div class="text-center">
                         <h3 class="font-semibold text-slate-600">Nivel de Confianza General</h3>
                         <?php
                            $total_ratings = $reputation_data['seller_ratings_count'] + $reputation_data['buyer_ratings_count'];
                            $overall_avg = $total_ratings > 0 ? (($reputation_data['avg_seller_rating'] * $reputation_data['seller_ratings_count']) + ($reputation_data['avg_buyer_rating'] * $reputation_data['buyer_ratings_count'])) / $total_ratings : 0;

                            $trust_level = 'Nuevo';
                            $trust_color = 'text-slate-500';
                            if ($reputation_data['completed_transactions'] >= 5 && $overall_avg >= 4.0) {
                                $trust_level = 'Confiable';
                                $trust_color = 'text-blue-600';
                            }
                            if ($reputation_data['completed_transactions'] >= 10 && $overall_avg >= 4.5) {
                                $trust_level = 'Recomendado';
                                $trust_color = 'text-green-600';
                            }
                        ?>
                        <p class="text-3xl font-bold <?php echo $trust_color; ?> mt-1"><?php echo $trust_level; ?></p>
                    </div>
                </div>

                <!-- Columna 3: Cambiar Contraseña -->
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-slate-800 mb-6">Cambiar Contraseña</h2>
                    <form action="edit_profile.php" method="POST" class="space-y-4">
                        <input type="hidden" name="change_password" value="1">
                        <div>
                            <label for="current_password" class="block text-sm font-medium text-slate-700">Contraseña Actual</label>
                            <input type="password" name="current_password" id="current_password" class="mt-1 w-full p-3 border border-slate-300 rounded-lg" required>
                        </div>
                         <div>
                            <label for="new_password" class="block text-sm font-medium text-slate-700">Nueva Contraseña</label>
                            <input type="password" name="new_password" id="new_password" class="mt-1 w-full p-3 border border-slate-300 rounded-lg" required>
                        </div>
                         <div>
                            <label for="confirm_password" class="block text-sm font-medium text-slate-700">Confirmar Nueva Contraseña</label>
                            <input type="password" name="confirm_password" id="confirm_password" class="mt-1 w-full p-3 border border-slate-300 rounded-lg" required>
                        </div>
                        <button type="submit" class="w-full bg-slate-800 text-white font-bold py-3 px-4 rounded-lg hover:bg-slate-900">Actualizar Contraseña</button>
                    </form>
                </div>
            </div>

            <!-- Sección de Comentarios (Ancho Completo) -->
            <div class="bg-white p-8 rounded-2xl shadow-lg">
                <h2 class="text-2xl font-bold text-slate-800 mb-6">Comentarios Recientes</h2>
                <div class="space-y-4">
                    <?php if (empty($reputation_data['recent_reviews'])): ?>
                        <p class="text-slate-500 italic text-center py-4">Aún no has recibido comentarios.</p>
                    <?php else: ?>
                        <?php foreach($reputation_data['recent_reviews'] as $review): ?>
                        <div class="p-4 bg-slate-50 rounded-lg">
                            <div class="flex items-center justify-between mb-1">
                                <div><?php for($i = 0; $i < 5; $i++) { echo '<i class="fas fa-star ' . ($i < $review['rating'] ? 'text-yellow-400' : 'text-slate-300') . '"></i>'; } ?></div>
                                <p class="text-xs text-slate-500">De: <?php echo htmlspecialchars($review['author_name']); ?></p>
                            </div>
                            <p class="text-slate-700 italic">"<?php echo htmlspecialchars($review['comment']); ?>"</p>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
