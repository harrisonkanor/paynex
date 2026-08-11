(function() {
  'use strict';

  var countdownEls = document.querySelectorAll('[data-spin-countdown]');
  if (countdownEls.length) {
    (function updateBadge() {
      countdownEls.forEach(function(el) {
        var end = parseInt(el.dataset.spinCountdown, 10);
        if (!end) return;
        var diff = end - Math.floor(Date.now() / 1000);
        if (diff <= 0) {
          el.innerHTML = '<span class="badge badge-active"><i class="fa-solid fa-bolt"></i> Ready to spin</span>';
          return;
        }
        var h = Math.floor(diff / 3600);
        var m = Math.floor((diff % 3600) / 60);
        var s = diff % 60;
        el.innerHTML = '<span class="badge badge-pending"><i class="fa-solid fa-clock"></i> ' +
          String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0')+'</span>';
      });
    })();
    setInterval(function() {
      document.querySelectorAll('[data-spin-countdown]').forEach(function(el) {
        var end = parseInt(el.dataset.spinCountdown, 10);
        if (!end) return;
        var diff = end - Math.floor(Date.now() / 1000);
        if (diff <= 0) {
          el.innerHTML = '<span class="badge badge-active"><i class="fa-solid fa-bolt"></i> Ready to spin</span>';
          return;
        }
        var h = Math.floor(diff / 3600);
        var m = Math.floor((diff % 3600) / 60);
        var s = diff % 60;
        el.innerHTML = '<span class="badge badge-pending"><i class="fa-solid fa-clock"></i> ' +
          String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0')+'</span>';
      });
    }, 1000);
  }

  var canvas = document.getElementById('dash-wheel-canvas');
  if (!canvas) return;

  var ctx = canvas.getContext('2d');
  var pCanvas = document.getElementById('dash-particle-canvas');
  var cCanvas = document.getElementById('dash-confetti-canvas');
  var pCtx = pCanvas ? pCanvas.getContext('2d') : null;
  var cCtx = cCanvas ? cCanvas.getContext('2d') : null;

  var segments = ['$5.00','$3.00','$1.00','$0.50','$0.50','$0.50','$0.50','$0.50','$0.50','$0.50','$0.50','$0.50','$0.50','$0.30','$0.30','$0.30','$0.30','$0.30','$0.30','$0.30','$0.30','$0.30','$0.30','$0.30','$0.30','$0.20','$0.20','$0.20','$0.20','$0.20','$0.20','$0.20','$0.20','$0.20','$0.20','$0.20','$0.20','$0.20','$0.20','$0.20','$0.10','$0.10','$0.10','$0.10','$0.10','$0.10','$0.10','$0.10','$0.10','$0.10'];
  var segColors = ['#FFD700','#FFD700','#FFD700','#8AD24A','#8AD24A','#8AD24A','#8AD24A','#8AD24A','#8AD24A','#8AD24A','#8AD24A','#8AD24A','#8AD24A','#2E8FD6','#2E8FD6','#2E8FD6','#2E8FD6','#2E8FD6','#2E8FD6','#2E8FD6','#2E8FD6','#2E8FD6','#2E8FD6','#2E8FD6','#2E8FD6','#0A1B29','#0A1B29','#0A1B29','#0A1B29','#0A1B29','#0A1B29','#0A1B29','#0A1B29','#0A1B29','#0A1B29','#0A1B29','#0A1B29','#0A1B29','#0A1B29','#0A1B29','#F7931A','#F7931A','#F7931A','#F7931A','#F7931A','#F7931A','#F7931A','#F7931A','#F7931A','#F7931A'];
  var numSeg = segments.length;
  var arc = (2 * Math.PI) / numSeg;
  var angle = 0;
  var spinning = false;
  var particles = [];
  var confetti = [];
  var POINTER_ANGLE = 3 * Math.PI / 2;

  var BASE_META = document.querySelector('meta[name="base-url"]');
  var baseUrl = BASE_META ? BASE_META.getAttribute('content') : '/paynex';

  var audioCtx = null;
  function initAudio() { if (audioCtx) return; try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e) {} }
  function playTick() { if (!audioCtx) return; try { var osc = audioCtx.createOscillator(); osc.type = 'sine'; var gain = audioCtx.createGain(); osc.connect(gain); gain.connect(audioCtx.destination); osc.frequency.value = 800 + Math.random() * 400; gain.gain.setValueAtTime(0.08, audioCtx.currentTime); gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.08); osc.start(audioCtx.currentTime); osc.stop(audioCtx.currentTime + 0.08); } catch(e) {} }
  function playWin() { if (!audioCtx) return; try { [523,659,784,1047].forEach(function(freq,i) { var osc = audioCtx.createOscillator(); osc.type = 'sine'; var gain = audioCtx.createGain(); osc.connect(gain); gain.connect(audioCtx.destination); osc.frequency.value = freq; var t = audioCtx.currentTime + i*0.12; gain.gain.setValueAtTime(0.1,t); gain.gain.exponentialRampToValueAtTime(0.001,t+0.3); osc.start(t); osc.stop(t+0.3); }); } catch(e) {} }

  function addParticles(x,y,count,color) { for (var i=0;i<count;i++) { particles.push({x:x,y:y,vx:(Math.random()-0.5)*6,vy:(Math.random()-0.5)*6-2,life:1,decay:0.015+Math.random()*0.025,size:2+Math.random()*4,color:color||'#8AD24A'}); } }
  function addConfetti(count) { var colors=['#8AD24A','#2E8FD6','#F7931A','#E2685F','#FFD700','#FF69B4','#00CED1']; for(var i=0;i<count;i++){ confetti.push({x:120,y:120,vx:(Math.random()-0.5)*14,vy:(Math.random()-0.5)*14-8,life:1,decay:0.004+Math.random()*0.008,size:4+Math.random()*6,color:colors[Math.floor(Math.random()*colors.length)],rot:Math.random()*Math.PI*2,rotSpeed:(Math.random()-0.5)*0.3,gravity:0.1+Math.random()*0.06}); } }

  function drawWheel(rot) {
    ctx.clearRect(0,0,240,240);
    var og=ctx.createRadialGradient(120,120,100,120,120,130);og.addColorStop(0,'rgba(138,210,74,0)');og.addColorStop(0.7,'rgba(138,210,74,0.05)');og.addColorStop(1,'rgba(138,210,74,0.15)');ctx.fillStyle=og;ctx.beginPath();ctx.arc(120,120,130,0,Math.PI*2);ctx.fill();
    for(var i=0;i<numSeg;i++){var sA=rot+arc*i,eA=rot+arc*(i+1);ctx.beginPath();ctx.moveTo(121,121);ctx.arc(121,121,110,sA,eA);ctx.closePath();ctx.fillStyle='rgba(0,0,0,0.2)';ctx.fill();ctx.beginPath();ctx.moveTo(120,120);ctx.arc(120,120,110,sA,eA);ctx.closePath();ctx.fillStyle=segColors[i%segColors.length];ctx.fill();ctx.beginPath();ctx.moveTo(120,120);ctx.arc(120,120,90,sA+0.05,eA-0.05);ctx.closePath();var sh=ctx.createRadialGradient(120,120,0,120,120,90);sh.addColorStop(0,'rgba(255,255,255,0.15)');sh.addColorStop(0.6,'rgba(255,255,255,0.05)');sh.addColorStop(1,'rgba(255,255,255,0)');ctx.fillStyle=sh;ctx.fill();ctx.beginPath();ctx.moveTo(120,120);ctx.arc(120,120,110,sA,eA);ctx.closePath();ctx.strokeStyle='rgba(255,255,255,0.35)';ctx.lineWidth=1.5;ctx.stroke();ctx.save();ctx.translate(120,120);ctx.rotate(sA+arc/2);ctx.fillStyle='#fff';ctx.font='bold 7px "Space Grotesk",sans-serif';ctx.textAlign='right';ctx.shadowColor='rgba(0,0,0,0.5)';ctx.shadowBlur=2;ctx.fillText(segments[i],106,2);ctx.shadowBlur=0;ctx.restore();}
    var hg=ctx.createRadialGradient(116,116,0,120,120,20);hg.addColorStop(0,'#B5E87A');hg.addColorStop(0.4,'#8AD24A');hg.addColorStop(0.8,'#4A8A2A');hg.addColorStop(1,'#0A1B29');ctx.beginPath();ctx.arc(120,120,18,0,Math.PI*2);ctx.fillStyle=hg;ctx.fill();ctx.beginPath();ctx.arc(120,120,18,0,Math.PI*2);ctx.strokeStyle='rgba(255,255,255,0.25)';ctx.lineWidth=2;ctx.stroke();ctx.beginPath();ctx.arc(120,120,4,0,Math.PI*2);ctx.fillStyle='#0A1B29';ctx.fill();
    ctx.shadowColor='rgba(247,147,26,0.4)';ctx.shadowBlur=12;ctx.beginPath();ctx.moveTo(120,6);ctx.lineTo(108,0);ctx.lineTo(132,0);ctx.closePath();var pg=ctx.createLinearGradient(120,0,120,6);pg.addColorStop(0,'#FFB74D');pg.addColorStop(0.5,'#F7931A');pg.addColorStop(1,'#E67A00');ctx.fillStyle=pg;ctx.fill();ctx.shadowBlur=0;
    if(pCtx){pCtx.clearRect(0,0,240,240);for(var j=particles.length-1;j>=0;j--){var p=particles[j];p.x+=p.vx;p.y+=p.vy;p.vy+=0.05;p.life-=p.decay;if(p.life<=0){particles.splice(j,1);continue;}pCtx.globalAlpha=p.life;pCtx.beginPath();pCtx.arc(p.x,p.y,p.size*p.life,0,Math.PI*2);pCtx.fillStyle=p.color;pCtx.fill();}pCtx.globalAlpha=1;}
    if(cCtx){cCtx.clearRect(0,0,240,240);for(var k=confetti.length-1;k>=0;k--){var c=confetti[k];c.x+=c.vx;c.y+=c.vy;c.vy+=c.gravity;c.vx*=0.99;c.rot+=c.rotSpeed;c.life-=c.decay;if(c.life<=0){confetti.splice(k,1);continue;}cCtx.save();cCtx.translate(c.x,c.y);cCtx.rotate(c.rot);cCtx.globalAlpha=c.life;cCtx.fillStyle=c.color;cCtx.fillRect(-c.size/2,-c.size/4,c.size,c.size/2);cCtx.restore();}cCtx.globalAlpha=1;}
  }

  drawWheel(0);

  var btn = document.getElementById('dash-spin-btn');
  var wheelWrap = btn ? btn.closest('.wheel-wrap') : null;
  if (!btn) return;

  function showCountdown(nextSpinTimestamp) {
    if (!wheelWrap) return;
    var target = nextSpinTimestamp || parseInt((document.querySelector('[data-spin-countdown]') || {}).dataset.spinCountdown, 10);
    if (!target) return;
    wheelWrap.innerHTML = '<div style="text-align:center;padding:20px 0;">' +
      '<i class="fa-solid fa-hourglass-half" style="font-size:32px;color:var(--amber);display:block;margin-bottom:10px;"></i>' +
      '<p class="text-muted">Next spin at midnight in:</p>' +
      '<p style="font-size:28px;font-weight:700;font-family:Space Grotesk,monospace;color:var(--amber);" id="dash-countdown-display">00:00:00</p>' +
      '<p style="font-size:12px;color:var(--ink-soft);margin-top:8px;">Spin resets daily at 00:00 UTC.</p>' +
      '</div>';
    if (window._cdInterval) clearInterval(window._cdInterval);
    window._cdInterval = setInterval(function() {
      var diff = target - Math.floor(Date.now() / 1000);
      if (diff <= 0) { clearInterval(window._cdInterval); window.location.reload(); return; }
      var h = Math.floor(diff / 3600);
      var m = Math.floor((diff % 3600) / 60);
      var s = diff % 60;
      var el = document.getElementById('dash-countdown-display');
      if (el) el.textContent = String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    }, 1000);
  }

  btn.onclick = function() {
    if (spinning) return;
    initAudio();
    spinning = true;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Spinning...';
    var resultEl = document.getElementById('dash-wheel-result');
    if (resultEl) { resultEl.textContent = ''; resultEl.className = 'wheel-result'; }

    fetch(baseUrl + '/spin.php', { method: 'POST' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.success) { if (resultEl) resultEl.textContent = 'Error: ' + data.error; spinning = false; btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-rotate"></i> Spin now'; return; }
        var targetSeg = data.segment;
        var targetAngle = POINTER_ANGLE - (targetSeg + 0.5) * arc;
        targetAngle = ((targetAngle % (Math.PI * 2)) + Math.PI * 2) % (Math.PI * 2);
        var totalSpin = Math.PI * 2 * 7 + targetAngle;
        var start = null, duration = 4000, lastTickAngle = 0;

        function easeOutQuart(t) { return 1 - Math.pow(1 - t, 4); }

        function animate(ts) {
          if (!start) start = ts;
          var elapsed = ts - start;
          var progress = Math.min(elapsed / duration, 1);
          var current = easeOutQuart(progress) * totalSpin;
          angle = current % (Math.PI * 2);
          drawWheel(angle);
          if (audioCtx) { var segAngle = current / arc; if (Math.abs(segAngle - Math.round(segAngle)) < 0.08 && Math.abs(segAngle - lastTickAngle) > 0.3) { playTick(); lastTickAngle = Math.round(segAngle); } }
          if (progress < 1 && Math.random() > 0.7) addParticles(120+Math.random()*40-20,120+Math.random()*40-20,2,'#FFD700');
          if (progress < 1) { requestAnimationFrame(animate); } else {
            var reward = parseFloat(data.reward) || 0;
            if (resultEl) { resultEl.innerHTML = reward > 0 ? '<span style="font-size:28px;">&#127881;</span> You won ' + data.result + '!' : data.result + ' &#128546;'; resultEl.classList.add('pop-in'); btn.innerHTML = '<i class="fa-solid fa-lock"></i> Spin locked'; }
            addConfetti(30); addParticles(120,120,15,'#8AD24A'); addParticles(120,120,10,'#FFD700');
            if (reward > 0) playWin();
            setTimeout(function() { if (data.next_spin_at) showCountdown(data.next_spin_at); }, 2500);
          }
        }
        requestAnimationFrame(animate);
      })
      .catch(function(err) { if (resultEl) resultEl.textContent = 'Error spinning. Try again.'; spinning = false; btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-rotate"></i> Spin now'; });
  };
})();
