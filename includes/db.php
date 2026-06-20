<?php
$servername = "localhost";
$username   = "root";
$password   = "";
$database   = "nymix_hardwares";

$conn = mysqli_connect($servername, $username, $password, $database);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>