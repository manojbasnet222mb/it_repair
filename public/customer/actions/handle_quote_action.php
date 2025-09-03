<?php
/**
 * Handles customer actions for quotes (approve/reject).
 * Located at: /it_repair/public/customer/actions/handle_quote_action.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_role('customer');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    }

    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $reject_reason = trim($_POST['reject_reason'] ?? '');

    if (!$invoice_id) {
        $errors[] = 'Invalid invoice ID.';
    }
    if (!in_array($action, ['approve', 'reject'])) {
        $errors[] = 'Invalid action.';
    }

    if (!$errors) {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            // 1. Verify invoice belongs to customer and status is Pending
            $stmt = $pdo->prepare("
                SELECT i.*, rr.customer_id
                FROM invoices i
                JOIN repair_requests rr ON i.request_id = rr.id
                WHERE i.id = ? AND rr.customer_id = ? AND i.quote_status = 'Pending'
            ");
            $stmt->execute([$invoice_id, $_SESSION['user']['id']]);
            $invoice = $stmt->fetch();

            if (!$invoice) {
                 $errors[] = 'Quote not found or not eligible for this action.';
            } else {
                // 2. Update invoice status
                if ($action === 'approve') {
                    $stmt = $pdo->prepare("UPDATE invoices SET quote_status = 'Approved' WHERE id = ?");
                    $stmt->execute([$invoice_id]);

                    // 3a. Optionally, add a note to the request history
                    $historyStmt = $pdo->prepare("
                        INSERT INTO request_status_history (request_id, status, note, changed_by)
                        VALUES (?, 'In Repair', 'Customer approved quote.', ?)
                    ");
                    $historyStmt->execute([$invoice['request_id'], $_SESSION['user']['id']]);

                    $note = 'Quote approved successfully.';
                    // Consider if you want to automatically trigger the 'Send to Billing' action in staff/repair.php
                    // or if staff needs to manually click it.

                } elseif ($action === 'reject') {
                    $stmt = $pdo->prepare("UPDATE invoices SET quote_status = 'Rejected' WHERE id = ?");
                    $stmt->execute([$invoice_id]);

                    // 3b. Add a note to the request history, including the reason
                    $noteText = 'Customer rejected quote.' . ($reject_reason ? ' Reason: ' . $reject_reason : '');
                    $historyStmt = $pdo->prepare("
                        INSERT INTO request_status_history (request_id, status, note, changed_by)
                        VALUES (?, 'In Repair', ?, ?)
                    ");
                    $historyStmt->execute([$invoice['request_id'], $noteText, $_SESSION['user']['id']]);

                    $note = 'Quote rejected.';
                }
                $pdo->commit();
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Quote Action Error (Invoice ID: $invoice_id, User ID: {$_SESSION['user']['id']}): " . $e->getMessage());
            $errors[] = 'An error occurred while processing your request. Please try again.';
        }
    }
}

// Redirect back to the requests page with feedback
$redirectUrl = base_url('customer/requests.php');
if (isset($note)) {
    $redirectUrl .= '?note=' . urlencode($note);
} elseif (!empty($errors)) {
    $errorMsg = implode('; ', $errors); // Join errors if multiple
    $redirectUrl .= '?error=' . urlencode($errorMsg);
}

header("Location: " . $redirectUrl);
exit;

?>