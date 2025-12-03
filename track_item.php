<?php
require_once 'config.php';
requireLogin();

if ($_SESSION['role'] != 'admin') {
    header("Location: dashboard.php");
    exit;
}

$item_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Get item details
$item_sql = "SELECT i.*, u.full_name, u.email FROM items i 
             LEFT JOIN users u ON i.user_id = u.id 
             WHERE i.id = ?";
$stmt = $conn->prepare($item_sql);
$stmt->bind_param("i", $item_id);
$stmt->execute();
$item_result = $stmt->get_result();
$item = $item_result->fetch_assoc();

if (!$item) {
    $_SESSION['error'] = "Item not found!";
    header("Location: items.php");
    exit;
}

// Handle tracking update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_tracking'])) {
    $location = trim($_POST['location']);
    $status = $_POST['status'];
    $remarks = trim($_POST['remarks']);
    
    $conn->begin_transaction();
    
    try {
        // Insert tracking
        $track_sql = "INSERT INTO tracking (item_id, location, status, remarks) VALUES (?, ?, ?, ?)";
        $track_stmt = $conn->prepare($track_sql);
        $track_stmt->bind_param("isss", $item_id, $location, $status, $remarks);
        $track_stmt->execute();
        
        // Update item
        $update_sql = "UPDATE items SET status = ?, current_location = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ssi", $status, $location, $item_id);
        $update_stmt->execute();
        
        // Notify user
        if ($item['user_id']) {
            $message = "Your item {$item['item_code']} is now {$status} at {$location}. {$remarks}";
            $notif_sql = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
            $notif_stmt = $conn->prepare($notif_sql);
            $notif_stmt->bind_param("is", $item['user_id'], $message);
            $notif_stmt->execute();
        }
        
        $conn->commit();
        $_SESSION['success'] = "Tracking updated successfully!";
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header("Location: track_item.php?id=$item_id");
    exit;
}

