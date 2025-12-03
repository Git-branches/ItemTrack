<?php
require_once 'config.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $item_id = (int)$_POST['item_id'];
    $location = $conn->real_escape_string(trim($_POST['location']));
    $status = $conn->real_escape_string(trim($_POST['status']));
    $remarks = $conn->real_escape_string(trim($_POST['remarks']));
    
    // Update item's current location
    $update_stmt = $conn->prepare("UPDATE items SET current_location = ? WHERE id = ?");
    $update_stmt->bind_param("si", $location, $item_id);
    $update_stmt->execute();
    
    // Add tracking record
    $track_stmt = $conn->prepare("INSERT INTO tracking (item_id, location, status, remarks) VALUES (?, ?, ?, ?)");
    $track_stmt->bind_param("isss", $item_id, $location, $status, $remarks);
    
    if ($track_stmt->execute()) {
        // Get item details and user
        $item_query = $conn->prepare("SELECT i.*, u.id as user_id FROM items i LEFT JOIN users u ON i.user_id = u.id WHERE i.id = ?");
        $item_query->bind_param("i", $item_id);
        $item_query->execute();
        $item = $item_query->get_result()->fetch_assoc();
        
        // Send notification to assigned user
        if ($item['user_id']) {
            $notif_msg = "Item '{$item['item_name']}' location updated: {$location} - {$status}";
            
            // Check if addNotification function exists, if not create it
            if (!function_exists('addNotification')) {
                // Create notification directly
                $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                $notif_stmt->bind_param("is", $item['user_id'], $notif_msg);
                $notif_stmt->execute();
            } else {
                addNotification($item['user_id'], $notif_msg);
            }
        }
        
        header("Location: track_item.php?id=$item_id&success=Location updated successfully");
        exit();
    } else {
        header("Location: track_item.php?id=$item_id&error=Failed to update location");
        exit();
    }
} else {
    header("Location: dashboard.php");
    exit();
}
?>