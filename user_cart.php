<?php
require_once 'config.php';
requireUser();

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// DEBUG: Check what's being received
error_log("CART DEBUG: User ID: $user_id");
error_log("CART DEBUG: POST data: " . print_r($_POST, true));
error_log("CART DEBUG: GET data: " . print_r($_GET, true));

// Handle ADD TO CART from dashboard
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action']) && in_array($_POST['action'], ['add_to_cart', 'buy_now'])) {
        $action = $_POST['action'];
        $is_buy_now = ($action === 'buy_now');
        
        if (!isset($_POST['item_id']) || empty($_POST['item_id'])) {
            $error = "Item ID is required!";
        } else {
            $item_id = (int)$_POST['item_id'];
            $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
            
            error_log("CART PROCESS: Action=$action, Item=$item_id, Qty=$quantity, User=$user_id");
            
            // Validate item exists and is available
            $item_stmt = $conn->prepare("SELECT id, item_name, price, quantity, is_available FROM items WHERE id = ?");
            $item_stmt->bind_param("i", $item_id);
            $item_stmt->execute();
            $item_result = $item_stmt->get_result();
            
            if ($item_result->num_rows === 0) {
                $error = "Product not found!";
            } else {
                $item = $item_result->fetch_assoc();
                
                if (!$item['is_available']) {
                    $error = "Product is not available!";
                } elseif ($item['quantity'] < $quantity) {
                    $error = "Insufficient stock! Only {$item['quantity']} items available.";
                } else {
                    // Check if item already in cart
                    $cart_check = $conn->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND item_id = ?");
                    $cart_check->bind_param("ii", $user_id, $item_id);
                    $cart_check->execute();
                    $cart_result = $cart_check->get_result();
                    
                    if ($cart_result->num_rows > 0) {
                        // Update existing cart item
                        $cart_item = $cart_result->fetch_assoc();
                        $new_quantity = $cart_item['quantity'] + $quantity;
                        
                        $update_stmt = $conn->prepare("UPDATE cart SET quantity = ? WHERE id = ?");
                        $update_stmt->bind_param("ii", $new_quantity, $cart_item['id']);
                        
                        if ($update_stmt->execute()) {
                            $success = "Cart updated successfully!";
                        } else {
                            $error = "Failed to update cart!";
                        }
                    } else {
                        // Add new item to cart
                        $insert_stmt = $conn->prepare("INSERT INTO cart (user_id, item_id, quantity) VALUES (?, ?, ?)");
                        $insert_stmt->bind_param("iii", $user_id, $item_id, $quantity);
                        
                        if ($insert_stmt->execute()) {
                            $success = "Item added to cart successfully!";
                        } else {
                            $error = "Failed to add item to cart!";
                        }
                    }
                    
                    // Redirect for buy now
                    if ($is_buy_now && empty($error)) {
                        header("Location: user_cart.php?success=" . urlencode($success ?: "Item added to cart!"));
                        exit;
                    }
                }
            }
        }
        
        // For add to cart, redirect back to dashboard with message
        if (!$is_buy_now && empty($error)) {
            header("Location: user_dashboard.php?success=" . urlencode($success));
            exit;
        }
    }
}

