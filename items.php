<?php
require_once 'config.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$full_name = h($_SESSION['full_name'] ?? 'User');
$role = $_SESSION['role'];

// Notifications count
$notif_count = 0;
if ($role == 'user') {
    $notif_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0");
    $notif_stmt->bind_param('i', $user_id);
    $notif_stmt->execute();
    $notif_count = $notif_stmt->get_result()->fetch_assoc()['cnt'];
}

// Search & Filter
$search = isset($_GET['search']) ? trim($conn->real_escape_string($_GET['search'])) : '';
$status_filter = isset($_GET['status']) ? trim($conn->real_escape_string($_GET['status'])) : '';

// Build query
if ($role == 'admin') {
    $query = "SELECT i.*, u.full_name as user_name FROM items i 
              LEFT JOIN users u ON i.user_id = u.id WHERE 1=1";
} else {
    $query = "SELECT i.*, u.full_name as user_name FROM items i 
              LEFT JOIN users u ON i.user_id = u.id 
              WHERE i.user_id = $user_id";
}

if ($search) {
    $query .= " AND (i.item_code LIKE '%$search%' OR i.item_name LIKE '%$search%' OR i.category LIKE '%$search%')";
}
if ($status_filter) {
    $query .= " AND i.status = '$status_filter'";
}
$query .= " ORDER BY i.created_at DESC";
$result = $conn->query($query);

// Auto-generate next code
$code_result = $conn->query("SELECT item_code FROM items WHERE item_code LIKE 'SUP%' ORDER BY item_code DESC LIMIT 1");
$next_code = 'SUP001';
if ($code_result->num_rows > 0) {
    $last = $code_result->fetch_assoc()['item_code'];
    $num = (int)substr($last, 3);
    $next_code = 'SUP' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
}

// Get users for assignment
$users_result = $conn->query("SELECT id, full_name, region FROM users WHERE role = 'user' AND region IS NOT NULL ORDER BY full_name");

