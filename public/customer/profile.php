<?php
/**
 * Customer Profile — NexusFix (Ultimate Edition)
 * World-class UX inspired by Apple, Google, and Microsoft.
 * With Profile Picture Support.
 * Refined Upload Logic Version.
 */

declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php';
require_role('customer');

$u = $_SESSION['user'];
$errors = [];
$note = null;

// --- File Upload Settings ---
$allowedExt = ['jpg','jpeg','png','gif'];
$maxSize = 2 * 1024 * 1024; // 2MB
// Use DIRECTORY_SEPARATOR for robust path construction
$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile_pictures' . DIRECTORY_SEPARATOR;

// Ensure upload directory exists
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        error_log("Failed to create upload directory: " . $uploadDir);
        $errors['fatal'] = 'Profile picture upload is currently unavailable. Please contact support.';
    }
}

$old = [
  'name'  => $u['name'],
  'email' => $u['email'],
  'phone' => $u['phone'] ?? ''
];

// Handle Profile Picture Deletion
if (isset($_POST['delete_picture'])) {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $errors['csrf'] = 'Invalid session token.';
    } else {
        // Construct full path for file check and deletion
        $oldPicturePath = $u['profile_picture'];
        if ($oldPicturePath && file_exists(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $oldPicturePath)) {
            if (unlink(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $oldPicturePath)) {
                // Successfully deleted file
            } else {
                error_log("Warning: Could not delete old profile picture from disk: " . $oldPicturePath);
            }
        } else {
            // File might have already been deleted or path was incorrect
        }

        // Update database to NULL
        $stmt_delete_pic = db()->prepare("UPDATE users SET profile_picture = NULL WHERE id = ?");
        $stmt_delete_pic->execute([$u['id']]);

        // Update session
        $_SESSION['user']['profile_picture'] = null;

        $note = 'Profile picture deleted successfully.';
        // Refresh user data for display
        $u['profile_picture'] = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_picture'])) {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $errors['csrf'] = 'Invalid session token.';
    }

    $data = [
        'name'  => trim($_POST['name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? '')
    ];
    $old = array_merge($old, $data);

    if (!$data['name']) $errors['name'] = 'Name is required.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if (!$errors) {
        $stmt_check_email = db()->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt_check_email->execute([$data['email'], $u['id']]);
        if ($stmt_check_email->fetch()) {
            $errors['email'] = 'This email is already in use by another account.';
        }
    }

         // --- Handle Profile Picture Upload ---
      $profilePicturePath = $u['profile_picture']; // Default to existing picture path
      if (isset($_FILES['profile_picture']) && is_array($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
        error_log("DEBUG profile.php: File upload initiated for user ID " . $u['id']); // <-- Log Start

        $file = $_FILES['profile_picture'];
        $fileName = $file['name'] ?? '';
        $fileSize = $file['size'] ?? 0;
        $fileTmpName = $file['tmp_name'] ?? '';
        $fileError = $file['error'] ?? UPLOAD_ERR_CANT_WRITE; // Default to a generic error if somehow missing

        error_log("DEBUG profile.php: File details - Name: '$fileName', Size: $fileSize, Tmp: '$fileTmpName', Error: $fileError"); // <-- Log Details

        if ($fileError !== UPLOAD_ERR_OK) {
            error_log("DEBUG profile.php: File upload error code $fileError encountered."); // <-- Log Error Code
            $errors['profile_picture'] = 'Error uploading file (Code: ' . $fileError . '). Please try again.';
        } else {
            // --- Validate File Type and Size ---
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            error_log("DEBUG profile.php: Extracted file extension: '$fileExt'"); // <-- Log Extension
            if (!in_array($fileExt, $allowedExt)) {
                error_log("DEBUG profile.php: Invalid file extension '$fileExt' detected. Allowed: " . implode(', ', $allowedExt)); // <-- Log Invalid Ext
                $errors['profile_picture'] = 'Invalid file type. Only JPG, JPEG, PNG, and GIF are allowed.';
            } elseif ($fileSize > $maxSize) {
                error_log("DEBUG profile.php: File size $fileSize exceeds maximum allowed size $maxSize."); // <-- Log Size Exceeded
                $errors['profile_picture'] = 'File size too large. Maximum size is ' . ($maxSize / 1024 / 1024) . 'MB.';
            } else {
                // --- Generate Unique Filename and Destination Path ---
                $newFileName = uniqid('profile_', true) . '.' . $fileExt;
                $destination = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $newFileName; // Ensure trailing slash consistency

                error_log("DEBUG profile.php: Generated new filename: '$newFileName'"); // <-- Log New Name
                error_log("DEBUG profile.php: Constructed destination path: '$destination'"); // <-- Log Destination

                // --- Check Upload Directory Writability ---
                if (!is_dir($uploadDir)) {
                     error_log("DEBUG profile.php: ERROR - Upload directory does not exist: '$uploadDir'"); // <-- Log Dir Missing
                     $errors['profile_picture'] = 'Upload failed: Server configuration error (Upload directory missing). Please contact support.';
                } elseif (!is_writable($uploadDir)) {
                     error_log("DEBUG profile.php: ERROR - Upload directory is NOT writable: '$uploadDir'"); // <-- Log Dir Not Writable
                     $errors['profile_picture'] = 'Upload failed: Server cannot write to the upload directory. Please contact support.';
                } else {
                    // --- Attempt to Move the Uploaded File ---
                    error_log("DEBUG profile.php: Attempting move_uploaded_file from '$fileTmpName' to '$destination'"); // <-- Log Move Attempt
                    if (move_uploaded_file($fileTmpName, $destination)) {
                        error_log("DEBUG profile.php: File moved successfully to '$destination'"); // <-- Log Success

                        // --- Delete Old Picture (if exists and different) ---
                        $oldPicturePathRelative = $u['profile_picture'];
                        if (!empty($oldPicturePathRelative) && $oldPicturePathRelative !== $profilePicturePath) {
                            // Construct full server path for the old file
                            $oldPictureFullPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $oldPicturePathRelative;
                            error_log("DEBUG profile.php: Checking for old picture to delete: '$oldPictureFullPath'"); // <-- Log Old Check
                            if (file_exists($oldPictureFullPath) && is_file($oldPictureFullPath)) {
                                error_log("DEBUG profile.php: Attempting to delete old picture: '$oldPictureFullPath'"); // <-- Log Delete Attempt
                                if (unlink($oldPictureFullPath)) {
                                    error_log("DEBUG profile.php: Old picture deleted successfully."); // <-- Log Delete Success
                                } else {
                                    error_log("DEBUG profile.php: Warning - Failed to delete old picture: '$oldPictureFullPath'. Check permissions."); // <-- Log Delete Fail
                                    // Not a critical error, continue
                                }
                            } else {
                                 error_log("DEBUG profile.php: Old picture file not found or not a file: '$oldPictureFullPath'"); // <-- Log Not Found
                            }
                        } else {
                             error_log("DEBUG profile.php: No old picture to delete or path is the same."); // <-- Log No Old
                        }

                        // --- Prepare Path for Database Storage ---
                        $profilePicturePath = 'uploads/profile_pictures/' . $newFileName;
                        error_log("DEBUG profile.php: Profile picture path prepared for database: '$profilePicturePath'"); // <-- Log DB Path

                    } else {
                        // --- Handle Move Failure ---
                        $errorMsg = "DEBUG profile.php: ERROR - move_uploaded_file FAILED. From: '$fileTmpName', To: '$destination'. ";
                        // Check common reasons
                        if (!is_uploaded_file($fileTmpName)) {
                            $errorMsg .= "Reason: Source is not an uploaded file. Possible security issue or tmp file cleanup. ";
                        }
                        if (!file_exists($fileTmpName)) {
                            $errorMsg .= "Reason: Source tmp file does not exist. ";
                        }
                        if (file_exists($destination)) {
                            $errorMsg .= "Reason: Destination file already exists. ";
                        }
                        if (!is_dir(dirname($destination))) {
                            $errorMsg .= "Reason: Destination directory does not exist. ";
                        }
                        error_log($errorMsg); // <-- Log Detailed Move Error
                        $errors['profile_picture'] = 'Failed to save the uploaded file. Please try again.';
                    }
                }
            }
        }
        error_log("DEBUG profile.php: File upload process finished for user ID " . $u['id'] . ". Errors: " . print_r($errors['profile_picture'] ?? 'None', true)); // <-- Log Finish
      } else {
         error_log("DEBUG profile.php: No file uploaded or invalid \$_FILES structure detected."); // <-- Log No File
      }
    
    // --- End Profile Picture Upload ---

      if (!$errors) {
        try {
            error_log("DEBUG profile.php: Entering main update try block for user ID: " . ($u['id'] ?? 'UNKNOWN')); // <-- LOG ENTRY 1

            // --- Database Update Preparation ---
            error_log("DEBUG profile.php: Preparing database update statement for user ID: " . ($u['id'] ?? 'UNKNOWN')); // <-- LOG ENTRY 2
            error_log("DEBUG profile.php: Update data - Name: '{$data['name']}', Email: '{$data['email']}', Phone: '{$data['phone']}', Picture Path: '" . ($profilePicturePath ?? 'NULL') . "'"); // <-- LOG ENTRY 3
            $stmt_update = db()->prepare("UPDATE users SET name = ?, email = ?, phone = ?, profile_picture = ? WHERE id = ?");
            error_log("DEBUG profile.php: Database statement prepared successfully."); // <-- LOG ENTRY 4

            // --- Database Update Execution ---
            error_log("DEBUG profile.php: About to execute database update for user ID: " . ($u['id'] ?? 'UNKNOWN')); // <-- LOG ENTRY 5
            $db_execute_result = $stmt_update->execute([$data['name'], $data['email'], $data['phone'], $profilePicturePath, $u['id']]);
            $db_row_count = $stmt_update->rowCount(); // Get affected rows
            error_log("DEBUG profile.php: Database update executed. Result: " . ($db_execute_result ? 'TRUE' : 'FALSE') . ", Rows Affected: $db_row_count"); // <-- LOG ENTRY 6

            // --- Session Update ---
            error_log("DEBUG profile.php: Updating session variables for user ID: " . ($u['id'] ?? 'UNKNOWN')); // <-- LOG ENTRY 7
            $_SESSION['user']['name'] = $data['name'];
            $_SESSION['user']['email'] = $data['email'];
            $_SESSION['user']['phone'] = $data['phone'];
            $_SESSION['user']['profile_picture'] = $profilePicturePath; // Update profile picture in session
            error_log("DEBUG profile.php: Session variables updated successfully."); // <-- LOG ENTRY 8

            $note = 'Your profile has been updated successfully!';
            // Refresh user data for display in the script
            $u['name'] = $data['name'];
            $u['email'] = $data['email'];
            $u['phone'] = $data['phone'];
            $u['profile_picture'] = $profilePicturePath;
            error_log("DEBUG profile.php: Local user variable (\$u) refreshed. Exiting try block successfully for user ID: " . ($u['id'] ?? 'UNKNOWN')); // <-- LOG ENTRY 9

        } catch (Exception $e) {
            // --- Catch Block Logging ---
            error_log("DEBUG profile.php: EXCEPTION CAUGHT in main update block for user ID: " . ($u['id'] ?? 'UNKNOWN')); // <-- LOG ENTRY A
            error_log("DEBUG profile.php: EXCEPTION MESSAGE: " . $e->getMessage()); // <-- LOG ENTRY B
            error_log("DEBUG profile.php: EXCEPTION CODE: " . $e->getCode()); // <-- LOG ENTRY C
            error_log("DEBUG profile.php: EXCEPTION FILE: " . $e->getFile()); // <-- LOG ENTRY D
            error_log("DEBUG profile.php: EXCEPTION LINE: " . $e->getLine()); // <-- LOG ENTRY E
            error_log("DEBUG profile.php: EXCEPTION TRACE: " . $e->getTraceAsString()); // <-- LOG ENTRY F
            // --- End Catch Block Logging ---
            $errors['fatal'] = 'Could not update profile. Please try again.';
        } catch (Error $er) { // Catch fatal errors too
             // --- Catch Error Logging ---
            error_log("DEBUG profile.php: FATAL ERROR CAUGHT in main update block for user ID: " . ($u['id'] ?? 'UNKNOWN')); // <-- LOG ENTRY G
            error_log("DEBUG profile.php: FATAL ERROR MESSAGE: " . $er->getMessage()); // <-- LOG ENTRY H
            error_log("DEBUG profile.php: FATAL ERROR CODE: " . $er->getCode()); // <-- LOG ENTRY I
            error_log("DEBUG profile.php: FATAL ERROR FILE: " . $er->getFile()); // <-- LOG ENTRY J
            error_log("DEBUG profile.php: FATAL ERROR LINE: " . $er->getLine()); // <-- LOG ENTRY K
            error_log("DEBUG profile.php: FATAL ERROR TRACE: " . $er->getTraceAsString()); // <-- LOG ENTRY L
            // --- End Catch Error Logging ---
            $errors['fatal'] = 'A critical error occurred while updating your profile. Please contact support.';
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

    /* Theme Toggle */
    .theme-toggle {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.9rem;
      color: var(--muted);
    }

    .toggle-switch {
      position: relative;
      display: inline-block;
      width: 44px;
      height: 24px;
    }

    .toggle-switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }

    .slider {
      position: absolute;
      cursor: pointer;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: var(--field-border);
      transition: .3s;
      border-radius: 24px;
    }

    .slider:before {
      position: absolute;
      content: "";
      height: 18px;
      width: 18px;
      left: 3px;
      bottom: 3px;
      background: white;
      transition: .3s;
      border-radius: 50%;
    }

    input:checked + .slider {
      background: var(--primary);
    }

    input:checked + .slider:before {
      transform: translateX(20px);
    }

    /* Form */
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

    /* Profile Picture Section */
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

    /* Messages */
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

    /* Actions */
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
  <?php require __DIR__.'/../../includes/header.php'; ?>

  <main aria-labelledby="page-title">
  <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
  <div>
    <h2 id="page-title">My Profile</h2>
    <p class="subtitle">Update your personal information and contact details.</p>
  </div>
</div>

    <!-- Success Message -->
    <?php if ($note): ?>
      <div class="alert success" role="alert">
        <strong>Success:</strong> <?= e($note) ?>
      </div>
    <?php endif; ?>
   <!-- Error Messages -->
    <?php if ($errors): ?>
      <div class="alert error" role="alert">
        <strong>Please fix the following:</strong>
        <ul>
          <?php foreach ($errors as $key => $m): ?>
            <?php if ($key !== 'csrf' && $key !== 'fatal'): // Don't display CSRF/Fatal errors inline ?>
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

    <!-- Profile Form -->
    <form method="post" class="profile-form" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <!-- Profile Picture Section -->
      <div class="profile-picture-section">
        <label for="profile_picture_input">Profile Picture</label>
        <?php if (!empty($u['profile_picture']) && file_exists(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $u['profile_picture'])): ?>
            <img src="<?= e(base_url($u['profile_picture'])) ?>" alt="Profile Picture" class="current-picture">
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
            <input type="file" id="profile_picture_input" name="profile_picture" accept=".jpg,.jpeg,.png,.gif">
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
          aria-describedby="phone-help"
        >
        <div id="phone-help" style="color:var(--muted); font-size:0.85rem; margin-top:4px;">
          Used for urgent service updates.
        </div>
      </div>

      <div class="actions">
        <button type="submit" class="btn primary">Update Profile</button>
        <a href="<?= e(base_url('customer/dashboard.php')) ?>" class="btn subtle">Back to Dashboard</a>
      </div>
    </form>
  </main>

  <script>
    // Display selected file name
    document.getElementById('profile_picture_input').addEventListener('change', function(e) {
        const fileName = e.target.files[0] ? e.target.files[0].name : 'No file chosen';
        document.getElementById('file-name').textContent = fileName;
    });
  </script>
</body>
</html>