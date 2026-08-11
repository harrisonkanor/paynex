<?php
/**
 * spin.php AJAX endpoint - server determines result, JS animates to match
 * Cooldown resets at midnight (00:00 UTC) daily.
 */
require_once __DIR__ . '/config/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']));
}

$user = current_user();
if ($user['role'] === 'admin') {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Admins cannot spin.']));
}

$uStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$uStmt->execute([':id' => $user['id']]);
$u = $uStmt->fetch();

if (!$u) { http_response_code(404); die(json_encode(['success' => false, 'error' => 'User not found.'])); }
if ($u['status'] === 'suspended') { http_response_code(403); die(json_encode(['success' => false, 'error' => 'Account suspended.'])); }

$vipLevel = (int) ($u['vip_level'] ?? 0);
if (!$vipLevel) { http_response_code(403); die(json_encode(['success' => false, 'error' => 'VIP plan required to spin.'])); }

// Midnight cooldown
$midnightToday = strtotime('today 00:00:00 UTC');
$midnightTomorrow = strtotime('tomorrow 00:00:00 UTC');
$lastSpin = $u['last_spin_at'] ?? null;
if ($lastSpin) {
    $lastSpinTs = strtotime($lastSpin . ' UTC');
    if ($lastSpinTs >= $midnightToday) {
        $remaining = $midnightTomorrow - time();
        $remainingHours = ceil($remaining / 3600);
        http_response_code(429);
        die(json_encode(['success' => false, 'error' => "Please wait {$remainingHours}h until midnight for your next spin."]));
    }
}

/* 16 segments with their values */
$segments = [1.00, 0.30, 0.15, 0.20, 1.00, 0.50, 0.10, 0.30, 0.20, 0.50, 0.10, 0.20, 0.30, 0.50, 0.20, 0.10];
$segmentLabels = ['$1.00','$0.30','$0.15','$0.20','$1.00','$0.50','$0.10','$0.30','$0.20','$0.50','$0.10','$0.20','$0.30','$0.50','$0.20','$0.10'];

/* Weighted odds - higher values are less likely */
$roll = random_int(1, 10000);

if ($roll <= 1)      { $segIndex = 0;  }  // $1.00 - 0.01% chance
elseif ($roll <= 100){ $segIndex = 4;  }  // $1.00 - 0.99% chance  
elseif ($roll <= 200){ $segIndex = 2;  }  // $0.15 - 1% chance
elseif ($roll <= 2000){$segIndex = [5,9,13][random_int(0,2)]; }  // $0.50 - 18% chance
elseif ($roll <= 4200){$segIndex = [1,7,12][random_int(0,2)]; }  // $0.30 - 22% chance
elseif ($roll <= 7200){$segIndex = [3,8,11,14][random_int(0,3)]; }  // $0.20 - 30% chance
else                { $segIndex = [6,10,15][random_int(0,2)]; }  // $0.10 - 28% chance

// Get the reward from the segment value (not the odds)
$reward = $segments[$segIndex];
$result = $segmentLabels[$segIndex];

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE users SET wallet_balance = wallet_balance + :amt, last_spin_at = UTC_TIMESTAMP() WHERE id = :id')
        ->execute([':amt' => $reward, ':id' => $user['id']]);
    $pdo->prepare('INSERT INTO wallet_transactions (user_id, type, amount, description) VALUES (:uid, "credit", :amt, :desc)')
        ->execute([':uid' => $user['id'], ':amt' => $reward, ':desc' => 'Daily spin win: ' . $result]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'Database error.']));
}

log_activity($pdo, $user['id'], "spin_wheel: {$result}");
$nextSpinAt = strtotime('tomorrow 00:00:00 UTC');

header('Content-Type: application/json');
echo json_encode(['success'=>true,'segment'=>$segIndex,'result'=>$result,'reward'=>$reward,'next_spin_at'=>$nextSpinAt]);
