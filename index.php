<?php
require_once 'config/db.php';
session_start();

// Get statistics
$stats = [];

// Documents count
$result = $conn->query("SELECT COUNT(*) as total FROM documents");
$stats['documents'] = $result->fetch_assoc()['total'];

// Parcels received
$result = $conn->query("SELECT COUNT(*) as total FROM parcels_received");
$stats['parcels_received'] = $result->fetch_assoc()['total'];

// Pending parcels (not picked up)
$result = $conn->query("
    SELECT COUNT(*) as total 
    FROM parcels_received pr 
    LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id 
    WHERE pp.id IS NULL
");
$stats['pending_parcels'] = $result->fetch_assoc()['total'];

// Newspapers count
$result = $conn->query("SELECT COUNT(*) as total FROM newspapers");
$stats['newspapers'] = $result->fetch_assoc()['total'];

// Today's activities
$today = date('Y-m-d');
$result = $conn->query("
    SELECT COUNT(*) as total FROM documents WHERE date_received = '$today'
");
$stats['today_documents'] = $result->fetch_assoc()['total'];

$result = $conn->query("
    SELECT COUNT(*) as total FROM parcels_received WHERE date_received = '$today'
");
$stats['today_parcels'] = $result->fetch_assoc()['total'];

// Recent activities
$recent_activities = $conn->query("
    (SELECT 'document' as type, document_name as title, date_received as date, 'File' as icon FROM documents)
    UNION ALL
    (SELECT 'parcel' as type, description as title, date_received as date, 'Box' as icon FROM parcels_received)
    UNION ALL
    (SELECT 'newspaper' as type, newspaper_name as title, date_received as date, 'Newspaper' as icon FROM newspapers)
    ORDER BY date DESC LIMIT 10
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mailroom System - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gradient-to-br from-slate-50 to-slate-100">
    <div class="flex">
        <!-- Sidebar -->
        <?php include 'components/sidebar.php'; ?>
        
        <!-- Main Content -->
        <div class="flex-1 ml-64 p-8">
            <!-- Header -->
            <div class="flex justify-between items-center mb-8">
                <div>
                    <h1 class="text-3xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">
                        Dashboard
                    </h1>
                    <p class="text-gray-600 mt-2"><?php echo date('l, F j, Y'); ?></p>
                </div>
                
                <!-- Quick Actions Dropdown -->
                <div class="relative">
                    <button onclick="toggleQuickMenu()" class="bg-gradient-to-r from-blue-600 to-purple-600 text-white px-4 py-2 rounded-lg hover:from-blue-700 hover:to-purple-700 transition-all">
                        <i class="fas fa-plus mr-2"></i>
                        Quick Actions
                    </button>
                    <div id="quickMenu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg py-2 z-50">
                        <a href="/mailroom-system/parcels/receive.php" class="block px-4 py-2 text-gray-800 hover:bg-gray-100">
                            <i class="fas fa-box mr-2"></i> Receive Parcel
                        </a>
                        <a href="/mailroom-system/documents/add.php" class="block px-4 py-2 text-gray-800 hover:bg-gray-100">
                            <i class="fas fa-file mr-2"></i> Add Document
                        </a>
                        <a href="/mailroom-system/newspapers/add.php" class="block px-4 py-2 text-gray-800 hover:bg-gray-100">
                            <i class="fas fa-newspaper mr-2"></i> Add Newspaper
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Documents Card -->
                <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl shadow-lg p-6 text-white transform hover:scale-105 transition-all">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-blue-100 text-sm">Total Documents</p>
                            <p class="text-4xl font-bold mt-2"><?php echo $stats['documents']; ?></p>
                            <p class="text-blue-100 text-sm mt-2">+<?php echo $stats['today_documents']; ?> today</p>
                        </div>
                        <div class="bg-white/20 rounded-full p-3">
                            <i class="fas fa-file-alt text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Parcels Card -->
                <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-2xl shadow-lg p-6 text-white transform hover:scale-105 transition-all">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-green-100 text-sm">Total Parcels</p>
                            <p class="text-4xl font-bold mt-2"><?php echo $stats['parcels_received']; ?></p>
                            <p class="text-green-100 text-sm mt-2">+<?php echo $stats['today_parcels']; ?> today</p>
                        </div>
                        <div class="bg-white/20 rounded-full p-3">
                            <i class="fas fa-box text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Pending Parcels Card -->
                <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-2xl shadow-lg p-6 text-white transform hover:scale-105 transition-all">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-orange-100 text-sm">Pending Pickup</p>
                            <p class="text-4xl font-bold mt-2"><?php echo $stats['pending_parcels']; ?></p>
                            <p class="text-orange-100 text-sm mt-2">Awaiting collection</p>
                        </div>
                        <div class="bg-white/20 rounded-full p-3">
                            <i class="fas fa-clock text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Newspapers Card -->
                <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl shadow-lg p-6 text-white transform hover:scale-105 transition-all">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-purple-100 text-sm">Newspapers</p>
                            <p class="text-4xl font-bold mt-2"><?php echo $stats['newspapers']; ?></p>
                            <p class="text-purple-100 text-sm mt-2">Total editions</p>
                        </div>
                        <div class="bg-white/20 rounded-full p-3">
                            <i class="fas fa-newspaper text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Search Section -->
            <div class="bg-white rounded-2xl shadow-lg p-6 mb-8">
                <div class="flex items-center mb-4">
                    <i class="fas fa-search text-gray-400 mr-2"></i>
                    <h2 class="text-xl font-semibold text-gray-800">Global Search</h2>
                </div>
                <div class="relative">
                    <input type="text" id="searchInput" placeholder="Search documents, parcels, newspapers..." 
                           class="w-full pl-12 pr-4 py-3 rounded-xl border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all">
                    <i class="fas fa-search absolute left-4 top-4 text-gray-400"></i>
                </div>
                <div id="searchResults" class="mt-4 hidden divide-y"></div>
            </div>
            
            <!-- Recent Activities and Charts -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Recent Activities -->
                <div class="lg:col-span-2 bg-white rounded-2xl shadow-lg p-6">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4">Recent Activities</h2>
                    <div class="space-y-4">
                        <?php while($activity = $recent_activities->fetch_assoc()): ?>
                        <div class="flex items-center p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                            <div class="w-10 h-10 rounded-full <?php 
                                echo $activity['type'] == 'document' ? 'bg-blue-100 text-blue-600' : 
                                    ($activity['type'] == 'parcel' ? 'bg-green-100 text-green-600' : 'bg-purple-100 text-purple-600'); 
                            ?> flex items-center justify-center">
                                <i class="fas fa-<?php 
                                    echo $activity['type'] == 'document' ? 'file-alt' : 
                                        ($activity['type'] == 'parcel' ? 'box' : 'newspaper'); 
                                ?>"></i>
                            </div>
                            <div class="ml-3 flex-1">
                                <p class="text-sm font-medium text-gray-900">
                                    <?php echo $activity['type'] == 'document' ? 'Document added' : 
                                        ($activity['type'] == 'parcel' ? 'Parcel received' : 'Newspaper added'); ?>:
                                    <?php echo substr($activity['title'], 0, 50); ?>
                                </p>
                                <p class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($activity['date'])); ?></p>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
                
                <!-- Quick Stats & Actions -->
                <div class="space-y-6">
                    <!-- Quick Stats -->
                    <div class="bg-gradient-to-br from-indigo-500 to-purple-600 rounded-2xl shadow-lg p-6 text-white">
                        <h3 class="text-lg font-semibold mb-4">Today's Summary</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span><i class="fas fa-file-alt mr-2"></i> Documents</span>
                                <span class="font-bold"><?php echo $stats['today_documents']; ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span><i class="fas fa-box mr-2"></i> Parcels</span>
                                <span class="font-bold"><?php echo $stats['today_parcels']; ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span><i class="fas fa-clock mr-2"></i> Pending</span>
                                <span class="font-bold"><?php echo $stats['pending_parcels']; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="bg-white rounded-2xl shadow-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Quick Actions</h3>
                        <div class="space-y-2">
                            <button onclick="printDistributionSheet()" class="w-full text-left px-4 py-2 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                                <i class="fas fa-print text-gray-600 mr-2"></i>
                                Print Distribution Sheet
                            </button>
                            <a href="/mailroom-system/parcels/pickup.php" class="block px-4 py-2 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                                <i class="fas fa-truck text-gray-600 mr-2"></i>
                                Process Pickups
                            </a>
                            <a href="/mailroom-system/distribution/add.php" class="block px-4 py-2 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                                <i class="fas fa-chart-line text-gray-600 mr-2"></i>
                                New Distribution
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Quick menu toggle
        function toggleQuickMenu() {
            document.getElementById('quickMenu').classList.toggle('hidden');
        }
        
        // Close quick menu when clicking outside
        window.onclick = function(event) {
            if (!event.target.matches('.bg-gradient-to-r')) {
                var menu = document.getElementById('quickMenu');
                if (menu && !menu.classList.contains('hidden')) {
                    menu.classList.add('hidden');
                }
            }
        }
        
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const query = e.target.value;
            if(query.length > 2) {
                fetch(`/mailroom-system/search.php?q=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(data => {
                        const resultsDiv = document.getElementById('searchResults');
                        resultsDiv.classList.remove('hidden');
                        
                        let html = '';
                        
                        if(data.documents.length > 0) {
                            html += '<div class="py-2"><h4 class="font-semibold text-gray-700 px-2">Documents</h4>';
                            data.documents.forEach(doc => {
                                html += `<div class="p-2 hover:bg-gray-50 rounded cursor-pointer">📄 ${doc.document_name}</div>`;
                            });
                            html += '</div>';
                        }
                        
                        if(data.parcels.length > 0) {
                            html += '<div class="py-2"><h4 class="font-semibold text-gray-700 px-2">Parcels</h4>';
                            data.parcels.forEach(parcel => {
                                html += `<div class="p-2 hover:bg-gray-50 rounded cursor-pointer">📦 ${parcel.description} (${parcel.tracking_id})</div>`;
                            });
                            html += '</div>';
                        }
                        
                        if(data.newspapers.length > 0) {
                            html += '<div class="py-2"><h4 class="font-semibold text-gray-700 px-2">Newspapers</h4>';
                            data.newspapers.forEach(paper => {
                                html += `<div class="p-2 hover:bg-gray-50 rounded cursor-pointer">📰 ${paper.newspaper_name} - ${paper.newspaper_number}</div>`;
                            });
                            html += '</div>';
                        }
                        
                        if(html === '') {
                            html = '<div class="p-4 text-gray-500 text-center">No results found</div>';
                        }
                        
                        resultsDiv.innerHTML = html;
                    });
            } else {
                document.getElementById('searchResults').classList.add('hidden');
            }
        });
        
        // Print distribution sheet
        function printDistributionSheet() {
            window.open('/mailroom-system/distribution/print.php', '_blank');
        }
    </script>
</body>
</html>