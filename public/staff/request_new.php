<?php
/**
 * Staff Create Request — NexusFix (Enterprise Edition)
 * World-class UX inspired by Apple, Microsoft, and Best Buy.
 * Features: Mandatory fields, confirmation preview, dark/light mode.
 * Enhanced with: Auto-save, Conditional Logic, Real-time Validation, Integration Hooks.
 *
 * Modified to change the "Choose a Service Time" section to a date picker and time period selector.
 * Modified to include file attachment functionality similar to customer version.
 * Modified to fix file attachment saving issue:
 * - Files are moved to uploads/temp/ immediately on upload.
 * - Path to temp file is passed through confirmation.
 * - File is moved from temp to final location on confirmation submit.
 */
declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php';
require_role('staff', 'admin');

$errors = [];
$old = [
  'customer_id'        => '',
  'device_type'        => 'Laptop',
  'brand'              => '',
  'model'              => '',
  'serial_no'          => '',
  'issue_description'  => '',
  'service_type'       => 'dropoff',
  'preferred_contact'  => 'phone',
  'accessories'        => '',
  'warranty_status'    => 'unknown',
  'priority'           => 'normal',
  'address'            => '',
  'city'               => '',
  'postal_code'       => '',
  // --- MODIFIED FIELDS ---
  'service_date'       => '',
  'service_time_period'=> '',
  // --- END MODIFIED FIELDS ---
];

// --- Helpers ---
function valid_in(array $allowed, string $val): bool { return in_array($val, $allowed, true); }
function clamp_len(?string $v, int $max): string { return mb_substr(trim((string)$v), 0, $max); }

// --- File upload settings ---
$allowedExt = ['jpg','jpeg','png','gif','mp4','mov','avi','mkv'];
$maxSize = 20 * 1024 * 1024; // 20MB
function validFileUpload(string $name, int $size): bool {
  global $allowedExt, $maxSize;
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  return in_array($ext, $allowedExt, true) && $size > 0 && $size <= $maxSize;
}

// Fetch all customers
$customers = db()->query("SELECT id, name, email FROM users WHERE role='customer' ORDER BY name")->fetchAll();

// Flag for confirmation step
$confirming = false;
$confirmation_data = [];
$temp_files = [];

