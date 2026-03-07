<?php
include "../config/db.php";

if(isset($_POST['save'])){

$name = $_POST['name'];
$number = $_POST['number'];
$date = $_POST['date'];
$received = $_POST['received_by'];

$sql = "INSERT INTO newspapers 
(newspaper_name,newspaper_number,date_received,received_by)
VALUES('$name','$number','$date','$received')";

$conn->query($sql);
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Add Newspaper</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 p-10">

<div class="bg-white p-6 rounded shadow w-96">

<h2 class="text-xl font-bold mb-4">Add Newspaper</h2>

<form method="POST">

<input type="text" name="name" placeholder="Newspaper Name"
class="w-full border p-2 mb-3 rounded">

<input type="text" name="number" placeholder="Number"
class="w-full border p-2 mb-3 rounded">

<input type="date" name="date"
class="w-full border p-2 mb-3 rounded">

<input type="text" name="received_by" placeholder="Received By"
class="w-full border p-2 mb-3 rounded">

<button name="save"
class="bg-blue-600 text-white px-4 py-2 rounded w-full">
Save
</button>

</form>

</div>

</body>
</html>