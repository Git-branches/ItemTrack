<?php
require_once 'config.php';
requireAdmin();

$item_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($item_id <= 0) {
    header('Location: items.php?error=Invalid item');
    exit;
}

$success = $error = '';

// Fetch item
$stmt = $conn->prepare("SELECT * FROM items WHERE id = ?");
$stmt->bind_param("i", $item_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: items.php?error=Item not found');
    exit;
}

$item = $result->fetch_assoc();

// Fetch users
$users_result = $conn->query("SELECT id, full_name FROM users WHERE role = 'user' ORDER BY full_name");

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_code        = trim($_POST['item_code'] ?? '');
    $item_name        = trim($_POST['item_name'] ?? '');
    $category         = trim($_POST['category'] ?? '');
    $quantity         = (int)($_POST['quantity'] ?? 0);
    $status           = $_POST['status'] ?? '';
    $current_location = trim($_POST['current_location'] ?? '');
    $destination      = trim($_POST['destination'] ?? '');
    $user_id          = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
    $estimated_delivery = !empty($_POST['estimated_delivery']) ? $_POST['estimated_delivery'] : null;

    // Validate
    if (empty($item_code) || empty($item_name) || empty($category) || $quantity < 1 || empty($current_location)) {
        $error = 'Fill all required fields.';
    } elseif (!in_array($status, ['in_stock', 'in_transit', 'delivered'], true)) {
        $error = 'Invalid status.';
    } else {
        // Check duplicate code
        $check = $conn->prepare("SELECT id FROM items WHERE item_code = ? AND id != ?");
        $check->bind_param("si", $item_code, $item_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = 'Item code already exists.';
        } else {
            $stmt = $conn->prepare("
                UPDATE items 
                SET item_code = ?, item_name = ?, category = ?, quantity = ?, 
                    status = ?, current_location = ?, destination = ?, 
                    user_id = ?, estimated_delivery = ? 
                WHERE id = ?
            ");
            $stmt->bind_param(
                "sssisssisi",
                $item_code, $item_name, $category, $quantity,
                $status, $current_location, $destination,
                $user_id, $estimated_delivery, $item_id
            );

            if ($stmt->execute()) {
                $success = 'Item updated!';
                $stmt = $conn->prepare("SELECT * FROM items WHERE id = ?");
                $stmt->bind_param("i", $item_id);
                $stmt->execute();
                $item = $stmt->get_result()->fetch_assoc();
            } else {
                $error = 'Update failed.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit #<?= h($item['item_code']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="dashboard.php">Inventory System</a>
        <div class="collapse navbar-collapse">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="items.php">Items</a></li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item"><a class="nav-link" href="logout.php">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-warning text-dark">
                    <h4 class="mb-0">Edit Item</h4>
                </div>
                <div class="card-body">

                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= $success ?></div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= h($error) ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <!-- NO CSRF TOKEN HERE -->
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label>Item Code *</label>
                                <input type="text" name="item_code" class="form-control" value="<?= h($item['item_code']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label>Item Name *</label>
                                <input type="text" name="item_name" class="form-control" value="<?= h($item['item_name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label>Category *</label>
                                <select name="category" class="form-select" required>
                                    <option value="">Select</option>
                                    <?php foreach (['Electronics','Furniture','Office Supplies','Equipment','Others'] as $c): ?>
                                        <option value="<?= $c ?>" <?= $item['category'] === $c ? 'selected' : '' ?>><?= $c ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label>Quantity *</label>
                                <input type="number" name="quantity" class="form-control" min="1" value="<?= $item['quantity'] ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label>Status *</label>
                                <select name="status" class="form-select" required>
                                    <option value="in_stock"    <?= $item['status'] === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                                    <option value="in_transit"  <?= $item['status'] === 'in_transit' ? 'selected' : '' ?>>In Transit</option>
                                    <option value="delivered"   <?= $item['status'] === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label>Current Location *</label>
                                <input type="text" name="current_location" class="form-control" value="<?= h($item['current_location']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label>Destination</label>
                                <input type="text" name="destination" class="form-control" value="<?= h($item['destination']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label>Assign to User</label>
                                <select name="user_id" class="form-select">
                                    <option value="">None</option>
                                    <?php while ($u = $users_result->fetch_assoc()): ?>
                                        <option value="<?= $u['id'] ?>" <?= $item['user_id'] == $u['id'] ? 'selected' : '' ?>>
                                            <?= h($u['full_name']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label>Estimated Delivery</label>
                                <input type="datetime-local" name="estimated_delivery" class="form-control"
                                       value="<?= $item['estimated_delivery'] && $item['estimated_delivery'] !== '0000-00-00 00:00:00' ? date('Y-m-d\TH:i', strtotime($item['estimated_delivery'])) : '' ?>">
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <a href="items.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-warning text-dark">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>