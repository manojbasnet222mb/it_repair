<?php
// /htdocs/it_repair/includes/functions.php

/* ========= Output escaping ========= */
function e($value): string {
    if ($value === null) return '';
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ========= CSRF helpers ========= */
function csrf_token(): string {
    if (!isset($_SESSION)) {
        session_start();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_verify(?string $token): bool {
    if (!isset($_SESSION)) {
        session_start();
    }
    if (!$token || !isset($_SESSION['csrf'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf'], $token);
}

/* ========= Redirect helper ========= */
function redirect(string $url, int $status = 302): void {
    if (headers_sent()) {
        echo "<script>location.href='$url';</script>";
        exit;
    }
    header("Location: $url", true, $status);
    exit;
}

/* ========= URL helper ========= */
function base_url(string $path = ''): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = "/it_repair/public/";
    return $scheme . '://' . $host . $base . ltrim($path, '/');
}

/* ========= Ticket code generator (Structured) ========= */
/**
 * Generates a structured ticket code based on date, device type, service type, and an incrementing sequence.
 *
 * Format: YYMMDD + DeviceCode(2) + ServiceCode(1) + SequenceNumber(3)
 * Example: 241029LTPO001
 *
 * @param string $device_type The type of device (e.g., 'Laptop', 'Phone').
 * @param string $service_type The service option (e.g., 'dropoff', 'pickup').
 * @param PDO $pdo The database connection object.
 * @return string The generated ticket code.
 */
function ticket_code(string $device_type, string $service_type, PDO $pdo): string {
    // --- 1. Get Date Component (YYMMDD) ---
    $date_component = date('ymd'); // e.g., 241029

    // --- 2. Get Device Type Component ---
    // Create a mapping for device types to 2-letter codes
    $device_codes = [
        'Laptop' => 'LT',
        'Desktop' => 'DT',
        'Phone' => 'PH',
        'Tablet' => 'TB',
        'Printer' => 'PR',
        'Peripheral' => 'PE',
        'Other' => 'OT'
        // Add more mappings if your application introduces new device types
        // Ensure these codes are unique and match the options in new.php
    ];
    // Use the provided device type to get the code, default to 'XX' if not found
    $device_component = $device_codes[$device_type] ?? 'XX';

    // --- 3. Get Service Type Component ---
    // Create a mapping for service types to single letters
    $service_codes = [
        'dropoff' => 'D',
        'pickup' => 'P',
        'onsite' => 'O'
    ];
    // Use the provided service type to get the code, default to 'X' if not found
    $service_component = $service_codes[$service_type] ?? 'X';

    // --- 4. Get Incremental Sequence Number ---
    // Create a unique key for the sequence based on date, device, and service
    $sequence_key = "ticket_seq_{$date_component}_{$device_component}_{$service_component}";

    // --- 5. Atomically Get and Increment the Sequence Number ---
    $sequence_number = 1; // Default value
    try {
        // Start a dedicated transaction for sequence update
        $pdo->beginTransaction();

        // Lock the specific row for update to prevent race conditions
        $stmt = $pdo->prepare("SELECT next_value FROM ticket_sequences WHERE seq_key = :seq_key FOR UPDATE");
        $stmt->execute([':seq_key' => $sequence_key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Key exists, use the fetched value and increment it in the database
            $sequence_number = (int)$row['next_value'];
            $new_next_value = $sequence_number + 1;
            $updateStmt = $pdo->prepare("UPDATE ticket_sequences SET next_value = :new_next_value WHERE seq_key = :seq_key");
            $updateStmt->execute([':new_next_value' => $new_next_value, ':seq_key' => $sequence_key]);
        } else {
            // Key does not exist, insert it with next_value = 2 (since we are using 1 now)
            $insertStmt = $pdo->prepare("INSERT INTO ticket_sequences (seq_key, next_value) VALUES (:seq_key, 2)");
            $insertStmt->execute([':seq_key' => $sequence_key]);
            // $sequence_number remains 1 for the first ticket of this type combo today
        }

        // Commit the dedicated transaction for sequence update
        $pdo->commit();

    } catch (PDOException $e) {
        // Rollback the dedicated transaction on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error generating structured ticket sequence: " . $e->getMessage());
        // Fallback to random suffix on DB error
        $sequence_component = strtoupper(bin2hex(random_bytes(2)));
        return $date_component . $device_component . $service_component . $sequence_component;
    }

    // --- 6. Format Sequence Number ---
    $sequence_component = str_pad((string)$sequence_number, 3, '0', STR_PAD_LEFT);

    // --- 7. Combine Components ---
    return $date_component . $device_component . $service_component . $sequence_component;
}


/* ========= Simple file logger ========= */
function app_log(string $level, string $msg, array $context = []): void {
    $dir = __DIR__ . '/../storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $timestamp = date('c');
    $line = "[$timestamp] [$level] $msg";
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $line .= PHP_EOL;
    $file = $dir . '/app.log';
    $result = @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    // Optional: chmod on first write
    if (!file_exists($file)) {
        @chmod($file, 0664);
    }
}

/* ========= Optional: Debug helper (remove in production) ========= */
if (file_exists(__DIR__ . '/../.env') && strpos(file_get_contents(__DIR__ . '/../.env'), 'APP_DEBUG=true') !== false) {
    function dd(...$vars): void {
        echo '<pre style="background:#1e1e1e;color:#dcdcdc;padding:1rem;overflow:auto;border-radius:8px;margin:1rem;">';
        foreach ($vars as $var) {
            var_dump($var);
        }
        echo '</pre>';
        exit;
    }
}
?>