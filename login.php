<?php
require_once 'config.php';

// ---------------------------------------------------------------------
// Redirect if already logged in
if (isLoggedIn()) {
    // Redirect based on role
    if ($_SESSION['role'] == 'admin') {
        header('Location: dashboard.php');
    } else {
        header('Location: user_dashboard.php');
    }
    exit;
}

$error = '';

// ---------------------------------------------------------------------
// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password']; // raw, will be verified with password_verify

    $stmt = $conn->prepare(
        "SELECT id, username, password, full_name, role 
           FROM users 
          WHERE username = ? 
          LIMIT 1"
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password'])) {
            // ---- SUCCESS ----
            $_SESSION['user_id']   = (int)$user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];

            // Regenerate session ID to prevent fixation
            session_regenerate_id(true);

            // Redirect based on role
            if ($user['role'] == 'admin') {
                header('Location: dashboard.php');
            } else {
                header('Location: user_dashboard.php');
            }
            exit;
        } else {
            $error = 'Invalid password!';
        }
    } else {
        $error = 'Username not found!';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login – ShopStyle</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 2rem;
            text-align: center;
        }
        .login-body {
            padding: 2.5rem;
        }
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px;
            font-weight: 600;
            border-radius: 8px;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .form-control {
            border-radius: 8px;
            padding: 12px;
            border: 2px solid #e9ecef;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .demo-credentials {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
        }
        .role-badge {
            font-size: 0.7rem;
            margin-left: 5px;
        }
    </style>
</head>
<body>

<div class="container login-container">
    <div class="row justify-content-center w-100">
        <div class="col-md-6 col-lg-5">

            <div class="card login-card">
                <div class="login-header">
                    <h2 class="mb-2"><i class="fas fa-shopping-bag"></i> ShopStyle</h2>
                    <p class="mb-0 opacity-75">Sign in to your account</p>
                </div>

                <div class="card-body login-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" novalidate>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Username</label>
                            <input type="text" name="username" class="form-control" 
                                   placeholder="Enter your username" required autofocus>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Password</label>
                            <input type="password" name="password" class="form-control" 
                                   placeholder="Enter your password" required>
                        </div>

                        <button type="submit" class="btn btn-login w-100 mb-4 text-white">
                            Sign In
                        </button>

                        <div class="text-center">
                            <p class="mb-0">Don't have an account? 
                                <a href="register.php" class="text-decoration-none fw-semibold">
                                    Create one here
                                </a>
                            </p>
                        </div>
                    </form>

                    <?php if (defined('DEBUG') && DEBUG): ?>
                        <div class="demo-credentials">
                            <h6 class="fw-semibold mb-2">Demo Credentials:</h6>
                            <div class="row">
                                <div class="col-6">
                                    <small class="text-muted">Admin Account:</small><br>
                                    <code>admin / admin123</code>
                                    <span class="badge bg-warning role-badge">ADMIN</span>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">User Account:</small><br>
                                    <code>user / user123</code>
                                    <span class="badge bg-info role-badge">USER</span>
                                </div>
                            </div>
                            <div class="mt-2 small text-muted">
                                <i class="fas fa-info-circle"></i> You'll be redirected to different dashboards based on your role
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
</body>
</html>