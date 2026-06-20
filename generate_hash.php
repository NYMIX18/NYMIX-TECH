<?php
$password = "24509996";

// Generate hash
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "<h3>Your Hashed Password:</h3>";
echo $hash;
?>