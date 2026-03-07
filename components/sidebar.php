<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    <div class="w-64 bg-gradient-to-b from-gray-900 to-gray-800 text-white min-h-screen p-4 fixed left-0 top-0 overflow-y-auto">
    <!-- Logo/Header -->
    <div class="mb-8">
        <h2 class="text-2xl font-bold bg-gradient-to-r from-blue-400 to-purple-400 bg-clip-text text-transparent">
            📬 Mail Room
        </h2>
        <p class="text-xs text-gray-400 mt-1">Management System</p>
    </div>
    
    <!-- Navigation -->
    <nav>
        <ul class="space-y-2">
            <!-- Dashboard -->
            <li>
                <a href="/mailroom-system/index.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-gray-700/50 transition-all group <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'bg-gradient-to-r from-blue-600 to-blue-700' : ''; ?>">
                    <i class="fas fa-home w-5 text-gray-400 group-hover:text-white"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            
            <!-- Newspapers Section -->
            <li class="pt-4">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider px-4 mb-2">Newspapers</p>
            </li>
            <li>
                <a href="/mailroom-system/newspapers/add.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-gray-700/50 transition-all group">
                    <i class="fas fa-plus-circle w-5 text-gray-400 group-hover:text-white"></i>
                    <span>Add Newspaper</span>
                </a>
            </li>
            <li>
                <a href="/mailroom-system/newspapers/list.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-gray-700/50 transition-all group">
                    <i class="fas fa-newspaper w-5 text-gray-400 group-hover:text-white"></i>
                    <span>Newspaper List</span>
                </a>
            </li>
            
            <!-- Documents Section -->
            <li class="pt-4">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider px-4 mb-2">Documents</p>
            </li>
            <li>
                <a href="/mailroom-system/documents/add.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-gray-700/50 transition-all group">
                    <i class="fas fa-file-upload w-5 text-gray-400 group-hover:text-white"></i>
                    <span>Add Document</span>
                </a>
            </li>
            <li>
                <a href="/mailroom-system/documents/list.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-gray-700/50 transition-all group">
                    <i class="fas fa-file-alt w-5 text-gray-400 group-hover:text-white"></i>
                    <span>Document List</span>
                </a>
            </li>
            
            <!-- Parcels Section -->
            <li class="pt-4">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider px-4 mb-2">Parcels</p>
            </li>
            <li>
                <a href="../parcels/receive.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-gray-700/50 transition-all group">
                    <i class="fas fa-inbox w-5 text-gray-400 group-hover:text-white"></i>
                    <span>Receive Parcel</span>
                </a>
            </li>
            <li>
                <a href="../parcels/pickup.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-gray-700/50 transition-all group">
                    <i class="fas fa-truck w-5 text-gray-400 group-hover:text-white"></i>
                    <span>Parcel Pickup</span>
                </a>
            </li>
            
            <!-- Distribution Section -->
            <li class="pt-4">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider px-4 mb-2">Distribution</p>
            </li>
            <li>
                <a href="/mailroom-system/distribution/add.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-gray-700/50 transition-all group">
                    <i class="fas fa-chart-line w-5 text-gray-400 group-hover:text-white"></i>
                    <span>Add Distribution</span>
                </a>
            </li>
            <li>
                <a href="/mailroom-system/distribution/list.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-gray-700/50 transition-all group">
                    <i class="fas fa-list w-5 text-gray-400 group-hover:text-white"></i>
                    <span>Distribution List</span>
                </a>
            </li>
        </ul>
    </nav>
    
    <!-- User Profile -->
    <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-gray-700">
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 rounded-full bg-gradient-to-r from-blue-500 to-purple-500 flex items-center justify-center">
                <i class="fas fa-user text-white"></i>
            </div>
            <div>
                <p class="text-sm font-medium">Mailroom Staff</p>
                <p class="text-xs text-gray-400">Online</p>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Menu Button -->
<button id="mobileMenuBtn" class="lg:hidden fixed top-4 left-4 z-50 bg-gray-900 text-white p-2 rounded-lg">
    <i class="fas fa-bars text-xl"></i>
</button>

<script>
    // Mobile menu toggle
    document.getElementById('mobileMenuBtn').addEventListener('click', function() {
        document.querySelector('.w-64').classList.toggle('-translate-x-full');
    });
</script>
</body>
</html>