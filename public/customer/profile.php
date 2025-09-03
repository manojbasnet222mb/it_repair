<?php
/**
 * Customer Profile — NexusFix (Ultimate Edition)
 * World-class UX inspired by Apple, Google, and Microsoft.
 * With Profile Picture Support and Enhanced Security.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role('customer');

// Validate session data
if (!isset($_SESSION['user']) || !is_array($_SESSION['user']) || !isset($_SESSION['user']['id'])) {
    app_log('error', "Invalid session data for user profile access.");
    header("Location: " . base_url('login.php'));
    exit;
}

$u = $_SESSION['user'];
$errors = [];
$note = null;

// --- Check required PHP extensions ---
if (!extension_loaded('fileinfo') || !extension_loaded('gd')) {
    app_log('error', "Missing required PHP extensions: fileinfo=" . (extension_loaded('fileinfo') ? 'loaded' : 'missing') . ", gd=" . (extension_loaded('gd') ? 'loaded' : 'missing'));
    $errors['fatal'] = 'Profile picture upload is temporarily unavailable due to server configuration. Please contact support.';
}

// --- File Upload Settings ---
$allowedExt = ['jpg', 'jpeg', 'png', 'gif'];
$maxSize = 2 * 1024 * 1024; // 2MB

// Centralize base path - Use DOCUMENT_ROOT for absolute file paths
// This ensures we correctly build paths for file system operations (saving/deleting)
define('BASE_UPLOAD_PATH', rtrim(realpath($_SERVER['DOCUMENT_ROOT']), DIRECTORY_SEPARATOR));
// This is the path relative to the web server's document root for URL construction
define('WEB_UPLOAD_PATH_RELATIVE', 'it_repair/uploads/profile_pictures');

$uploadDir = BASE_UPLOAD_PATH . DIRECTORY_SEPARATOR . 'it_repair' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile_pictures' . DIRECTORY_SEPARATOR;

// Ensure upload directory exists and is writable
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) || !chmod($uploadDir, 0755)) {
        app_log('error', "Failed to create or set permissions for upload directory: $uploadDir");
        $errors['fatal'] = 'Profile picture upload is currently unavailable. Please contact support.';
        $uploadDir = null;
    }
} elseif (!is_writable($uploadDir)) {
    app_log('error', "Upload directory is not writable: $uploadDir");
    $errors['fatal'] = 'Profile picture upload is currently unavailable. Please contact support.';
    $uploadDir = null;
}

// Check temporary directory
$tmpDir = sys_get_temp_dir();
if (!is_writable($tmpDir)) {
    app_log('error', "PHP temporary directory is not writable: $tmpDir");
    $errors['fatal'] = 'File upload failed: server temporary directory is not writable. Please contact support.';
    $uploadDir = null;
}

$old = [
    'name' => e($u['name']),
    'email' => e($u['email']),
    'phone' => e($u['phone'] ?? '')
];

// Handle Profile Picture Deletion
if (isset($_POST['delete_picture'])) {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $errors['csrf'] = 'Your session has expired. Please refresh the page and try again.';
    } else {
        $oldPicturePath = $u['profile_picture'] ?? null;

        // Delete the physical file if it exists
        if ($oldPicturePath) {
            // Construct the full file path on the server
            $oldPictureFullPath = BASE_UPLOAD_PATH . DIRECTORY_SEPARATOR . $oldPicturePath;
            app_log('debug', "Attempting to delete old picture at: " . $oldPictureFullPath);
            if (file_exists($oldPictureFullPath) && is_file($oldPictureFullPath)) {
                if (unlink($oldPictureFullPath)) {
                    app_log('debug', "Old profile picture deleted: $oldPictureFullPath");
                } else {
                    app_log('warning', "Failed to delete old profile picture file: $oldPictureFullPath");
                    // Note: We don't stop the process if file deletion fails, we still update the DB
                }
            } else {
                 app_log('debug', "Old profile picture file not found for deletion: $oldPictureFullPath");
            }
        }

        try {
            $stmt_delete_pic = db()->prepare("UPDATE users SET profile_picture = NULL WHERE id = ?");
            $stmt_delete_pic->execute([$u['id']]);
            $_SESSION['user']['profile_picture'] = null;
            // Refresh local $u variable
            $u['profile_picture'] = null;
            $note = 'Profile picture deleted successfully.';
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Refresh CSRF token
        } catch (PDOException $e) {
            app_log('error', "Database error deleting profile picture: " . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $errors['fatal'] = 'Failed to delete profile picture. Please contact support.';
        }
    }
}

// Handle Form Submission (Profile Update + Picture Upload)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_picture'])) {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $errors['csrf'] = 'Your session has expired. Please refresh the page and try again.';
    }

    $data = [
        'name' => trim($_POST['name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? '')
    ];
    $old = array_merge($old, array_map('e', $data));

    if (!$data['name']) $errors['name'] = 'Name is required.';
    if (strlen($data['name']) > 120) $errors['name'] = 'Name must not exceed 120 characters.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    // --- Improved Phone Number Validation ---
    if ($data['phone'] !== '') {
        // Allow common formatting characters like spaces, dashes, parentheses, but require 7-15 actual digits
        if (!preg_match('/^[\+]?[\d\s\-\(\)]+$/', $data['phone'])) {
            $errors['phone'] = 'Phone number contains invalid characters.';
        } else {
            // Count actual digits
            $digitCount = preg_match_all('/\d/', $data['phone']);
            if ($digitCount < 7 || $digitCount > 15) {
                $errors['phone'] = 'Phone number must contain between 7 and 15 digits.';
            }
        }
    }
    // Enforce max length even for formatted numbers
    if (strlen($data['phone']) > 30) {
        $errors['phone'] = 'Phone number must not exceed 30 characters.';
    }
    // --- End of Phone Validation ---

    if (!$errors) {
        try {
            $stmt_check_email = db()->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt_check_email->execute([$data['email'], $u['id']]);
            if ($stmt_check_email->fetch()) {
                $errors['email'] = 'This email is already in use by another account.';
            }
        } catch (PDOException $e) {
            app_log('error', "Database error checking email uniqueness: " . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $errors['fatal'] = 'Database error occurred. Please contact support.';
        }
    }

    // Handle Profile Picture Upload
    $profilePicturePath = $u['profile_picture'] ?? null; // Start with current path
    if ($uploadDir && !$errors && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['profile_picture'];
        $fileName = $file['name'];
        $fileSize = $file['size'];
        $fileTmpName = $file['tmp_name'];
        $fileError = $file['error'];

        if ($fileSize > $maxSize) {
            $errors['profile_picture'] = 'File size too large. Maximum size is 2MB.';
        } elseif ($fileError !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit.',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form size limit (2MB).',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Temporary directory missing.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.'
            ];
            $errors['profile_picture'] = $errorMessages[$fileError] ?? 'Error uploading file. Please try again.';
        } else {
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (!in_array($fileExt, $allowedExt)) {
                $errors['profile_picture'] = 'Invalid file type. Only JPG, JPEG, PNG, and GIF are allowed.';
            } else {
                $fileType = mime_content_type($fileTmpName);
                if (!in_array($fileType, ['image/jpeg', 'image/png', 'image/gif'])) {
                    $errors['profile_picture'] = 'Invalid file type. Only JPG, PNG, and GIF images are allowed.';
                } elseif (!($imageInfo = getimagesize($fileTmpName))) {
                    $errors['profile_picture'] = 'Uploaded file is not a valid image.';
                } else {
                    $newFileName = uniqid('profile_', true) . '.' . $fileExt;
                    $destination = $uploadDir . $newFileName;

                    // Debug: Log the destination path
                    app_log('debug', "Attempting to save image to: " . $destination);

                    try {
                        $success = false;
                        switch ($fileType) {
                            case 'image/jpeg':
                                $image = @imagecreatefromjpeg($fileTmpName);
                                if ($image !== false) {
                                    $success = @imagejpeg($image, $destination, 90);
                                    imagedestroy($image);
                                    if (!$success) {
                                        throw new Exception("Failed to save JPEG image to $destination.");
                                    }
                                } else {
                                    throw new Exception("Failed to create image resource from JPEG.");
                                }
                                break;
                            case 'image/png':
                                $image = @imagecreatefrompng($fileTmpName);
                                if ($image !== false) {
                                    // Use compression level 6 (0-9, 9 is highest compression/slowest)
                                    $success = @imagepng($image, $destination, 6);
                                    imagedestroy($image);
                                    if (!$success) {
                                        throw new Exception("Failed to save PNG image to $destination.");
                                    }
                                } else {
                                    throw new Exception("Failed to create image resource from PNG.");
                                }
                                break;
                            case 'image/gif':
                                $success = @copy($fileTmpName, $destination);
                                if (!$success) {
                                    throw new Exception("Failed to copy GIF image to $destination.");
                                }
                                break;
                        }

                        if ($success) {
                             app_log('debug', "Successfully saved image to: " . $destination);

                            // Delete old picture file if it existed and was different
                            if ($profilePicturePath) {
                                $oldPictureFullPath = BASE_UPLOAD_PATH . DIRECTORY_SEPARATOR . $profilePicturePath;
                                if (file_exists($oldPictureFullPath) && is_file($oldPictureFullPath) && $profilePicturePath !== (WEB_UPLOAD_PATH_RELATIVE . '/' . $newFileName)) {
                                    @unlink($oldPictureFullPath) or app_log('warning', "Failed to delete old picture after upload: $oldPictureFullPath");
                                }
                            }

                            // Set the new path to be saved in the database (relative to web root)
                            $profilePicturePath = WEB_UPLOAD_PATH_RELATIVE . '/' . $newFileName;

                            // Final confirmation file exists
                            if (!file_exists($destination)) {
                                app_log('error', "File was reported saved but not found at: " . $destination);
                                $errors['profile_picture'] = 'Failed to save image file. Please try again.';
                            }
                        } else {
                             // Error should already be logged by the switch/case logic
                             if (!isset($errors['profile_picture'])) {
                                 $errors['profile_picture'] = 'Failed to process image. Please try again.';
                             }
                        }

                    } catch (Exception $e) {
                        app_log('error', "Image processing error: " . $e->getMessage(), [
                            'file' => $e->getFile(),
                            'line' => $e->getLine()
                        ]);
                        if (!isset($errors['profile_picture'])) { // Prevent overwriting specific error
                            $errors['profile_picture'] = 'Failed to process image. Please try again.';
                        }
                    }
                }
            }
        }
    } // End of Profile Picture Upload Logic

    if (!$errors) {
        try {
            // Sanitize name before saving
            $data['name'] = preg_replace('/[\x00-\x1F\x7F]/u', '', $data['name']);
            $stmt_update = db()->prepare("UPDATE users SET name = ?, email = ?, phone = ?, profile_picture = ? WHERE id = ?");
            // Use the potentially updated $profilePicturePath (could be NULL if deleted, or new path if uploaded)
            $stmt_update->execute([$data['name'], $data['email'], $data['phone'], $profilePicturePath, $u['id']]);
            $_SESSION['user'] = array_merge($_SESSION['user'], [
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'profile_picture' => $profilePicturePath // Update session
            ]);
            // Refresh local $u variable to reflect changes immediately
            $u = $_SESSION['user'];
            $note = 'Your profile has been updated successfully!';
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Refresh CSRF token
        } catch (PDOException $e) {
            app_log('error', "Database error updating profile: " . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $errors['fatal'] = 'A database error occurred. Please contact support.';
        }
    }
}
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Update your personal information and contact details.">
  <title>My Profile — NexusFix</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <style>
    :root {
      --bg: #0b0c0f;
      --card: #101218;
      --text: #e8eaf0;
      --muted: #a6adbb;
      --border: #1f2430;
      --field-border: #2a3242;
      --primary: #60a5fa;
      --accent: #6ee7b7;
      --danger: #f87171;
      --shadow: 0 10px 30px rgba(0,0,0,.35);
      --shadow-sm: 0 4px 12px rgba(0,0,0,.18);
      --radius: 16px;
      --transition: all 0.2s ease;
    }

    [data-theme="light"] {
      --bg: #f7f8fb;
      --card: #ffffff;
      --text: #0b0c0f;
      --muted: #5b6172;
      --border: #e5e7eb;
      --field-border: #cbd5e1;
      --shadow: 0 10px 25px rgba(15,23,42,.08);
      --shadow-sm: 0 4px 12px rgba(0,0,0,.1);
    }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      line-height: 1.5;
      margin: 0;
      padding: 0;
      transition: background 0.3s ease;
    }

    main {
      max-width: 680px;
      margin: 2rem auto;
      padding: 1rem;
    }

    h2 {
      font-size: 1.75rem;
      font-weight: 600;
      margin-bottom: 0.5rem;
    }

    .subtitle {
      color: var(--muted);
      font-size: 0.95rem;
      margin-bottom: 1.5rem;
    }

    .profile-form {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 1.5rem;
      box-shadow: var(--shadow-sm);
    }

    .form-group {
      margin-bottom: 1rem;
    }

    .form-group label {
      display: block;
      font-weight: 600;
      font-size: 0.95rem;
      margin-bottom: 6px;
      color: var(--text);
    }

    .form-group input:not([type="file"]) {
      width: 100%;
      padding: 0.75rem 0.9rem;
      border: 1px solid var(--field-border);
      border-radius: 12px;
      background: var(--card);
      color: var(--text);
      font-size: 1rem;
    }

    .form-group input:focus:not([type="file"]) {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
    }

    .profile-picture-section {
        display: flex;
        flex-direction: column;
        align-items: center;
        margin-bottom: 1.5rem;
        padding: 1rem;
        border: 1px dashed var(--field-border);
        border-radius: 12px;
        background-color: rgba(255, 255, 255, 0.02);
    }

    .current-picture {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid var(--primary);
        margin-bottom: 1rem;
    }

    .current-picture-placeholder {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary), #818cf8);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        color: white;
        font-size: 2rem;
        margin-bottom: 1rem;
        border: 2px solid var(--primary);
    }

    .file-input-wrapper {
        position: relative;
        overflow: hidden;
        display: inline-block;
        margin-bottom: 0.5rem;
    }

    .file-input-wrapper input[type=file] {
        position: absolute;
        left: 0;
        top: 0;
        opacity: 0;
        width: 100%;
        height: 100%;
        cursor: pointer;
    }

    .file-input-label {
        display: inline-block;
        padding: 0.5rem 1rem;
        background-color: var(--primary);
        color: white;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.9rem;
        transition: background-color 0.2s;
    }

    .file-input-label:hover {
        background-color: #4f9cf9;
    }

    .file-name {
        margin-top: 0.5rem;
        font-size: 0.85rem;
        color: var(--muted);
    }

    .delete-picture-btn {
        background-color: var(--danger);
        color: white;
        border: none;
        padding: 0.4rem 0.8rem;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.85rem;
        transition: background-color 0.2s;
        margin-top: 0.5rem;
    }

    .delete-picture-btn:hover {
        background-color: #e53e3e;
    }

    .alert {
        padding: 1rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
        font-size: 0.95rem;
    }

    .alert.success {
        background: rgba(52, 211, 153, 0.15);
        border: 1px solid rgba(52, 211, 153, 0.3);
        color: var(--accent);
    }

    .alert.error {
        background: rgba(248, 113, 113, 0.15);
        border: 1px solid rgba(248, 113, 113, 0.3);
        color: var(--danger);
    }

    .alert ul {
        margin: 0.5rem 0 0 1.2rem;
        padding: 0;
    }

    .actions {
        display: flex;
        gap: 12px;
        margin-top: 1.5rem;
        flex-wrap: wrap;
    }

    .btn {
        padding: 0.75rem 1.25rem;
        border-radius: 12px;
        border: 1px solid transparent;
        background: rgba(255,255,255,0.06);
        color: var(--text);
        font-weight: 500;
        cursor: pointer;
        text-decoration: none;
        transition: var(--transition);
        font-size: 1rem;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn.primary {
        background: var(--primary);
        color: white;
    }

    .btn.primary:hover {
        background: #4f9cf9;
        transform: translateY(-1px);
    }

    .btn.subtle {
        background: transparent;
        color: var(--muted);
        border: 1px solid var(--field-border);
    }

    .btn.subtle:hover {
        background: rgba(255,255,255,0.06);
    }

    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }

    @media (prefers-reduced-motion: reduce) {
        * {
            transition: none !important;
            animation: none !important;
        }
    }
  </style>
</head>
<body>
  <?php require __DIR__ . '/../../includes/header.php'; ?>

  <main aria-labelledby="page-title">
    <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
      <div>
        <h2 id="page-title">My Profile</h2>
        <p class="subtitle">Update your personal information and contact details.</p>
      </div>
      <!-- Theme toggle removed from here as it's in header.php -->
    </div>

    <?php if ($note): ?>
      <div class="alert success" role="alert">
        <strong>Success:</strong> <?= e($note) ?>
      </div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert error" role="alert">
        <strong>Please fix the following:</strong>
        <ul>
          <?php foreach ($errors as $key => $m): ?>
            <?php if ($key !== 'csrf' && $key !== 'fatal'): ?>
              <li><?= e($m) ?></li>
            <?php endif; ?>
          <?php endforeach; ?>
        </ul>
        <?php if (!empty($errors['csrf'])): ?>
          <p style="margin-top: 0.5rem;"><?= e($errors['csrf']) ?></p>
        <?php endif; ?>
        <?php if (!empty($errors['fatal'])): ?>
          <p style="margin-top: 0.5rem;"><?= e($errors['fatal']) ?></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="post" class="profile-form" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="MAX_FILE_SIZE" value="2097152">

      <div class="profile-picture-section">
        <label for="profile_picture_input">Profile Picture</label>
        <?php
        // Determine if we have a valid picture path and the file exists
        $validPicturePath = !empty($u['profile_picture']) && file_exists(BASE_UPLOAD_PATH . DIRECTORY_SEPARATOR . $u['profile_picture']);
        if ($validPicturePath):
            // Use the relative path stored in the database for the image source
            // This should work correctly with your web server setup
        ?>
          <img src="<?= e('/' . $u['profile_picture']) ?>" alt="Profile Picture" class="current-picture">
        <?php else: ?>
          <div class="current-picture-placeholder">
            <?php
            $name = $u['name'];
            $initials = '';
            $words = explode(' ', $name);
            foreach (array_slice($words, 0, 2) as $word) {
                $initials .= strtoupper($word[0]);
            }
            echo $initials;
            ?>
          </div>
        <?php endif; ?>

        <div class="file-input-wrapper">
          <label class="file-input-label" for="profile_picture_input">Choose File</label>
          <input type="file" id="profile_picture_input" name="profile_picture" accept=".jpg,.jpeg,.png,.gif" <?= isset($errors['fatal']) ? 'disabled' : '' ?>>
        </div>
        <div class="file-name" id="file-name">No file chosen</div>

        <?php if (!empty($u['profile_picture'])): ?>
          <button type="submit" name="delete_picture" value="1" class="delete-picture-btn" onclick="return confirm('Are you sure you want to delete your profile picture?');">Delete Picture</button>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="name">Full Name *</label>
        <input
          type="text"
          id="name"
          name="name"
          value="<?= e($old['name']) ?>"
          required
          aria-describedby="name-error"
        >
        <?php if (isset($errors['name'])): ?>
          <div id="name-error" style="color:var(--danger); font-size:0.85rem; margin-top:4px;">
            <?= e($errors['name']) ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="email">Email Address *</label>
        <input
          type="email"
          id="email"
          name="email"
          value="<?= e($old['email']) ?>"
          required
          aria-describedby="email-error"
        >
        <?php if (isset($errors['email'])): ?>
          <div id="email-error" style="color:var(--danger); font-size:0.85rem; margin-top:4px;">
            <?= e($errors['email']) ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="phone">Phone Number (Optional)</label>
        <input
          type="text"
          id="phone"
          name="phone"
          value="<?= e($old['phone']) ?>"
          placeholder="e.g. +1 (555) 123-4567"
          aria-describedby="phone-help phone-error"
        >
        <div id="phone-help" style="color:var(--muted); font-size:0.85rem; margin-top:4px;">
          Used for urgent service updates.
        </div>
        <?php if (isset($errors['phone'])): ?>
          <div id="phone-error" style="color:var(--danger); font-size:0.85rem; margin-top:4px;">
            <?= e($errors['phone']) ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="actions">
        <button type="submit" class="btn primary">Update Profile</button>
        <a href="<?= e(base_url('customer/dashboard.php')) ?>" class="btn subtle">Back to Dashboard</a>
      </div>
    </form>
  </main>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('.profile-form');
        const fileInput = document.getElementById('profile_picture_input');
        const fileNameDisplay = document.getElementById('file-name');
        const toggle = document.getElementById('theme-toggle'); // This is from header.php
        const html = document.documentElement;

        fileNameDisplay.textContent = 'No file chosen';
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            fileNameDisplay.textContent = file ? file.name : 'No file chosen';
            if (file && file.size > 2097152) {
                alert('File size exceeds 2MB limit.');
                e.target.value = '';
                fileNameDisplay.textContent = 'No file chosen';
            }
        });

        form.addEventListener('submit', function(e) {
            const name = document.getElementById('name').value.trim();
            const email = document.getElementById('email').value.trim();
            if (!name) {
                e.preventDefault();
                alert('Full Name is required.');
                document.getElementById('name').focus();
            } else if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                e.preventDefault();
                alert('Please enter a valid email address.');
                document.getElementById('email').focus();
            }
        });

        // Theme toggle logic is handled by header.php script, but we can still sync initial state
        // if needed or add page-specific logic here if necessary in the future.
        // The header script already handles the toggle change event.

        // Sync initial theme state if needed (optional, header script should handle this)
        // const savedTheme = localStorage.getItem('theme') || 'dark';
        // html.setAttribute('data-theme', savedTheme);
        // if (toggle) { toggle.checked = savedTheme === 'light'; }
    });
  </script>
</body>
</html>