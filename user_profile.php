<?php
require_once 'config.php';
requireUser(); // Only regular users can access this page

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Get user details
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Get user statistics
$order_stats = getUserOrderStats($conn, $user_id);
$cart_count = getCartItemCount($conn, $user_id);

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $full_name = $conn->real_escape_string(trim($_POST['full_name']));
    $email = $conn->real_escape_string(trim($_POST['email']));
    $phone = $conn->real_escape_string(trim($_POST['phone']));
    $address = $conn->real_escape_string(trim($_POST['address']));
    $city = $conn->real_escape_string(trim($_POST['city']));
    
    // Check if email already exists (except current user)
    $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $check->bind_param("si", $email, $user_id);
    $check->execute();
    
    if ($check->get_result()->num_rows > 0) {
        $error = "Email already exists!";
    } else {
        $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, phone=?, address=?, city=? WHERE id=?");
        $stmt->bind_param("sssssi", $full_name, $email, $phone, $address, $city, $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['full_name'] = $full_name;
            $success = "Profile updated successfully!";
            
            // Refresh user data
            $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
        } else {
            $error = "Error updating profile!";
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (password_verify($current_password, $user['password'])) {
        if ($new_password === $confirm_password) {
            if (strlen($new_password) >= 6) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
                $stmt->bind_param("si", $hashed_password, $user_id);
                
                if ($stmt->execute()) {
                    $success = "Password changed successfully!";
                } else {
                    $error = "Error changing password!";
                }
            } else {
                $error = "New password must be at least 6 characters!";
            }
        } else {
            $error = "New passwords do not match!";
        }
    } else {
        $error = "Current password is incorrect!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - ShopStyle</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .profile-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }
        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 500;
            padding: 12px 20px;
        }
        .nav-tabs .nav-link.active {
            color: #667eea;
            border-bottom: 3px solid #667eea;
            background: none;
        }
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 3rem;
            color: white;
        }
        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
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
                        <a class="nav-link" href="user_track.php">
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
                        <a class="nav-link active" href="user_profile.php">
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
        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-4 mb-4">
                <div class="card profile-card">
                    <div class="card-body text-center">
                        <div class="profile-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <h4 class="mb-2"><?php echo h($user['full_name']); ?></h4>
                        <p class="text-muted mb-3">
                            <span class="badge bg-info">
                                <?php echo h(ucfirst($user['role'])); ?> Account
                            </span>
                        </p>
                        <hr>
                        
                        <!-- User Statistics -->
                        <div class="row text-center mt-4">
                            <div class="col-6 mb-3">
                                <div class="stats-card" style="background: #28a745;">
                                    <div class="stats-number"><?php echo $order_stats['total_orders']; ?></div>
                                    <small>Total Orders</small>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="stats-card" style="background: #ffc107; color: #000;">
                                    <div class="stats-number"><?php echo $cart_count; ?></div>
                                    <small>Cart Items</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stats-card" style="background: #17a2b8;">
                                    <div class="stats-number"><?php echo $order_stats['completed']; ?></div>
                                    <small>Completed</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stats-card" style="background: #6c757d;">
                                    <div class="stats-number"><?php echo $order_stats['cancelled']; ?></div>
                                    <small>Cancelled</small>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="text-start">
                            <p class="mb-2">
                                <i class="fas fa-envelope text-primary"></i>
                                <small><?php echo h($user['email']); ?></small>
                            </p>
                            <?php if ($user['phone']): ?>
                            <p class="mb-2">
                                <i class="fas fa-phone text-success"></i>
                                <small><?php echo h($user['phone']); ?></small>
                            </p>
                            <?php endif; ?>
                            <?php if ($user['city']): ?>
                            <p class="mb-2">
                                <i class="fas fa-map-marker-alt text-danger"></i>
                                <small><?php echo h($user['city']); ?></small>
                            </p>
                            <?php endif; ?>
                            <p class="mb-0">
                                <i class="fas fa-calendar text-info"></i>
                                <small>Member since <?php echo date('M Y', strtotime($user['created_at'])); ?></small>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-lg-8">
                <h2 class="mb-4"><i class="fas fa-user-cog"></i> Account Settings</h2>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle"></i> <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle"></i> <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Navigation Tabs -->
                <ul class="nav nav-tabs mb-4" id="profileTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab">
                            <i class="fas fa-user-edit"></i> Profile Information
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab">
                            <i class="fas fa-key"></i> Change Password
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="profileTabsContent">
                    <!-- Profile Information Tab -->
                    <div class="tab-pane fade show active" id="profile" role="tabpanel">
                        <div class="card profile-card">
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="form-section">
                                        <h5 class="mb-4"><i class="fas fa-user-circle"></i> Personal Information</h5>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Username</label>
                                                <input type="text" class="form-control" value="<?php echo h($user['username']); ?>" readonly>
                                                <small class="text-muted">Username cannot be changed</small>
                                            </div>

                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Account Type</label>
                                                <input type="text" class="form-control" value="Shopping Account" readonly>
                                            </div>

                                            <div class="col-md-12 mb-3">
                                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                                <input type="text" name="full_name" class="form-control" value="<?php echo h($user['full_name']); ?>" required>
                                            </div>

                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                                <input type="email" name="email" class="form-control" value="<?php echo h($user['email']); ?>" required>
                                            </div>

                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Phone Number</label>
                                                <input type="text" name="phone" class="form-control" value="<?php echo h($user['phone']); ?>" placeholder="09123456789">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-section">
                                        <h5 class="mb-4"><i class="fas fa-map-marker-alt"></i> Shipping Address</h5>
                                        <div class="row">
                                            <div class="col-12 mb-3">
                                                <label class="form-label">Complete Address</label>
                                                <textarea name="address" class="form-control" rows="3" placeholder="House #, Street, Barangay, City"><?php echo h($user['address']); ?></textarea>
                                            </div>

                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">City</label>
                                                <input type="text" name="city" class="form-control" value="<?php echo h($user['city']); ?>" placeholder="e.g., Manila, Cebu, Davao">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-end">
                                        <button type="submit" name="update_profile" class="btn btn-primary btn-lg">
                                            <i class="fas fa-save"></i> Save Changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Change Password Tab -->
                    <div class="tab-pane fade" id="password" role="tabpanel">
                        <div class="card profile-card">
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="form-section">
                                        <h5 class="mb-4"><i class="fas fa-shield-alt"></i> Security Settings</h5>
                                        
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i> For security reasons, please enter your current password to make changes.
                                        </div>

                                        <div class="row">
                                            <div class="col-md-12 mb-3">
                                                <label class="form-label">Current Password <span class="text-danger">*</span></label>
                                                <input type="password" name="current_password" class="form-control" required>
                                            </div>

                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">New Password <span class="text-danger">*</span></label>
                                                <input type="password" name="new_password" class="form-control" minlength="6" required>
                                                <small class="text-muted">Minimum 6 characters</small>
                                            </div>

                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                                                <input type="password" name="confirm_password" class="form-control" minlength="6" required>
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-end">
                                            <button type="submit" name="change_password" class="btn btn-warning btn-lg">
                                                <i class="fas fa-key"></i> Change Password
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>