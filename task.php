<?php
/**
 * task.php — Individual task completion page.
 */
require_once __DIR__ . '/config/config.php';
require_login();
require_email_verified($pdo);

$user   = current_user();
$taskId = (int) ($_GET['id'] ?? 0);

$uStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$uStmt->execute([':id' => $user['id']]);
$u = $uStmt->fetch();

$tStmt = $pdo->prepare('SELECT * FROM tasks WHERE id = :id');
$tStmt->execute([':id' => $taskId]);
$task = $tStmt->fetch();

if (!$task) { flash('error', 'Task not found.'); redirect('/tasks.php'); }

if ((int)($u['vip_level'] ?? 0) !== (int)$task['vip_level']) {
    flash('error', 'This task is not available for your VIP level.');
    redirect('/tasks.php');
}

$claimStmt = $pdo->prepare('SELECT * FROM task_claims WHERE task_id = :tid AND user_id = :uid');
$claimStmt->execute([':tid' => $taskId, ':uid' => $user['id']]);
$claim = $claimStmt->fetch();

if (!$claim) { flash('error', 'Please claim this task first from the task library.'); redirect('/tasks.php'); }

$isExpired = strtotime($claim['expires_at']) < time();

$subStmt = $pdo->prepare('SELECT * FROM task_submissions WHERE task_id = :tid AND user_id = :uid');
$subStmt->execute([':tid' => $taskId, ':uid' => $user['id']]);
$existing = $subStmt->fetch();

$errors = [];
if (!$existing && !$isExpired && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $proof      = trim($_POST['proof_text'] ?? '');
    $spinResult = trim($_POST['spin_result'] ?? '');
    if ($task['type'] === 'survey' && $proof === '') $errors[] = 'Please describe your proof of completion.';
    if ($task['type'] === 'spin_wheel' && $spinResult === '') $errors[] = 'Please spin the wheel to generate your result.';
    // Server-side validation: ensure spin result is valid
    $validResults = ['$1.00', '$1.00', '$0.15', '$0.50', '$0.30', '$0.20', '$0.10'];
    if ($task['type'] === 'spin_wheel' && $spinResult !== '' && !in_array($spinResult, $validResults, true)) {
        $errors[] = 'Invalid spin result. Please spin the wheel again.';
    }
    if (!$errors) {
        // Server-side weighted odds for spin_wheel (0.01% for $5, small amounts dominate)
        if ($task['type'] === 'spin_wheel') {
            $roll = random_int(1, 10000);
            if ($roll <= 1)       { $spinResult = '$1.00'; $actualReward = 5.00; }
            elseif ($roll <= 100) { $spinResult = '$1.00'; $actualReward = 1.00; }
            elseif ($roll <= 200) { $spinResult = '$0.15'; $actualReward = 0.15; }
            elseif ($roll <= 2000){ $spinResult = '$0.50'; $actualReward = 0.50; }
            elseif ($roll <= 4200){ $spinResult = '$0.30'; $actualReward = 0.30; }
            elseif ($roll <= 7200){ $spinResult = '$0.20'; $actualReward = 0.20; }
            else                { $spinResult = '$0.10'; $actualReward = 0.10; }
        } else {
            $actualReward = (float) $task['reward'];
        }
        if ($proof === '') $proof = 'Spin result: ' . $spinResult;
        try {
            $pdo->beginTransaction();
            if ($task['type'] === 'spin_wheel') {
                $ins = $pdo->prepare('INSERT INTO task_submissions (task_id, user_id, proof_text, spin_result, status, reviewed_at) VALUES (:tid, :uid, :proof, :spin, "approved", NOW())');
                $ins->execute([':tid' => $taskId, ':uid' => $user['id'], ':proof' => $proof, ':spin' => $spinResult ?: null]);
                $pdo->prepare('UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :uid')->execute([':amt' => $actualReward, ':uid' => $user['id']]);
                $pdo->prepare('INSERT INTO wallet_transactions (user_id, type, amount, description) VALUES (:uid, "credit", :amt, :desc)')->execute([':uid' => $user['id'], ':amt' => $actualReward, ':desc' => 'Task completed: ' . $task['title']]);
                $pdo->prepare('UPDATE tasks SET slots_filled = slots_filled + 1, status = IF(slots_filled + 1 >= slots, "closed", status) WHERE id = :tid')->execute([':tid' => $taskId]);
            } else {
                // Handle screenshot upload
                $screenshotPath = null;
                if (!empty($_FILES['screenshot']['name'])) {
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    if (in_array($_FILES['screenshot']['type'], $allowedTypes, true) && $_FILES['screenshot']['size'] <= 5 * 1024 * 1024) {
                        $ext = pathinfo($_FILES['screenshot']['name'], PATHINFO_EXTENSION);
                        $filename = 'screenshot_' . $user['id'] . '_' . $taskId . '_' . time() . '.' . $ext;
                        $dest = __DIR__ . '/uploads/screenshots/' . $filename;
                        if (move_uploaded_file($_FILES['screenshot']['tmp_name'], $dest)) {
                            $screenshotPath = 'uploads/screenshots/' . $filename;
                        }
                    } else {
                        $errors[] = 'Invalid screenshot. Allowed: JPEG, PNG, GIF, WebP under 5MB.';
                    }
                }
                if (!$errors) {
                    $ins = $pdo->prepare('INSERT INTO task_submissions (task_id, user_id, proof_text, screenshot_path, spin_result, status, reviewed_at) VALUES (:tid, :uid, :proof, :sspath, :spin, "pending", NULL)');
                    $ins->execute([':tid' => $taskId, ':uid' => $user['id'], ':proof' => $proof, ':sspath' => $screenshotPath, ':spin' => null]);
                }
            }
            $pdo->commit();
            log_activity($pdo, $user['id'], "task_submitted: #{$taskId}");
            if ($task['type'] === 'spin_wheel') { flash('success', 'Task completed! Your reward has been credited to your wallet.'); } else { flash('success', 'Task submitted! Your proof is under review. You will be credited within 5 minutes once approved.'); }
            redirect('/tasks.php');
        } catch (PDOException $e) { $pdo->rollBack(); $errors[] = 'You have already submitted for this task.'; }
        catch (Throwable $e) { $pdo->rollBack(); error_log('Task auto-approve error: ' . $e->getMessage()); $errors[] = 'Could not process your submission. Please try again.'; }
    }
}

