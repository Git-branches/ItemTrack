<?php
require_once 'config.php';
requireAdmin();

$success = '';
$error = '';

// Get users with region
$users_result = $conn->query("
    SELECT id, full_name, region 
    FROM users 
    WHERE role = 'user' AND region IS NOT NULL 
    ORDER BY full_name
");

// AUTO-GENERATE ITEM CODE: SUP001, SUP002, ...
$code_result = $conn->query("SELECT item_code FROM items WHERE item_code LIKE 'SUP%' ORDER BY item_code DESC LIMIT 1");
$next_code = 'SUP001';

if ($code_result->num_rows > 0) {
    $last = $code_result->fetch_assoc()['item_code'];
    $num = (int)substr($last, 3); // remove "SUP"
    $next_code = 'SUP' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
}

// Handle form
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $item_name = trim($_POST['item_name']);
    $category = trim($_POST['category']);
    $quantity = (int)$_POST['quantity'];
    $price = floatval($_POST['price']);
    $current_location = trim($_POST['current_location']);
    $user_id = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : NULL;

    // Validate
    if (empty($item_name) || empty($category) || $quantity < 1 || $price <= 0 || empty($current_location)) {
        $error = "Please fill all required fields correctly. Price must be greater than zero.";
    } else {
        // Use auto-generated code
        $item_code = $next_code;

        // Check if code already exists (safety)
        $check = $conn->prepare("SELECT id FROM items WHERE item_code = ?");
        $check->bind_param("s", $item_code);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = "Item code conflict. Please try again.";
        } else {
            // Default values
            $estimated_delivery = NULL;
            $status = 'in_stock';
            $destination = NULL;

            if ($user_id) {
                $user_stmt = $conn->prepare("SELECT region FROM users WHERE id = ?");
                $user_stmt->bind_param("i", $user_id);
                $user_stmt->execute();
                $region_row = $user_stmt->get_result()->fetch_assoc();
                $region = $region_row['region'] ?? 'mindanao';

                $days = match($region) {
                    'luzon' => rand(2, 3),
                    'visayas' => 5,
                    'mindanao' => 7,
                    default => 7
                };

                $estimated_delivery = date('Y-m-d H:i:s', strtotime("+$days days"));
                $status = 'in_transit';
                $destination = ucfirst($region);
            }

            // Insert item
            $stmt = $conn->prepare("
                INSERT INTO items 
                (item_code, item_name, category, quantity, price, status, current_location, destination, user_id, estimated_delivery, is_available)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->bind_param("sssidsssis", $item_code, $item_name, $category, $quantity, $price, $status, $current_location, $destination, $user_id, $estimated_delivery);

            if ($stmt->execute()) {
                $item_id = $conn->insert_id;

                // Tracking
                $track = $conn->prepare("INSERT INTO tracking (item_id, location, status, remarks) VALUES (?, ?, ?, ?)");
                $remarks = $user_id 
                    ? "Assigned to user in $destination. ETA: " . date('M d, Y', strtotime($estimated_delivery))
                    : "Added to inventory by supplier";
                $track->bind_param("isss", $item_id, $current_location, $status, $remarks);
                $track->execute();

                // Notify user
                if ($user_id) {
                    $msg = "New item assigned:\n$item_name ($item_code)\nPrice: ₱" . number_format($price, 2) . 
                           "\nFrom: $current_location\nETA: " . date('M d, Y', strtotime($estimated_delivery));
                    $notif = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                    $notif->bind_param("is", $user_id, $msg);
                    $notif->execute();
                }

                $success = "Item <strong>$item_name</strong> added!<br>Code: <code>$item_code</code><br>Price: <strong>₱" . number_format($price, 2) . "</strong>" . 
                          ($user_id ? "<br>ETA: " . date('M d, Y', strtotime($estimated_delivery)) : "");
                
                // Regenerate next code
                $next_num = (int)substr($item_code, 3) + 1;
                $next_code = 'SUP' . str_pad($next_num, 3, '0', STR_PAD_LEFT);
            } else {
                $error = "Failed to add item.";
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
    <title>Add Product - Supplier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .form-label { font-weight: 600; }
        .required { color: #d63384; }
        .item-code-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 12px 16px;
            font-family: monospace;
            font-size: 1.1rem;
            font-weight: bold;
            color: #495057;
        }
        .eta-preview { font-size: 0.9rem; color: #2c3e50; font-weight: 500; margin-top: 8px; }
    </style>
</head>
<body class="bg-light">

    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php">Supplier Panel</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li><a class="nav-link" href="items.php">Inventory</a></li>
                    <li><a class="nav-link active" href="add_item.php">Add Product</a></li>
                </ul>
                <ul class="navbar-nav">
                    <li><a class="nav-link" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-9">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">Add New Product</h4>
                        <div class="item-code-box"><?php echo $next_code; ?></div>
                    </div>
                    <div class="card-body">

                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <?php echo $success; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <?php echo $error; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" id="addForm">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Item Name <span class="required">*</span></label>
                                    <input type="text" name="item_name" class="form-control" required placeholder="e.g., Wireless Mouse" value="<?= htmlspecialchars($_POST['item_name'] ?? '') ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Category <span class="required">*</span></label>
                                    <select name="category" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <option <?= ($_POST['category'] ?? '') == 'Electronics' ? 'selected' : '' ?>>Electronics</option>
                                        <option <?= ($_POST['category'] ?? '') == 'Furniture' ? 'selected' : '' ?>>Furniture</option>
                                        <option <?= ($_POST['category'] ?? '') == 'Office Supplies' ? 'selected' : '' ?>>Office Supplies</option>
                                        <option <?= ($_POST['category'] ?? '') == 'Equipment' ? 'selected' : '' ?>>Equipment</option>
                                        <option <?= ($_POST['category'] ?? '') == 'Others' ? 'selected' : '' ?>>Others</option>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Quantity <span class="required">*</span></label>
                                    <input type="number" name="quantity" class="form-control" min="1" value="<?= $_POST['quantity'] ?? 1 ?>" required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Price (₱) <span class="required">*</span></label>
                                    <input type="number" name="price" step="0.01" class="form-control" min="0.01" 
                                           value="<?= $_POST['price'] ?? '' ?>" required placeholder="e.g., 1299.99">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Current Location <span class="required">*</span></label>
                                    <input type="text" name="current_location" class="form-control" required 
                                           placeholder="e.g., Davao Warehouse" value="<?= htmlspecialchars($_POST['current_location'] ?? '') ?>">
                                </div>

                                <div class="col-md-8">
                                    <label class="form-label">Assign to User (Optional)</label>
                                    <select name="user_id" id="userSelect" class="form-select">
                                        <option value="">-- None --</option>
                                        <?php 
                                        $users_result->data_seek(0);
                                        while ($user = $users_result->fetch_assoc()): ?>
                                            <option value="<?= $user['id'] ?>" 
                                                    data-region="<?= $user['region'] ?>"
                                                    <?= ($_POST['user_id'] ?? '') == $user['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($user['full_name']) ?> (<?= ucfirst($user['region']) ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <div class="eta-preview" id="etaPreview"></div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2 mt-4">
                                <a href="items.php" class="btn btn-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary">
                                    Add Product
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const userSelect = document.getElementById('userSelect');
        const etaPreview = document.getElementById('etaPreview');

        const etaMap = {
            'luzon': '2-3 days',
            'visayas': '5 days',
            'mindanao': '7 days'
        };

        function updateETA() {
            const option = userSelect.selectedOptions[0];
            const region = option?.dataset.region;
            if (region && etaMap[region]) {
                const days = region === 'luzon' ? (Math.random() < 0.5 ? 2 : 3) : (region === 'visayas' ? 5 : 7);
                const date = new Date();
                date.setDate(date.getDate() + days);
                etaPreview.innerHTML = `<strong>ETA:</strong> ${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} (${etaMap[region]})`;
            } else {
                etaPreview.innerHTML = '';
            }
        }

        userSelect.addEventListener('change', updateETA);
        updateETA(); // on load if pre-selected
    </script>
</body>
</html>