// === HANDLE AJAX POST (ADD & EDIT) ===
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $role == 'admin') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    if ($action === 'edit') {
        // === EDIT ITEM ===
        $item_id = (int)($_POST['item_id'] ?? 0);
        $item_name = trim($_POST['item_name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $quantity = (int)($_POST['quantity'] ?? 0);
        $price = floatval($_POST['price'] ?? 0);
        $current_location = trim($_POST['current_location'] ?? '');
        $user_id_post = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;

        if (empty($item_name) || empty($category) || $quantity < 1 || $price <= 0 || empty($current_location)) {
            echo json_encode(['success' => false, 'message' => 'All required fields must be filled.']);
            exit;
        }

        $status = $user_id_post ? 'in_transit' : 'in_stock';
        $destination = $estimated_delivery = null;

        if ($user_id_post) {
            $stmt = $conn->prepare("SELECT region FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id_post);
            $stmt->execute();
            $region = $stmt->get_result()->fetch_assoc()['region'] ?? 'mindanao';
            $days = match($region) { 'luzon' => rand(2,3), 'visayas' => 5, 'mindanao' => 7, default => 7 };
            $estimated_delivery = date('Y-m-d H:i:s', strtotime("+$days days"));
            $destination = ucfirst($region);
        }

        $stmt = $conn->prepare("
            UPDATE items SET 
            item_name = ?, category = ?, quantity = ?, price = ?, 
            status = ?, current_location = ?, destination = ?, user_id = ?, estimated_delivery = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssidsisssi", $item_name, $category, $quantity, $price, $status, $current_location, $destination, $user_id_post, $estimated_delivery, $item_id);

        if ($stmt->execute()) {
            $track = $conn->prepare("INSERT INTO tracking (item_id, location, status, remarks) VALUES (?, ?, ?, ?)");
            $remarks = $user_id_post 
                ? "Updated: Reassigned to user in $destination. New ETA: " . date('M d, Y', strtotime($estimated_delivery))
                : "Updated: Location changed to $current_location";
            $track->bind_param("isss", $item_id, $current_location, $status, $remarks);
            $track->execute();

            $status_map = [
                'in_stock' => ['info', 'In Stock'],
                'in_transit' => ['warning', 'In Transit'],
                'delivered' => ['success', 'Delivered']
            ];
            $s = $status_map[$status] ?? ['secondary', 'Unknown'];

            echo json_encode([
                'success' => true,
                'item' => [
                    'id' => $item_id,
                    'item_name' => $item_name,
                    'category' => $category,
                    'quantity' => $quantity,
                    'price' => $price,
                    'current_location' => $current_location
                ],
                'status_class' => $s[0],
                'status_text' => $s[1]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update item.']);
        }
        exit;
    } else {
        // === ADD ITEM ===
        $item_name = trim($_POST['item_name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $quantity = (int)($_POST['quantity'] ?? 0);
        $price = floatval($_POST['price'] ?? 0);
        $current_location = trim($_POST['current_location'] ?? '');
        $user_id_post = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;

        if (empty($item_name) || empty($category) || $quantity < 1 || $price <= 0 || empty($current_location)) {
            echo json_encode(['success' => false, 'message' => 'All required fields must be filled.']);
            exit;
        }

        $item_code = $next_code;
        $check = $conn->prepare("SELECT id FROM items WHERE item_code = ?");
        $check->bind_param("s", $item_code);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Item code already exists.']);
            exit;
        }

        $status = $user_id_post ? 'in_transit' : 'in_stock';
        $destination = $estimated_delivery = null;

        if ($user_id_post) {
            $stmt = $conn->prepare("SELECT region FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id_post);
            $stmt->execute();
            $region = $stmt->get_result()->fetch_assoc()['region'] ?? 'mindanao';
            $days = match($region) { 'luzon' => rand(2,3), 'visayas' => 5, 'mindanao' => 7, default => 7 };
            $estimated_delivery = date('Y-m-d H:i:s', strtotime("+$days days"));
            $destination = ucfirst($region);
        }

        $stmt = $conn->prepare("
            INSERT INTO items 
            (item_code, item_name, category, quantity, price, status, current_location, destination, user_id, estimated_delivery, is_available)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->bind_param("sssidsssis", $item_code, $item_name, $category, $quantity, $price, $status, $current_location, $destination, $user_id_post, $estimated_delivery);

        if ($stmt->execute()) {
            $item_id = $conn->insert_id;
            $track = $conn->prepare("INSERT INTO tracking (item_id, location, status, remarks) VALUES (?, ?, ?, ?)");
            $remarks = $user_id_post 
                ? "Assigned to user in $destination. ETA: " . date('M d, Y', strtotime($estimated_delivery))
                : "Added to inventory";
            $track->bind_param("isss", $item_id, $current_location, $status, $remarks);
            $track->execute();

            if ($user_id_post) {
                $msg = "New item assigned:\n$item_name ($item_code)\nPrice: ₱" . number_format($price, 2) .
                       "\nFrom: $current_location\nETA: " . date('M d, Y', strtotime($estimated_delivery));
                $notif = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                $notif->bind_param("is", $user_id_post, $msg);
                $notif->execute();
            }

            $status_map = [
                'in_stock' => ['info', 'In Stock'],
                'in_transit' => ['warning', 'In Transit'],
                'delivered' => ['success', 'Delivered']
            ];
            $s = $status_map[$status] ?? ['secondary', 'Unknown'];

            echo json_encode([
                'success' => true,
                'item' => [
                    'id' => $item_id,
                    'item_code' => $item_code,
                    'item_name' => $item_name,
                    'category' => $category,
                    'quantity' => $quantity,
                    'price' => $price,
                    'current_location' => $current_location
                ],
                'status_class' => $s[0],
                'status_text' => $s[1]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error.']);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Items - Inventory System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #4361ee; --success: #06d6a0; --warning: #ffd166; --danger: #ef476f; --info: #118ab2; }
        body { background: linear-gradient(135deg, #f0f4f8, #e2e8f0); font-family: 'Segoe UI', sans-serif; min-height: 100vh; }
        .navbar { background: var(--primary) !important; box-shadow: 0 4px 15px rgba(67,97,238,0.25); padding: 0.75rem 0; position: sticky; top: 0; z-index: 1050; }
        .navbar-brand { font-weight: 700; font-size: 1.45rem; letter-spacing: 0.6px; color: white !important; display: flex; align-items: center; }
        .nav-link { font-weight: 500; color: rgba(255,255,255,0.9) !important; padding: 0.5rem 1rem !important; border-radius: 10px; transition: all 0.2s ease; display: flex; align-items: center; gap: 0.5rem; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.18); color: white !important; }
        .nav-link i { font-size: 1.1rem; width: 20px; text-align: center; }
        .navbar-right { display: flex; align-items: center; gap: 1rem; }
        .notification-bell { position: relative; color: white; font-size: 1.35rem; padding: 0.5rem; border-radius: 50%; transition: background 0.2s; }
        .notification-bell:hover { background: rgba(255,255,255,0.2); }
        .notification-badge { position: absolute; top: 6px; right: 6px; font-size: 0.65rem; min-width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; border: 2px solid white; }
        .profile-dropdown { display: flex; align-items: center; gap: 0.6rem; color: white; text-decoration: none; padding: 0.5rem 0.8rem; border-radius: 14px; transition: background 0.2s; min-width: 190px; cursor: pointer; }
        .profile-dropdown:hover { background: rgba(255,255,255,0.18); }
        .profile-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(45deg, #FFD700, #FFA500); display: flex; align-items: center; justify-content: center; color: #000; font-weight: bold; font-size: 1.1rem; flex-shrink: 0; }
        .profile-info { display: flex; flex-direction: column; line-height: 1.3; overflow: hidden; }
        .profile-name { font-weight: 600; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 130px; }
        .profile-role { font-size: 0.7rem; background: rgba(255,255,255,0.3); color: white; padding: 0.2rem 0.55rem; border-radius: 50px; font-weight: 500; display: inline-block; }
        .dropdown-menu { border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.18); border-radius: 14px; padding: 0.5rem 0; min-width: 180px; margin-top: 0.5rem; }
        .dropdown-item { padding: 0.6rem 1.2rem; border-radius: 10px; margin: 0 0.5rem; font-size: 0.9rem; transition: background 0.2s; }
        .dropdown-item:hover { background: #f1f3f5; }
        @media (max-width: 768px) { .profile-info { display: none; } .profile-avatar { width: 36px; height: 36px; font-size: 1rem; } .navbar-right { gap: 0.75rem; } .navbar-brand { font-size: 1.25rem; } }
        .card { border-radius: 18px; border: none; box-shadow: 0 5px 18px rgba(0,0,0,0.09); overflow: hidden; }
        .table th { font-weight: 600; color: #495057; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.6px; }
        .badge-status { font-size: 0.75rem; padding: 0.4em 0.85em; border-radius: 50px; font-weight: 500; }
        .item-code-box { background: #f8f9fa; border: 2px solid #dee2e6; border-radius: 14px; padding: 0.8rem 1.3rem; font-family: 'Courier New', monospace; font-size: 1.35rem; font-weight: bold; color: var(--primary); display: inline-block; }
        .eta-preview { font-size: 0.9rem; color: #2c3e50; font-weight: 500; padding: 0.5rem 0; }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">
            <i class="fas fa-boxes"></i> Inventory System
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link active" href="items.php"><i class="fas fa-list"></i> All Items</a></li>
            </ul>
            <div class="navbar-right">
                <a href="notifications.php" class="notification-bell">
                    <i class="fas fa-bell"></i>
                    <?php if ($notif_count > 0): ?>
                        <span class="badge rounded-pill bg-danger notification-badge"><?= $notif_count ?></span>
                    <?php endif; ?>
                </a>
                <div class="dropdown">
                    <a href="#" class="profile-dropdown dropdown-toggle" data-bs-toggle="dropdown">
                        <div class="profile-avatar"><?= strtoupper(substr($full_name, 0, 1)) ?></div>
                        <div class="profile-info">
                            <div class="profile-name"><?= $full_name ?></div>
                            <div class="profile-role"><?= ucfirst($role) ?></div>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid py-4 px-3 px-md-4">
    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary">All Items</h2>
        </div>
        <div class="col-md-6 text-end">
            <?php if ($role == 'admin'): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="fas fa-plus me-1"></i> Add New Item
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Search & Filter -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Code, name, category..." value="<?= h($search) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="in_stock" <?= $status_filter == 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                        <option value="in_transit" <?= $status_filter == 'in_transit' ? 'selected' : '' ?>>In Transit</option>
                        <option value="delivered" <?= $status_filter == 'delivered' ? 'selected' : '' ?>>Delivered</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary">Search</button>
                    <a href="items.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Items Table -->
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Item Code</th>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Location</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($item = $result->fetch_assoc()): ?>
                        <tr data-item-id="<?= $item['id'] ?>">
                            <td><strong><?= h($item['item_code']) ?></strong></td>
                            <td><?= h($item['item_name']) ?></td>
                            <td><span class="badge bg-secondary"><?= h($item['category']) ?></span></td>
                            <td><strong><?= $item['quantity'] ?></strong></td>
                            <td><strong>₱<?= number_format($item['price'], 2) ?></strong></td>
                            <td>
                                <?php
                                $status_map = ['in_stock'=>['info','In Stock'],'in_transit'=>['warning','In Transit'],'delivered'=>['success','Delivered']];
                                $s = $status_map[$item['status']] ?? ['secondary','Unknown'];
                                ?>
                                <span class="badge badge-status bg-<?= $s[0] ?>"><?= $s[1] ?></span>
                            </td>
                            <td><?= h($item['current_location'] ?: 'N/A') ?></td>
                            <td>
                                <a href="track_item.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-primary">Track</a>
                                <?php if ($role == 'admin'): ?>
                                <button class="btn btn-sm btn-warning edit-item-btn" data-item='<?= json_encode($item) ?>'>Edit</button>
                                <a href="delete_item.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-danger"
                                   onclick="return confirm('Delete this item?')">Delete</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-box-open fa-2x mb-3"></i><br>No items found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ADD ITEM MODAL -->
<?php if ($role == 'admin'): ?>
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="addItemForm">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Add New Product</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <div class="item-code-box"><?= $next_code ?></div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Item Name <span class="text-danger">*</span></label>
                            <input type="text" name="item_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select name="category" class="form-select" required>
                                <option value="">Choose...</option>
                                <option>Electronics</option>
                                <option>Furniture</option>
                                <option>Office Supplies</option>
                                <option>Equipment</option>
                                <option>Others</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input type="number" name="quantity" class="form-control" min="1" value="1" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Price (₱) <span class="text-danger">*</span></label>
                            <input type="number" name="price" step="0.01" class="form-control" min="0.01" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Location <span class="text-danger">*</span></label>
                            <input type="text" name="current_location" class="form-control" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Assign to User (Optional)</label>
                            <select name="user_id" id="addUserSelect" class="form-select">
                                <option value="">-- None --</option>
                                <?php
                                $users_result->data_seek(0);
                                while ($u = $users_result->fetch_assoc()): ?>
                                    <option value="<?= $u['id'] ?>" data-region="<?= $u['region'] ?>">
                                        <?= h($u['full_name']) ?> (<?= ucfirst($u['region']) ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <div class="eta-preview" id="addEtaPreview"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Product</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- EDIT ITEM MODAL -->
<?php if ($role == 'admin'): ?>
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="editItemForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="item_id" id="editItemId">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title">Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <div class="item-code-box" id="editItemCode"></div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Item Name <span class="text-danger">*</span></label>
                            <input type="text" name="item_name" id="editItemName" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select name="category" id="editItemCategory" class="form-select" required>
                                <option value="">Choose...</option>
                                <option>Electronics</option>
                                <option>Furniture</option>
                                <option>Office Supplies</option>
                                <option>Equipment</option>
                                <option>Others</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input type="number" name="quantity" id="editItemQuantity" class="form-control" min="1" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Price (₱) <span class="text-danger">*</span></label>
                            <input type="number" name="price" id="editItemPrice" step="0.01" class="form-control" min="0.01" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Location <span class="text-danger">*</span></label>
                            <input type="text" name="current_location" id="editItemLocation" class="form-control" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Assign to User (Optional)</label>
                            <select name="user_id" id="editUserSelect" class="form-select">
                                <option value="">-- None --</option>
                                <?php
                                $users_result->data_seek(0);
                                while ($u = $users_result->fetch_assoc()): ?>
                                    <option value="<?= $u['id'] ?>" data-region="<?= $u['region'] ?>">
                                        <?= h($u['full_name']) ?> (<?= ucfirst($u['region']) ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <div class="eta-preview" id="editEtaPreview"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Update Product</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const addUserSelect = document.getElementById('addUserSelect');
    const addEtaPreview = document.getElementById('addEtaPreview');
    const editUserSelect = document.getElementById('editUserSelect');
    const editEtaPreview = document.getElementById('editEtaPreview');
    const etaMap = { 'luzon': '2-3 days', 'visayas': '5 days', 'mindanao': '7 days' };

    function updateETA(select, preview) {
        const region = select?.selectedOptions[0]?.dataset.region;
        if (region && etaMap[region]) {
            const days = region === 'luzon' ? (Math.random() < 0.5 ? 2 : 3) : (region === 'visayas' ? 5 : 7);
            const date = new Date();
            date.setDate(date.getDate() + days);
            preview.innerHTML = `<strong>ETA:</strong> ${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} (${etaMap[region]})`;
        } else {
            preview.innerHTML = '';
        }
    }
    addUserSelect?.addEventListener('change', () => updateETA(addUserSelect, addEtaPreview));
    editUserSelect?.addEventListener('change', () => updateETA(editUserSelect, editEtaPreview));

    // ADD ITEM
    document.getElementById('addItemForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.delete('action'); // not needed for add

        fetch('', { method: 'POST', body: formData })
        .then(res => res.ok ? res.json() : Promise.reject(res.status))
        .then(data => {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('addItemModal')).hide();
                const tbody = document.querySelector('table tbody');
                const row = document.createElement('tr');
                row.dataset.itemId = data.item.id;
                row.innerHTML = `
                    <td><strong>${data.item.item_code}</strong></td>
                    <td>${data.item.item_name}</td>
                    <td><span class="badge bg-secondary">${data.item.category}</span></td>
                    <td><strong>${data.item.quantity}</strong></td>
                    <td><strong>₱${parseFloat(data.item.price).toFixed(2)}</strong></td>
                    <td><span class="badge badge-status bg-${data.status_class}">${data.status_text}</span></td>
                    <td>${data.item.current_location || 'N/A'}</td>
                    <td>
                        <a href="track_item.php?id=${data.item.id}" class="btn btn-sm btn-primary">Track</a>
                        <button class="btn btn-sm btn-warning edit-item-btn" data-item='${JSON.stringify(data.item).replace(/'/g, "\\'")}'>Edit</button>
                        <a href="delete_item.php?id=${data.item.id}" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Delete</a>
                    </td>
                `;
                tbody.insertBefore(row, tbody.firstChild);
                showToast(`Item <strong>${data.item.item_code}</strong> added!`, 'success');
            } else {
                alert(data.message);
            }
        })
        .catch(err => { console.error(err); alert('Error: ' + err); });
    });

    // EDIT ITEM
    document.querySelectorAll('.edit-item-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const item = JSON.parse(this.dataset.item);
            document.getElementById('editItemId').value = item.id;
            document.getElementById('editItemCode').textContent = item.item_code;
            document.getElementById('editItemName').value = item.item_name;
            document.getElementById('editItemCategory').value = item.category;
            document.getElementById('editItemQuantity').value = item.quantity;
            document.getElementById('editItemPrice').value = item.price;
            document.getElementById('editItemLocation').value = item.current_location;
            document.getElementById('editUserSelect').value = item.user_id || '';
            updateETA(editUserSelect, editEtaPreview);
            new bootstrap.Modal(document.getElementById('editItemModal')).show();
        });
    });

    document.getElementById('editItemForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);

        fetch('', { method: 'POST', body: formData })
        .then(res => res.ok ? res.json() : Promise.reject(res.status))
        .then(data => {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('editItemModal')).hide();
                const row = document.querySelector(`tr[data-item-id="${data.item.id}"]`);
                row.innerHTML = `
                    <td><strong>${row.querySelector('td').innerHTML}</strong></td>
                    <td>${data.item.item_name}</td>
                    <td><span class="badge bg-secondary">${data.item.category}</span></td>
                    <td><strong>${data.item.quantity}</strong></td>
                    <td><strong>₱${parseFloat(data.item.price).toFixed(2)}</strong></td>
                    <td><span class="badge badge-status bg-${data.status_class}">${data.status_text}</span></td>
                    <td>${data.item.current_location || 'N/A'}</td>
                    <td>
                        <a href="track_item.php?id=${data.item.id}" class="btn btn-sm btn-primary">Track</a>
                        <button class="btn btn-sm btn-warning edit-item-btn" data-item='${JSON.stringify(data.item).replace(/'/g, "\\'")}'>Edit</button>
                        <a href="delete_item.php?id=${data.item.id}" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Delete</a>
                    </td>
                `;
                showToast('Item updated!', 'success');
            } else {
                alert(data.message);
            }
        })
        .catch(err => { console.error(err); alert('Error: ' + err); });
    });

    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-white bg-${type} border-0 position-fixed`;
        toast.style.top = '1rem'; toast.style.right = '1rem'; toast.style.zIndex = '9999';
        toast.innerHTML = `<div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
        document.body.appendChild(toast);
        new bootstrap.Toast(toast, { delay: 4000 }).show();
        setTimeout(() => toast.remove(), 4500);
    }
</script>
</body>
</html>