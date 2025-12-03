<?php
require_once 'config.php';

$success = '';
$error = '';

// Handle registration
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $region = $_POST['region'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Validate
    if (empty($full_name) || empty($username) || empty($email) || empty($phone) || empty($address) || empty($region) || empty($password)) {
        $error = "All fields are required.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $error = "Username: 3-20 characters, letters, numbers, underscore only.";
    } elseif (!preg_match('/^09[0-9]{9}$/', $phone)) {
        $error = "Phone must be 11 digits starting with 09.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        // Check duplicates
        $check_email = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check_email->bind_param("s", $email);
        $check_email->execute();
        if ($check_email->get_result()->num_rows > 0) {
            $error = "Email already registered.";
        } else {
            $check_username = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $check_username->bind_param("s", $username);
            $check_username->execute();
            if ($check_username->get_result()->num_rows > 0) {
                $error = "Username already taken.";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                // Insert with username
                $stmt = $conn->prepare("
                    INSERT INTO users 
                    (full_name, username, email, phone, address, region, password, role) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'user')
                ");
                $stmt->bind_param("sssssss", $full_name, $username, $email, $phone, $address, $region, $hashed_password);

                if ($stmt->execute()) {
                    $success = "Registration successful! You can now log in.";
                } else {
                    $error = "Registration failed. Please try again.";
                }
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
    <title>Register - Supplier System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 20px 0;
        }
        .register-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 1.5rem;
            text-align: center;
        }
        .card-header h3 {
            margin: 0;
            font-weight: 700;
            font-size: 1.5rem;
        }
        .card-body {
            padding: 1.5rem;
        }
        .form-control, .form-select {
            border-radius: 8px;
            padding: 10px 12px;
            border: 1.5px solid #e0e0e0;
            font-size: 0.9rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-register {
            border-radius: 8px;
            padding: 10px;
            font-weight: 600;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            font-size: 0.9rem;
        }
        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        small {
            font-size: 0.75rem;
        }
        .region-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-top: 5px;
        }
        .luzon { background: #fff3cd; color: #856404; }
        .visayas { background: #d4edda; color: #155724; }
        .mindanao { background: #cce5ff; color: #004085; }
    </style>
</head>
<body>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card register-card">
                    <div class="card-header">
                        <h3>Create Account</h3>
                        <p class="mb-0 mt-1" style="font-size: 0.9rem;">Join our supplier network</p>
                    </div>
                    <div class="card-body">

                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show py-2">
                                <small><?php echo $success; ?></small>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show py-2">
                                <small><?php echo $error; ?></small>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" name="full_name" class="form-control" required placeholder="Juan Dela Cruz">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Username</label>
                                    <input type="text" name="username" class="form-control" required 
                                           placeholder="juandelacruz123" minlength="3" maxlength="20">
                                    <small class="text-muted">3-20 chars, letters, numbers, _</small>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" required placeholder="juan@example.com">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text" name="phone" class="form-control" required 
                                           placeholder="09123456789" maxlength="11">
                                    <small class="text-muted">11 digits, starts with 09</small>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Region</label>
                                    <select name="region" class="form-select" required>
                                        <option value="">Select Region</option>
                                        <option value="luzon">Luzon</option>
                                        <option value="visayas">Visayas</option>
                                        <option value="mindanao">Mindanao</option>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Complete Address</label>
                                    <textarea name="address" class="form-control" rows="2" required 
                                              placeholder="House #, Street, Barangay, City, Province"></textarea>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Password</label>
                                    <input type="password" name="password" class="form-control" required minlength="6">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Confirm Password</label>
                                    <input type="password" name="confirm_password" class="form-control" required>
                                </div>
                            </div>

                            <div class="d-grid mt-3">
                                <button type="submit" class="btn btn-primary btn-register">
                                    Register Account
                                </button>
                            </div>

                            <div class="text-center mt-2">
                                <p class="mb-0" style="font-size: 0.85rem;">
                                    Already have an account? 
                                    <a href="login.php" class="text-decoration-none fw-bold">Login here</a>
                                </p>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Real-time region badge
        document.querySelector('[name="region"]').addEventListener('change', function() {
            const badgeContainer = this.parentElement.querySelector('.region-badge');
            if (badgeContainer) badgeContainer.remove();

            if (this.value) {
                const badge = document.createElement('span');
                badge.className = `region-badge ${this.value}`;
                badge.textContent = this.options[this.selectedIndex].text;
                this.parentElement.appendChild(badge);
            }
        });

        // Username validation
        document.querySelector('[name="username"]').addEventListener('input', function() {
            this.value = this.value.replace(/[^a-zA-Z0-9_]/g, '');
        });

        // Phone validation
        document.querySelector('[name="phone"]').addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '').substring(0, 11);
            if (this.value.length > 0 && !this.value.startsWith('09')) {
                this.setCustomValidity('Must start with 09');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>