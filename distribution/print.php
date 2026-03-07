<?php
require_once '../config/db.php';

// Get distribution data for printing
$result = $conn->query("
    SELECT d.*, 
           (SELECT COUNT(*) FROM documents WHERE date_received = d.date_distributed) as documents_count,
           (SELECT COUNT(*) FROM parcels_received WHERE date_received = d.date_distributed) as parcels_count
    FROM distribution d 
    ORDER BY d.date_distributed DESC 
    LIMIT 30
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Distribution Sheet - Print</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            body { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .no-print { display: none; }
            .print-page { page-break-after: always; }
        }
    </style>
</head>
<body class="bg-white p-8">
    <div class="max-w-6xl mx-auto">
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900">MAILROOM DISTRIBUTION SHEET</h1>
            <p class="text-gray-600 mt-2">Generated on: <?php echo date('F j, Y, g:i a'); ?></p>
        </div>
        
        <!-- Summary Cards -->
        <div class="grid grid-cols-3 gap-4 mb-8">
            <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-4 rounded-lg text-center">
                <p class="text-sm text-blue-600">Total Documents</p>
                <p class="text-2xl font-bold text-blue-800">
                    <?php echo $conn->query("SELECT COUNT(*) as total FROM documents")->fetch_assoc()['total']; ?>
                </p>
            </div>
            <div class="bg-gradient-to-br from-green-50 to-green-100 p-4 rounded-lg text-center">
                <p class="text-sm text-green-600">Total Parcels</p>
                <p class="text-2xl font-bold text-green-800">
                    <?php echo $conn->query("SELECT COUNT(*) as total FROM parcels_received")->fetch_assoc()['total']; ?>
                </p>
            </div>
            <div class="bg-gradient-to-br from-purple-50 to-purple-100 p-4 rounded-lg text-center">
                <p class="text-sm text-purple-600">Pending Pickup</p>
                <p class="text-2xl font-bold text-purple-800">
                    <?php 
                    $pending = $conn->query("
                        SELECT COUNT(*) as total 
                        FROM parcels_received pr 
                        LEFT JOIN parcels_pickup pp ON pr.id = pp.parcel_id 
                        WHERE pp.id IS NULL
                    ")->fetch_assoc()['total'];
                    echo $pending;
                    ?>
                </p>
            </div>
        </div>
        
        <!-- Distribution Table -->
        <table class="min-w-full divide-y divide-gray-200 border border-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Document Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Received</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Distributed</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php while($row = $result->fetch_assoc()): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap"><?php echo $row['date_distributed']; ?></td>
                    <td class="px-6 py-4"><?php echo $row['document_name']; ?></td>
                    <td class="px-6 py-4"><?php echo $row['type']; ?></td>
                    <td class="px-6 py-4 text-center"><?php echo $row['received_qty']; ?></td>
                    <td class="px-6 py-4 text-center"><?php echo $row['distributed_qty']; ?></td>
                    <td class="px-6 py-4">
                        <?php 
                        $remaining = $row['received_qty'] - $row['distributed_qty'];
                        if($remaining == 0) {
                            echo '<span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs">Complete</span>';
                        } else {
                            echo '<span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs">Pending: ' . $remaining . '</span>';
                        }
                        ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        
        <!-- Footer -->
        <div class="mt-8 flex justify-between items-center text-sm text-gray-500">
            <div>Prepared by: ______________________</div>
            <div>Date: ______________________</div>
            <div>Signature: ______________________</div>
        </div>
        
        <!-- Print Button -->
        <div class="mt-8 text-center no-print">
            <button onclick="window.print()" 
                    class="px-6 py-3 bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-xl hover:from-blue-700 hover:to-purple-700">
                <i class="fas fa-print mr-2"></i>
                Print Distribution Sheet
            </button>
        </div>
    </div>
</body>
</html>