<?php
require_once 'config.php';
requireUser();

$user_id = $_SESSION['user_id'];
$success = isset($_GET['success']) ? h($_GET['success']) : '';
$error = isset($_GET['error']) ? h($_GET['error']) : '';

// Handle order actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $order_id = (int)$_POST['order_id'];
    $action = $_POST['action'];
    
    if ($action == 'cancel') {
        // Return stock before cancelling
        $get_order = $conn->prepare("SELECT item_id, quantity FROM orders WHERE id = ? AND user_id = ? AND status = 'to_pay'");
        $get_order->bind_param("ii", $order_id, $user_id);
        $get_order->execute();
        $order_data = $get_order->get_result()->fetch_assoc();
        
        if ($order_data) {
            // Return stock to inventory
            $return_stock = $conn->prepare("UPDATE items SET quantity = quantity + ? WHERE id = ?");
            $return_stock->bind_param("ii", $order_data['quantity'], $order_data['item_id']);
            $return_stock->execute();
            
            // Cancel order
            $stmt = $conn->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $order_id, $user_id);
            if ($stmt->execute()) {
                $success = "Order cancelled successfully! Stock has been returned.";
            } else {
                $error = "Failed to cancel order!";
            }
        } else {
            $error = "Order not found or cannot be cancelled!";
        }
    } elseif ($action == 'remove') {
        // Permanently delete cancelled orders
        $stmt = $conn->prepare("DELETE FROM orders WHERE id = ? AND user_id = ? AND status = 'cancelled'");
        $stmt->bind_param("ii", $order_id, $user_id);
        if ($stmt->execute()) {
            $success = "Order removed from your order history!";
        } else {
            $error = "Failed to remove order!";
        }
    } elseif ($action == 'confirm_receipt') {
        $stmt = $conn->prepare("UPDATE orders SET status = 'completed' WHERE id = ? AND user_id = ? AND status = 'to_receive'");
        $stmt->bind_param("ii", $order_id, $user_id);
        if ($stmt->execute()) {
            $success = "Order marked as completed! Thank you for your purchase!";
        } else {
            $error = "Failed to confirm receipt!";
        }
    } elseif ($action == 'pay_online') {
        // Simulate online payment processing
        $stmt = $conn->prepare("UPDATE orders SET status = 'to_ship', payment_method = 'Online Payment' WHERE id = ? AND user_id = ? AND status = 'to_pay'");
        $stmt->bind_param("ii", $order_id, $user_id);
        if ($stmt->execute()) {
            $success = "Payment successful! Your order is now being processed.";
        } else {
            $error = "Payment failed! Please try again.";
        }
    } elseif ($action == 'pay_cod') {
        // Process COD (Cash on Delivery)
        $stmt = $conn->prepare("UPDATE orders SET status = 'to_ship', payment_method = 'Cash on Delivery (COD)' WHERE id = ? AND user_id = ? AND status = 'to_pay'");
        $stmt->bind_param("ii", $order_id, $user_id);
        if ($stmt->execute()) {
            $success = "Order confirmed with COD! Prepare cash payment upon delivery.";
        } else {
            $error = "Failed to process COD order!";
        }
    }
}

