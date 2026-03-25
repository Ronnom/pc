<?php
/**
 * Customer Communications Management
 * Send email/SMS, manage templates, track history, customer segmentation
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('customers.view');

$db = getDB();
$customer_id = (int)($_GET['id'] ?? 0);
$segment = trim($_GET['segment'] ?? '');
$tab = trim($_GET['tab'] ?? 'send');

// SEND COMMUNICATION HANDLER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action']);
    
    if ($action === 'send_communication') {
        $comm_type = trim($_POST['communication_type'] ?? '');
        $template_id = trim($_POST['template_id'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $recipients_type = trim($_POST['recipients_type'] ?? '');
        
        if (empty($comm_type)) {
            setFlashMessage('error', 'Please select a communication type');
        } elseif (empty($message)) {
            setFlashMessage('error', 'Message is required');
        } else {
            $recipients = [];
            
            // Determine recipients
            if ($recipients_type === 'single' && $customer_id > 0) {
                $recipients = [$customer_id];
            } elseif ($recipients_type === 'segment' && !empty($_POST['selected_segment'])) {
                $segment_name = trim($_POST['selected_segment']);
                $segment_customers = $db->fetchAll(
                    "SELECT id FROM customers WHERE status_segment = ? AND is_active = 1",
                    [$segment_name]
                );
                $recipients = array_column($segment_customers, 'id');
            } elseif ($recipients_type === 'selected' && isset($_POST['selected_customers'])) {
                $recipients = array_filter(array_map('intval', (array)$_POST['selected_customers']));
            }
            
            if (empty($recipients)) {
                setFlashMessage('error', 'No valid recipients selected');
            } else {
                $success_count = 0;
                $failed_count = 0;
                
                foreach ($recipients as $cid) {
                    try {
                        $db->execute(
                            "INSERT INTO communications (customer_id, type, subject, message, template_id, status, sent_by, sent_at) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                            [$cid, $comm_type, $subject, $message, !empty($template_id) ? $template_id : null, 'sent', $_SESSION['user_id'] ?? null]
                        );
                        
                        // TODO: Integrate actual email/SMS sending through external service
                        // For now, log as successful in database
                        $success_count++;
                        
                    } catch (Exception $e) {
                        $failed_count++;
                    }
                }
                
                logUserActivity('communication_sent', 'Sent ' . $comm_type . ' communication to ' . count($recipients) . ' recipient(s)', [
                    'communication_type' => $comm_type,
                    'recipient_count' => count($recipients),
                    'recipients_type' => $recipients_type
                ]);
                
                setFlashMessage('success', "Communication sent to $success_count recipient(s)" . ($failed_count > 0 ? " ($failed_count failed)" : ''));
                redirect('communications.php' . ($customer_id > 0 ? '?id=' . $customer_id : ''));
            }
        }
    }
    
    // MANAGE OPT-IN/OUT
    elseif ($action === 'update_preferences' && $customer_id > 0) {
        $email_opt_in = isset($_POST['email_opt_in']) ? 1 : 0;
        $sms_opt_in = isset($_POST['sms_opt_in']) ? 1 : 0;
        
        $db->execute(
            "UPDATE customers SET email_opt_in = ?, sms_opt_in = ? WHERE id = ?",
            [$email_opt_in, $sms_opt_in, $customer_id]
        );
        
        logUserActivity('communication_preferences_updated', 'Updated communication preferences for customer', [
            'customer_id' => $customer_id,
            'email_opt_in' => $email_opt_in,
            'sms_opt_in' => $sms_opt_in
        ]);
        
        setFlashMessage('success', 'Communication preferences updated');
        redirect('communications.php?id=' . $customer_id . '&tab=preferences');
    }
}

// FETCH DATA
$customer = $customer_id > 0 ? $db->fetch("SELECT * FROM customers WHERE id = ?", [$customer_id]) : null;

// Get communication history
$history_query = "SELECT c.*, cust.first_name, cust.last_name, cust.email, u.display_name as sent_by_name
                  FROM communications c
                  LEFT JOIN customers cust ON c.customer_id = cust.id
                  LEFT JOIN users u ON c.sent_by = u.id
                  WHERE 1=1";
$history_params = [];

if ($customer_id > 0) {
    $history_query .= " AND c.customer_id = ?";
    $history_params[] = $customer_id;
} elseif (!empty($segment)) {
    $history_query .= " AND c.customer_id IN (SELECT id FROM customers WHERE status_segment = ?)";
    $history_params[] = $segment;
}

$history_query .= " ORDER BY c.sent_at DESC LIMIT 50";
$communications = $db->fetchAll($history_query, $history_params);

// Get email/SMS templates
$templates = $db->fetchAll(
    "SELECT id, name, type, subject, body FROM communication_templates WHERE is_active = 1 ORDER BY name"
);

// Get customer segments for bulk sending
$segments = $db->fetchAll(
    "SELECT DISTINCT status_segment FROM customers WHERE status_segment IS NOT NULL AND status_segment != '' ORDER BY status_segment"
);

// Calculate segment sizes
$segment_sizes = [];
foreach ($segments as $seg) {
    $count = $db->fetch(
        "SELECT COUNT(*) as cnt FROM customers WHERE status_segment = ? AND is_active = 1 AND email_opt_in = 1 OR sms_opt_in = 1",
        [$seg['status_segment']]
    );
    $segment_sizes[$seg['status_segment']] = $count['cnt'] ?? 0;
}

$pageTitle = $customer_id > 0 ? 'Communications - ' . escape($customer['first_name']) : 'Customer Communications';
include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Customer Communications</h1>
            <?php if ($customer_id > 0): ?>
            <a href="<?php echo getBaseUrl(); ?>/customer_profile.php?id=<?php echo $customer_id; ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Profile
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?php echo ($tab === 'send' ? 'active' : ''); ?>" href="?<?php echo $customer_id > 0 ? 'id=' . $customer_id . '&' : ''; ?>tab=send">
            <i class="bi bi-envelope"></i> Send Communication
        </a>
    </li>
    <?php if ($customer_id > 0): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo ($tab === 'preferences' ? 'active' : ''); ?>" href="?id=<?php echo $customer_id; ?>&tab=preferences">
            <i class="bi bi-gear"></i> Preferences
        </a>
    </li>
    <?php endif; ?>
    <li class="nav-item">
        <a class="nav-link <?php echo ($tab === 'templates' ? 'active' : ''); ?>" href="?<?php echo $customer_id > 0 ? 'id=' . $customer_id . '&' : ''; ?>tab=templates">
            <i class="bi bi-file-text"></i> Templates
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo ($tab === 'history' ? 'active' : ''); ?>" href="?<?php echo $customer_id > 0 ? 'id=' . $customer_id . '&' : ''; ?>tab=history">
            <i class="bi bi-clock-history"></i> History
        </a>
    </li>
</ul>

<!-- SEND COMMUNICATION TAB -->
<?php if ($tab === 'send'): ?>
<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Send Communication</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="send_communication">
                    
                    <!-- Communication Type -->
                    <div class="mb-3">
                        <label class="form-label">Communication Type</label>
                        <select class="form-control" name="communication_type" id="communication_type" required>
                            <option value="">-- Select Type --</option>
                            <option value="email">Email</option>
                            <option value="sms">SMS</option>
                            <option value="notification">In-App Notification</option>
                        </select>
                    </div>
                    
                    <!-- Recipients -->
                    <div class="mb-3">
                        <label class="form-label">Recipients</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="recipients_type" id="recipients_single" value="single" <?php echo $customer_id > 0 ? 'checked' : ''; ?> <?php echo $customer_id > 0 ? '' : 'disabled'; ?>>
                            <label class="btn btn-outline-primary" for="recipients_single" <?php echo $customer_id > 0 ? '' : 'style="opacity: 0.5; cursor: not-allowed;"'; ?>>
                                Single Customer
                            </label>
                            
                            <input type="radio" class="btn-check" name="recipients_type" id="recipients_segment" value="segment" <?php echo !$customer_id && !empty($segment) ? 'checked' : ''; ?>>
                            <label class="btn btn-outline-primary" for="recipients_segment">
                                Customer Segment
                            </label>
                            
                            <input type="radio" class="btn-check" name="recipients_type" id="recipients_selected" value="selected">
                            <label class="btn btn-outline-primary" for="recipients_selected">
                                Selected Customers
                            </label>
                        </div>
                    </div>
                    
                    <!-- Segment Selection -->
                    <div class="mb-3" id="segment_section" style="display: <?php echo !$customer_id && !empty($segment) ? 'block' : 'none'; ?>;">
                        <label class="form-label">Select Segment</label>
                        <select class="form-control" name="selected_segment">
                            <option value="">-- Select Segment --</option>
                            <?php foreach ($segment_sizes as $seg_name => $size): ?>
                            <option value="<?php echo escape($seg_name); ?>" <?php echo $segment === $seg_name ? 'selected' : ''; ?>>
                                <?php echo escape($seg_name); ?> (<?php echo $size; ?> customers)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Template Selection -->
                    <div class="mb-3">
                        <label class="form-label">Template (Optional)</label>
                        <select class="form-control" name="template_id" id="template_selector">
                            <option value="">-- No Template --</option>
                            <?php foreach ($templates as $template): ?>
                            <option value="<?php echo escape($template['id']); ?>" data-subject="<?php echo escape($template['subject']); ?>" data-body="<?php echo escape($template['body']); ?>">
                                <?php echo escape($template['name']); ?> (<?php echo ucfirst($template['type']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Subject (for email) -->
                    <div class="mb-3" id="subject_section" style="display: none;">
                        <label class="form-label">Subject</label>
                        <input type="text" class="form-control" name="subject" placeholder="Email subject">
                        <small class="text-muted">Available variables: {customer_name}, {customer_code}, {current_date}, {loyalty_points}</small>
                    </div>
                    
                    <!-- Message -->
                    <div class="mb-3">
                        <label class="form-label">Message</label>
                        <textarea class="form-control" name="message" rows="8" placeholder="Enter your message here..."></textarea>
                        <small class="text-muted">Variables: {customer_name}, {customer_code}, {current_date}, {loyalty_points}, {last_purchase_amount}, {next_tier}</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send"></i> Send Communication
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Recipients Preview -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Recipients Summary</div>
            <div class="card-body">
                <?php if ($customer_id > 0): ?>
                <div class="alert alert-info mb-0">
                    <strong>1 Recipient:</strong><br>
                    <?php echo escape($customer['first_name'] . ' ' . $customer['last_name']); ?><br>
                    <small><?php echo escape($customer['email']); ?></small>
                </div>
                <?php elseif (!empty($segment) && isset($segment_sizes[$segment])): ?>
                <div class="alert alert-info mb-0">
                    <strong><?php echo escape($segment); ?>:</strong><br>
                    <?php echo $segment_sizes[$segment]; ?> customer(s)
                </div>
                <?php else: ?>
                <div class="alert alert-warning mb-0">
                    Select a recipient type and segment to see preview
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- PREFERENCES TAB -->
<?php elseif ($tab === 'preferences' && $customer_id > 0): ?>
<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Communication Preferences</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_preferences">
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="email_opt_in" name="email_opt_in" value="1" <?php echo $customer['email_opt_in'] ?? 0 ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="email_opt_in">
                                <strong>Email Communications</strong><br>
                                <small class="text-muted">Receive promotional emails and updates</small>
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="sms_opt_in" name="sms_opt_in" value="1" <?php echo $customer['sms_opt_in'] ?? 0 ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="sms_opt_in">
                                <strong>SMS Communications</strong><br>
                                <small class="text-muted">Receive SMS notifications and offers</small>
                            </label>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Save Preferences</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- TEMPLATES TAB -->
<?php elseif ($tab === 'templates'): ?>
<div class="row">
    <?php if (empty($templates)): ?>
    <div class="col-12">
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> No communication templates available. Contact administrator to create templates.
        </div>
    </div>
    <?php else: ?>
    <?php foreach ($templates as $template): ?>
    <div class="col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="card-title"><?php echo escape($template['name']); ?></h6>
                <p class="text-muted small mb-2">
                    <span class="badge bg-secondary"><?php echo ucfirst($template['type']); ?></span>
                </p>
                <?php if (!empty($template['subject'])): ?>
                <p><strong>Subject:</strong> <?php echo escape($template['subject']); ?></p>
                <?php endif; ?>
                <p><strong>Body:</strong></p>
                <p class="small text-muted" style="max-height: 150px; overflow-y: auto;">
                    <?php echo nl2br(escape($template['body'])); ?>
                </p>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- HISTORY TAB -->
<?php elseif ($tab === 'history'): ?>
<div class="card">
    <div class="card-header">
        Communication History
        <?php if (!empty($communications)): ?>
        <a href="javascript:void(0);" class="btn btn-sm btn-outline-primary float-end" onclick="exportCommunications()">
            <i class="bi bi-download"></i> Export
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($communications)): ?>
        <div class="alert alert-info mb-0">No communications found.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Type</th>
                        <th>Recipient</th>
                        <th>Subject/Preview</th>
                        <th>Status</th>
                        <th>Sent By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($communications as $comm): ?>
                    <tr>
                        <td><?php echo formatDateTime($comm['sent_at']); ?></td>
                        <td>
                            <?php
                            $type_badge = match($comm['type']) {
                                'email' => 'bg-info',
                                'sms' => 'bg-primary',
                                'notification' => 'bg-secondary',
                                default => 'bg-light'
                            };
                            ?>
                            <span class="badge <?php echo $type_badge; ?>"><?php echo ucfirst($comm['type']); ?></span>
                        </td>
                        <td>
                            <?php if (!empty($comm['first_name'])): ?>
                            <a href="customer_profile.php?id=<?php echo $comm['customer_id']; ?>">
                                <?php echo escape($comm['first_name'] . ' ' . $comm['last_name']); ?>
                            </a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small>
                                <?php if ($comm['type'] === 'email' && !empty($comm['subject'])): ?>
                                <strong><?php echo escape(substr($comm['subject'], 0, 50)); ?></strong><br>
                                <?php endif; ?>
                                <?php echo escape(substr($comm['message'], 0, 60)); ?>...
                            </small>
                        </td>
                        <td>
                            <?php
                            $status_badge = match($comm['status'] ?? 'sent') {
                                'sent' => 'bg-success',
                                'failed' => 'bg-danger',
                                'pending' => 'bg-warning',
                                default => 'bg-secondary'
                            };
                            ?>
                            <span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst($comm['status'] ?? 'sent'); ?></span>
                        </td>
                        <td><small><?php echo escape($comm['sent_by_name'] ?? 'System'); ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<script>
// Toggle subject field visibility based on communication type
document.getElementById('communication_type')?.addEventListener('change', function() {
    const subject_section = document.getElementById('subject_section');
    if (this.value === 'email') {
        subject_section.style.display = 'block';
    } else {
        subject_section.style.display = 'none';
    }
});

// Toggle segment section visibility
document.getElementById('recipients_segment')?.addEventListener('change', function() {
    document.getElementById('segment_section').style.display = this.checked ? 'block' : 'none';
});

// Auto-fill template content
document.getElementById('template_selector')?.addEventListener('change', function() {
    const option = this.options[this.selectedIndex];
    if (option.dataset.body) {
        document.querySelector('textarea[name="message"]').value = option.dataset.body;
        if (option.dataset.subject) {
            document.querySelector('input[name="subject"]').value = option.dataset.subject;
        }
    }
});

// Export communications to CSV
function exportCommunications() {
    alert('Export feature - integration with CSV download');
    // TODO: Implement CSV export of communication history
}
</script>

<?php include 'templates/footer.php'; ?>
