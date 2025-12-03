<?php
require_once 'config.php';
requireUser();

echo "<h1>Debug Cart Test</h1>";
echo "<pre>";
echo "POST data: " . print_r($_POST, true) . "\n";
echo "GET data: " . print_r($_GET, true) . "\n";
echo "User ID: " . $_SESSION['user_id'] . "\n";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    echo "Form was submitted!\n";
    
    if (isset($_POST['item_id'])) {
        $item_id = (int)$_POST['item_id'];
        $quantity = (int)$_POST['quantity'];
        
        echo "Item ID: $item_id\n";
        echo "Quantity: $quantity\n";
        
        // Simple test - just insert into cart
        $stmt = $conn->prepare("INSERT INTO cart (user_id, item_id, quantity) VALUES (?, ?, ?)");
        $stmt->bind_param("iii", $_SESSION['user_id'], $item_id, $quantity);
        
        if ($stmt->execute()) {
            echo "SUCCESS: Item added to cart!\n";
        } else {
            echo "ERROR: " . $stmt->error . "\n";
        }
    }
}
echo "</pre>";

// Back link
echo '<a href="user_dashboard.php">Back to Dashboard</a>';