// Get tracking history
$tracking_sql = "SELECT * FROM tracking WHERE item_id = ? ORDER BY tracking_time DESC";
$tracking_stmt = $conn->prepare($tracking_sql);
$tracking_stmt->bind_param("i", $item_id);
$tracking_stmt->execute();
$tracking_result = $tracking_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Item #<?php echo htmlspecialchars($item['item_code']); ?> - Inventory System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f5f7fa; font-family: 'Segoe UI', sans-serif; }
        .card { border: none; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .card-header { border-radius: 16px 16px 0 0 !important; font-weight: 600; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
        .info-item { background: #f8f9fa; padding: 1rem; border-radius: 12px; }
        .info-label { font-size: 0.85rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
        .info-value { font-weight: 600; color: #2c3e50; margin-top: 4px; }
        .badge-status { font-size: 0.9rem; padding: 0.5em 1em; border-radius: 50px; }
        .progress-steps { display: flex; justify-content: space-between; position: relative; margin: 2rem 0; }
        .progress-steps::before { content: ''; position: absolute; top: 20px; left: 40px; right: 40px; height: 4px; background: #e9ecef; z-index: 0; }
        .step { text-align: center; z-index: 1; flex: 1; }
        .step-circle { width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; font-size: 1.3rem; font-weight: bold; transition: all 0.3s; }
        .step-completed .step-circle { background: #28a745; color: white; }
        .step-active .step-circle { background: #007bff; color: white; transform: scale(1.15); box-shadow: 0 0 0 8px rgba(0,123,255,0.2); }
        .step-pending .step-circle { background: #6c757d; color: white; }
        .step-label { font-size: 0.9rem; font-weight: 500; color: #495057; }
        .timeline { position: relative; padding-left: 2rem; }
        .timeline::before { content: ''; position: absolute; left: 20px; top: 0; bottom: 0; width: 2px; background: #dee2e6; }
        .timeline-item { position: relative; margin-bottom: 1.5rem; }
        .timeline-icon { position: absolute; left: -38px; top: 0; width: 36px; height: 36px; border-radius: 50%; background: #007bff; color: white; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; }
        .timeline-content { background: white; padding: 1rem; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.05); }
        .form-control, .form-select { border-radius: 12px; }
        .btn-update { border-radius: 12px; font-weight: 600; }
        .alert { border-radius: 12px; }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="dashboard.php">
            Inventory System
        </a>
        <div class="collapse navbar-collapse">
            <ul class="navbar-nav me-auto">
                <li><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                <li><a class="nav-link" href="items.php">All Items</a></li>
                <li><a class="nav-link active" href="#">Track Item</a></li>
            </ul>
            <ul class="navbar-nav">
                <li><a class="nav-link" href="profile.php"><?php echo $_SESSION['full_name']; ?></a></li>
                <li><a class="nav-link" href="logout.php">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-lg-8">

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Item Info Card -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Item Details</h5>
                    <span class="badge bg-light text-dark fs-6"><?php echo htmlspecialchars($item['item_code']); ?></span>
                </div>
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Item Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($item['item_name']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Category</div>
                            <div class="info-value">
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($item['category']); ?></span>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Quantity</div>
                            <div class="info-value"><?php echo $item['quantity']; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Price</div>
                            <div class="info-value">₱<?php echo number_format($item['price'], 2); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Current Location</div>
                            <div class="info-value"><?php echo htmlspecialchars($item['current_location']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Status</div>
                            <div class="info-value">
                                <?php
                                $status_colors = ['in_stock'=>'info', 'in_transit'=>'warning', 'delivered'=>'success'];
                                $status_text = ucwords(str_replace('_', ' ', $item['status']));
                                ?>
                                <span class="badge badge-status bg-<?php echo $status_colors[$item['status']]; ?>">
                                    <?php echo $status_text; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Delivery Progress -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Delivery Progress</h5>
                </div>
                <div class="card-body">
                    <div class="progress-steps">
                        <?php
                        $steps = [
                            'in_stock' => ['icon' => 'warehouse', 'label' => 'In Stock'],
                            'in_transit' => ['icon' => 'truck', 'label' => 'In Transit'],
                            'delivered' => ['icon' => 'check', 'label' => 'Delivered']
                        ];
                        $current = $item['status'];
                        $statuses = array_keys($steps);
                        $current_index = array_search($current, $statuses);
                        ?>
                        <?php foreach ($steps as $key => $step): 
                            $index = array_search($key, $statuses);
                            $state = $index < $current_index ? 'completed' : ($index == $current_index ? 'active' : 'pending');
                        ?>
                            <div class="step step-<?php echo $state; ?>">
                                <div class="step-circle">
                                    <i class="fas fa-<?php echo $step['icon']; ?>"></i>
                                </div>
                                <div class="step-label"><?php echo $step['label']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Tracking History -->
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Tracking History</h5>
                </div>
                <div class="card-body">
                    <?php if ($tracking_result->num_rows > 0): ?>
                        <div class="timeline">
                            <?php while ($track = $tracking_result->fetch_assoc()): ?>
                                <div class="timeline-item">
                                    <div class="timeline-icon">
                                        <i class="fas fa-map-marker-alt"></i>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($track['location']); ?></h6>
                                                <p class="mb-1 text-muted"><?php echo htmlspecialchars($track['remarks'] ?: 'No remarks'); ?></p>
                                            </div>
                                            <span class="badge bg-<?php echo $status_colors[$track['status']]; ?> ms-2">
                                                <?php echo ucwords(str_replace('_', ' ', $track['status'])); ?>
                                            </span>
                                        </div>
                                        <small class="text-muted">
                                            <i class="fas fa-clock"></i> 
                                            <?php echo date('M j, Y • g:i A', strtotime($track['tracking_time'])); ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-muted py-4">No tracking updates yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Update Form Sidebar -->
        <div class="col-lg-4">
            <div class="card shadow-sm sticky-top" style="top: 1rem;">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">Update Tracking</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Location</label>
                            <input type="text" name="location" class="form-control" 
                                   value="<?php echo htmlspecialchars($item['current_location']); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" class="form-select" required>
                                <option value="in_stock" <?php echo $item['status'] == 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                                <option value="in_transit" <?php echo $item['status'] == 'in_transit' ? 'selected' : ''; ?>>In Transit</option>
                                <option value="delivered" <?php echo $item['status'] == 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Remarks (Optional)</label>
                            <textarea name="remarks" class="form-control" rows="3" 
                                      placeholder="e.g., Left Manila hub, arriving tomorrow..."></textarea>
                        </div>

                        <button type="submit" name="update_tracking" class="btn btn-primary btn-update w-100">
                            Update Tracking
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>