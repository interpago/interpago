<?php
// admin/includes/sidebar.php
// Este archivo contiene el menú lateral para todo el panel de administración.

// Determina cuál es la página actual para resaltar el enlace correcto en el menú.
$current_page = basename($_SERVER['PHP_SELF']);

// Definimos los elementos del menú en un array para que sea fácil de gestionar.
$menu_items = [
    'index.php' => ['icon' => 'fa-tachometer-alt', 'label' => 'Dashboard'],
    'transactions.php' => ['icon' => 'fa-list-ul', 'label' => 'Transacciones'],
    'withdrawals.php' => ['icon' => 'fa-hand-holding-usd', 'label' => 'Retiros'],
    'verifications.php' => ['icon' => 'fa-user-check', 'label' => 'Verificaciones'],
    'disputes.php' => ['icon' => 'fa-gavel', 'label' => 'Disputas'] // ENLACE AÑADIDO
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
                    <a href="<?php echo $url; ?>" class="flex items-center p-3 rounded-lg <?php echo $linkClass; ?>">
                        <i class="fas <?php echo $item['icon']; ?> w-6"></i>
                        <span class="ml-3"><?php echo $item['label']; ?></span>
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
