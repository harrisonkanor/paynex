<?php
/**
 * spin.php AJAX endpoint - server determines result, JS animates to match
 * Cooldown: Users can only spin on Fridays (once per week).
 * Prize range: $0.10 to $0.50 only.
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

// Friday-only cooldown - users can only spin on Fridays
$today = date('w'); // 0=Sunday, 6=Saturday, 5=Friday
if ($today != 5) {
    // Calculate days until next Friday
    $daysUntilFriday = (5 - $today + 7) % 7;
    if ($daysUntilFriday == 0) $daysUntilFriday = 7; // If today is Friday but already spun, wait 7 days
    
    // Check if user already spun this Friday
    $lastSpin = $u['last_spin_at'] ?? null;
    if ($lastSpin) {
        $lastSpinDate = date('Y-m-d', strtotime($lastSpin . ' UTC'));
        $thisFriday = date('Y-m-d', strtotime('last friday'));
        if ($thisFriday == date('Y-m-d') && $lastSpinDate == $thisFriday) {
            // Already spun this Friday, wait until next Friday
            $nextFriday = date('Y-m-d', strtotime('next friday'));
            http_response_code(429);
            die(json_encode(['success' => false, 'error' => "You can only spin on Fridays. Next spin available: {$nextFriday}"]));
        }
    }
    
    $nextFriday = date('Y-m-d', strtotime('next friday'));
    http_response_code(429);
    die(json_encode(['success' => false, 'error' => "Spin wheel is only available on Fridays. Next spin: {$nextFriday}"]));
}

// Check if user already spun this Friday
$lastSpin = $u['last_spin_at'] ?? null;
if ($lastSpin) {
    $lastSpinDate = date('Y-m-d', strtotime($lastSpin . ' UTC'));
    $thisFriday = date('Y-m-d', strtotime('last friday'));
    if ($lastSpinDate >= $thisFriday) {
        $nextFriday = date('Y-m-d', strtotime('next friday'));
        http_response_code(429);
        die(json_encode(['success' => false, 'error' => "You've already spun this Friday. Next spin: {$nextFriday}"]));
    }
}

// 50 segments matching the JS wheel - ONLY $0.10 to $0.50 range
// Positions 0-49 on the wheel
$segments = [
    0.10, 0.20, 0.30, 0.50, 0.10,  // 0-4
    0.20, 0.30, 0.10, 0.50, 0.20,  // 5-9
    0.10, 0.30, 0.20, 0.50, 0.10,  // 10-14
    0.30, 0.20, 0.10, 0.50, 0.30,  // 15-19
    0.10, 0.20, 0.50, 0.30, 0.10,  // 20-24
    0.20, 0.30, 0.50, 0.10, 0.20,  // 25-29
    0.30, 0.10, 0.50, 0.20, 0.30,  // 30-34
    0.10, 0.50, 0.20, 0.30, 0.10,  // 35-39
    0.20, 0.50, 0.30, 0.10, 0.20,  // 40-44
    0.50, 0.30, 0.10, 0.20, 0.50   // 45-49
];

// Weighted odds - higher values ($0.50) are less likely
$roll = random_int(1, 10000);

if ($roll <= 500)     { $segIndex = [3,8,13,18,23,28,33,38,43,48][random_int(0,9)]; }   // $0.50 - 5% chance
elseif ($roll <= 2000) { $segIndex = [2,6,11,16,21,26,31,36,41,46][random_int(0,9)]; }  // $0.30 - 15% chance
elseif ($roll <= 5500) { $segIndex = [1,5,10,15,20,25,30,35,40,45,49][random_int(0,10)]; } // $0.20 - 35% chance
else                   { $segIndex = [0,4,7,9,12,14,17,19,22,24,27,29,32,34,37,39,42,44,47][random_int(0,18)]; } // $0.10 - 45% chance

// Get the reward from the segment value
$reward = $segments[$segIndex];
$result = '$' . number_format($reward, 2);

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE users SET wallet_balance = wallet_balance + :amt, last_spin_at = UTC_TIMESTAMP() WHERE id = :id')
        ->execute([':amt' => $reward, ':id' => $user['id']]);
    $pdo->prepare('INSERT INTO wallet_transactions (user_id, type, amount, description) VALUES (:uid, "credit", :amt, :desc)')
        ->execute([':uid' => $user['id'], ':amt' => $reward, ':desc' => 'Weekly spin win: ' . $result]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'Database error.']));
}

log_activity($pdo, $user['id'], "spin_wheel: {$result}");
$nextSpinAt = strtotime('next friday 00:00:00 UTC');

header('Content-Type: application/json');
echo json_encode(['success'=>true,'segment'=>$segIndex,'result'=>$result,'reward'=>$reward,'next_spin_at'=>$nextSpinAt]);