$pageTitle = e($task['title']) . ' — payNex';
require __DIR__ . '/includes/header.php';

$segments = ['$1.00','$0.30','$0.15','$0.20','$1.00','$0.50','$0.10','$0.30','$0.20','$0.50','$0.10','$0.20','$0.30','$0.50','$0.20','$0.10'];
$segColors = ['#FFD700','#2E8FD6','#00BFA5','#0A1B29','#FFD700','#8AD24A','#F7931A','#2E8FD6','#0A1B29','#8AD24A','#F7931A','#0A1B29','#2E8FD6','#8AD24A','#0A1B29','#F7931A'];
?>

<div class="page-wrap" style="max-width:780px;">
  <div class="page-head">
    <h1>
      <?php if ($task['type'] === 'spin_wheel'): ?>
        <i class="fa-solid fa-circle-notch" style="color:var(--green);"></i>
      <?php else: ?>
        <i class="fa-solid fa-clipboard-list" style="color:var(--green);"></i>
      <?php endif; ?>
      <?= e($task['title']) ?>
    </h1>
    <p>
      <?php if ($task['type'] === 'spin_wheel'): ?>
        Reward: <strong>Spin to win!</strong>
      <?php else: ?>
        Reward: <strong><?= e(money((float)$task['reward'])) ?></strong>
      <?php endif; ?>
      &nbsp;·&nbsp;
      <?php if (!$isExpired && !$existing): ?>
        Time remaining:
        <span class="task-timer" id="main-timer" data-expires="<?= e($claim['expires_at']) ?>"><i class="fa-solid fa-stopwatch"></i> --:--</span>
      <?php elseif ($isExpired && !$existing): ?>
        <span class="task-timer expired"><i class="fa-solid fa-circle-xmark"></i> Time expired</span>
      <?php endif; ?>
    </p>
  </div>

  <div class="card">
    <h2><i class="fa-solid fa-book-open"></i> Instructions</h2>
    <p style="line-height:1.7;"><?= nl2br(e($task['description'])) ?></p>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:20px;"><i class="fa-solid fa-circle-exclamation"></i><div><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div></div>
  <?php endif; ?>

  <?php if ($existing): ?>
    <div class="card">
      <h2><i class="fa-solid fa-check-circle"></i> Submission received</h2>
      <p>Status: <span class="badge badge-<?= $task['type'] === 'spin_wheel' ? 'approved' : e($existing['status']) ?>"><?= $task['type'] === 'spin_wheel' ? 'Completed' : e(ucfirst($existing['status'])) ?></span></p>
      <p style="color:var(--ink-soft);font-size:14px;margin-top:8px;">Your proof: <?= nl2br(e($existing['proof_text'])) ?></p>
      <?php if ($existing['spin_result']): ?>
        <p style="color:var(--ink-soft);font-size:14px;">Spin result: <strong><?= e($existing['spin_result']) ?></strong></p>
      <?php endif; ?>
    </div>

  <?php elseif ($isExpired): ?>
    <div class="card" style="text-align:center;padding:36px;">
      <i class="fa-solid fa-hourglass-end" style="font-size:40px;color:var(--red);margin-bottom:16px;display:block;"></i>
      <h2>Time expired</h2>
      <p class="text-muted">You did not complete this task within the time limit.</p>
      <a href="<?= BASE_URL ?>/tasks.php" class="btn btn-dark mt-12"><i class="fa-solid fa-arrow-left"></i> Back to tasks</a>
    </div>

  <?php elseif ($task['type'] === 'survey'): ?>
    <div class="card">
      <h2><i class="fa-solid fa-file-pen"></i> Submit your proof</h2>
      <form method="post" action="<?= BASE_URL ?>/task.php?id=<?= $taskId ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <div class="field">
          <label><i class="fa-solid fa-pencil"></i> Proof of completion</label>
          <textarea name="proof_text" placeholder="Describe what you did, paste a link, or any proof that shows you completed the task..."><?= e($_POST['proof_text'] ?? '') ?></textarea>
          <div class="input-hint" style="margin-top:8px;">Upload a screenshot as proof:</div>
          <input type="file" name="screenshot" accept="image/jpeg,image/png,image/gif,image/webp" style="margin-top:6px;">
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Submit for review</button>
        </div>
      </form>
    </div>

  <?php else: ?>
    <div class="card">
      <h2><i class="fa-solid fa-circle-notch"></i> Spin the wheel</h2>
      <p class="text-muted" style="font-size:14px;margin-bottom:16px;">Spin the wheel once. Your prize will be credited automatically!</p>

      <div class="wheel-wrap">
        <div class="wheel-container" id="wheel-container">
          <canvas id="wheel-canvas" class="wheel-canvas" width="300" height="300"></canvas>
          <canvas id="particle-canvas" class="particle-canvas" width="300" height="300"></canvas>
          <canvas id="confetti-canvas" class="confetti-canvas" width="300" height="300"></canvas>
        </div>
        <div class="wheel-result" id="wheel-result"></div>
        <button class="btn btn-primary wheel-btn" id="spin-btn"><i class="fa-solid fa-rotate"></i> Spin now</button>
      </div>

      <form method="post" action="<?= BASE_URL ?>/task.php?id=<?= $taskId ?>" id="spin-form" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="spin_result" id="spin-result-input">
        <input type="hidden" name="proof_text" id="spin-proof-input">
      </form>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  var el=document.getElementById('main-timer');if(!el)return;
  function tick(){var diff=Math.floor((new Date(el.dataset.expires)-new Date())/1000);if(diff<=0){el.innerHTML='<i class="fa-solid fa-circle-xmark"></i> Expired';el.classList.add('expired');return;}var h=Math.floor(diff/3600),m=Math.floor((diff%3600)/60),s=diff%60;el.innerHTML='<i class="fa-solid fa-stopwatch"></i> '+String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');setTimeout(tick,1000);}
  tick();
})();

