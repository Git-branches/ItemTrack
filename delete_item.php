<?php
// ALTERNATIVE APPROACH: disable_item.php
// Use this instead of hard delete for items with orders

require_once 'config.php';
requireAdmin();

$item_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($item_id > 0) {
    // Get item details
    $stmt = $conn->prepare("SELECT item_name, is_available FROM items WHERE id = ?");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $item = $result->fetch_assoc();
        
        // Check if item has existing orders
        $order_check = $conn->prepare("SELECT COUNT(*) as order_count FROM orders WHERE item_id = ?");
        $order_check->bind_param("i", $item_id);
        $order_check->execute();
        $order_result = $order_check->get_result()->fetch_assoc();
        
        if ($order_result['order_count'] > 0) {
            // Item has orders - mark as unavailable instead of deleting
            $update_stmt = $conn->prepare("UPDATE items SET is_available = 0, quantity = 0 WHERE id = ?");
            $update_stmt->bind_param("i", $item_id);
            
            if ($update_stmt->execute()) {
                // Remove from all carts
                $remove_cart = $conn->prepare("DELETE FROM cart WHERE item_id = ?");
                $remove_cart->bind_param("i", $item_id);
                $remove_cart->execute();
                
                header("Location: items.php?success=Item '{$item['item_name']}' marked as unavailable (has {$order_result['order_count']} order history)");
            } else {
                header("Location: items.php?error=Failed to update item");
            }
        } else {
            // No orders - safe to permanently delete
            // Remove from carts first
            $remove_cart = $conn->prepare("DELETE FROM cart WHERE item_id = ?");
            $remove_cart->bind_param("i", $item_id);
            $remove_cart->execute();
            
            // Delete item
            $delete_stmt = $conn->prepare("DELETE FROM items WHERE id = ?");
            $delete_stmt->bind_param("i", $item_id);
            
            if ($delete_stmt->execute()) {
                header("Location: items.php?success=Item '{$item['item_name']}' permanently deleted");
            } else {
                header("Location: items.php?error=Failed to delete item");
            }
        }
    } else {
        header("Location: items.php?error=Item not found");
    }
} else {
    header("Location: items.php?error=Invalid item ID");
}
exit();
?>