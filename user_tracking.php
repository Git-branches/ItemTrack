<?php
require_once 'config.php';
requireUser();

$user_id = $_SESSION['user_id'];
$item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;

// Get user details including region
$user_sql = "SELECT u.* FROM users u WHERE u.id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

// Get item details
$item = null;
$tracking_history = [];

if ($item_id > 0) {
    $item_sql = "SELECT i.* FROM items i WHERE i.id = ?";
    $stmt = $conn->prepare($item_sql);
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $item_result = $stmt->get_result();
    $item = $item_result->fetch_assoc();
    
    if ($item) {
        $tracking_sql = "SELECT * FROM tracking WHERE item_id = ? ORDER BY tracking_time ASC";
        $tracking_stmt = $conn->prepare($tracking_sql);
        $tracking_stmt->bind_param("i", $item_id);
        $tracking_stmt->execute();
        $tracking_history = $tracking_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// Get all items for sidebar
$all_items_sql = "SELECT id, item_code, item_name, status, current_location, created_at FROM items ORDER BY created_at DESC LIMIT 10";
$all_items = $conn->query($all_items_sql);

// SMART DELIVERY CALCULATION BASED ON REGION
function calculateEstimatedDelivery($user_region, $item_location, $item_status, $created_at) {
    if ($item_status == 'delivered') {
        return null;
    }
    
    $region_delivery_times = [
        'luzon' => [
            'within_luzon' => 2,
            'to_visayas' => 4,
            'to_mindanao' => 5
        ],
        'visayas' => [
            'within_visayas' => 2,
            'to_luzon' => 4,
            'to_mindanao' => 3
        ],
        'mindanao' => [
            'within_mindanao' => 3,
            'to_visayas' => 4,
            'to_luzon' => 5
        ]
    ];
    
    $delivery_days = 3;
    
    if ($user_region == 'luzon') {
        if (stripos($item_location, 'manila') !== false || 
            stripos($item_location, 'luzon') !== false) {
            $delivery_days = $region_delivery_times['luzon']['within_luzon'];
        } elseif (stripos($item_location, 'visayas') !== false) {
            $delivery_days = $region_delivery_times['luzon']['to_visayas'];
        } else {
            $delivery_days = $region_delivery_times['luzon']['to_mindanao'];
        }
    }
    elseif ($user_region == 'visayas') {
        if (stripos($item_location, 'cebu') !== false || 
            stripos($item_location, 'visayas') !== false) {
            $delivery_days = $region_delivery_times['visayas']['within_visayas'];
        } elseif (stripos($item_location, 'luzon') !== false) {
            $delivery_days = $region_delivery_times['visayas']['to_luzon'];
        } else {
            $delivery_days = $region_delivery_times['visayas']['to_mindanao'];
        }
    }
    elseif ($user_region == 'mindanao') {
        if (stripos($item_location, 'davao') !== false || 
            stripos($item_location, 'gensan') !== false ||
            stripos($item_location, 'mindanao') !== false) {
            $delivery_days = $region_delivery_times['mindanao']['within_mindanao'];
        } elseif (stripos($item_location, 'visayas') !== false) {
            $delivery_days = $region_delivery_times['mindanao']['to_visayas'];
        } else {
            $delivery_days = $region_delivery_times['mindanao']['to_luzon'];
        }
    }
    
    $created_date = new DateTime($created_at);
    $estimated_delivery = clone $created_date;
    $estimated_delivery->modify("+$delivery_days days");
    
    return $estimated_delivery->format('M d, Y g:i A');
}

// FIXED PROGRESS FUNCTION - AUTO OUT FOR DELIVERY
function getUserProgressData($status, $current_location, $tracking_history) {
    $steps = [
        1 => ['icon' => 'fa-shipping-fast', 'label' => 'Shipped'],
        2 => ['icon' => 'fa-truck', 'label' => 'Out for Delivery'],
        3 => ['icon' => 'fa-home', 'label' => 'Delivered']
    ];
    
    $current_step = 1;
    $current_progress = 33;
    
    if ($status == 'in_transit') {
        // Check if there are multiple tracking entries (meaning it's moving)
        $tracking_count = count($tracking_history);
        
        // AUTO: If item has been in transit for a while (multiple tracking entries), show Out for Delivery
        if ($tracking_count >= 2) {
            $current_step = 2;
            $current_progress = 66;
        } 
        // OR: Check for specific location keywords
        elseif ($current_location) {
            $location_lower = strtolower($current_location);
            
            if (strpos($location_lower, 'delivery') !== false || 
                strpos($location_lower, 'out for') !== false ||
                strpos($location_lower, 'rider') !== false ||
                strpos($location_lower, 'courier') !== false ||
                strpos($location_lower, 'final') !== false ||
                strpos($location_lower, 'last mile') !== false) {
                $current_step = 2;
                $current_progress = 66;
            } else {
                $current_step = 1;
                $current_progress = 33;
            }
        } else {
            $current_step = 1;
            $current_progress = 33;
        }
    } elseif ($status == 'delivered') {
        $current_step = 3;
        $current_progress = 100;
    }
    
    return [
        'steps' => $steps,
        'current_progress' => $current_progress,
        'current_step' => $current_step
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Item - ShopStyle</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .tracking-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .progress-step {
            text-align: center;
            padding: 10px;
        }
        .step-active {
            color: #007bff;
            font-weight: bold;
        }
        .step-completed {
            color: #28a745;
        }
        .step-pending {
            color: #6c757d;
            opacity: 0.6;
        }
        .delivery-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 15px;
        }
        .progress-bar-container {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            margin: 30px 0;
            position: relative;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        .tracking-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
        }
        .tracking-step {
            text-align: center;
            position: relative;
            z-index: 2;
            flex: 1;
        }
        .step-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 1.5rem;
            transition: all 0.3s ease;
            border: 4px solid white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .step-active .step-icon {
            background: linear-gradient(135deg, #007bff 0%, #764ba2 100%);
            color: white;
            transform: scale(1.1);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        .step-completed .step-icon {
            background: #28a745;
            color: white;
        }
        .step-pending .step-icon {
            background: #e9ecef;
            color: #6c757d;
        }
        .step-label {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .step-status {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: 500;
        }
        .auto-progress {
            background: #e7f3ff;
            border-left: 4px solid #007bff;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="user_dashboard.php">
                <i class="fas fa-shopping-bag"></i> ShopStyle
            </a>
            <div class="collapse navbar-collapse">
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
                    <li class="nav-item">
                        <a class="nav-link active" href="user_tracking.php">
                            <i class="fas fa-truck"></i> Track Item
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="user_profile.php">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                            <small class="d-block"><?php echo ucfirst($user['region']); ?> Region</small>
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
        <div class="row">
            <!-- Main Content -->
            <div class="col-lg-8">
                <?php if (!$item): ?>
                    <!-- No item selected -->
                    <div class="card tracking-card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-search fa-3x text-muted mb-3"></i>
                            <h4 class="text-muted">Track Any Item</h4>
                            <p class="text-muted mb-4">Select an item from the list to view its tracking information</p>
                            
                            <?php if ($all_items->num_rows > 0): ?>
                                <div class="mt-4">
                                    <h6>Available Items:</h6>
                                    <?php while ($track_item = $all_items->fetch_assoc()): ?>
                                        <div class="mb-2">
                                            <a href="user_tracking.php?item_id=<?php echo $track_item['id']; ?>" 
                                               class="btn btn-outline-primary btn-sm">
                                                <?php echo htmlspecialchars($track_item['item_name']); ?> 
                                                (<?php echo htmlspecialchars($track_item['item_code']); ?>)
                                                - <span class="badge bg-secondary"><?php echo ucfirst($track_item['status']); ?></span>
                                            </a>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-danger">No items available for tracking.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <?php
                    // FIXED: Pass tracking_history to the function
                    $progress_data = getUserProgressData($item['status'], $item['current_location'], $tracking_history);
                    $estimated_delivery = calculateEstimatedDelivery(
                        $user['region'], 
                        $item['current_location'], 
                        $item['status'],
                        $item['created_at']
                    );
                    ?>
                    
                    <!-- Item Tracking -->
                    <div class="card tracking-card mb-4">
                        <div class="card-header bg-white">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h5 class="mb-0">
                                        <i class="fas fa-truck"></i> Tracking: <?php echo htmlspecialchars($item['item_name']); ?>
                                    </h5>
                                    <small class="text-muted">Item Code: <?php echo htmlspecialchars($item['item_code']); ?></small>
                                </div>
                                <div class="col-md-4 text-end">
                                    <span class="badge bg-<?php 
                                        echo $item['status'] == 'in_stock' ? 'info' : 
                                             ($item['status'] == 'in_transit' ? 'warning' : 'success'); 
                                    ?> fs-6">
                                        <?php echo ucfirst(str_replace('_', ' ', $item['status'])); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Delivery Information -->
                            <div class="delivery-info mb-4">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6><i class="fas fa-map-marker-alt"></i> Current Location</h6>
                                        <h4 class="mb-0"><?php echo htmlspecialchars($item['current_location'] ?: 'Processing...'); ?></h4>
                                        <small>Tracking Updates: <?php echo count($tracking_history); ?></small>
                                    </div>
                                    <div class="col-md-6">
                                        <h6><i class="fas fa-clock"></i> Estimated Delivery</h6>
                                        <?php if ($estimated_delivery && $item['status'] != 'delivered'): ?>
                                            <h4 class="mb-0"><?php echo $estimated_delivery; ?></h4>
                                            <small>Based on your location (<?php echo ucfirst($user['region']); ?> Region)</small>
                                        <?php elseif ($item['status'] == 'delivered'): ?>
                                            <h4 class="mb-0">Delivered</h4>
                                            <small>Item has been successfully delivered</small>
                                        <?php else: ?>
                                            <h4 class="mb-0">Calculating...</h4>
                                            <small>Estimating delivery time</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Auto Progress Info -->
                            <?php if ($item['status'] == 'in_transit' && count($tracking_history) >= 2): ?>
                                <div class="auto-progress">
                                    <i class="fas fa-bolt text-primary"></i>
                                    <strong>Auto-Progress:</strong> Item is now <strong>Out for Delivery</strong> 
                                    (multiple tracking updates detected)
                                </div>
                            <?php endif; ?>

                            <!-- Progress Bar -->
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill" style="width: <?php echo $progress_data['current_progress']; ?>%"></div>
                            </div>
                            
                            <!-- Progress Steps -->
                            <div class="tracking-steps">
                                <?php foreach ($progress_data['steps'] as $step_num => $step): ?>
                                    <?php
                                    $step_class = '';
                                    $status_text = '';
                                    
                                    if ($step_num < $progress_data['current_step']) {
                                        $step_class = 'step-completed';
                                        $status_text = 'Completed';
                                    } elseif ($step_num == $progress_data['current_step']) {
                                        $step_class = 'step-active';
                                        $status_text = 'Current';
                                    } else {
                                        $step_class = 'step-pending';
                                        $status_text = 'Pending';
                                    }
                                    ?>
                                    <div class="tracking-step <?php echo $step_class; ?>">
                                        <div class="step-icon">
                                            <i class="fas <?php echo $step['icon']; ?>"></i>
                                        </div>
                                        <div class="step-label"><?php echo $step['label']; ?></div>
                                        <div class="step-status"><?php echo $status_text; ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Progress Percentage -->
                            <div class="text-center mt-4">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h4><?php echo $progress_data['current_progress']; ?>%</h4>
                                        <small class="text-muted">Overall Progress</small>
                                    </div>
                                    <div class="col-md-6">
                                        <h4><?php echo $progress_data['current_step']; ?>/3</h4>
                                        <small class="text-muted">Steps Completed</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Tracking History -->
                            <h6 class="mt-5 mb-3"><i class="fas fa-history"></i> Tracking History</h6>
                            <?php if (!empty($tracking_history)): ?>
                                <div class="list-group">
                                    <?php foreach ($tracking_history as $track): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($track['location']); ?></h6>
                                                <small><?php echo date('M d, Y g:i A', strtotime($track['tracking_time'])); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <span class="badge bg-<?php 
                                                    echo $track['status'] == 'in_stock' ? 'info' : 
                                                         ($track['status'] == 'in_transit' ? 'warning' : 'success'); 
                                                ?>">
                                                    <?php echo ucwords(str_replace('_', ' ', $track['status'])); ?>
                                                </span>
                                            </p>
                                            <?php if ($track['remarks']): ?>
                                                <p class="mb-0 text-muted"><?php echo htmlspecialchars($track['remarks']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-3">
                                    <i class="fas fa-history fa-2x text-muted mb-2"></i>
                                    <p class="text-muted">No tracking history available yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- All Items -->
                <div class="card tracking-card">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="fas fa-boxes"></i> Trackable Items</h6>
                    </div>
                    <div class="card-body">
                        <?php 
                        $all_items->data_seek(0); 
                        ?>
                        <?php if ($all_items->num_rows > 0): ?>
                            <div class="list-group list-group-flush">
                                <?php while ($track_item = $all_items->fetch_assoc()): ?>
                                    <a href="user_tracking.php?item_id=<?php echo $track_item['id']; ?>" 
                                       class="list-group-item list-group-item-action <?php echo $item_id == $track_item['id'] ? 'active' : ''; ?>">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($track_item['item_name']); ?></h6>
                                            <small><?php echo date('M d', strtotime($track_item['created_at'])); ?></small>
                                        </div>
                                        <p class="mb-1 small"><?php echo htmlspecialchars($track_item['item_code']); ?></p>
                                        <span class="badge bg-<?php 
                                            echo $track_item['status'] == 'in_stock' ? 'info' : 
                                                 ($track_item['status'] == 'in_transit' ? 'warning' : 'success'); 
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $track_item['status'])); ?>
                                        </span>
                                    </a>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-box fa-2x text-muted mb-2"></i>
                                <p class="text-muted small">No items available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- User Region Info -->
                <div class="card tracking-card mt-4">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="fas fa-map"></i> Your Region</h6>
                    </div>
                    <div class="card-body text-center">
                        <h4 class="text-primary"><?php echo ucfirst($user['region']); ?> Region</h4>
                        <p class="text-muted small mb-0">
                            Estimated delivery times are calculated based on your region
                        </p>
                    </div>
                </div>

                <!-- Auto Progress Info -->
                <div class="card tracking-card mt-4">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="fas fa-robot"></i> Auto Progress</h6>
                    </div>
                    <div class="card-body">
                        <small class="text-muted">
                            <strong>Automatic Progress:</strong><br>
                            • <strong>Shipped:</strong> First in_transit update<br>
                            • <strong>Out for Delivery:</strong> After 2+ tracking updates<br>
                            • <strong>Delivered:</strong> When status changes to delivered
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        <?php if ($item): ?>
        setTimeout(function() {
            window.location.reload();
        }, 30000);
        <?php endif; ?>
    </script>
</body>
</html>