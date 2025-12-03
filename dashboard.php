<?php
require_once 'config.php';
requireLogin();

// Redirect non-admin
if ($_SESSION['role'] !== 'admin') {
    header('Location: user_dashboard.php');
    exit;
}

// User data
$user_id   = (int)$_SESSION['user_id'];
$full_name = h($_SESSION['full_name'] ?? 'Admin');
$role      = $_SESSION['role'] ?? 'admin';

// Statistics
$total_items = $conn->query("SELECT COUNT(*) AS cnt FROM items")->fetch_assoc()['cnt'];
$in_transit  = $conn->query("SELECT COUNT(*) AS cnt FROM items WHERE status = 'in_transit'")->fetch_assoc()['cnt'];
$delivered   = $conn->query("SELECT COUNT(*) AS cnt FROM items WHERE status = 'delivered'")->fetch_assoc()['cnt'];
$in_stock    = $conn->query("SELECT COUNT(*) AS cnt FROM items WHERE status = 'in_stock'")->fetch_assoc()['cnt'];
$total_users = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'user'")->fetch_assoc()['cnt'];

// Recent Items
$items_stmt = $conn->prepare("
    SELECT i.item_code, i.item_name, i.status, i.id 
    FROM items i 
    ORDER BY i.created_at DESC 
    LIMIT 10
");
$items_stmt->execute();
$items_result = $items_stmt->get_result();

// Recent Users
$users_stmt = $conn->prepare("
    SELECT id, username, full_name, created_at 
    FROM users 
    WHERE role = 'user' 
    ORDER BY created_at DESC 
    LIMIT 5
");
$users_stmt->execute();
$recent_users = $users_stmt->get_result();

// Notifications
$notif_count = getNotificationCount($conn, $user_id);
$notif_stmt = $conn->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$notif_stmt->bind_param('i', $user_id);
$notif_stmt->execute();
$notifications = $notif_stmt->get_result();

// Auto-generate next item code
$next_code_result = $conn->query("SELECT item_code FROM items WHERE item_code LIKE 'SUP%' ORDER BY item_code DESC LIMIT 1");
$next_code = 'SUP001';
if ($next_code_result->num_rows > 0) {
    $last = $next_code_result->fetch_assoc()['item_code'];
    $num = (int)substr($last, 3);
    $next_code = 'SUP' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
}
$users_result = $conn->query("SELECT id, full_name, region FROM users WHERE role = 'user' AND region IS NOT NULL ORDER BY full_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Inventory System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3f37c9;
            --success: #06d6a0;
            --warning: #ffd166;
            --danger: #ef476f;
            --info: #118ab2;
            --light: #f8f9fa;
            --dark: #212529;
            --gradient-bg: linear-gradient(135deg, #f0f4f8, #e2e8f0);
        }

        * { box-sizing: border-box; }

        body {
            background: var(--gradient-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            margin: 0;
        }

        /* NAVBAR - CLEAN, FIXED, PROFESSIONAL */
        .navbar {
            background: var(--primary) !important;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.25);
            padding: 0.75rem 0;
            position: sticky;
            top: 0;
            z-index: 1050;
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.45rem;
            letter-spacing: 0.6px;
            color: white !important;
            display: flex;
            align-items: center;
        }

        .nav-link {
            font-weight: 500;
            color: rgba(255,255,255,0.9) !important;
            padding: 0.5rem 1rem !important;
            border-radius: 10px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-link:hover,
        .nav-link.active {
            background: rgba(255,255,255,0.18);
            color: white !important;
        }

        .nav-link i {
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }

        /* RIGHT NAVBAR: BELL + PROFILE */
        .navbar-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .notification-bell {
            position: relative;
            color: white;
            font-size: 1.35rem;
            padding: 0.5rem;
            border-radius: 50%;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .notification-bell:hover {
            background: rgba(255,255,255,0.2);
        }

        .notification-badge {
            position: absolute;
            top: 6px;
            right: 6px;
            font-size: 0.65rem;
            min-width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
        }

        /* PROFILE DROPDOWN */
        .profile-dropdown {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            color: white;
            text-decoration: none;
            padding: 0.5rem 0.8rem;
            border-radius: 14px;
            transition: background 0.2s;
            min-width: 190px;
            cursor: pointer;
        }

        .profile-dropdown:hover {
            background: rgba(255,255,255,0.18);
        }

        .profile-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(45deg, #FFD700, #FFA500);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #000;
            font-weight: bold;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .profile-info {
            display: flex;
            flex-direction: column;
            line-height: 1.3;
            overflow: hidden;
        }

        .profile-name {
            font-weight: 600;
            font-size: 0.95rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 130px;
        }

        .profile-role {
            font-size: 0.7rem;
            background: rgba(255,255,255,0.3);
            color: white;
            padding: 0.2rem 0.55rem;
            border-radius: 50px;
            font-weight: 500;
            display: inline-block;
        }

        .dropdown-menu {
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.18);
            border-radius: 14px;
            padding: 0.5rem 0;
            min-width: 180px;
            margin-top: 0.5rem;
        }

        .dropdown-item {
            padding: 0.6rem 1.2rem;
            border-radius: 10px;
            margin: 0 0.5rem;
            font-size: 0.9rem;
            transition: background 0.2s;
        }

        .dropdown-item:hover {
            background: #f1f3f5;
        }

        /* Mobile Fix */
        @media (max-width: 768px) {
            .profile-info { display: none; }
            .profile-avatar { width: 36px; height: 36px; font-size: 1rem; }
            .navbar-right { gap: 0.75rem; }
            .navbar-brand { font-size: 1.25rem; }
        }

        /* CARDS & LAYOUT */
        .stats-card {
            border-radius: 18px;
            border: none;
            color: white;
            transition: all 0.3s ease;
            overflow: hidden;
            position: relative;
        }

        .stats-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.12), transparent);
            pointer-events: none;
        }

        .stats-card:hover {
            transform: translateY(-10px) scale(1.03);
            box-shadow: 0 15px 30px rgba(0,0,0,0.18);
        }

        .stats-card .card-body {
            padding: 1.7rem;
            text-align: center;
        }

        .stats-card i {
            font-size: 2.3rem;
            opacity: 0.9;
            margin-bottom: 0.8rem;
        }

        .stats-card h4 {
            font-size: 1.9rem;
            font-weight: 700;
            margin: 0;
        }

        .card {
            border-radius: 18px;
            border: none;
            box-shadow: 0 5px 18px rgba(0,0,0,0.09);
            overflow: hidden;
        }

        .card-header {
            background: transparent;
            border-bottom: 1px solid #e9ecef;
            padding: 1.1rem 1.6rem;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .table th {
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .badge-status {
            font-size: 0.75rem;
            padding: 0.4em 0.85em;
            border-radius: 50px;
            font-weight: 500;
        }

        .welcome-section {
            background: white;
            border-radius: 18px;
            padding: 1.6rem;
            box-shadow: 0 5px 18px rgba(0,0,0,0.07);
            margin-bottom: 1.6rem;
        }

        .welcome-actions .btn {
            border-radius: 14px;
            font-weight: 500;
            padding: 0.65rem 1.3rem;
            box-shadow: 0 3px 8px rgba(0,0,0,0.12);
        }

        .item-code-box {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 14px;
            padding: 0.8rem 1.3rem;
            font-family: 'Courier New', monospace;
            font-size: 1.35rem;
            font-weight: bold;
            color: var(--primary);
            display: inline-block;
        }

        .eta-preview {
            font-size: 0.9rem;
            color: #2c3e50;
            font-weight: 500;
            padding: 0.5rem 0;
        }

        .list-group-item {
            border: none;
            border-bottom: 1px solid #eee;
            padding: 0.9rem 1.6rem;
            transition: background 0.2s;
        }

        .list-group-item:hover {
            background: #f8f9fa;
        }

        .list-group-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>

<!-- PERFECT NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container-fluid">
        <!-- Brand -->
        <a class="navbar-brand" href="dashboard.php">
            <i class="fas fa-boxes"></i> Inventory System
        </a>

        <!-- Toggler -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Menu -->
        <div class="collapse navbar-collapse" id="navbarNav">
            <!-- Left -->
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link active" href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="items.php">
                        <i class="fas fa-list"></i> All Items
                    </a>
                </li>
            </ul>

            <!-- Right: Bell + Profile -->
            <div class="navbar-right">
                <!-- Notifications -->
                <a href="notifications.php" class="notification-bell">
                    <i class="fas fa-bell"></i>
                    <?php if ($notif_count > 0): ?>
                        <span class="badge rounded-pill bg-danger notification-badge">
                            <?= $notif_count ?>
                        </span>
                    <?php endif; ?>
                </a>

                <!-- Profile -->
                <div class="dropdown">
                    <a href="#" class="profile-dropdown dropdown-toggle" data-bs-toggle="dropdown">
                        <div class="profile-avatar">
                            <?= strtoupper(substr($full_name, 0, 1)) ?>
                        </div>
                        <div class="profile-info">
                            <div class="profile-name"><?= $full_name ?></div>
                            <div class="profile-role">Admin</div>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item text-danger" href="logout.php">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid py-4 px-3 px-md-4">
    <!-- Welcome + Reports -->
    <div class="welcome-section mb-4">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2 class="mb-1 fw-bold text-primary">Welcome back, <?= $full_name ?>!</h2>
                <p class="text-muted mb-0">Here's what's happening with your inventory today.</p>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <small class="text-muted d-block mb-2"><?= date('l, F j, Y') ?></small>
                <a href="reports.php" class="btn btn-outline-info welcome-actions">
                    <i class="fas fa-chart-bar me-2"></i> View Reports
                </a>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card stats-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <div class="card-body">
                    <i class="fas fa-box"></i>
                    <h4><?= $total_items ?></h4>
                    <small>Total Items</small>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <div class="card-body">
                    <i class="fas fa-truck"></i>
                    <h4><?= $in_transit ?></h4>
                    <small>In Transit</small>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                <div class="card-body">
                    <i class="fas fa-warehouse"></i>
                    <h4><?= $in_stock ?></h4>
                    <small>In Stock</small>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card stats-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                <div class="card-body">
                    <i class="fas fa-check-circle"></i>
                    <h4><?= $delivered ?></h4>
                    <small>Delivered</small>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card stats-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                <div class="card-body">
                    <i class="fas fa-users"></i>
                    <h4><?= $total_users ?></h4>
                    <small>Total Users</small>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="card stats-card" style="background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #333;">
                <div class="card-body">
                    <i class="fas fa-bell"></i>
                    <h4><?= $notif_count ?></h4>
                    <small>Notifications</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="row g-3">
        <!-- Recent Items -->
        <div class="col-xl-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Recent Items</h5>
                    <div>
                        <button class="btn btn-sm btn-primary me-1" data-bs-toggle="modal" data-bs-target="#addItemModal">
                            <i class="fas fa-plus"></i> Add
                        </button>
                        <a href="items.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($items_result->num_rows > 0): ?>
                                    <?php while ($item = $items_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?= h($item['item_code']) ?></strong></td>
                                        <td><?= h($item['item_name']) ?></td>
                                        <td>
                                            <?php
                                            $status_map = [
                                                'in_stock'    => ['info', 'In Stock'],
                                                'in_transit'  => ['warning', 'In Transit'],
                                                'delivered'   => ['success', 'Delivered']
                                            ];
                                            $s = $status_map[$item['status']] ?? ['secondary', 'Unknown'];
                                            ?>
                                            <span class="badge badge-status bg-<?= $s[0] ?>"><?= $s[1] ?></span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="track_item.php?id=<?= $item['id'] ?>" class="btn btn-outline-primary">Track</a>
                                                <a href="edit_item.php?id=<?= $item['id'] ?>" class="btn btn-outline-warning">Edit</a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted">No items found</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column -->
        <div class="col-xl-6">
            <div class="row g-3">
                <!-- Recent Users -->
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Recent Users</h5>
                            <a href="users.php" class="btn btn-sm btn-outline-success">View All</a>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php if ($recent_users->num_rows > 0): ?>
                                    <?php while ($user = $recent_users->fetch_assoc()): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-1"><?= h($user['full_name']) ?></h6>
                                                <small class="text-muted">@<?= h($user['username']) ?></small>
                                            </div>
                                            <small class="text-muted"><?= date('M d', strtotime($user['created_at'])) ?></small>
                                        </div>
                                    </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div class="text-center py-4 text-muted">No users</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notifications -->
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Notifications</h5>
                            <a href="notifications.php" class="btn btn-sm btn-outline-warning">View All</a>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php if ($notifications->num_rows > 0): ?>
                                    <?php while ($n = $notifications->fetch_assoc()): ?>
                                    <a href="notifications.php" class="list-group-item list-group-item-action <?= !$n['is_read'] ? 'bg-light fw-semibold' : '' ?>">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <p class="mb-1 flex-grow-1"><?= h($n['message']) ?></p>
                                            <small class="text-muted text-nowrap ms-2">
                                                <?= date('M d, h:i A', strtotime($n['created_at'])) ?>
                                            </small>
                                        </div>
                                    </a>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div class="text-center py-4 text-muted">No notifications</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ADD ITEM MODAL -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="addItemForm" method="POST">
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
                            <select name="user_id" id="userSelect" class="form-select">
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
                            <div class="eta-preview" id="etaPreview"></div>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ETA Preview
    const userSelect = document.getElementById('userSelect');
    const etaPreview = document.getElementById('etaPreview');
    const etaMap = { 'luzon': '2-3 days', 'visayas': '5 days', 'mindanao': '7 days' };

    function updateETA() {
        const region = userSelect?.selectedOptions[0]?.dataset.region;
        if (region && etaMap[region]) {
            const days = region === 'luzon' ? (Math.random() < 0.5 ? 2 : 3) : (region === 'visayas' ? 5 : 7);
            const date = new Date();
            date.setDate(date.getDate() + days);
            etaPreview.innerHTML = `<strong>ETA:</strong> ${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} (${etaMap[region]})`;
        } else {
            etaPreview.innerHTML = '';
        }
    }
    userSelect?.addEventListener('change', updateETA);
    updateETA();

    // AJAX Submit
    document.getElementById('addItemForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        fetch('', { method: 'POST', body: formData })
        .then(res => res.text())
        .then(html => {
            document.documentElement.innerHTML = html;
            bootstrap.Modal.getInstance(document.getElementById('addItemModal'))?.hide();
        })
        .catch(() => alert('Error adding item.'));
    });
</script>

<?php
// Handle POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $item_name = trim($_POST['item_name']);
    $category = trim($_POST['category']);
    $quantity = (int)$_POST['quantity'];
    $price = floatval($_POST['price']);
    $current_location = trim($_POST['current_location']);
    $user_id = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;

    if (empty($item_name) || empty($category) || $quantity < 1 || $price <= 0 || empty($current_location)) {
        echo "<script>alert('Fill all required fields.'); window.location='dashboard.php';</script>";
        exit;
    }

    $item_code = $next_code;
    $check = $conn->prepare("SELECT id FROM items WHERE item_code = ?");
    $check->bind_param("s", $item_code);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo "<script>alert('Code conflict.'); window.location='dashboard.php';</script>";
        exit;
    }

    $status = $user_id ? 'in_transit' : 'in_stock';
    $destination = $estimated_delivery = null;

    if ($user_id) {
        $stmt = $conn->prepare("SELECT region FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
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
    $stmt->bind_param("sssidsssis", $item_code, $item_name, $category, $quantity, $price, $status, $current_location, $destination, $user_id, $estimated_delivery);

    if ($stmt->execute()) {
        $item_id = $conn->insert_id;
        $track = $conn->prepare("INSERT INTO tracking (item_id, location, status, remarks) VALUES (?, ?, ?, ?)");
        $remarks = $user_id ? "Assigned to user in $destination. ETA: " . date('M d, Y', strtotime($estimated_delivery)) : "Added to inventory";
        $track->bind_param("isss", $item_id, $current_location, $status, $remarks);
        $track->execute();

        if ($user_id) {
            $msg = "New item assigned:\n$item_name ($item_code)\nPrice: ₱" . number_format($price, 2) .
                   "\nFrom: $current_location\nETA: " . date('M d, Y', strtotime($estimated_delivery));
            $notif = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $notif->bind_param("is", $user_id, $msg);
            $notif->execute();
        }

        echo "<script>window.location='dashboard.php?success=1';</script>";
    } else {
        echo "<script>alert('Failed to add item.'); window.location='dashboard.php';</script>";
    }
    exit;
}
?>
</body>
</html>