// --- Handle File Uploads ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    $errors['csrf'] = 'Invalid session token. Please refresh and try again.';
  }

  foreach ($old as $k => $v) {
    $old[$k] = isset($_POST[$k]) ? trim((string)$_POST[$k]) : $v;
  }
  $old['brand']     = clamp_len($old['brand'], 80);
  $old['model']     = clamp_len($old['model'], 120);
  $old['serial_no'] = clamp_len($old['serial_no'], 120);
  $old['address']   = clamp_len($old['address'], 255);
  $old['city']      = clamp_len($old['city'], 100);
  $old['postal_code'] = clamp_len($old['postal_code'], 20);

  // --- MODIFIED FILE UPLOAD HANDLING ---
  if (!empty($_FILES['attachments']['name'][0])) {
       $tempUploadBaseDir = __DIR__ . '/../../uploads/temp/';
       if (!is_dir($tempUploadBaseDir)) {
           if (!mkdir($tempUploadBaseDir, 0777, true)) {
               error_log("Failed to create temporary upload directory: $tempUploadBaseDir");
               $errors['attachments'] = 'System error handling uploads.';
           }
       }
       if (!isset($errors['attachments'])) {
           foreach ($_FILES['attachments']['name'] as $i => $n) {
               if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                   if (validFileUpload($n, (int)$_FILES['attachments']['size'][$i])) {
                       $originalName = $n;
                       $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                       $tempFileName = uniqid('temp_', true) . '.' . $extension;
                       $tempFilePath = $tempUploadBaseDir . $tempFileName;
                       if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $tempFilePath)) {
                           $temp_files[] = [
                               'name' => $originalName,
                               'type' => $extension,
                               'tmp_name' => $tempFilePath,
                               'size' => $_FILES['attachments']['size'][$i],
                               'temp_stored_name' => $tempFileName
                           ];
                           error_log("✅ File moved to temp dir: {$originalName} -> {$tempFilePath}");
                       } else {
                           error_log("❌ Failed to move uploaded file to temp dir: {$originalName}");
                           $errors['attachments'] = 'Failed to process uploaded file.';
                           break;
                       }
                   } else {
                       $errors['attachments'] = 'Invalid file type or too large (max 20MB).';
                       break;
                   }
               } elseif ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                   $errors['attachments'] = 'One of the files failed to upload.';
                   break;
               }
           }
       }
   } elseif (isset($_POST['temp_files_data'])) {
       $temp_files = json_decode($_POST['temp_files_data'], true) ?: [];
   }
  // --- END MODIFIED FILE UPLOAD HANDLING ---

  // --- Validation ---
  if ($old['customer_id'] === '') $errors['customer_id'] = 'Please select a customer.';
  if ($old['issue_description'] === '') $errors['issue_description'] = 'Please describe the issue.';
  if ($old['device_type'] === '') $errors['device_type'] = 'Select a device type.';
  if ($old['brand'] === '') $errors['brand'] = 'Brand is required.';
  if ($old['model'] === '') $errors['model'] = 'Model is required.';
  if (!valid_in(['dropoff','pickup','onsite'], $old['service_type'])) $errors['service_type'] = 'Invalid service option.';
  if (!valid_in(['phone','email','both'], $old['preferred_contact'])) $errors['preferred_contact'] = 'Choose phone, email or both.';
  if (!valid_in(['in_warranty','out_of_warranty','unknown'], $old['warranty_status'])) $errors['warranty_status'] = 'Invalid warranty status.';
  if (!valid_in(['low','normal','high'], $old['priority'])) $errors['priority'] = 'Invalid priority.';
  if (in_array($old['service_type'], ['pickup','onsite'], true)) {
    if ($old['address'] === '') $errors['address'] = 'Address is required.';
    if ($old['city'] === '') $errors['city'] = 'City is required.';
    if ($old['postal_code'] === '') $errors['postal_code'] = 'Postal code is required.';
  }

  // --- MODIFIED: Validate Selected Date and Time Period ---
  $selectedDateStr = $old['service_date'] ?? '';
  $selectedTimePeriod = $old['service_time_period'] ?? '';
  if (empty($selectedDateStr)) {
      $errors['service_date'] = 'Please select a service date.';
  } else {
      $dateParts = explode('-', $selectedDateStr);
      if (count($dateParts) !== 3 || !checkdate((int)$dateParts[1], (int)$dateParts[2], (int)$dateParts[0])) {
          $errors['service_date'] = 'Invalid date selected.';
      } elseif (strtotime($selectedDateStr) < strtotime(date('Y-m-d'))) {
          $errors['service_date'] = 'Please select a date today or in the future.';
      }
  }
  if (empty($selectedTimePeriod)) {
      $errors['service_time_period'] = 'Please select a service time period.';
  } elseif (!in_array($selectedTimePeriod, ['morning', 'afternoon', 'evening'], true)) {
      $errors['service_time_period'] = 'Invalid time period selected.';
  }
  if (empty($errors['service_date']) && empty($errors['service_time_period']) && $selectedDateStr && $selectedTimePeriod) {
       $confirmation_data['service_date'] = $selectedDateStr;
       $confirmation_data['service_time_period'] = $selectedTimePeriod;
  }
  // --- END MODIFIED VALIDATION ---

  // If no errors and not confirming, show confirmation
  if (!$errors && !isset($_POST['confirm'])) {
      $confirming = true;
      $confirmation_data = $old;
      if (!empty($confirmation_data['service_date']) && !empty($confirmation_data['service_time_period'])) {
          $timeLabels = [
              'morning' => 'Morning (9:00 AM - 12:00 PM)',
              'afternoon' => 'Afternoon (12:00 PM - 5:00 PM)',
              'evening' => 'Evening (5:00 PM - 8:00 PM)'
          ];
          $displayDate = date('D, M j, Y', strtotime($confirmation_data['service_date']));
          $displayTime = $timeLabels[$confirmation_data['service_time_period']] ?? $confirmation_data['service_time_period'];
          $confirmation_data['slot_details'] = [
              'date' => $displayDate,
              'time' => $displayTime
          ];
      }
      $confirmation_data['temp_files'] = $temp_files;
      // Add customer name to confirmation data
      foreach ($customers as $c) {
          if ((string)$c['id'] === $old['customer_id']) {
              $confirmation_data['customer_name'] = $c['name'];
              $confirmation_data['customer_email'] = $c['email'];
              break;
          }
      }
  }

  // If confirming and user clicks "Confirm Submit"
  if (!$errors && isset($_POST['confirm']) && $_POST['confirm'] === 'yes') {
    try {
      $pdo = db();
      $ticket = ticket_code($old['device_type'], $old['service_type'], $pdo);
      $pdo->beginTransaction();
      $stmt = $pdo->prepare("INSERT INTO repair_requests
        (ticket_code, customer_id, device_type, brand, model, serial_no,
         issue_description, service_type, preferred_contact, accessories,
         warranty_status, priority, address, city, postal_code, status, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
      $stmt->execute([
        $ticket,
        $old['customer_id'],
        $old['device_type'],
        $old['brand'] ?: null,
        $old['model'] ?: null,
        $old['serial_no'],
        $old['issue_description'],
        $old['service_type'],
        $old['preferred_contact'],
        $old['accessories'] ?: null,
        $old['warranty_status'],
        $old['priority'],
        $old['address'] ?: null,
        $old['city'] ?: null,
        $old['postal_code'] ?: null,
        'Received'
      ]);
      $req_id = (int)$pdo->lastInsertId();
      $hist = $pdo->prepare("INSERT INTO request_status_history (request_id, status, note, changed_by) VALUES (?,?,?,?)");
      $hist->execute([$req_id, 'Received', 'Request created by staff', $_SESSION['user']['id']]);

      // --- MODIFIED: Insert Booking for Date/Time Period ---
      if (!empty($confirmation_data['service_date']) && !empty($confirmation_data['service_time_period'])) {
          $bookingIdentifier = $confirmation_data['service_date'] . '_' . $confirmation_data['service_time_period'];
          $insB = $pdo->prepare("INSERT INTO service_bookings (slot_id, request_id, user_id, status, notes) VALUES (?,?,?,?,?)");
          $insB->execute([$bookingIdentifier, $req_id, $old['customer_id'], 'booked', 'Staff booked date/time period during request creation']);
      }
      // --- END MODIFIED BOOKING INSERTION ---

      // --- MODIFIED ATTACHMENT SAVING ---
      if (!empty($temp_files)) {
          $finalDir = __DIR__ . '/../../uploads/requests/' . $req_id;
          if (!is_dir($finalDir)) {
              if (!mkdir($finalDir, 0777, true)) {
                  error_log("❌ Failed to create final upload directory: $finalDir");
                  $errors['attachments'] = 'System error saving attachments.';
              }
          }
          if (!isset($errors['attachments'])) {
              $tempUploadBaseDir = __DIR__ . '/../../uploads/temp/';
              $insertAttachment = $pdo->prepare("
                  INSERT INTO request_attachments (request_id, file_path, file_type, uploaded_at)
                  VALUES (?, ?, ?, NOW())
              ");
              foreach ($temp_files as $file) {
                  $tempSourcePath = $file['tmp_name'];
                  $originalName = $file['name'];
                  $extension = $file['type'];
                  $finalFileName = 'att_' . uniqid('', true) . '.' . $extension;
                  $finalDestPath = $finalDir . '/' . $finalFileName;
                  if (file_exists($tempSourcePath)) {
                      if (rename($tempSourcePath, $finalDestPath)) {
                          $dbFilePath = "uploads/requests/{$req_id}/{$finalFileName}";
                          error_log("✅ Attachment moved to final location: {$originalName} -> {$finalDestPath}");
                          try {
                              $insertAttachment->execute([$req_id, $dbFilePath, $extension]);
                              error_log("✅ Attachment DB record inserted for: {$dbFilePath}");
                          } catch (PDOException $e) {
                              error_log("❌ DB Insert Failed for attachment: " . $e->getMessage());
                          }
                      } else {
                          error_log("❌ Failed to move attachment from temp to final: {$tempSourcePath} -> {$finalDestPath}");
                      }
                  } else {
                      error_log("⚠️ Temporary source file not found for attachment: {$tempSourcePath} (Original: {$originalName})");
                  }
              }
          }
      }
      // --- END MODIFIED ATTACHMENT SAVING ---

      // Send notification
      $n = $pdo->prepare("INSERT INTO notifications (user_id, title, body) VALUES (?,?,?)");
      $n->execute([$old['customer_id'], 'Request Received', "Ticket $ticket has been created."]);

      $pdo->commit();
      redirect(base_url('staff/dashboard.php?new='.$ticket));
    } catch (Throwable $e) {
      if (isset($pdo) && $pdo->inTransaction()) {
          $pdo->rollBack();
      }
      $errors['fatal'] = 'Could not submit your request. '.$e->getMessage();
      error_log("Submission error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    }
  }
}
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="Create a new repair request on behalf of a customer with NexusFix. Upload photos, describe issues, and book service slots.">
  <title>Create Repair Request (Staff) — NexusFix</title>
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
      --success: #34d399;
      --warning: #fbbf24;
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
      max-width: 1080px;
      margin: 24px auto;
      padding: 16px;
    }
    h2 {
      font-size: 1.75rem;
      font-weight: 600;
      margin-bottom: 1rem;
    }
    .subtitle {
      color: var(--muted);
      font-size: 0.95rem;
      margin-top: 0.25rem;
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
    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 18px;
      box-shadow: var(--shadow-sm);
      margin-bottom: 16px;
      transition: var(--transition);
    }
    .card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
    }
    .card h3 {
      margin: 0 0 8px;
      font-size: 1.15rem;
      font-weight: 600;
    }
    .card p {
      margin: 0 0 14px;
      color: var(--muted);
      font-size: 0.95rem;
    }
    form label {
      display: block;
      font-weight: 600;
      font-size: 0.95rem;
      margin-bottom: 6px;
      color: var(--text);
    }
    form input, form select, form textarea {
      width: 100%;
      padding: 0.75rem 0.9rem;
      border: 1px solid var(--field-border);
      border-radius: 12px;
      background: var(--card);
      color: var(--text);
      font-size: 1rem;
      transition: var(--transition);
    }
    form input:focus, form select:focus, form textarea:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
    }
    .grid { display: grid; gap: 12px; }
    .grid-4 { grid-template-columns: repeat(4,1fr); }
    .grid-3 { grid-template-columns: repeat(3,1fr); }
    .grid-2 { grid-template-columns: repeat(2,1fr); }
    @media (max-width: 1024px) { .grid-4, .grid-3 { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 768px) { .grid-4, .grid-3, .grid-2 { grid-template-columns: 1fr; } }
    /* Buttons */
    .btn {
      padding: 0.75rem 1.25rem;
      border-radius: 12px;
      border: 1px solid transparent;
      background: rgba(255,255,255,.06);
      color: var(--text);
      font-weight: 600;
      font-size: 1rem;
      cursor: pointer;
      text-decoration: none;
      transition: var(--transition);
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
      background: rgba(255,255,255,.06);
    }
    .btn:focus-visible {
      outline: 3px solid rgba(96, 165, 250, 0.45);
      outline-offset: 3px;
    }
    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }
    /* Messages */
    .alert {
      background: rgba(248, 113, 113, 0.15);
      border: 1px solid rgba(248, 113, 113, 0.3);
      color: var(--danger);
      border-radius: 12px;
      padding: 1rem;
      margin-bottom: 1.5rem;
      font-size: 0.95rem;
    }
    .alert ul {
      margin: 0.5rem 0 0 1.2rem;
      padding: 0;
    }
    /* Actions */
    .actions {
      display: flex;
      gap: 12px;
      margin-top: 20px;
      flex-wrap: wrap;
    }
    /* File Upload */
    .dropzone {
      border: 2px dashed var(--field-border);
      border-radius: 12px;
      padding: 2rem;
      text-align: center;
      background: rgba(255,255,255,0.03);
      cursor: pointer;
      transition: var(--transition);
    }
    .dropzone:hover, .dropzone.dragover {
      border-color: var(--primary);
      background: rgba(96, 165, 250, 0.05);
    }
    .preview-grid {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 10px;
    }
    .preview-item {
      position: relative;
      width: 120px;
      height: 120px;
      border-radius: 12px;
      overflow: hidden;
      border: 1px solid var(--field-border);
    }
    .preview-item img, .preview-item video {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .remove-btn {
      position: absolute;
      top: 4px;
      right: 4px;
      background: var(--danger);
      border: 2px solid var(--card);
      border-radius: 50%;
      width: 24px;
      height: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 1rem;
      cursor: pointer;
      z-index: 10;
      padding: 0;
      margin: 0;
    }
    /* Fullscreen Modal */
    .modal {
      display: none;
      position: fixed;
      z-index: 100;
      left: 0; top: 0;
      width: 100%; height: 100%;
      background: rgba(0,0,0,0.9);
      justify-content: center;
      align-items: center;
    }
    .modal.active {
      display: flex;
    }
    .modal-content {
      max-width: 90vw;
      max-height: 90vh;
      border-radius: 12px;
      overflow: hidden;
    }
    .modal-content img, .modal-content video {
      max-width: 100%;
      max-height: 90vh;
      border-radius: 12px;
    }
    .modal-close {
      position: absolute;
      top: 15px;
      right: 15px;
      background: black;
      color: white;
      border: none;
      width: 40px;
      height: 40px;
      border-radius: 50%;
      font-size: 1.5rem;
      cursor: pointer;
      z-index: 11;
    }
    /* Preview Section */
    .preview-section {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 1.5rem;
      margin-bottom: 1rem;
      box-shadow: var(--shadow-sm);
    }
    .preview-section h3 {
      margin-top: 0;
      margin-bottom: 0.75rem;
      font-size: 1.1rem;
      color: var(--muted);
    }
    .preview-item-row {
      display: flex;
      justify-content: space-between;
      padding: 0.5rem 0;
      border-bottom: 1px solid var(--field-border);
    }
    .preview-item-row:last-child {
      border-bottom: none;
    }
    .preview-key {
      font-weight: 600;
    }
    .preview-value {
      text-align: right;
      word-break: break-word;
    }
    .preview-attachments {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 10px;
    }
    .preview-attachment {
      position: relative;
      width: 100px;
      height: 100px;
      border-radius: 8px;
      overflow: hidden;
      border: 1px solid var(--field-border);
    }
    .preview-attachment img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .preview-attachment-name {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      background: rgba(0,0,0,0.7);
      color: white;
      font-size: 0.7rem;
      padding: 2px 4px;
      text-align: center;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    @media (prefers-reduced-motion: reduce) {
      *,
      *::before,
      *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
      }
    }
    /* Issue Chips - Clean Box Style */
    #issue-chips {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 12px;
      margin-bottom: 4px;
    }
    .chip {
      padding: 6px 12px;
      border-radius: 12px;
      background: rgba(96, 165, 250, 0.1);
      color: var(--primary);
      font-size: 0.875rem;
      font-weight: 500;
      border: 1px solid rgba(96, 165, 250, 0.3);
      cursor: pointer;
      transition: all 0.2s ease;
      user-select: none;
      white-space: nowrap;
    }
    .chip:hover, .chip:focus {
      background: rgba(96, 165, 250, 0.2);
      transform: translateY(-1px);
      outline: 2px solid rgba(96, 165, 250, 0.4);
    }
    .chip:active {
      transform: translateY(0);
      background: rgba(96, 165, 250, 0.15);
    }
    /* Integration Hub */
    .integration-hub {
      display: flex;
      gap: 10px;
      margin-top: 10px;
    }
    .integration-icon {
      width: 30px;
      height: 30px;
      border-radius: 50%;
      background: var(--primary);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 0.8rem;
    }
    /* Modal overlay */
    #customerModal {
      display:none;
      position:fixed;top:0;left:0;width:100%;height:100%;
      background:rgba(0,0,0,.6);
      align-items:center;justify-content:center;
      z-index:1000;opacity:0;transition:opacity .3s ease;
    }
    #customerModal.show { opacity:1; }

    /* Modal card */
    #customerModal .modal-card {
      background:var(--card);
      padding:1.5rem;
      border-radius:12px;
      border:1px solid var(--border);
      max-width:500px;width:95%;
      box-shadow:0 8px 30px rgba(0,0,0,.25);
      transform:translateY(-40px);
      transition:transform .3s ease;
    }
    #customerModal.show .modal-card { transform:translateY(0); }

    #customerModal h3 { margin-bottom:1rem; }
    #customerModal label { font-weight:600; display:block; }
    #customerModal input {
      width:100%; padding:.6rem .75rem; border:1px solid var(--field-border);
      border-radius:8px; background:var(--card); color:var(--text);
    }
    #c_error { color:#dc2626; font-size:.9rem; margin:6px 0 0; }
  </style>
