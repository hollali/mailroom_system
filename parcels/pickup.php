<?php
require_once '../config/db.php';
session_start();

$message = '';
$error = '';

// Handle pickup
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['parcel_id'])) {
    $parcel_id = $_POST['parcel_id'];
    $picked_by = $_POST['picked_by'];
    $phone_number = $_POST['phone_number'];
    $designation = $_POST['designation'];
    $date_picked = date('Y-m-d');
    
    // Check if parcel exists and not picked up
    $check = $conn->query("SELECT * FROM parcels_received WHERE id = $parcel_id");
    if($check->num_rows > 0) {
        $check_pickup = $conn->query("SELECT * FROM parcels_pickup WHERE parcel_id = $parcel_id");
        if($check_pickup->num_rows == 0) {
            $stmt = $conn->prepare("INSERT INTO parcels_pickup (parcel_id, picked_by, phone_number, designation, date_picked) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $parcel_id, $picked_by, $phone_number, $designation, $date_picked);
            
            if($stmt->execute()) {
                $message = "Parcel picked up successfully!";
            } else {
                $error = "Error: " . $conn->error;
            }
        } else {
            $error = "This parcel has already been picked up!";
        }
    } else {
        $error = "Parcel not found!";
    }
}

// Get all parcels with pickup status
$parcels = $conn->query("
    SELECT pr.*, 
           pp.id as pickup_id, 
           pp.picked_by, 
           pp.date_picked,
           CASE WHEN pp.id IS NULL THEN 'Pending' ELSE 'Picked Up' END as status
    FROM parcels_received pr
    LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id
    ORDER BY pr.date_received DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parcel Pickup - Mailroom System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gradient-to-br from-slate-50 to-slate-100">
    <div class="flex">
        <?php include '../components/sidebar.php'; ?>
        
        <div class="flex-1 ml-64 p-8">
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">
                    Parcel Pickup
                </h1>
                <p class="text-gray-600 mt-2">Process parcel pickups and track delivery status</p>
            </div>
            
            <!-- Messages -->
            <?php if($message): ?>
            <div class="mb-6 p-4 bg-green-100 border-l-4 border-green-500 text-green-700 rounded">
                <i class="fas fa-check-circle mr-2"></i>
                <?php echo $message; ?>
            </div>
            <?php endif; ?>
            
            <?php if($error): ?>
            <div class="mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-700 rounded">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?php echo $error; ?>
            </div>
            <?php endif; ?>
            
            <!-- Search and Filter -->
            <div class="bg-white rounded-2xl shadow-lg p-6 mb-6">
                <div class="flex gap-4">
                    <div class="flex-1">
                        <input type="text" id="searchParcel" placeholder="Search by tracking ID, sender, or recipient..." 
                               class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                    </div>
                    <select id="statusFilter" class="px-4 py-2 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                        <option value="all">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="picked">Picked Up</option>
                    </select>
                </div>
            </div>
            
            <!-- Parcels Table -->
            <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tracking ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sender</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Recipient</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date Received</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200" id="parcelTable">
                        <?php while($parcel = $parcels->fetch_assoc()): ?>
                        <tr class="hover:bg-gray-50 transition-colors parcel-row" 
                            data-status="<?php echo strtolower(str_replace(' ', '-', $parcel['status'])); ?>"
                            data-search="<?php echo strtolower($parcel['tracking_id'] . ' ' . $parcel['sender'] . ' ' . $parcel['addressed_to']); ?>">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-sm font-mono">
                                    <?php echo $parcel['tracking_id']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4"><?php echo substr($parcel['description'], 0, 30); ?>...</td>
                            <td class="px-6 py-4"><?php echo $parcel['sender']; ?></td>
                            <td class="px-6 py-4"><?php echo $parcel['addressed_to']; ?></td>
                            <td class="px-6 py-4"><?php echo $parcel['date_received']; ?></td>
                            <td class="px-6 py-4">
                                <?php if($parcel['status'] == 'Pending'): ?>
                                    <span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs">Pending</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs">
                                        Picked up by <?php echo $parcel['picked_by']; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if($parcel['status'] == 'Pending'): ?>
                                    <button onclick="openPickupModal(<?php echo $parcel['id']; ?>, '<?php echo $parcel['tracking_id']; ?>')"
                                            class="bg-gradient-to-r from-blue-600 to-purple-600 text-white px-4 py-2 rounded-lg text-sm hover:from-blue-700 hover:to-purple-700 transition-all">
                                        <i class="fas fa-truck mr-1"></i> Process Pickup
                                    </button>
                                <?php else: ?>
                                    <button onclick="viewPickupDetails(<?php echo $parcel['id']; ?>)"
                                            class="text-gray-600 hover:text-gray-800">
                                        <i class="fas fa-info-circle"></i> Details
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Pickup Modal -->
    <div id="pickupModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-lg bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Process Parcel Pickup</h3>
                <form method="POST" action="">
                    <input type="hidden" name="parcel_id" id="modalParcelId">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Tracking ID</label>
                        <input type="text" id="modalTrackingId" readonly 
                               class="w-full px-3 py-2 bg-gray-100 border border-gray-300 rounded-lg">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Picked By</label>
                        <input type="text" name="picked_by" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                        <input type="text" name="phone_number" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Designation</label>
                        <input type="text" name="designation" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closePickupModal()"
                                class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">
                            Cancel
                        </button>
                        <button type="submit"
                                class="px-4 py-2 bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-lg hover:from-blue-700 hover:to-purple-700">
                            Confirm Pickup
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Modal functions
        function openPickupModal(id, trackingId) {
            document.getElementById('pickupModal').classList.remove('hidden');
            document.getElementById('modalParcelId').value = id;
            document.getElementById('modalTrackingId').value = trackingId;
        }
        
        function closePickupModal() {
            document.getElementById('pickupModal').classList.add('hidden');
        }
        
        // Search and filter
        document.getElementById('searchParcel').addEventListener('input', filterTable);
        document.getElementById('statusFilter').addEventListener('change', filterTable);
        
        function filterTable() {
            const searchTerm = document.getElementById('searchParcel').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;
            const rows = document.getElementsByClassName('parcel-row');
            
            for(let row of rows) {
                const searchText = row.getAttribute('data-search');
                const status = row.getAttribute('data-status');
                
                const matchesSearch = searchText.includes(searchTerm);
                const matchesStatus = statusFilter === 'all' || status === statusFilter;
                
                row.style.display = matchesSearch && matchesStatus ? '' : 'none';
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('pickupModal');
            if (event.target == modal) {
                closePickupModal();
            }
        }
    </script>
</body>
</html>