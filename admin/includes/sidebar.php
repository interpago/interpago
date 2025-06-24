<?php
// admin/includes/sidebar.php
$current_page = basename($_SERVER['SCRIPT_NAME']);
?>
<aside class="w-64 bg-slate-800 text-white flex flex-col min-h-screen">
    <div class="p-6 text-center border-b border-slate-700">
        <a href="index.php" class="text-2xl font-bold">Interpago Admin</a>
    </div>
    <nav class="flex-grow p-4 space-y-2">
        <a href="index.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700 transition-colors <?php echo $current_page === 'index.php' ? 'bg-slate-900' : ''; ?>">
            <i class="fas fa-tachometer-alt w-6 text-center"></i>
            <span class="ml-4">Dashboard</span>
        </a>

        <!-- === ENLACE ACTUALIZADO/AÑADIDO === -->
        <a href="users.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700 transition-colors <?php echo $current_page === 'users.php' ? 'bg-slate-900' : ''; ?>">
            <i class="fas fa-users w-6 text-center"></i>
            <span class="ml-4">Usuarios</span>
        </a>
        <!-- === FIN DEL ENLACE === -->

        <a href="transactions.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700 transition-colors <?php echo $current_page === 'transactions.php' ? 'bg-slate-900' : ''; ?>">
            <i class="fas fa-exchange-alt w-6 text-center"></i>
            <span class="ml-4">Transacciones</span>
        </a>
        <a href="withdrawals.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700 transition-colors <?php echo $current_page === 'withdrawals.php' ? 'bg-slate-900' : ''; ?>">
            <i class="fas fa-hand-holding-usd w-6 text-center"></i>
            <span class="ml-4">Retiros</span>
        </a>
        <a href="chat.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700 transition-colors <?php echo $current_page === 'chat.php' ? 'bg-slate-900' : ''; ?>">
            <i class="fas fa-comments w-6 text-center"></i>
            <span class="ml-4">Chat en Vivo</span>
        </a>
        <a href="settings.php" class="flex items-center p-3 rounded-lg hover:bg-slate-700 transition-colors <?php echo $current_page === 'settings.php' ? 'bg-slate-900' : ''; ?>">
            <i class="fas fa-cog w-6 text-center"></i>
            <span class="ml-4">Configuración</span>
        </a>
    </nav>
    <div class="p-4 border-t border-slate-700">
        <a href="logout.php" class="flex items-center p-3 rounded-lg text-red-400 hover:bg-red-500 hover:text-white transition-colors">
            <i class="fas fa-sign-out-alt w-6 text-center"></i>
            <span class="ml-4">Cerrar Sesión</span>
        </a>
    </div>
</aside>
