<?php
/**
 * admin/cron/auto_approve_tasks.php - Auto-approve pending task submissions after 5 minutes.
 * Credits the user wallet and records the transaction.
 * Run via cron every 5 minutes.
 */
require_once __DIR__ . '/../../config/config.php';
if (php_sapi_name() !== 'cli') die('CLI only.');

echo "[" . date('Y-m-d H:i:s') . "] Auto-approving pending submissions...
";

$stmt = $pdo->prepare(
    "SELECT ts.id, ts.user_id, ts.submitted_at,
            t.reward, t.title
     FROM task_submissions ts
     JOIN tasks t ON t.id = ts.task_id
     WHERE ts.status = 'pending'
       AND ts.submitted_at <= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
);
$stmt->execute();
$pending = $stmt->fetchAll();

$count = 0;
foreach ($pending as $sub) {
    try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE task_submissions SET status = 'approved', reviewed_at = NOW() WHERE id = :id")
            ->execute([':id' => $sub['id']]);

        $reward = (float) $sub['reward'];

        $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :uid")
            ->execute([':amt' => $reward, ':uid' => $sub['user_id']]);

        $pdo->prepare("INSERT INTO wallet_transactions (user_id, type, amount, description) VALUES (:uid, 'credit', :amt, :desc)")
            ->execute([
                ':uid'  => $sub['user_id'],
                ':amt'  => $reward,
                ':desc' => 'Task auto-approved: ' . $sub['title'],
            ]);

        $pdo->prepare("UPDATE tasks SET slots_filled = slots_filled + 1 WHERE id = (SELECT task_id FROM task_submissions WHERE id = :sid)")
            ->execute([':sid' => $sub['id']]);

        $pdo->commit();
        $count++;
        echo "  Approved submission #{$sub['id']} (user #{$sub['user_id']}) +$" . number_format($reward, 2) . "
";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "  ERROR on submission #{$sub['id']}: " . $e->getMessage() . "
";
    }
}

echo "Approved {$count} submissions.
";
echo "[" . date('Y-m-d H:i:s') . "] Done.
";
