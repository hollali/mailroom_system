<?php
require_once '../config/db.php';
session_start();

$message = '';
$error = '';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Generate unique tracking ID
    $tracking_id = 'PRCL-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    
    $description = $_POST['description'];
    $sender = $_POST['sender'];
    $addressed_to = $_POST['addressed_to'];
    $received_by = $_POST['received_by'];
    $date_received = $_POST['date_received'];
    
    $stmt = $conn->prepare("INSERT INTO parcels_received (description, sender, addressed_to, date_received, received_by, tracking_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $description, $sender, $addressed_to, $date_received, $received_by, $tracking_id);
    
    if($stmt->execute()) {
        $message = "Parcel received successfully! Tracking ID: $tracking_id";
    } else {
        $error = "Error: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receive Parcel - Mailroom System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gradient-to-br from-slate-50 to-slate-100">
    <div class="flex h-screen">
        <?php include '../components/sidebar.php'; ?>
        
        <div class="flex-1 flex flex-col overflow-hidden">
            <?php include '../components/header.php'; ?>
            
            <main class="flex-1 overflow-y-auto p-6">
                <div class="max-w-4xl mx-auto">
                    <!-- Header -->
                    <div class="mb-8">
                        <h1 class="text-3xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">
                            Receive New Parcel
                        </h1>
                        <p class="text-gray-600 mt-2">Fill in the details to register a new parcel with tracking ID</p>
                    </div>
                    
                    <!-- Messages -->
                    <?php if($message): ?>
                    <div class="mb-6 p-4 bg-gradient-to-r from-green-50 to-green-100 border-l-4 border-green-500 rounded-r-lg">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-green-500 text-xl mr-3"></i>
                            <p class="text-green-700"><?php echo $message; ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if($error): ?>
                    <div class="mb-6 p-4 bg-gradient-to-r from-red-50 to-red-100 border-l-4 border-red-500 rounded-r-lg">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle text-red-500 text-xl mr-3"></i>
                            <p class="text-red-700"><?php echo $error; ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Form -->
                    <div class="bg-white rounded-2xl shadow-lg p-8">
                        <form method="POST" action="">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                                    <textarea name="description" rows="3" required
                                              class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all"
                                              placeholder="Enter parcel description"></textarea>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Sender</label>
                                    <input type="text" name="sender" required
                                           class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all"
                                           placeholder="Sender name">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Addressed To</label>
                                    <input type="text" name="addressed_to" required
                                           class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all"
                                           placeholder="Recipient name">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Date Received</label>
                                    <input type="date" name="date_received" required value="<?php echo date('Y-m-d'); ?>"
                                           class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Received By</label>
                                    <input type="text" name="received_by" required
                                           class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all"
                                           placeholder="Staff name">
                                </div>
                            </div>
                            
                            <div class="mt-8 flex justify-end space-x-4">
                                <button type="reset"
                                        class="px-6 py-3 border border-gray-300 rounded-xl text-gray-700 hover:bg-gray-50 transition-colors">
                                    Clear
                                </button>
                                <button type="submit"
                                        class="px-6 py-3 bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-xl hover:from-blue-700 hover:to-purple-700 transition-all transform hover:scale-105">
                                    <i class="fas fa-save mr-2"></i>
                                    Receive Parcel
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Recent Parcels -->
                    <div class="mt-8">
                        <h2 class="text-xl font-semibold text-gray-800 mb-4">Recent Parcels</h2>
                        <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tracking ID</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sender</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php
                                    $recent = $conn->query("SELECT * FROM parcels_received ORDER BY date_received DESC LIMIT 5");
                                    while($row = $recent->fetch_assoc()):
                                    ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-sm font-mono">
                                                <?php echo $row['tracking_id']; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4"><?php echo substr($row['description'], 0, 50); ?>...</td>
                                        <td class="px-6 py-4"><?php echo $row['sender']; ?></td>
                                        <td class="px-6 py-4"><?php echo $row['date_received']; ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
</body>
</html>