// AUTO-SYNC: Update order status based on item delivery status
$sync_orders = $conn->prepare("
    UPDATE orders o 
    JOIN items i ON o.item_id = i.id 
    SET o.status = 'completed' 
    WHERE o.user_id = ? 
    AND o.status = 'to_receive' 
    AND i.status = 'delivered'
");
$sync_orders->bind_param("i", $user_id);
$sync_orders->execute();

// AUTO-SYNC: Update order status to 'to_receive' when item is shipped
$sync_shipped = $conn->prepare("
    UPDATE orders o 
    JOIN items i ON o.item_id = i.id 
    SET o.status = 'to_receive' 
    WHERE o.user_id = ? 
    AND o.status = 'to_ship' 
    AND i.status = 'in_transit'
");
$sync_shipped->bind_param("i", $user_id);
$sync_shipped->execute();

// Get filter parameters
$status_filter = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

// Build query for user's orders WITH ITEM STATUS
$query = "
    SELECT o.*, i.item_name, i.item_code, i.current_location, i.price as unit_price, i.status as item_status
    FROM orders o 
    JOIN items i ON o.item_id = i.id 
    WHERE o.user_id = $user_id
";

if ($status_filter) {
    $query .= " AND o.status = '$status_filter'";
}

if ($search) {
    $query .= " AND (i.item_name LIKE '%$search%' OR i.item_code LIKE '%$search%')";
}

$query .= " ORDER BY o.created_at DESC";
$orders_result = $conn->query($query);

// Get order statistics (UPDATED to show accurate counts)
$stats_query = $conn->prepare("
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN o.status = 'to_pay' THEN 1 ELSE 0 END) as to_pay,
        SUM(CASE WHEN o.status = 'to_ship' THEN 1 ELSE 0 END) as to_ship,
        SUM(CASE WHEN o.status = 'to_receive' THEN 1 ELSE 0 END) as to_receive,
        SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM orders o 
    WHERE o.user_id = ?
");
$stats_query->bind_param("i", $user_id);
$stats_query->execute();
$stats_result = $stats_query->get_result();
$stats = $stats_result->fetch_assoc();

// Get cart count
$cart_count = getCartItemCount($conn, $user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - ShopStyle</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .order-card {
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }
        .order-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .order-header {
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            padding: 15px 20px;
            border-radius: 10px 10px 0 0;
        }
        .order-status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-to_pay { background: #fff3cd; color: #856404; }
        .status-to_ship { background: #cce7ff; color: #004085; }
        .status-to_receive { background: #d4edda; color: #155724; }
        .status-completed { background: #d1ecf1; color: #0c5460; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }
        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .payment-method-badge {
            background: #e3f2fd;
            color: #1976d2;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .btn-group-vertical .btn {
            margin-bottom: 5px;
        }
        .item-status-info {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 5px;
        }
        .sync-notice {
            background: #e7f3ff;
            border-left: 4px solid #007bff;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
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
                        <a class="nav-link active" href="user_orders.php">
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
                        <a class="nav-link" href="user_cart.php">
                            <i class="fas fa-shopping-cart"></i> Cart
                            <span class="badge bg-danger"><?php echo $cart_count; ?></span>
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
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Auto-Sync Notice -->
        <div class="sync-notice">
            <i class="fas fa-sync-alt text-primary"></i>
            <strong>Auto-Sync Active:</strong> Order status automatically updates when items are shipped or delivered.
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <h2 class="mb-4"><i class="fas fa-shopping-cart"></i> My Orders</h2>
            </div>
        </div>

        <!-- Order Statistics -->
        <div class="row mb-4">
            <div class="col-md-2 col-6 mb-3">
                <div class="stats-card">
                    <div class="stats-number"><?php echo $stats['total_orders']; ?></div>
                    <small>Total Orders</small>
                </div>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="stats-card" style="background: #fff3cd; color: #856404;">
                    <div class="stats-number"><?php echo $stats['to_pay']; ?></div>
                    <small>To Pay</small>
                </div>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="stats-card" style="background: #cce7ff; color: #004085;">
                    <div class="stats-number"><?php echo $stats['to_ship']; ?></div>
                    <small>To Ship</small>
                </div>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="stats-card" style="background: #d4edda; color: #155724;">
                    <div class="stats-number"><?php echo $stats['to_receive']; ?></div>
                    <small>To Receive</small>
                </div>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="stats-card" style="background: #d1ecf1; color: #0c5460;">
                    <div class="stats-number"><?php echo $stats['completed']; ?></div>
                    <small>Completed</small>
                </div>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="stats-card" style="background: #f8d7da; color: #721c24;">
                    <div class="stats-number"><?php echo $stats['cancelled']; ?></div>
                    <small>Cancelled</small>
                </div>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-6">
                        <input type="text" name="search" class="form-control" placeholder="Search orders..." value="<?php echo h($search); ?>">
                    </div>
                    <div class="col-md-4">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="to_pay" <?php echo $status_filter == 'to_pay' ? 'selected' : ''; ?>>To Pay</option>
                            <option value="to_ship" <?php echo $status_filter == 'to_ship' ? 'selected' : ''; ?>>To Ship</option>
                            <option value="to_receive" <?php echo $status_filter == 'to_receive' ? 'selected' : ''; ?>>To Receive</option>
                            <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Orders List -->
        <div class="row">
            <div class="col-12">
                <?php if ($orders_result->num_rows > 0): ?>
                    <?php while ($order = $orders_result->fetch_assoc()): ?>
                    <div class="card order-card">
                        <div class="order-header">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center">
                                        <small class="text-muted me-3">Order #<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></small>
                                        <span class="order-status-badge status-<?php echo $order['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
                                        </span>
                                        <?php if ($order['payment_method']): ?>
                                            <span class="payment-method-badge ms-2">
                                                <i class="fas fa-<?php echo strpos($order['payment_method'], 'COD') !== false ? 'hand-holding-usd' : 'credit-card'; ?>"></i>
                                                <?php echo h($order['payment_method']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Item Status Info -->
                                    <div class="item-status-info">
                                        <small>
                                            <i class="fas fa-box"></i> Item Status: 
                                            <span class="badge bg-<?php 
                                                echo $order['item_status'] == 'in_stock' ? 'info' : 
                                                     ($order['item_status'] == 'in_transit' ? 'warning' : 'success'); 
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $order['item_status'])); ?>
                                            </span>
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-6 text-md-end">
                                    <small class="text-muted">Order Date: <?php echo date('M d, Y g:i A', strtotime($order['created_at'])); ?></small>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <h6 class="mb-1"><?php echo h($order['item_name']); ?></h6>
                                    <p class="text-muted mb-1">Code: <?php echo h($order['item_code']); ?></p>
                                    <p class="mb-0">Quantity: <?php echo h($order['quantity']); ?> × ₱<?php echo number_format($order['unit_price'], 2); ?></p>
                                    <?php if ($order['current_location']): ?>
                                        <p class="mb-0 small text-muted">
                                            <i class="fas fa-map-marker-alt"></i> <?php echo h($order['current_location']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-3 text-center">
                                    <h6 class="text-primary">₱<?php echo number_format($order['total_amount'], 2); ?></h6>
                                </div>
                                <div class="col-md-3 text-end">
                                    <?php if ($order['status'] == 'to_pay'): ?>
                                        <!-- Payment Options -->
                                        <div class="btn-group-vertical d-grid gap-2">
                                            <form method="POST">
                                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                <input type="hidden" name="action" value="pay_online">
                                                <button type="submit" class="btn btn-success btn-sm w-100">
                                                    <i class="fas fa-credit-card"></i> Pay Online
                                                </button>
                                            </form>
                                            <form method="POST">
                                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                <input type="hidden" name="action" value="pay_cod">
                                                <button type="submit" class="btn btn-warning btn-sm w-100">
                                                    <i class="fas fa-hand-holding-usd"></i> COD
                                                </button>
                                            </form>
                                            <a href="user_tracking.php?item_id=<?php echo $order['item_id']; ?>" class="btn btn-outline-primary btn-sm">
                                                <i class="fas fa-truck"></i> Track
                                            </a>
                                            <form method="POST">
                                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                <input type="hidden" name="action" value="cancel">
                                                <button type="submit" class="btn btn-outline-danger btn-sm w-100" onclick="return confirm('Are you sure you want to cancel this order?')">
                                                    <i class="fas fa-times"></i> Cancel
                                                </button>
                                            </form>
                                        </div>
                                    <?php elseif ($order['status'] == 'to_receive'): ?>
                                        <div class="btn-group-vertical d-grid gap-2">
                                            <form method="POST">
                                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                <input type="hidden" name="action" value="confirm_receipt">
                                                <button type="submit" class="btn btn-success btn-sm w-100">
                                                    <i class="fas fa-check"></i> Confirm Receipt
                                                </button>
                                            </form>
                                            <a href="user_tracking.php?item_id=<?php echo $order['item_id']; ?>" class="btn btn-outline-primary btn-sm">
                                                <i class="fas fa-truck"></i> Track
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <a href="user_tracking.php?item_id=<?php echo $order['item_id']; ?>" class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-truck"></i> Track Item
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                            <h4 class="text-muted">No Orders Found</h4>
                            <p class="text-muted mb-4">You haven't placed any orders yet.</p>
                            <a href="user_dashboard.php" class="btn btn-primary">
                                <i class="fas fa-shopping-bag"></i> Start Shopping
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-hide alerts
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });

        // Auto-refresh page every 60 seconds to sync status
        setTimeout(function() {
            window.location.reload();
        }, 60000);
    </script>
</body>
</html>