(function(){
  var canvas=document.getElementById('wheel-canvas');if(!canvas)return;
  var ctx=canvas.getContext('2d');
  var pCanvas=document.getElementById('particle-canvas');
  var cCanvas=document.getElementById('confetti-canvas');
  var pCtx=pCanvas?pCanvas.getContext('2d'):null;
  var cCtx=cCanvas?cCanvas.getContext('2d'):null;
  var segments=<?= json_encode($segments) ?>;
  var segColors=<?= json_encode($segColors) ?>;
  var numSeg=segments.length;
  var arc=(2*Math.PI)/numSeg;
  var angle=0,spinning=false,particles=[],confettiParticles=[],glowIntensity=0;

  var audioCtx=null;
  function initAudio(){if(audioCtx)return;try{audioCtx=new(window.AudioContext||window.webkitAudioContext)();}catch(e){}}
  function playTick(){if(!audioCtx)return;try{var osc=audioCtx.createOscillator();osc.type='square';var gain=audioCtx.createGain();osc.connect(gain);gain.connect(audioCtx.destination);osc.frequency.value=600+Math.random()*600;gain.gain.setValueAtTime(0.06,audioCtx.currentTime);gain.gain.exponentialRampToValueAtTime(0.001,audioCtx.currentTime+0.06);osc.start(audioCtx.currentTime);osc.stop(audioCtx.currentTime+0.06);}catch(e){}}
  function playWin(){if(!audioCtx)return;try{[523,659,784,1047].forEach(function(freq,i){var osc=audioCtx.createOscillator();osc.type='sine';var gain=audioCtx.createGain();osc.connect(gain);gain.connect(audioCtx.destination);osc.frequency.value=freq;var t=audioCtx.currentTime+i*0.12;gain.gain.setValueAtTime(0.1,t);gain.gain.exponentialRampToValueAtTime(0.001,t+0.3);osc.start(t);osc.stop(t+0.3);});}catch(e){}}

  function createParticles(x,y,c,clr){for(var i=0;i<c;i++){particles.push({x:x,y:y,vx:(Math.random()-0.5)*6,vy:(Math.random()-0.5)*6-2,life:1,decay:0.015+Math.random()*0.025,size:2+Math.random()*4,color:clr||'#8AD24A'});}}
  function createConfetti(c){var clrs=['#8AD24A','#2E8FD6','#F7931A','#E2685F','#FFD700','#FF69B4','#00CED1'];for(var i=0;i<c;i++){confettiParticles.push({x:150,y:150,vx:(Math.random()-0.5)*12,vy:(Math.random()-0.5)*12-6,life:1,decay:0.005+Math.random()*0.01,size:3+Math.random()*5,color:clrs[Math.floor(Math.random()*clrs.length)],rot:Math.random()*Math.PI*2,rotSpeed:(Math.random()-0.5)*0.2,gravity:0.08+Math.random()*0.05});}}

  function drawWheel(rot){
    ctx.clearRect(0,0,300,300);
    if(glowIntensity>0){var g=ctx.createRadialGradient(150,150,120,150,150,160+glowIntensity*20);g.addColorStop(0,'rgba(138,210,74,0)');g.addColorStop(0.5,'rgba(138,210,74,'+(glowIntensity*0.3)+')');g.addColorStop(1,'rgba(138,210,74,0)');ctx.fillStyle=g;ctx.beginPath();ctx.arc(150,150,160+glowIntensity*20,0,Math.PI*2);ctx.fill();}
    for(var i=0;i<numSeg;i++){
      ctx.beginPath();ctx.moveTo(150,150);ctx.arc(150,150,140,rot+arc*i,rot+arc*(i+1));ctx.closePath();
      ctx.fillStyle=segColors[i%segColors.length];ctx.fill();
      ctx.strokeStyle='rgba(255,255,255,0.6)';ctx.lineWidth=2;ctx.stroke();
      ctx.save();ctx.translate(150,150);ctx.rotate(rot+arc*i+arc/2);
      ctx.fillStyle='#fff';ctx.font='bold 12px "Space Grotesk",sans-serif';ctx.textAlign='right';
      ctx.shadowColor='rgba(0,0,0,0.3)';ctx.shadowBlur=3;ctx.fillText(segments[i],132,4);ctx.restore();
    }
    var cG=ctx.createRadialGradient(150,150,0,150,150,20);cG.addColorStop(0,'#8AD24A');cG.addColorStop(1,'#0A1B29');
    ctx.beginPath();ctx.arc(150,150,20,0,Math.PI*2);ctx.fillStyle=cG;ctx.fill();
    ctx.strokeStyle='rgba(255,255,255,0.3)';ctx.lineWidth=2;ctx.stroke();
    // Longer pointer that partially enters the wheel
    ctx.save();
    var px=150;
    ctx.beginPath();
    ctx.moveTo(px, 46);
    ctx.lineTo(134, -2);
    ctx.lineTo(150, -8);
    ctx.lineTo(166, -2);
    ctx.closePath();
    var pg=ctx.createLinearGradient(px, -8, px, 46);
    pg.addColorStop(0,'#FFB74D');
    pg.addColorStop(0.5,'#F7931A');
    pg.addColorStop(1,'#E67A00');
    ctx.fillStyle=pg;
    ctx.shadowColor='rgba(247,147,26,0.5)';
    ctx.shadowBlur=12;
    ctx.fill();
    ctx.shadowBlur=0;
    ctx.strokeStyle='rgba(255,255,255,0.3)';
    ctx.lineWidth=1;
    ctx.stroke();
    ctx.restore();
    if(pCtx){pCtx.clearRect(0,0,300,300);for(var j=particles.length-1;j>=0;j--){var p=particles[j];p.x+=p.vx;p.y+=p.vy;p.vy+=0.05;p.life-=p.decay;if(p.life<=0){particles.splice(j,1);continue;}pCtx.globalAlpha=p.life;pCtx.beginPath();pCtx.arc(p.x,p.y,p.size*p.life,0,Math.PI*2);pCtx.fillStyle=p.color;pCtx.fill();}pCtx.globalAlpha=1;}
    if(cCtx){cCtx.clearRect(0,0,300,300);for(var k=confettiParticles.length-1;k>=0;k--){var c=confettiParticles[k];c.x+=c.vx;c.y+=c.vy;c.vy+=c.gravity;c.vx*=0.99;c.rot+=c.rotSpeed;c.life-=c.decay;if(c.life<=0){confettiParticles.splice(k,1);continue;}cCtx.save();cCtx.translate(c.x,c.y);cCtx.rotate(c.rot);cCtx.globalAlpha=c.life;cCtx.fillStyle=c.color;cCtx.fillRect(-c.size/2,-c.size/4,c.size,c.size/2);cCtx.restore();}cCtx.globalAlpha=1;}
    if(glowIntensity>0)glowIntensity=Math.max(0,glowIntensity-0.02);
  }
  drawWheel(0);

  document.getElementById('spin-btn').addEventListener('click',function(){
    if(spinning)return;spinning=true;this.disabled=true;this.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Spinning...';glowIntensity=1;
    initAudio();
    var extraSpin=Math.PI*2*(5+Math.floor(Math.random()*5));var finalAngle=extraSpin+Math.random()*Math.PI*2;var start=null,duration=4000,lastTickSeg=-1;
    function eo(t){return 1-Math.pow(1-t,3);}
    function ee(t){if(t===0||t===1)return t;return Math.pow(2,-10*t)*Math.sin((t-0.075)*(2*Math.PI)/0.3)+1;}
    function animate(ts){
      if(!start)start=ts;var elapsed=ts-start;var progress=Math.min(elapsed/duration,1);
      var current=(progress<0.85)?eo(progress/0.85)*finalAngle*0.85:(finalAngle*0.85)+ee((progress-0.85)/0.15)*finalAngle*0.15;
      angle=current%(Math.PI*2);drawWheel(angle);
      if(audioCtx){var segAngle=current/arc;var roundedSeg=Math.round(segAngle);if(Math.abs(segAngle-roundedSeg)<0.07&&roundedSeg!==lastTickSeg){playTick();lastTickSeg=roundedSeg;}}
      if(progress<1&&Math.random()>0.7)createParticles(150+Math.random()*60-30,150+Math.random()*60-30,3,'#FFD700');
      if(progress<1){requestAnimationFrame(animate);}else{
        var pa=3*Math.PI/2;var n=((pa-angle)%(Math.PI*2)+Math.PI*2)%(Math.PI*2);var si=Math.floor(n/arc)%numSeg;var res=segments[si];
        var rel=document.getElementById('wheel-result');rel.textContent='Result: '+res;rel.classList.add('pop-in');
        createConfetti(40);glowIntensity=1.5;createParticles(150,150,20,'#8AD24A');createParticles(150,150,15,'#FFD700');createParticles(150,150,15,'#2E8FD6');
        playWin();
        document.getElementById('spin-result-input').value=res;document.getElementById('spin-proof-input').value='Spin result: '+res;
        setTimeout(function(){document.getElementById('spin-form').submit();},1800);
      }
    }
    requestAnimationFrame(animate);
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
