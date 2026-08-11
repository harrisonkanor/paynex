<?php
/**
 * daily_task_refresh.php — Cron job that runs at 00:00 daily.
 *
 * 1. Closes all open tasks (they expire at midnight).
 * 2. Creates new random tasks for each VIP level (1, 2, 3).
 *    - Survey tasks get the VIP plan reward.
 *    - Spin_wheel tasks get $0.00 reward (the spin determines the payout).
 *
 * Run via cron:
 *   0 0 * * * /usr/bin/php /opt/lampp/htdocs/paynex/admin/cron/daily_task_refresh.php
 */

require_once __DIR__ . '/../../config/config.php';

// Close all open tasks
$pdo->exec("UPDATE tasks SET status = 'closed' WHERE status = 'open'");

// Get admin user
$adminStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$adminStmt->execute();
$adminId = (int) $adminStmt->fetchColumn();

if (!$adminId) {
    error_log('daily_task_refresh: No admin user found, cannot create tasks.');
    exit(1);
}

// Get VIP plan levels
$vipStmt = $pdo->query('SELECT level FROM vip_plans ORDER BY level');
$vipLevels = $vipStmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($vipLevels)) {
    error_log('daily_task_refresh: No VIP plans found.');
    exit(1);
}

// Task templates
$taskTemplates = [
    'survey' => [
        ['title' => 'Product Review', 'desc' => 'Write a short review of a product you recently purchased. Describe your experience, what you liked, and what could be improved. Submit a screenshot of your review.'],
        ['title' => 'Social Media Follow', 'desc' => 'Follow our official social media page and like the latest post. Submit a screenshot of your follow and like as proof.'],
        ['title' => 'YouTube Video Watch', 'desc' => 'Watch the featured video for at least 3 minutes. Submit a screenshot showing the video playing as proof.'],
        ['title' => 'Website Feedback', 'desc' => 'Visit our partner website and provide constructive feedback. What do you like about the design? What would you change? Paste your feedback in the box below.'],
        ['title' => 'App Download & Signup', 'desc' => 'Download the partner app and create a free account. Submit a screenshot of your profile page showing your username.'],
        ['title' => 'Survey Response', 'desc' => 'Complete the short market research survey. Submit the confirmation code you receive at the end as proof.'],
        ['title' => 'Blog Comment', 'desc' => 'Read the blog post and leave a thoughtful comment. Submit a screenshot showing your published comment.'],
        ['title' => 'Telegram Group Join', 'desc' => 'Join our Telegram group and say hello in the chat. Submit a screenshot showing you are a member.'],
        ['title' => 'Google Maps Review', 'desc' => 'Leave a 5-star review on Google Maps for our partner business. Submit a screenshot of your published review.'],
        ['title' => 'TikTok Video Like', 'desc' => 'Watch and like our latest TikTok video. Submit a screenshot of the liked video as proof.'],
    ],
    'spin_wheel' => [
        ['title' => 'Lucky Spin Challenge', 'desc' => 'Spin the wheel and claim your reward! The result is determined randomly. Good luck!'],
        ['title' => 'Daily Bonus Spin', 'desc' => 'Your daily bonus spin is ready! Give the wheel a spin and see what you win today.'],
        ['title' => 'Mega Spin Reward', 'desc' => 'Try your luck on the mega wheel! Every spin guarantees a reward.'],
    ],
];

$insertStmt = $pdo->prepare(
    'INSERT INTO tasks (admin_id, title, description, type, vip_level, reward, ticket_price, slots, slots_filled, time_limit_minutes, available_from, available_until, status, created_at)
     VALUES (:admin_id, :title, :description, :type, :vip_level, :reward, :ticket_price, :slots, 0, :time_limit, :avail_from, :avail_until, "open", NOW())'
);

foreach ($vipLevels as $level) {
    // Get the VIP plan reward for survey tasks
    $planStmt = $pdo->prepare('SELECT task_reward FROM vip_plans WHERE level = :lv');
    $planStmt->execute([':lv' => $level]);
    $taskReward = (float) $planStmt->fetchColumn();

    // Create 3 survey tasks for this level (get fixed VIP plan reward)
    for ($i = 0; $i < 3; $i++) {
        $template = $taskTemplates['survey'][array_rand($taskTemplates['survey'])];
        $insertStmt->execute([
            ':admin_id'     => $adminId,
            ':title'        => $template['title'] . ' (VIP ' . $level . ')',
            ':description'  => $template['desc'],
            ':type'         => 'survey',
            ':vip_level'    => $level,
            ':reward'       => $taskReward,
            ':ticket_price' => 0.00,
            ':slots'        => 50,
            ':time_limit'   => 60,
            ':avail_from'   => '00:00:00',
            ':avail_until'  => '23:59:59',
        ]);
    }

    // Create 1 spin_wheel task for this level (reward comes from the wheel spin, not a fixed amount)
    $template = $taskTemplates['spin_wheel'][array_rand($taskTemplates['spin_wheel'])];
    $insertStmt->execute([
        ':admin_id'     => $adminId,
        ':title'        => $template['title'] . ' (VIP ' . $level . ')',
        ':description'  => $template['desc'],
        ':type'         => 'spin_wheel',
        ':vip_level'    => $level,
        ':reward'       => 0.00,
        ':ticket_price' => 0.00,
        ':slots'        => 50,
        ':time_limit'   => 60,
        ':avail_from'   => '00:00:00',
        ':avail_until'  => '23:59:59',
    ]);
}

error_log('daily_task_refresh: Tasks refreshed successfully at ' . date('Y-m-d H:i:s'));
echo "Tasks refreshed at " . date('Y-m-d H:i:s') . "\n";
