<?php
// /htdocs/it_repair/includes/auth.php
require_once __DIR__ . '/../config/db.php';

/* ===== Brute-force Protection Constants ===== */
// These could also be defined in a config file
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes in seconds

/* ===== User lookup ===== */
function auth_find_user_by_email(string $email) {
    // Basic email validation before DB query
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch();
}

/* ===== Registration (customer) ===== */
function auth_register_customer(array $data, array &$errors): bool {
    // --- Input Sanitization & Validation ---
    $name = trim($data['name'] ?? '');
    $email = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $phone = trim($data['phone'] ?? '');
    $password = $data['password'] ?? '';
    $password2 = $data['password2'] ?? '';

    // --- Validation Rules ---
    if (empty($name)) {
        $errors['name'] = 'Name is required.';
    } elseif (strlen($name) > 120) {
        $errors['name'] = 'Name must not exceed 120 characters.';
    } elseif (preg_match('/[\x00-\x1F\x7F]/', $name)) { // Control characters
        $errors['name'] = 'Name contains invalid characters.';
    }

    if (!$email) {
        $errors['email'] = 'A valid email address is required.';
    } elseif (strlen($email) > 254) { // RFC 5321 limit
        $errors['email'] = 'Email address is too long.';
    }

    // Phone validation (more robust)
    if ($phone !== '') {
        if (!preg_match('/^[\+]?[\d\s\-\(\)]+$/', $phone)) {
            $errors['phone'] = 'Phone number contains invalid characters.';
        } else {
            $digitCount = preg_match_all('/\d/', $phone);
            if ($digitCount < 7 || $digitCount > 15) {
                $errors['phone'] = 'Phone number must contain between 7 and 15 digits.';
            } elseif(strlen($phone) > 30) {
                 $errors['phone'] = 'Phone number is too long.';
            }
        }
    }

    if (strlen($password) < 6) {
        $errors['password'] = 'Password must be at least 6 characters long.';
    } elseif ($password !== $password2) {
        $errors['password2'] = 'Passwords do not match.';
    }
    // You could add more password strength rules here if needed.

    if ($errors) {
        return false; // Stop if initial validation fails
    }

    // --- Business Logic Validation ---
    if (auth_find_user_by_email($email)) {
        $errors['email'] = 'This email is already registered.';
        return false;
    }

    // --- Data Processing & Storage ---
    $hash = password_hash($password, PASSWORD_DEFAULT);
    // Sanitize name again before storing
    $cleanName = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

    try {
        $stmt = db()->prepare("INSERT INTO users (role, name, email, phone, password_hash) VALUES ('customer', ?, ?, ?, ?)");
        $result = $stmt->execute([$cleanName, $email, $phone !== '' ? $phone : null, $hash]);
        return $result;
    } catch (PDOException $e) {
        // Log the actual error for debugging (don't show to user)
        error_log("Registration DB error: " . $e->getMessage());
        // Provide a generic error message to the user
        $errors['general'] = 'An error occurred during registration. Please try again.';
        return false;
    }
}

/* ===== Brute-force Protection Helpers ===== */
function auth_get_login_attempts(string $identifier): int {
    // Use email as the identifier for rate limiting
    $key = 'login_attempts_' . md5(strtolower($identifier)); // Normalize email
    $attempts = $_SESSION[$key]['count'] ?? 0;
    $last_attempt = $_SESSION[$key]['time'] ?? 0;

    // Check if the lockout period has expired
    if (time() - $last_attempt > LOGIN_LOCKOUT_TIME) {
        // Lockout expired, reset attempts
        unset($_SESSION[$key]);
        return 0;
    }

    return (int)$attempts;
}

function auth_increment_login_attempts(string $identifier): void {
    $key = 'login_attempts_' . md5(strtolower($identifier));
    $attempts = $_SESSION[$key]['count'] ?? 0;
    $_SESSION[$key] = [
        'count' => $attempts + 1,
        'time' => time()
    ];
}

function auth_clear_login_attempts(string $identifier): void {
    $key = 'login_attempts_' . md5(strtolower($identifier));
    unset($_SESSION[$key]);
}

/* ===== Attempt login ===== */
function auth_attempt_login(string $email, string $password, array &$errors): ?array {
    $email = trim($email);

    // --- Pre-validation & Brute-force Check ---
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['login'] = 'Invalid email or password.'; // Generic message
        return null;
    }

    $attempts = auth_get_login_attempts($email);
    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $errors['login'] = 'Too many failed attempts. Please try again later.';
        return null;
    }

    // --- Authentication Logic ---
    $user = auth_find_user_by_email($email);
    if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
        auth_increment_login_attempts($email); // Increment on failure
        $errors['login'] = 'Invalid email or password.'; // Generic message
        return null;
    }

    // --- Success ---
    auth_clear_login_attempts($email); // Clear attempts on success
    return $user;
}


/* ===== Login/logout session helpers ===== */
function auth_login(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'role' => $user['role'],
        'name' => $user['name'],
        'email' => $user['email'],
        'phone' => $user['phone'] ?? null,
        'profile_picture' => $user['profile_picture'] ?? null
    ];
    // Clear any lingering login attempt data for this user
    auth_clear_login_attempts($user['email']);
}

function auth_logout(): void {
    // Clear login attempts for the current user if they are logged in
    if (isset($_SESSION['user']['email'])) {
        auth_clear_login_attempts($_SESSION['user']['email']);
    }

    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        // Use httponly flag from params if available, otherwise default to true
        $httponly = $params["httponly"] ?? true;
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $httponly
        );
    }
    session_destroy();
}