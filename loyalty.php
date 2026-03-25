<?php
/**
 * Loyalty Program Management
 * Display loyalty points, tier progression, redemption options, and rewards
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('customers.view');

$db = getDB();
$customer_id = (int)($_GET['id'] ?? 0);

if (!$customer_id) {
    setFlashMessage('error', 'Invalid customer ID');
    redirect('customers.php');
}

// Fetch customer
$customer = $db->fetch(
    "SELECT * FROM customers WHERE id = ?",
    [$customer_id]
);

if (!$customer) {
    setFlashMessage('error', 'Customer not found');
    redirect('customers.php');
}

// Define loyalty tiers
$tiers = [
    'Bronze' => ['min_points' => 0, 'max_points' => 499, 'discount' => 5, 'benefits' => '5% discount on all purchases'],
    'Silver' => ['min_points' => 500, 'max_points' => 999, 'discount' => 10, 'benefits' => '10% discount on all purchases + birthday reward'],
    'Gold' => ['min_points' => 1000, 'max_points' => 999999, 'discount' => 15, 'benefits' => '15% discount + free shipping + exclusive sales access']
];

// Determine current tier
$current_tier = $customer['loyalty_tier'] ?? 'Bronze';
$current_points = (int)($customer['loyalty_points'] ?? 0);

// Find next tier
$next_tier = null;
$points_to_next = 0;
if ($current_points < 500) {
    $next_tier = 'Silver';
    $points_to_next = 500 - $current_points;
} elseif ($current_points < 1000) {
    $next_tier = 'Gold';
    $points_to_next = 1000 - $current_points;
}

// Check for birthday reward eligibility
$is_birthday_month = false;
$birthday_reward_given = false;
if (!empty($customer['dob'])) {
    $dob = new DateTime($customer['dob']);
    $today = new DateTime();
    $is_birthday_month = ($dob->format('m') === $today->format('m'));
    
    // Check if birthday reward was already given this month
    $birthday_reward = $db->fetch(
        "SELECT id FROM loyalty_points 
         WHERE customer_id = ? AND type = 'birthday' 
         AND MONTH(created_at) = ? AND YEAR(created_at) = ?",
        [$customer_id, $today->format('m'), $today->format('Y')]
    );
    $birthday_reward_given = !empty($birthday_reward);
}

// Handle birthday reward grant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'grant_birthday_reward' && !$birthday_reward_given && $is_birthday_month) {
        $db->execute(
            "INSERT INTO loyalty_points (customer_id, points, type, description, created_at) 
             VALUES (?, ?, ?, ?, NOW())",
            [$customer_id, 100, 'birthday', 'Birthday bonus reward']
        );
        
        $db->execute(
            "UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?",
            [100, $customer_id]
        );
        
        logUserActivity('loyalty_birthday_reward', 'Granted birthday reward to customer', [
            'customer_id' => $customer_id,
            'points' => 100
        ]);
        
        setFlashMessage('success', 'Birthday reward of 100 points granted!');
        redirect('loyalty.php?id=' . $customer_id);
    }
    
    // Handle points redemption
    if ($_POST['action'] === 'redeem_points') {
        $redemption_method = trim($_POST['redemption_method'] ?? 'discount');
        $points_to_redeem = (int)($_POST['points_to_redeem'] ?? 0);
        
        if ($points_to_redeem <= 0) {
            setFlashMessage('error', 'Invalid points amount');
        } elseif ($points_to_redeem > $current_points) {
            setFlashMessage('error', 'Insufficient loyalty points');
        } else {
            // Calculate discount value (100 points = $10 default)
            $discount_value = ($points_to_redeem / 100) * 10;
            
            // Record redemption
            $db->execute(
                "INSERT INTO loyalty_points (customer_id, points, type, description, created_at) 
                 VALUES (?, ?, ?, ?, NOW())",
                [$customer_id, -$points_to_redeem, 'redemption', "Redeemed $" . number_format($discount_value, 2) . " discount"]
            );
            
            // Deduct points from customer
            $db->execute(
                "UPDATE customers SET loyalty_points = loyalty_points - ? WHERE id = ?",
                [$points_to_redeem, $customer_id]
            );
            
            logUserActivity('loyalty_redemption', 'Customer redeemed loyalty points', [
                'customer_id' => $customer_id,
                'points_redeemed' => $points_to_redeem,
                'discount_value' => $discount_value,
                'method' => $redemption_method
            ]);
            
            setFlashMessage('success', 'Points redeemed! Discount of ' . formatCurrency($discount_value) . ' applied');
            redirect('loyalty.php?id=' . $customer_id);
        }
    }
}

// Get points history
$points_history = $db->fetchAll(
    "SELECT id, points, type, description, created_at
     FROM loyalty_points
     WHERE customer_id = ?
     ORDER BY created_at DESC
     LIMIT 20",
    [$customer_id]
);

// Get earned points summary
$points_summary = $db->fetch(
    "SELECT 
        SUM(CASE WHEN type = 'purchase' THEN points ELSE 0 END) as purchase_points,
        SUM(CASE WHEN type = 'birthday' THEN points ELSE 0 END) as birthday_points,
        SUM(CASE WHEN type = 'referral' THEN points ELSE 0 END) as referral_points,
        SUM(CASE WHEN type = 'redemption' THEN ABS(points) ELSE 0 END) as redeemed_points
     FROM loyalty_points
     WHERE customer_id = ?",
    [$customer_id]
);

$pageTitle = 'Loyalty Program - ' . escape($customer['first_name'] . ' ' . $customer['last_name']);
include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 mb-0"><?php echo escape($customer['first_name'] . ' ' . $customer['last_name']); ?> - Loyalty Program</h1>
                <small class="text-muted">Customer ID: <?php echo escape($customer['customer_code']); ?></small>
            </div>
            <a href="<?php echo getBaseUrl(); ?>/customer_profile.php?id=<?php echo $customer_id; ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Profile
            </a>
        </div>
    </div>
</div>

<!-- Current Loyalty Status -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-primary">
            <div class="card-body text-center">
                <h5 class="card-title">Current Points Balance</h5>
                <div class="display-4 text-primary mb-3"><?php echo number_format($current_points); ?></div>
                <p class="text-muted mb-0">Available points</p>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card border-success">
            <div class="card-body text-center">
                <h5 class="card-title">Current Tier</h5>
                <div class="display-4 text-success mb-3"><?php echo escape($current_tier); ?></div>
                <p class="text-muted mb-0"><?php echo $tiers[$current_tier]['benefits']; ?></p>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card border-info">
            <div class="card-body text-center">
                <h5 class="card-title">Member Since</h5>
                <div class="display-6 text-info"><?php echo formatDate($customer['created_at']); ?></div>
                <p class="text-muted mb-0">Active member</p>
            </div>
        </div>
    </div>
</div>

<!-- Birthday Reward Section -->
<?php if ($is_birthday_month && !$birthday_reward_given): ?>
<div class="alert alert-warning alert-dismissible fade show mb-4" role="alert">
    <i class="bi bi-gift"></i> <strong>Happy Birthday!</strong> This customer is eligible for a 100-point birthday reward.
    <form method="POST" class="d-inline-block" style="margin-left: 10px;">
        <input type="hidden" name="action" value="grant_birthday_reward">
        <button type="submit" class="btn btn-sm btn-warning">Grant Birthday Reward</button>
    </form>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- Tier Progression -->
<div class="card mb-4">
    <div class="card-header">Tier Progression</div>
    <div class="card-body">
        <div class="row">
            <?php foreach ($tiers as $tier_name => $tier_info): ?>
            <div class="col-md-4 mb-3">
                <div class="card <?php echo ($tier_name === $current_tier) ? 'border-primary border-2' : ''; ?> h-100">
                    <div class="card-body">
                        <h6 class="card-title mb-3">
                            <?php echo escape($tier_name); ?>
                            <?php if ($tier_name === $current_tier): ?>
                                <span class="badge bg-primary float-end">Current</span>
                            <?php endif; ?>
                        </h6>
                        <ul class="list-unstyled small">
                            <li class="mb-2"><strong><?php echo $tier_info['min_points']; ?>-<?php echo ($tier_info['max_points'] === 999999) ? '∞' : $tier_info['max_points']; ?> points</strong></li>
                            <li class="mb-2">
                                <i class="bi bi-percent"></i> <?php echo $tier_info['discount']; ?>% Discount
                            </li>
                            <?php if ($tier_name !== 'Bronze'): ?>
                            <li class="mb-2">
                                <i class="bi bi-gift"></i> Special Benefits
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php if ($next_tier): ?>
        <div class="mt-4 pt-3 border-top">
            <strong>Progress to <?php echo escape($next_tier); ?></strong>
            <div class="progress mt-2">
                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo (($current_points / 500) * 100); ?>%" aria-valuenow="<?php echo $current_points; ?>" aria-valuemin="0" aria-valuemax="500"></div>
            </div>
            <small class="text-muted"><?php echo number_format($points_to_next); ?> more points needed</small>
        </div>
        <?php else: ?>
        <div class="mt-4 pt-3 border-top alert alert-success mb-0">
            <i class="bi bi-check-circle"></i> <strong>Gold Tier Achieved!</strong> Maximum loyalty tier unlocked.
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Points Redemption -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Redeem Points</div>
            <div class="card-body">
                <?php if ($current_points >= 100): ?>
                    <p class="text-muted small mb-3">100 points = $10.00 discount</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="redeem_points">
                        
                        <div class="mb-3">
                            <label class="form-label">Points to Redeem</label>
                            <input type="number" class="form-control" name="points_to_redeem" 
                                   min="100" step="100" max="<?php echo $current_points; ?>" 
                                   placeholder="Enter points (multiples of 100)">
                            <small class="text-muted">Available: <?php echo number_format($current_points); ?> points</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Redemption Method</label>
                            <select class="form-control" name="redemption_method">
                                <option value="discount">Store Credit/Discount</option>
                                <option value="gift_card">Gift Card</option>
                                <option value="reward_product">Reward Product</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-gift"></i> Redeem Points
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info mb-0">
                        Customer needs at least 100 points to redeem. Currently has <?php echo number_format($current_points); ?> points.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Points Summary</div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-sm-8">Points from Purchases:</dt>
                    <dd class="col-sm-4 text-end text-success"><?php echo number_format($points_summary['purchase_points'] ?? 0); ?></dd>
                    
                    <dt class="col-sm-8">Birthday Rewards:</dt>
                    <dd class="col-sm-4 text-end text-success"><?php echo number_format($points_summary['birthday_points'] ?? 0); ?></dd>
                    
                    <dt class="col-sm-8">Referral Bonuses:</dt>
                    <dd class="col-sm-4 text-end text-success"><?php echo number_format($points_summary['referral_points'] ?? 0); ?></dd>
                    
                    <dt class="col-sm-8">Redeemed:</dt>
                    <dd class="col-sm-4 text-end text-danger">-<?php echo number_format($points_summary['redeemed_points'] ?? 0); ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<!-- Points History -->
<div class="card">
    <div class="card-header">Points History</div>
    <div class="card-body">
        <?php if (empty($points_history)): ?>
            <div class="alert alert-info mb-0">No points history.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th class="text-end">Points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($points_history as $entry): ?>
                        <tr>
                            <td><?php echo formatDateTime($entry['created_at']); ?></td>
                            <td>
                                <?php
                                $badge_class = match($entry['type']) {
                                    'purchase' => 'bg-success',
                                    'birthday' => 'bg-info',
                                    'referral' => 'bg-primary',
                                    'redemption' => 'bg-warning',
                                    default => 'bg-secondary'
                                };
                                ?>
                                <span class="badge <?php echo $badge_class; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $entry['type'])); ?>
                                </span>
                            </td>
                            <td><?php echo escape($entry['description']); ?></td>
                            <td class="text-end <?php echo ($entry['points'] >= 0) ? 'text-success' : 'text-danger'; ?>">
                                <?php echo ($entry['points'] > 0 ? '+' : ''); ?><?php echo number_format($entry['points']); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'templates/footer.php'; ?>
