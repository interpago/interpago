<?php
// RUTA: /admin/includes/sidebar.php
// ===============================================
// Propósito: Menú lateral para el panel de administración, con notificaciones de tickets.
// ===============================================

require_once __DIR__ . '/../../config.php';
$current_page = basename($_SERVER['PHP_SELF']);

// Obtener el conteo de tickets de soporte no leídos por el admin
$unread_tickets_result = $conn->query("SELECT COUNT(*) as count FROM support_tickets WHERE admin_unread = 1 AND status = 'abierto'");
$unread_tickets_count = $unread_tickets_result->fetch_assoc()['count'];

// Definimos los elementos del menú en un array para que sea fácil de gestionar.
$menu_items = [
    'index.php' => ['icon' => 'fa-tachometer-alt', 'label' => 'Dashboard'],
    'transactions.php' => ['icon' => 'fa-list-ul', 'label' => 'Transacciones'],
    'withdrawals.php' => ['icon' => 'fa-hand-holding-usd', 'label' => 'Retiros'],
    'verifications.php' => ['icon' => 'fa-user-check', 'label' => 'Verificaciones'],
    'disputes.php' => ['icon' => 'fa-gavel', 'label' => 'Disputas'],
    'support_tickets.php' => ['icon' => 'fa-life-ring', 'label' => 'Soporte', 'badge' => $unread_tickets_count] // Enlace de soporte con contador
];
?>
<aside class="w-64 bg-slate-800 text-white min-h-screen p-4 flex-shrink-0 flex flex-col">
    <div class="text-center mb-10">
        <a href="index.php" class="flex items-center justify-center space-x-2">
            <i class="fas fa-shield-alt text-3xl"></i>
            <h1 class="text-2xl font-bold">Admin Interpago</h1>
        </a>
    </div>
    <nav class="flex-grow">
        <ul class="space-y-2">
            <?php foreach ($menu_items as $url => $item): ?>
                <?php
                    // Comprueba si el enlace actual es la página que se está viendo.
                    $isActive = ($current_page === $url);
                    // Aplica una clase diferente si el enlace está activo.
                    $linkClass = $isActive ? 'bg-slate-900' : 'hover:bg-slate-700';
                ?>
                <li>
                    <a href="<?php echo $url; ?>" class="flex items-center justify-between p-3 rounded-lg <?php echo $linkClass; ?>">
                        <div class="flex items-center">
                            <i class="fas <?php echo $item['icon']; ?> w-6"></i>
                            <span class="ml-3"><?php echo $item['label']; ?></span>
                        </div>
                        <?php if (isset($item['badge']) && $item['badge'] > 0): ?>
                             <span class="bg-red-500 text-white text-xs font-bold rounded-full h-5 w-5 flex items-center justify-center">
                                <?php echo $item['badge']; ?>
                             </span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
    <div>
        <a href="logout.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700">
            <i class="fas fa-sign-out-alt w-6"></i>
            <span class="ml-3">Cerrar Sesión</span>
        </a>
    </div>
</aside>