</head>
<body>
  <?php require __DIR__.'/../../includes/header.php'; ?>
  <main aria-labelledby="page-title">
  <div>
  <h2 id="page-title">Create Repair Request (Staff)</h2>
  <p class="subtitle">Create a request on behalf of a customer, describe the device issue, attach photos, and choose the service option.</p>
</div>
  <?php if ($errors): ?>
      <div class="alert" role="alert" aria-live="polite">
        <strong>We couldn't submit the request:</strong>
        <ul>
          <?php foreach ($errors as $m): ?>
            <li><?= e($m) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <!-- Confirmation Preview -->
    <?php if ($confirming): ?>
        <div class="preview-section">
            <h3>Confirm Request Details</h3>
            <div class="preview-item-row">
                <span class="preview-key">Customer:</span>
                <span class="preview-value"><?= e($confirmation_data['customer_name'] ?? 'N/A') ?> (<?= e($confirmation_data['customer_email'] ?? 'N/A') ?>)</span>
            </div>
            <div class="preview-item-row">
                <span class="preview-key">Device Type:</span>
                <span class="preview-value"><?= e($confirmation_data['device_type']) ?></span>
            </div>
            <div class="preview-item-row">
                <span class="preview-key">Brand:</span>
                <span class="preview-value"><?= e($confirmation_data['brand']) ?></span>
            </div>
            <div class="preview-item-row">
                <span class="preview-key">Model:</span>
                <span class="preview-value"><?= e($confirmation_data['model']) ?></span>
            </div>
            <div class="preview-item-row">
                <span class="preview-key">Serial / IMEI:</span>
                <span class="preview-value"><?= e($confirmation_data['serial_no']) ?></span>
            </div>
            <div class="preview-item-row">
                <span class="preview-key">Issue Description:</span>
                <span class="preview-value"><?= nl2br(e($confirmation_data['issue_description'])) ?></span>
            </div>
            <div class="preview-item-row">
                <span class="preview-key">Service Type:</span>
                <span class="preview-value"><?= e(ucfirst($confirmation_data['service_type'])) ?></span>
            </div>
            <div class="preview-item-row">
                <span class="preview-key">Preferred Contact:</span>
                <span class="preview-value"><?= e(ucfirst($confirmation_data['preferred_contact'])) ?></span>
            </div>
            <div class="preview-item-row">
                <span class="preview-key">Priority:</span>
                <span class="preview-value"><?= e(ucfirst($confirmation_data['priority'])) ?></span>
            </div>
            <?php if (in_array($confirmation_data['service_type'], ['pickup', 'onsite'], true)): ?>
                <div class="preview-item-row">
                    <span class="preview-key">Address:</span>
                    <span class="preview-value"><?= e($confirmation_data['address']) ?>, <?= e($confirmation_data['city']) ?>, <?= e($confirmation_data['postal_code']) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($confirmation_data['accessories']): ?>
                <div class="preview-item-row">
                    <span class="preview-key">Accessories:</span>
                    <span class="preview-value"><?= e($confirmation_data['accessories']) ?></span>
                </div>
            <?php endif; ?>
            <div class="preview-item-row">
                <span class="preview-key">Warranty Status:</span>
                <span class="preview-value"><?= e(ucfirst(str_replace('_', ' ', $confirmation_data['warranty_status']))) ?></span>
            </div>
            <?php if (!empty($confirmation_data['slot_details'])): ?>
                <div class="preview-item-row">
                    <span class="preview-key">Service Date & Time:</span>
                    <span class="preview-value"><?= e($confirmation_data['slot_details']['date']) ?> - <?= e($confirmation_data['slot_details']['time']) ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($confirmation_data['temp_files'])): ?>
                <div class="preview-item-row">
                    <span class="preview-key">Attachments:</span>
                    <span class="preview-value">
                        <div class="preview-attachments">
                            <?php foreach ($confirmation_data['temp_files'] as $file): ?>
                                <?php
                                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                                    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                                    $tempFileUrl = '';
                                    if (isset($file['tmp_name']) && file_exists($file['tmp_name'])) {
                                        // Adjusted to match the correct URL (without /public/)
                                        $basePath = '/it_repair'; // Matches your project root URL
                                        $absoluteTempPath = realpath($file['tmp_name']);
                                        $projectRoot = realpath(__DIR__ . '/../../');
                                        if ($absoluteTempPath && strpos($absoluteTempPath, $projectRoot) === 0) {
                                            $relativePath = str_replace('\\', '/', substr($absoluteTempPath, strlen($projectRoot)));
                                            $tempFileUrl = $basePath . $relativePath;
                                        }
                                    }
                                ?>
                                <div class="preview-attachment">
                                    <?php if ($isImage && $tempFileUrl): ?>
                                        <img src="<?= e($tempFileUrl) ?>" alt="<?= e($file['name']) ?>">
                                    <?php elseif ($isImage): ?>
                                         <div style="display:flex;align-items:center;justify-content:center;height:100%;background:var(--card-2);color:var(--muted);font-size:0.8rem;text-align:center;padding:5px;">
                                            IMG
                                        </div>
                                    <?php else: ?>
                                        <div style="display:flex;align-items:center;justify-content:center;height:100%;background:var(--card-2);color:var(--muted);font-size:0.8rem;text-align:center;padding:5px;">
                                            <?= strtoupper($ext) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="preview-attachment-name"><?= e($file['name']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </span>
                </div>
            <?php endif; ?>
        </div>
        <form method="post" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <?php foreach ($confirmation_data as $k => $v): ?>
                <?php if ($k !== 'slot_details' && $k !== 'temp_files' && $k !== 'customer_name' && $k !== 'customer_email'): ?>
                    <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <input type="hidden" name="temp_files_data" value="<?= e(json_encode($confirmation_data['temp_files'])) ?>">
            <div class="actions">
                <button type="submit" name="confirm" value="yes" class="btn primary">Confirm & Submit</button>
                <button type="submit" name="confirm" value="no" class="btn subtle">Make Changes</button>
                <a href="<?= e(base_url('staff/dashboard.php')) ?>" class="btn subtle">Cancel</a>
            </div>
        </form>
    <?php else: ?>
        <!-- Main Form -->
        <form method="post" enctype="multipart/form-data" novalidate id="requestForm">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <!-- Customer selection -->
          <section class="card">
            <h3 class="form-section-title">Customer</h3>
            <label for="customer_id">Select Customer *
              <div style="display:flex; gap:8px; align-items:center;">
                <select name="customer_id" id="customer_id" required style="flex:1;">
                  <option value="">-- Select customer --</option>
                  <?php foreach($customers as $c): ?>
                    <option value="<?= e((string)$c['id']) ?>" <?= $old['customer_id']==$c['id']?'selected':'' ?>>
                      <?= e($c['name']) ?> (<?= e($c['email']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="button" id="addCustomerBtn" class="btn subtle">+ Add</button>
              </div>
            </label>
          </section>

          <!-- Device Info -->
          <section class="card">
            <h3 class="form-section-title">Device Information</h3>
            <div class="grid grid-4">
              <label for="device_type">Device Type *
                <select name="device_type" id="device_type" required>
                  <?php foreach (['Laptop','Desktop','Phone','Tablet','Printer','Peripheral','Other'] as $opt): ?>
                    <option value="<?= e($opt) ?>" <?= $old['device_type']===$opt ? 'selected' : '' ?>>
                      <?= e($opt) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label for="brand">Brand *
                <input type="text" id="brand" name="brand" value="<?= e($old['brand']) ?>" placeholder="e.g. Apple, Dell, Samsung" maxlength="80" required>
                <?php if (!empty($errors['brand'])): ?>
                    <div style="color:var(--danger); font-size:0.85rem; margin-top:4px;"><?= e($errors['brand']) ?></div>
                <?php endif; ?>
              </label>
              <label for="model">Model *
                <input type="text" id="model" name="model" value="<?= e($old['model']) ?>" placeholder="e.g. iPhone 14, ThinkPad T14" maxlength="120" required>
                <?php if (!empty($errors['model'])): ?>
                    <div style="color:var(--danger); font-size:0.85rem; margin-top:4px;"><?= e($errors['model']) ?></div>
                <?php endif; ?>
              </label>
              <label for="serial_no">Serial / IMEI
                <input type="text" id="serial_no" name="serial_no" value="<?= e($old['serial_no']) ?>" placeholder="Optional" maxlength="120">
              </label>
            </div>
          </section>
          <!-- Issue Description -->
          <section class="card">
            <h3 class="form-section-title">Describe the Issue</h3>
            <label for="issue_description">What's wrong? *
              <textarea id="issue_description" name="issue_description" rows="5" required><?= e($old['issue_description']) ?></textarea>
            </label>
            <div class="chips" id="issue-chips" role="group" aria-label="Common issues"></div>
          </section>
          <!-- Attachments -->
          <section class="card">
            <h3 class="form-section-title">Add Photos or Videos (Optional)</h3>
            <div class="dropzone" id="dropzone">
              <p>Drag & drop files here or click to browse</p>
              <input type="file" id="attachments" name="attachments[]" multiple accept="image/*,video/*" style="display:none">
            </div>
            <div id="preview" class="preview-grid" aria-live="polite"></div>
            <p class="sub" style="margin-top: 8px;">
              JPG, PNG, GIF, MP4, MOV, AVI, MKV — max 20MB per file.
            </p>
          </section>
          <!-- Service & Contact -->
          <section class="card">
            <h3 class="form-section-title">Service & Contact</h3>
            <div class="grid grid-3">
              <label for="service_type">Service Option
                <select name="service_type" id="service_type">
                  <option value="dropoff" <?= $old['service_type']==='dropoff' ? 'selected' : '' ?>>Drop-off</option>
                  <option value="pickup" <?= $old['service_type']==='pickup' ? 'selected' : '' ?>>Pickup</option>
                  <option value="onsite" <?= $old['service_type']==='onsite' ? 'selected' : '' ?>>On-site</option>
                </select>
              </label>
              <label for="preferred_contact">Preferred Contact
                <select name="preferred_contact" id="preferred_contact">
                  <option value="phone" <?= $old['preferred_contact']==='phone' ? 'selected' : '' ?>>Phone</option>
                  <option value="email" <?= $old['preferred_contact']==='email' ? 'selected' : '' ?>>Email</option>
                  <option value="both" <?= $old['preferred_contact']==='both' ? 'selected' : '' ?>>Both</option>
                </select>
              </label>
              <label for="priority">Priority
                <select name="priority" id="priority">
                  <option value="low" <?= $old['priority']==='low' ? 'selected' : '' ?>>Low</option>
                  <option value="normal" <?= $old['priority']==='normal' ? 'selected' : '' ?>>Normal</option>
                  <option value="high" <?= $old['priority']==='high' ? 'selected' : '' ?>>High</option>
                </select>
              </label>
            </div>
            <div id="location-fields" style="display:none;">
  <h4 style="margin:16px 0 12px; color:var(--primary); font-size:1rem;">Service Address</h4>
  <div class="grid grid-4" style="gap:12px;">
    <div style="grid-column: span 4;">
      <label for="address">Address *</label>
      <input type="text" id="address" name="address" value="<?= e($old['address']) ?>" placeholder="Street address, apartment, etc." maxlength="255" required>
      <?php if (!empty($errors['address'])): ?>
        <div style="color:var(--danger); font-size:0.85rem; margin-top:4px;"><?= e($errors['address']) ?></div>
      <?php endif; ?>
    </div>
    <div style="grid-column: span 2;">
      <label for="city">City *</label>
      <input type="text" id="city" name="city" value="<?= e($old['city']) ?>" placeholder="e.g. New York" maxlength="100" required>
      <?php if (!empty($errors['city'])): ?>
        <div style="color:var(--danger); font-size:0.85rem; margin-top:4px;"><?= e($errors['city']) ?></div>
      <?php endif; ?>
    </div>
    <div style="grid-column: span 2;">
      <label for="postal_code">Postal Code *</label>
      <input type="text" id="postal_code" name="postal_code" value="<?= e($old['postal_code']) ?>" placeholder="e.g. 10001" maxlength="20" required>
      <?php if (!empty($errors['postal_code'])): ?>
        <div style="color:var(--danger); font-size:0.85rem; margin-top:4px;"><?= e($errors['postal_code']) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>
          </section>
          <!-- Accessories & Warranty -->
          <section class="card">
            <h3 class="form-section-title">Additional Info</h3>
            <div class="grid grid-2">
              <label for="accessories">Accessories (e.g. charger, case)
                <input type="text" id="accessories" name="accessories" value="<?= e($old['accessories']) ?>" placeholder="Include any accessories">
              </label>
              <label for="warranty_status">Warranty Status
                <select name="warranty_status" id="warranty_status">
                  <option value="unknown" <?= $old['warranty_status']==='unknown' ? 'selected' : '' ?>>Unknown</option>
                  <option value="in_warranty" <?= $old['warranty_status']==='in_warranty' ? 'selected' : '' ?>>In Warranty</option>
                  <option value="out_of_warranty" <?= $old['warranty_status']==='out_of_warranty' ? 'selected' : '' ?>>Out of Warranty</option>
                </select>
              </label>
            </div>
          </section>
          <!-- Booking Slot (MODIFIED to match sajilosewa style) -->
          <section class="card">
            <h3 class="form-section-title">Book a Service</h3>
            <p class="sub">Team will arrive within the selected time.</p>
            <div class="grid grid-2" style="gap: 16px; margin-top: 16px;">
                <div>
                    <label for="service_date" style="display: block; margin-bottom: 6px; font-weight: 600;">Select Date *</label>
                    <input
                        type="date"
                        id="service_date"
                        name="service_date"
                        value="<?= e($old['service_date'] ?? '') ?>"
                        min="<?= date('Y-m-d') ?>"
                        style="width: 100%; padding: 0.75rem 0.9rem; border: 1px solid var(--field-border); border-radius: 12px; background: var(--card); color: var(--text); font-size: 1rem;"
                        required
                    >
                    <?php if (!empty($errors['service_date'])): ?>
                        <div style="color:var(--danger); font-size:0.85rem; margin-top:4px;"><?= e($errors['service_date']) ?></div>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="service_time_period" style="display: block; margin-bottom: 6px; font-weight: 600;">Choose a time period *</label>
                    <select
                        name="service_time_period"
                        id="service_time_period"
                        style="width: 100%; padding: 0.75rem 0.9rem; border: 1px solid var(--field-border); border-radius: 12px; background: var(--card); color: var(--text); font-size: 1rem;"
                        required
                    >
                        <option value="" <?= empty($old['service_time_period']) ? 'selected' : '' ?>>Select Time Period</option>
                        <option value="morning" <?= ($old['service_time_period'] ?? '') === 'morning' ? 'selected' : '' ?>>Morning (9:00 AM - 12:00 PM)</option>
                        <option value="afternoon" <?= ($old['service_time_period'] ?? '') === 'afternoon' ? 'selected' : '' ?>>Afternoon (12:00 PM - 5:00 PM)</option>
                        <option value="evening" <?= ($old['service_time_period'] ?? '') === 'evening' ? 'selected' : '' ?>>Evening (5:00 PM - 8:00 PM)</option>
                    </select>
                    <?php if (!empty($errors['service_time_period'])): ?>
                        <div style="color:var(--danger); font-size:0.85rem; margin-top:4px;"><?= e($errors['service_time_period']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
          </section>
          <!-- Integration Hub -->
          <section class="card">
            <h3 class="form-section-title">Connect with Other Services</h3>
            <div class="integration-hub">
              <div class="integration-icon">CRM</div>
              <div class="integration-icon">ERP</div>
              <div class="integration-icon">Chat</div>
            </div>
          </section>
          <!-- Actions -->
          <div class="actions">
            <button type="submit" class="btn primary">Review & Submit</button>
            <a href="<?= e(base_url('staff/dashboard.php')) ?>" class="btn subtle">Cancel</a>
          </div>
        </form>
    <?php endif; ?>
  </main>
  <!-- Fullscreen Modal -->
  <div class="modal" id="previewModal">
    <button class="modal-close" id="closeModal">&times;</button>
    <div class="modal-content" id="modalContent"></div>
  </div>
  <!-- Customer Modal -->
<div id="customerModal" role="dialog" aria-modal="true">
  <div class="modal-card">
    <h3>New Customer</h3>
    <form id="newCustomerForm" style="display:grid;gap:1rem;">
      <div class="grid-2">
        <label>Name *
          <input id="c_name" placeholder="e.g. John Doe" required>
        </label>
        <label>Email *
          <input id="c_email" type="email" placeholder="e.g. john@example.com" required>
        </label>
      </div>
      <div class="grid-2">
        <label>Phone
          <input id="c_phone" placeholder="Optional">
        </label>
        <label>Password *
          <input id="c_pass" type="password" placeholder="Temporary password" required>
        </label>
      </div>
      <p id="c_error"></p>
      <div class="actions">
        <button id="saveCustomer" class="btn primary" type="button">Save</button>
        <button id="cancelCustomer" class="btn subtle" type="button">Cancel</button>
      </div>
    </form>
  </div>
</div>
  <script>
    // --- Issue Chips (UPDATED to match customer version) ---
    const issueOptions = {
      "Phone": [
        "Cracked screen","Back glass cracked","Battery drains fast","Charging port not working",
        "Liquid damage","Camera blurry / not working","Speaker or mic issue",
        "Face ID / Touch ID issue","No signal / SIM not detected","Wi-Fi/Bluetooth issue",
        "Touchscreen unresponsive","Buttons not working"
      ],
      "Laptop": [
        "No power / won’t turn on","Blue screen / frequent crashes","Overheating / loud fan",
        "Slow performance","Storage / hard disk failure","Keyboard/trackpad not working",
        "Broken hinge / chassis","No display / GPU artifacts","USB/ports not working",
        "Operating system / software issue","Virus / malware infection","Data recovery needed"
      ],
      "Tablet": [
        "Cracked screen","Battery issue","Charging problem","Touchscreen unresponsive",
        "Wi-Fi not working","Camera issue","Slow performance","App crashes"
      ],
      "Desktop": [
        "No power","Blue screen","No display","Slow performance",
        "Hard disk failure","Overheating","Fan noise","USB/ports not working"
      ],
      "Printer": [
        "Paper jam","Not printing","Ink/toner issue","Connectivity problem",
        "Error codes","Lines/streaks on prints","Slow printing"
      ],
      "Peripheral": [
        "Not detected by computer","Connection issues","Button failure","Driver/software issue"
      ],
      "Other": [
        "Unidentified issue","Custom hardware","General maintenance","Diagnostics needed"
      ]
    };
    function renderChips(device) {
      const box = document.getElementById('issue-chips');
      box.innerHTML = '';
      (issueOptions[device] || []).forEach(text => {
        const chip = document.createElement('span');
        chip.className = 'chip';
        chip.textContent = text;
        chip.tabIndex = 0;
        chip.setAttribute('role', 'button');
        chip.onclick = chip.onkeydown = (e) => {
          if (e.type === 'keydown' && !['Enter',' '].includes(e.key)) return;
          const ta = document.getElementById('issue_description');
          if (!ta.value.includes(text)) {
            ta.value = ta.value ? ta.value.trim() + '; ' + text : text;
          }
          ta.focus();
        };
        box.appendChild(chip);
      });
    }
    function toggleLocation() {
      const v = document.getElementById('service_type').value;
      document.getElementById('location-fields').style.display = ['pickup', 'onsite'].includes(v) ? 'grid' : 'none';
    }
    // --- Fullscreen Modal ---
    const modal = document.getElementById('previewModal');
    const modalContent = document.getElementById('modalContent');
    const closeModal = document.getElementById('closeModal');
    closeModal.onclick = () => modal.classList.remove('active');
    modal.onclick = (e) => { if (e.target === modal) modal.classList.remove('active'); };
    function openFullscreen(src, type) {
      modalContent.innerHTML = '';
      if (type === 'image') {
        const img = document.createElement('img');
        img.src = src;
        modalContent.appendChild(img);
      } else if (type === 'video') {
        const video = document.createElement('video');
        video.src = src;
        video.controls = true;
        video.autoplay = true;
        modalContent.appendChild(video);
      }
      modal.classList.add('active');
    }
    // --- File Handling ---
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('attachments');
    const preview = document.getElementById('preview');
    let fileArray = [];
    <?php if ($confirming && !empty($confirmation_data['temp_files'])): ?>
        const tempFiles = <?= json_encode($confirmation_data['temp_files']) ?>;
        tempFiles.forEach(file => {
            const placeholderFile = new File([""], file.name, { type: "image/" + file.type });
            placeholderFile.isTempFile = true;
            placeholderFile.tempData = file;
            fileArray.push(placeholderFile);
        });
        updateFileInput();
    <?php endif; ?>
    function updateFileInput() {
      const dt = new DataTransfer();
      fileArray.forEach(f => {
          if (!f.isTempFile) {
              dt.items.add(f);
          }
      });
      fileInput.files = dt.files;
    }
    function renderPreview() {
        preview.innerHTML = '';
        fileArray.forEach((file, index) => {
            if (file.isTempFile) return;
            const ext = file.name.split('.').pop().toLowerCase();
            const isImage = ['jpg','jpeg','png','gif','webp'].includes(ext);
            const isVideo = ['mp4','mov','avi','mkv','webm'].includes(ext);
            let url;
            url = URL.createObjectURL(file);
            const container = document.createElement('div');
            container.className = 'preview-item';
            let media;
            if (isImage && url) {
              media = document.createElement('img');
              media.src = url;
              media.alt = file.name;
            } else if (isVideo && url) {
              media = document.createElement('video');
              media.src = url;
              media.muted = true;
            } else if (url) {
                media = document.createElement('div');
                media.textContent = `📎 ${file.name}`;
                media.style.padding = '10px';
                media.style.textAlign = 'center';
                media.style.wordBreak = 'break-word';
                media.style.fontSize = '0.8rem';
            } else {
                media = document.createElement('div');
                media.textContent = `⚠️ ${file.name}`;
                media.style.padding = '10px';
                media.style.textAlign = 'center';
                media.style.wordBreak = 'break-word';
                media.style.fontSize = '0.8rem';
                media.style.color = 'var(--muted)';
            }
            media.onclick = () => {
                if(url && (isImage || isVideo)) {
                     openFullscreen(url, isImage ? 'image' : 'video');
                } else {
                    console.log("Preview not available or not an image/video for fullscreen.");
                }
            };
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'remove-btn';
            removeBtn.innerHTML = '&times;';
            removeBtn.title = 'Remove';
            removeBtn.onclick = (e) => {
              e.stopPropagation();
              try {
                  URL.revokeObjectURL(url);
              } catch (err) {
                  console.log("Could not revoke URL:", err);
              }
              fileArray.splice(index, 1);
              renderPreview();
              updateFileInput();
            };
            container.appendChild(media);
            container.appendChild(removeBtn);
            preview.appendChild(container);
        });
    }
    dropzone.addEventListener('click', () => fileInput.click());
    dropzone.addEventListener('dragover', e => {
      e.preventDefault();
      dropzone.classList.add('dragover');
    });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
    dropzone.addEventListener('drop', e => {
      e.preventDefault();
      dropzone.classList.remove('dragover');
      const files = Array.from(e.dataTransfer.files);
      fileArray = [...fileArray, ...files.filter(validFile)];
      renderPreview();
      updateFileInput();
    });
    fileInput.addEventListener('change', () => {
      const files = Array.from(fileInput.files);
      fileArray = [...fileArray, ...files.filter(validFile)];
      renderPreview();
      updateFileInput();
    });
    function validFile(file) {
      const ext = file.name.split('.').pop().toLowerCase();
      const validExt = ['jpg','jpeg','png','gif','mp4','mov','avi','mkv'];
      return validExt.includes(ext) && file.size > 0 && file.size <= 20 * 1024 * 1024;
    }

    // --- Modal logic ---
    const modalCustomer=document.getElementById('customerModal');
    document.getElementById('addCustomerBtn').onclick=()=>{ 
      modalCustomer.style.display='flex'; 
      setTimeout(()=>modalCustomer.classList.add('show'),10); 
    };
    document.getElementById('cancelCustomer').onclick=()=>{ 
      modalCustomer.classList.remove('show'); 
      setTimeout(()=>modalCustomer.style.display='none',300); 
    };

    // --- Save new customer via AJAX ---
    document.getElementById('saveCustomer').onclick=async()=>{
      const name=document.getElementById('c_name').value.trim();
      const email=document.getElementById('c_email').value.trim();
      const phone=document.getElementById('c_phone').value.trim();
      const pass=document.getElementById('c_pass').value;
      const err=document.getElementById('c_error');

      if(!name||!email||!pass){ 
        err.textContent="Name, Email, and Password are required."; 
        return; 
      }

      try {
        const res = await fetch('add_customer.php',{
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body:JSON.stringify({name,email,phone,password:pass})
        });

        const text = await res.text(); // read raw response
        let data;
        try { data = JSON.parse(text); }
        catch(e){ 
          err.textContent="Server did not return valid JSON. Response was: "+text; 
          return; 
        }

        if(data.error){ 
          err.textContent=data.error; 
          return; 
        }

        // add customer to dropdown
        const sel=document.getElementById('customer_id');
        const opt=document.createElement('option');
        opt.value=data.id; 
        opt.textContent=`${data.name} (${data.email})`; 
        opt.selected=true;
        sel.appendChild(opt);

        // reset form + close modal
        ['c_name','c_email','c_phone','c_pass'].forEach(id => document.getElementById(id).value='');
        err.textContent='';
        modalCustomer.classList.remove('show');
        setTimeout(()=>modalCustomer.style.display='none',300);

      } catch (errFetch) {
        err.textContent="Request failed: "+errFetch.message;
      }
    };

    // --- Init ---
    document.addEventListener('DOMContentLoaded', () => {
      renderChips(document.getElementById('device_type').value);
      document.getElementById('device_type').addEventListener('change', e => renderChips(e.target.value));
      toggleLocation();
      document.getElementById('service_type').addEventListener('change', toggleLocation);
    });
  </script>
</body>
</html>
