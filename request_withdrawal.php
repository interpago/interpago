<?php
// request_withdrawal.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// **CORRECCIÓN: Se usan rutas absolutas para mayor fiabilidad.**
require_once __DIR__ . '/config.php';

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Obtener el saldo actual del usuario
$stmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$current_balance = $user['balance'];
$stmt->close();

// Procesar el formulario de solicitud
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = filter_var($_POST['amount'], FILTER_VALIDATE_FLOAT);
    $bank_details = [
        'bank_name' => trim($_POST['bank_name']),
        'account_type' => trim($_POST['account_type']),
        'account_number' => trim($_POST['account_number']),
        'holder_name' => trim($_POST['holder_name']),
        'holder_id' => trim($_POST['holder_id'])
    ];

    if ($amount <= 0) {
        $message = 'El monto a retirar debe ser mayor que cero.';
        $message_type = 'error';
    } elseif ($amount > $current_balance) {
        $message = 'No tienes saldo suficiente para realizar este retiro.';
        $message_type = 'error';
    } elseif (in_array('', $bank_details, true)) {
        $message = 'Por favor, completa todos los datos bancarios.';
        $message_type = 'error';
    } else {
        // Iniciar transacción de base de datos para asegurar consistencia
        $conn->begin_transaction();
        try {
            // 1. Restar el saldo de la billetera del usuario (bloquear la fila para evitar concurrencia)
            $new_balance = $current_balance - $amount;
            $update_balance_stmt = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
            $update_balance_stmt->bind_param("di", $new_balance, $user_id);
            $update_balance_stmt->execute();

            // 2. Insertar la solicitud de retiro en la tabla withdrawals
            $bank_details_json = json_encode($bank_details, JSON_UNESCAPED_UNICODE);
            $insert_withdrawal_stmt = $conn->prepare("INSERT INTO withdrawals (user_id, amount, bank_details) VALUES (?, ?, ?)");
            $insert_withdrawal_stmt->bind_param("ids", $user_id, $amount, $bank_details_json);
            $insert_withdrawal_stmt->execute();

            $conn->commit(); // Confirmar todos los cambios si no hubo errores
            $message = '¡Solicitud de retiro enviada con éxito! La procesaremos pronto.';
            $message_type = 'success';
            // Actualizar el saldo mostrado en la página
            $current_balance = $new_balance;

        } catch (Exception $e) {
            $conn->rollback(); // Revertir todo si algo falla
            $message = 'Ocurrió un error al procesar tu solicitud. Inténtalo de nuevo.';
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitar Retiro - Plataforma Escrow</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-6 md:p-10 max-w-2xl">
         <header class="text-center mb-8">
            <a href="dashboard.php" class="text-indigo-600 hover:underline mb-4 inline-block"><i class="fas fa-arrow-left mr-2"></i>Volver a mi Panel</a>
            <h1 class="text-4xl font-extrabold text-gray-900">Solicitar Retiro</h1>
            <p class="text-gray-600 mt-2">Transfiere el saldo de tu billetera a tu cuenta bancaria.</p>
        </header>

        <div class="bg-white p-8 rounded-2xl shadow-lg">
             <div class="bg-indigo-50 p-6 rounded-xl mb-8 text-center">
                <p class="text-lg text-indigo-700">Saldo Actual en tu Billetera</p>
                <p class="text-4xl font-extrabold text-indigo-900">$<?php echo number_format($current_balance, 2); ?> COP</p>
            </div>

            <?php if ($message): ?>
            <div class="p-4 mb-6 text-sm rounded-lg text-center <?php echo $message_type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                <?php echo $message; ?>
            </div>
            <?php endif; ?>

            <form action="request_withdrawal.php" method="POST" class="space-y-6">
                <div>
                    <label for="amount" class="block text-sm font-medium text-gray-700">Monto a Retirar (COP)</label>
                    <input type="number" name="amount" id="amount" step="0.01" class="mt-1 w-full p-3 border border-gray-300 rounded-lg" placeholder="Ej: 50000" required>
                </div>

                <hr>

                <h3 class="text-lg font-semibold text-gray-800">Datos de tu Cuenta Bancaria</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="bank_name" class="block text-sm font-medium text-gray-700">Nombre del Banco</label>
                        <input type="text" name="bank_name" id="bank_name" class="mt-1 w-full p-3 border border-gray-300 rounded-lg" required>
                    </div>
                     <div>
                        <label for="account_type" class="block text-sm font-medium text-gray-700">Tipo de Cuenta</label>
                        <select name="account_type" id="account_type" class="mt-1 w-full p-3 border border-gray-300 rounded-lg bg-white" required>
                            <option value="Ahorros">Ahorros</option>
                            <option value="Corriente">Corriente</option>
                        </select>
                    </div>
                </div>
                 <div>
                    <label for="account_number" class="block text-sm font-medium text-gray-700">Número de Cuenta</label>
                    <input type="text" name="account_number" id="account_number" class="mt-1 w-full p-3 border border-gray-300 rounded-lg" required>
                </div>
                 <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="holder_name" class="block text-sm font-medium text-gray-700">Nombre del Titular</gabel>
                        <input type="text" name="holder_name" id="holder_name" class="mt-1 w-full p-3 border border-gray-300 rounded-lg" required>
                    </div>
                     <div>
                        <label for="holder_id" class="block text-sm font-medium text-gray-700">Cédula del Titular</label>
                        <input type="text" name="holder_id" id="holder_id" class="mt-1 w-full p-3 border border-gray-300 rounded-lg" required>
                    </div>
                </div>

                <button type="submit" class="w-full bg-green-600 text-white font-bold py-4 rounded-lg hover:bg-green-700 text-lg transition-transform transform hover:scale-105">
                    Confirmar Solicitud de Retiro
                </button>
            </form>
        </div>
    </div>
</body>
</html>
