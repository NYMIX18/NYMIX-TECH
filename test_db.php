<?php
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();
    echo "✅ Connected to: " . DB_NAME . "<br>";
    
    // Check which tables exist
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($tables)) {
        echo "❌ Database exists but has NO tables yet.<br>";
    } else {
        echo "✅ Tables found (" . count($tables) . "):<br>";
        foreach ($tables as $t) echo "— $t<br>";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}