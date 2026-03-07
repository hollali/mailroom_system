<?php
include "../config/db.php";
$result = $conn->query("SELECT * FROM newspapers");
?>

<!DOCTYPE html>
<html>
<head>
<title>Newspapers</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 p-10">

<h2 class="text-2xl font-bold mb-6">Newspapers</h2>

<table class="w-full bg-white shadow rounded">

<tr class="bg-gray-200">
<th class="p-3">Name</th>
<th>Number</th>
<th>Date</th>
<th>Received By</th>
</tr>

<?php while($row = $result->fetch_assoc()) { ?>

<tr class="border-t">
<td class="p-3"><?php echo $row['newspaper_name']; ?></td>
<td><?php echo $row['newspaper_number']; ?></td>
<td><?php echo $row['date_received']; ?></td>
<td><?php echo $row['received_by']; ?></td>
</tr>

<?php } ?>

</table>

</body>
</html>