// Handle other cart actions (update, remove, checkout)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_cart'])) {
        $cart_items = $_POST['cart'] ?? [];
        foreach ($cart_items as $cart_id => $quantity) {
            $cart_id = (int)$cart_id;
            $quantity = (int)$quantity;
            
            if ($quantity <= 0) {
                // Remove item if quantity is 0 or less
                $stmt = $conn->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
                $stmt->bind_param("ii", $cart_id, $user_id);
                $stmt->execute();
            } else {
                // Update quantity
                $stmt = $conn->prepare("UPDATE cart SET quantity = ? WHERE id = ? AND user_id = ?");
                $stmt->bind_param("iii", $quantity, $cart_id, $user_id);
                $stmt->execute();
            }
        }
        $success = "Cart updated successfully!";
        
    } elseif (isset($_POST['remove_item'])) {
        $cart_id = (int)$_POST['cart_id'];
        $stmt = $conn->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $cart_id, $user_id);
        if ($stmt->execute()) {
            $success = "Item removed from cart!";
        } else {
            $error = "Failed to remove item!";
        }
        
    } elseif (isset($_POST['checkout'])) {
        // Process checkout
        $cart_items_stmt = $conn->prepare("
            SELECT c.*, i.item_name, i.item_code, i.price, i.quantity as stock 
            FROM cart c 
            JOIN items i ON c.item_id = i.id 
            WHERE c.user_id = ?
        ");
        $cart_items_stmt->bind_param("i", $user_id);
        $cart_items_stmt->execute();
        $cart_items = $cart_items_stmt->get_result();
        
        if ($cart_items->num_rows > 0) {
            $conn->begin_transaction();
            try {
                while ($cart_item = $cart_items->fetch_assoc()) {
                    // Check stock availability
                    if ($cart_item['quantity'] > $cart_item['stock']) {
                        throw new Exception("Insufficient stock for {$cart_item['item_name']}");
                    }
                    
                    // Create order
                    $total_amount = $cart_item['price'] * $cart_item['quantity'];
                    $order_stmt = $conn->prepare("
                        INSERT INTO orders (user_id, item_id, quantity, total_amount, status) 
                        VALUES (?, ?, ?, ?, 'to_pay')
                    ");
                    $order_stmt->bind_param("iiid", $user_id, $cart_item['item_id'], $cart_item['quantity'], $total_amount);
                    $order_stmt->execute();
                    
                    // Update item stock
                    $update_stmt = $conn->prepare("UPDATE items SET quantity = quantity - ? WHERE id = ?");
                    $update_stmt->bind_param("ii", $cart_item['quantity'], $cart_item['item_id']);
                    $update_stmt->execute();
                }
                
                // Clear cart
                $clear_stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
                $clear_stmt->bind_param("i", $user_id);
                $clear_stmt->execute();
                
                $conn->commit();
                $success = "Order placed successfully! Please proceed to payment.";
                header("Location: user_orders.php?success=" . urlencode($success));
                exit;
                
            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        } else {
            $error = "Your cart is empty!";
        }
    }
}

// Get cart items for display
$cart_stmt = $conn->prepare("
    SELECT c.*, i.item_name, i.item_code, i.price, i.quantity as stock, i.current_location
    FROM cart c 
    JOIN items i ON c.item_id = i.id 
    WHERE c.user_id = ?
");
$cart_stmt->bind_param("i", $user_id);
$cart_stmt->execute();
$cart_items = $cart_stmt->get_result();

// Calculate cart total
$cart_total = 0;
$cart_count = 0;
while ($item = $cart_items->fetch_assoc()) {
    $cart_total += $item['price'] * $item['quantity'];
    $cart_count += $item['quantity'];
}

// Reset pointer for display
$cart_items->data_seek(0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - ShopStyle</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .cart-item {
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        .cart-item:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .quantity-input {
            width: 80px;
            text-align: center;
        }
        .summary-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 25px;
            position: sticky;
            top: 20px;
        }
        .empty-cart {
            text-align: center;
            padding: 60px 20px;
        }
    </style>
</head>
<body class="bg-light">
    
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="user_dashboard.php">
                <i class="fas fa-shopping-bag"></i> ShopStyle
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="user_dashboard.php">
                            <i class="fas fa-home"></i> Home
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="user_orders.php">
                            <i class="fas fa-shopping-cart"></i> My Orders
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="user_tracking.php">
                            <i class="fas fa-truck"></i> Track Order
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link active" href="user_cart.php">
                            <i class="fas fa-shopping-cart"></i> Cart
                            <span class="badge bg-danger"><?php echo getCartItemCount($conn, $user_id); ?></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="user_profile.php">
                            <i class="fas fa-user"></i> <?php echo h($_SESSION['full_name']); ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <!-- Success/Error Messages -->
        <?php if ($success || isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo $success ?? $_GET['success']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <h2 class="mb-4"><i class="fas fa-shopping-cart"></i> Shopping Cart</h2>
            </div>
        </div>

        <?php if ($cart_items->num_rows > 0): ?>
        <form method="POST">
            <div class="row">
                <!-- Cart Items -->
                <div class="col-lg-8">
                    <?php while ($item = $cart_items->fetch_assoc()): ?>
                    <div class="cart-item">
                        <div class="row align-items-center">
                            <div class="col-md-2 text-center">
                                <i class="fas fa-box fa-2x text-muted"></i>
                            </div>
                            <div class="col-md-4">
                                <h6 class="mb-1"><?php echo h($item['item_name']); ?></h6>
                                <p class="text-muted mb-1 small">Code: <?php echo h($item['item_code']); ?></p>
                                <p class="text-muted mb-0 small">Location: <?php echo h($item['current_location']); ?></p>
                            </div>
                            <div class="col-md-2 text-center">
                                <h6 class="text-primary">₱<?php echo number_format($item['price'], 2); ?></h6>
                            </div>
                            <div class="col-md-2">
                                <input type="number" name="cart[<?php echo $item['id']; ?>]" 
                                       value="<?php echo $item['quantity']; ?>" 
                                       min="1" max="<?php echo $item['stock']; ?>"
                                       class="form-control quantity-input">
                                <small class="text-muted">Stock: <?php echo $item['stock']; ?></small>
                            </div>
                            <div class="col-md-2 text-end">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="cart_id" value="<?php echo $item['id']; ?>">
                                    <button type="submit" name="remove_item" class="btn btn-outline-danger btn-sm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="user_dashboard.php" class="btn btn-outline-primary">
                            <i class="fas fa-arrow-left"></i> Continue Shopping
                        </a>
                        <button type="submit" name="update_cart" class="btn btn-outline-secondary">
                            <i class="fas fa-sync"></i> Update Cart
                        </button>
                    </div>
                </div>

                <!-- Order Summary -->
                <div class="col-lg-4">
                    <div class="summary-card">
                        <h5 class="mb-4">Order Summary</h5>
                        
                        <div class="d-flex justify-content-between mb-3">
                            <span>Items (<?php echo $cart_count; ?>):</span>
                            <span>₱<?php echo number_format($cart_total, 2); ?></span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-3">
                            <span>Shipping:</span>
                            <span>₱50.00</span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-3">
                            <span>Tax:</span>
                            <span>₱<?php echo number_format($cart_total * 0.12, 2); ?></span>
                        </div>
                        
                        <hr>
                        
                        <div class="d-flex justify-content-between mb-4">
                            <strong>Total:</strong>
                            <strong>₱<?php echo number_format($cart_total + 50 + ($cart_total * 0.12), 2); ?></strong>
                        </div>
                        
                        <button type="submit" name="checkout" class="btn btn-primary w-100 btn-lg">
                            <i class="fas fa-credit-card"></i> Proceed to Checkout
                        </button>
                        
                        <div class="text-center mt-3">
                            <small class="text-muted">
                                <i class="fas fa-lock"></i> Your payment information is secure
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <?php else: ?>
            <!-- Empty Cart -->
            <div class="card">
                <div class="empty-cart">
                    <i class="fas fa-shopping-cart fa-4x text-muted mb-3"></i>
                    <h4 class="text-muted">Your cart is empty</h4>
                    <p class="text-muted mb-4">Add some products to your cart to see them here</p>
                    <a href="user_dashboard.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-shopping-bag"></i> Start Shopping
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>