<?php
// config.php – DB + helpers (NO HTML OUTPUT)

declare(strict_types=1);

// ---------------------------------------------------------------------
// 1. Start session (must be first)
session_start();

// ---------------------------------------------------------------------
// 2. Database
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'inventory_system');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    // In production log instead of die()
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Manila');

// ---------------------------------------------------------------------
// 3. Helper functions (pass $conn where needed)

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function isAdmin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

function isUser(): bool {
    return ($_SESSION['role'] ?? '') === 'user';
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        header('Location: user_dashboard.php'); // Redirect users to their dashboard
        exit;
    }
}

function requireUser(): void {
    requireLogin();
    if (!isUser()) {
        header('Location: dashboard.php'); // Redirect admin to admin dashboard
        exit;
    }
}

/** Redirect to appropriate dashboard based on role */
function redirectToDashboard(): void {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
    
    if (isAdmin()) {
        header('Location: dashboard.php');
    } else {
        header('Location: user_dashboard.php');
    }
    exit;
}

/** Escape for DB (prepared statements already safe, kept for raw queries) */
function db_escape(mysqli $conn, string $value): string {
    return $conn->real_escape_string(trim($value));
}

/** Escape for HTML output */
function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Get unread notification count */
function getNotificationCount(mysqli $conn, int $user_id): int {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)$row['cnt'];
}

/** Add a notification */
function addNotification(mysqli $conn, int $user_id, string $message): void {
    $stmt = $conn->prepare(
        "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())"
    );
    $stmt->bind_param('is', $user_id, $message);
    $stmt->execute();
}

/** Get cart item count for user */
function getCartItemCount(mysqli $conn, int $user_id): int {
    $stmt = $conn->prepare(
        "SELECT SUM(quantity) AS total FROM cart WHERE user_id = ?"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

/** Check if item is available for purchase */
function isItemAvailable(mysqli $conn, int $item_id): bool {
    $stmt = $conn->prepare(
        "SELECT quantity, is_available FROM items WHERE id = ? AND is_available = 1 AND quantity > 0"
    );
    $stmt->bind_param('i', $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

/** Get user's order statistics */
function getUserOrderStats(mysqli $conn, int $user_id): array {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'to_pay' THEN 1 ELSE 0 END) as to_pay,
            SUM(CASE WHEN status = 'to_ship' THEN 1 ELSE 0 END) as to_ship,
            SUM(CASE WHEN status = 'to_receive' THEN 1 ELSE 0 END) as to_receive,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM orders 
        WHERE user_id = ?
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc() ?? [
        'total_orders' => 0,
        'to_pay' => 0,
        'to_ship' => 0,
        'to_receive' => 0,
        'completed' => 0,
        'cancelled' => 0
    ];
}
?>