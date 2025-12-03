<?php
require_once 'config.php';
requireUser();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Search functionality
$search = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';
$category_filter = isset($_GET['category']) ? $conn->real_escape_string(trim($_GET['category'])) : '';

// Build query for available items (like products) - FIXED QUERY
$query = "SELECT * FROM items WHERE is_available = 1 AND quantity > 0";
if ($search) {
    $query .= " AND (item_name LIKE '%$search%' OR item_code LIKE '%$search%' OR category LIKE '%$search%')";
}
if ($category_filter) {
    $query .= " AND category = '$category_filter'";
}
$query .= " ORDER BY created_at DESC";
$available_items = $conn->query($query);

// Get user's orders
$orders_query = $conn->prepare("
    SELECT o.*, i.item_name, i.item_code 
    FROM orders o 
    JOIN items i ON o.item_id = i.id 
    WHERE o.user_id = ? 
    ORDER BY o.created_at DESC
    LIMIT 5
");
$orders_query->bind_param("i", $user_id);
$orders_query->execute();
$user_orders = $orders_query->get_result();

// Get order counts
$order_counts = getUserOrderStats($conn, $user_id);

// Get categories for filter
$categories_result = $conn->query("SELECT DISTINCT category FROM items WHERE is_available = 1 AND quantity > 0 ORDER BY category");

// Success/Error messages from cart actions
$success = isset($_GET['success']) ? $_GET['success'] : '';
$error = isset($_GET['error']) ? $_GET['error'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShopStyle - Online Store</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .product-card {
            border: 1px solid #e0e0e0;
            border-radius: 15px;
            transition: all 0.3s ease;
            height: 100%;
        }
        .product-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            transform: translateY(-5px);
        }
        .product-image {
            height: 200px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px 15px 0 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: white;
        }
        .price-tag {
            color: #e74c3c;
            font-weight: bold;
            font-size: 1.3rem;
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
        .search-box {
            border-radius: 25px;
            padding: 12px 20px;
            border: 2px solid #e0e0e0;
        }
        .search-box:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
        }
        .stats-card:hover {
            border-color: #667eea;
            transform: translateY(-2px);
        }
        .stats-number {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .category-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-cart {
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        .btn-cart:hover {
            transform: translateY(-2px);
        }
        .stock-low {
            color: #e74c3c;
            font-weight: bold;
        }
        .stock-good {
            color: #27ae60;
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
                        <a class="nav-link active" href="user_dashboard.php">
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
                        <a class="nav-link" href="user_cart.php">
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

    <div class="container-fluid py-4">
        <!-- Success/Error Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Search Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h4 class="mb-3">Discover Amazing Products 🛍️</h4>
                        <form method="GET" action="" class="row g-3">
                            <div class="col-md-6">
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control search-box" 
                                           placeholder="What are you looking for?" value="<?php echo htmlspecialchars($search); ?>">
                                    <button class="btn btn-primary" type="submit" style="border-radius: 0 25px 25px 0;">
                                        <i class="fas fa-search"></i> Search
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <select name="category" class="form-select" style="border-radius: 25px;">
                                    <option value="">All Categories</option>
                                    <?php while ($cat = $categories_result->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($cat['category']); ?>" 
                                            <?php echo $category_filter == $cat['category'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['category']); ?>
                                    </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <a href="user_dashboard.php" class="btn btn-outline-secondary w-100" style="border-radius: 25px;">
                                    <i class="fas fa-refresh"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Status Summary -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="card-title mb-4"><i class="fas fa-chart-line text-primary"></i> My Shopping Summary</h5>
                        <div class="row text-center">
                            <div class="col-md-2 col-6 mb-3">
                                <div class="stats-card">
                                    <div class="stats-number text-primary"><?php echo $order_counts['to_pay'] ?? 0; ?></div>
                                    <small class="text-muted">To Pay</small>
                                </div>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <div class="stats-card">
                                    <div class="stats-number text-info"><?php echo $order_counts['to_ship'] ?? 0; ?></div>
                                    <small class="text-muted">To Ship</small>
                                </div>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <div class="stats-card">
                                    <div class="stats-number text-warning"><?php echo $order_counts['to_receive'] ?? 0; ?></div>
                                    <small class="text-muted">To Receive</small>
                                </div>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <div class="stats-card">
                                    <div class="stats-number text-success"><?php echo $order_counts['completed'] ?? 0; ?></div>
                                    <small class="text-muted">Completed</small>
                                </div>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <div class="stats-card">
                                    <div class="stats-number text-danger"><?php echo $order_counts['cancelled'] ?? 0; ?></div>
                                    <small class="text-muted">Cancelled</small>
                                </div>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <div class="stats-card">
                                    <div class="stats-number text-dark"><?php echo $order_counts['total_orders'] ?? 0; ?></div>
                                    <small class="text-muted">Total Orders</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Products Grid -->
<!-- 
    COMPLETE FIX FOR user_dashboard.php
    Find the "Products Grid" section (around line 200-250)
    Replace the entire product card loop with this code
-->

<!-- Products Grid -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4>Featured Products</h4>
            <span class="badge category-badge"><?php echo $available_items->num_rows; ?> products found</span>
        </div>
    </div>
    
    <?php if ($available_items->num_rows > 0): ?>
        <?php while ($product = $available_items->fetch_assoc()): ?>
        <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
            <div class="card product-card">
                <div class="product-image">
                    <i class="fas fa-<?php 
                        switch($product['category']) {
                            case 'Electronics': echo 'laptop'; break;
                            case 'Furniture': echo 'chair'; break;
                            case 'Office Supplies': echo 'paperclip'; break;
                            case 'Clothing': echo 'tshirt'; break;
                            case 'Books': echo 'book'; break;
                            case 'Sports': echo 'basketball-ball'; break;
                            default: echo 'box';
                        }
                    ?>"></i>
                </div>
                <div class="card-body">
                    <h6 class="card-title"><?php echo h($product['item_name']); ?></h6>
                    <p class="card-text text-muted small mb-2">
                        <?php echo h($product['item_code']); ?>
                    </p>
                    <p class="card-text small mb-2">
                        <span class="badge bg-secondary"><?php echo h($product['category']); ?></span>
                    </p>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="price-tag">₱<?php echo number_format($product['price'], 2); ?></span>
                        <span class="small <?php echo $product['quantity'] <= 5 ? 'stock-low' : 'stock-good'; ?>">
                            <i class="fas fa-box"></i> 
                            <?php echo $product['quantity']; ?> in stock
                            <?php if ($product['quantity'] <= 5): ?>
                                <small class="d-block text-danger">Low stock!</small>
                            <?php endif; ?>
                        </span>
                    </div>

                    <!-- ✅ FIXED: Two separate forms, no nesting! -->
                    <div class="mt-3">
                        <!-- Add to Cart Form -->
<form method="POST" action="user_cart.php" style="margin-bottom: 10px;">
                        <input type="hidden" name="item_id" value="<?php echo $product['id']; ?>">
                        <input type="hidden" name="quantity" value="1">
                        <input type="hidden" name="action" value="add_to_cart">
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                            <i class="fas fa-cart-plus"></i> Add to Cart
                        </button>
                    </form>
                    
                    <!-- Buy Now Form -->
                    <form method="POST" action="user_cart.php" style="margin-bottom: 10px;">
                        <input type="hidden" name="item_id" value="<?php echo $product['id']; ?>">
                        <input type="hidden" name="quantity" value="1">
                        <input type="hidden" name="action" value="buy_now">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="fas fa-bolt"></i> Buy Now
                        </button>
                    </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-search fa-3x text-muted mb-3"></i>
                    <h4 class="text-muted">No Products Available</h4>
                    <p class="text-muted mb-4">
                        <?php if ($search || $category_filter): ?>
                            No products found matching your criteria. Try adjusting your search.
                        <?php else: ?>
                            No products are currently available. Please check back later.
                        <?php endif; ?>
                    </p>
                    <?php if ($search || $category_filter): ?>
                        <a href="user_dashboard.php" class="btn btn-primary">
                            <i class="fas fa-refresh"></i> Clear Filters
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

        <!-- Recent Orders -->
        <?php if ($user_orders->num_rows > 0): ?>
        <div class="row">
            <div class="col-12">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white border-0">
                        <h5 class="mb-0"><i class="fas fa-clock text-warning"></i> Recent Orders</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Product</th>
                                        <th>Quantity</th>
                                        <th>Total Price</th>
                                        <th>Status</th>
                                        <th>Order Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($order = $user_orders->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($order['item_name']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($order['item_code']); ?></small>
                                        </td>
                                        <td><?php echo $order['quantity']; ?></td>
                                        <td>₱<?php echo number_format($order['total_amount'], 2); ?></td>
                                        <td>
                                            <span class="order-status-badge status-<?php echo $order['status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y h:i A', strtotime($order['created_at'])); ?></td>
                                        <td>
                                            <?php if ($order['status'] == 'to_pay'): ?>
                                                <form method="POST" action="user_orders.php" class="d-inline">
                                                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                    <input type="hidden" name="action" value="pay_now">
                                                    <button type="submit" class="btn btn-success btn-sm">
                                                        <i class="fas fa-credit-card"></i> Pay Now
                                                    </button>
                                                </form>
                                            <?php elseif ($order['status'] == 'to_receive'): ?>
                                                <form method="POST" action="user_orders.php" class="d-inline">
                                                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                    <input type="hidden" name="action" value="confirm_receipt">
                                                    <button type="submit" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-check"></i> Confirm Receipt
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <a href="user_tracking.php?order_id=<?php echo $order['id']; ?>" 
                                               class="btn btn-info btn-sm">
                                                <i class="fas fa-truck"></i> Track
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-center mt-3">
                            <a href="user_orders.php" class="btn btn-outline-primary">
                                <i class="fas fa-list"></i> View All Orders
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
   <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-hide alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });

        // Debug form submissions (helpful for troubleshooting)
        const forms = document.querySelectorAll('form[action="user_cart.php"]');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                console.log('Form submitted to user_cart.php');
                const formData = new FormData(this);
                console.log('Form data:');
                for (let [key, value] of formData.entries()) {
                    console.log(key + ': ' + value);
                }
                
                // Optional: Add visual feedback without preventing submission
                const button = this.querySelector('button[type="submit"]');
                if (button) {
                    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    button.disabled = true;
                }
            });
        });

        // Add hover effects to product cards
        const productCards = document.querySelectorAll('.product-card');
        productCards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
            });
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
    });
    
    // Debug information
    console.log('=== Dashboard Debug Info ===');
    console.log('Available items: <?php echo $available_items->num_rows; ?>');
    console.log('Cart items: <?php echo getCartItemCount($conn, $user_id); ?>');
    console.log('User ID: <?php echo $user_id; ?>');
    console.log('User Role: <?php echo $_SESSION['role']; ?>');
    console.log('===========================');
</script>
</body>
</html>