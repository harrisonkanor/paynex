// =============================================================
// payNex — main.js
// Handles: scroll reveals, stat counters, feature toggle,
//          hamburger menu, profile nav dropdown, Three.js hero,
//          crypto address validation, copy-to-clipboard
// =============================================================

// ---------- Reveal on scroll ----------
const revealEls = document.querySelectorAll('.reveal');
const io = new IntersectionObserver((entries) => {
  entries.forEach(e => {
    if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
  });
}, { threshold: 0.15 });
revealEls.forEach(el => io.observe(el));

// ---------- Stat counters (landing page only) ----------
// Guard with null check — .ledger only exists on index.php.
// Without this guard the script crashes on every other page
// and breaks the hamburger, dropdown, and all other JS.
function animateCount(el, target, prefix = '', suffix = '', duration = 1400) {
  const start = performance.now();
  function tick(now) {
    const p = Math.min((now - start) / duration, 1);
    const eased = 1 - Math.pow(1 - p, 3);
    const val = Math.floor(eased * target);
    el.textContent = prefix + val.toLocaleString() + suffix;
    if (p < 1) requestAnimationFrame(tick);
  }
  requestAnimationFrame(tick);
}

const statsSection = document.querySelector('.ledger');
if (statsSection) {
  // Only run on pages that actually have the stat counters
  let countersStarted = false;
  const statIo = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (e.isIntersecting && !countersStarted) {
        countersStarted = true;
        var liveStats = window.PAYNEX_STATS || { tasks: 0, paid: 0, users: 0 };
        var tEl = document.getElementById('stat-tasks');
        var pEl = document.getElementById('stat-paid');
        var uEl = document.getElementById('stat-users');
        if (tEl) animateCount(tEl, liveStats.tasks);
        if (pEl) animateCount(pEl, liveStats.paid, '$');
        if (uEl) animateCount(uEl, liveStats.users);
      }
    });
  }, { threshold: 0.4 });
  statIo.observe(statsSection);
}

// ---------- Feature toggle content (landing page only) ----------
const FEATURES = {
  earner: [
    { t: 'Browse &amp; filter tasks',  d: 'Search by category, reward amount, difficulty or deadline to find work that fits right now.' },
    { t: 'Personal wallet',            d: 'Available balance, pending balance and lifetime earnings, always up to date.' },
    { t: 'Flexible withdrawals',       d: 'Request a payout to your preferred crypto wallet and track it through to Paid.' },
    { t: 'Referral bonuses',           d: 'Share your unique link, earn a bonus for every referral, and climb the leaderboard.' },
    { t: 'Task status tracking',       d: 'Know exactly where every task stands — from Available to Paid.' },
    { t: 'Ratings you can trust',      d: 'Rate task creators and see their ratings before you accept work.' },
  ],
  creator: [
    { t: 'Post tasks in minutes',      d: 'Set a reward, requirements, supporting files and a submission deadline.' },
    { t: 'Review submissions',         d: 'Approve, reject or leave feedback on every piece of submitted work.' },
    { t: 'Release payments',           d: 'Approve a submission and the reward moves straight to the earner\'s wallet.' },
    { t: 'Custom task categories',     d: 'Run surveys, data entry, referral campaigns or fully custom task types.' },
    { t: 'Worker ratings',             d: 'Rate the people who complete your tasks to build a reliable pool of workers.' },
    { t: 'Clear analytics',            d: 'See completion rates and spend across every task you\'ve posted.' },
  ]
};
const ICONS = {
  earner:  ['🔎', '👛', '⇄', '🔗', '📊', '⭐'],
  creator: ['✏️', '✅', '💸', '🗂️', '🏷️', '📈']
};

function renderFeatures(target) {
  const grid = document.getElementById('feature-grid');
  if (!grid) return;
  grid.innerHTML = '';
  FEATURES[target].forEach((f, i) => {
    const card = document.createElement('div');
    card.className = 'feature-card';
    card.style.animationDelay = (i * 0.06) + 's';
    card.innerHTML = `<div class="ic">${ICONS[target][i]}</div><h4>${f.t}</h4><p>${f.d}</p>`;
    grid.appendChild(card);
  });
}

const featureGrid = document.getElementById('feature-grid');
if (featureGrid) {
  renderFeatures('earner');
  document.querySelectorAll('#audience-toggle button').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('#audience-toggle button').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      renderFeatures(btn.dataset.target);
    });
  });
}

// =============================================================
// HAMBURGER MENU — works on every page
// Toggles .mobile-open on the drawer and .open on the button
// =============================================================
(function () {
  var toggle   = document.getElementById('menu-toggle');
  var mobileNav = document.getElementById('mobile-nav');
  if (!toggle || !mobileNav) return;

  toggle.addEventListener('click', function (e) {
    e.stopPropagation();
    var isOpen = mobileNav.classList.contains('mobile-open');
    if (isOpen) {
      // Close
      mobileNav.classList.remove('mobile-open');
      toggle.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
      mobileNav.setAttribute('aria-hidden', 'true');
    } else {
      // Open
      mobileNav.classList.add('mobile-open');
      toggle.classList.add('open');
      toggle.setAttribute('aria-expanded', 'true');
      mobileNav.setAttribute('aria-hidden', 'false');
    }
  });

  // Close mobile nav when clicking anywhere outside it
  document.addEventListener('click', function (e) {
    if (mobileNav.classList.contains('mobile-open') &&
        !mobileNav.contains(e.target) &&
        !toggle.contains(e.target)) {
      mobileNav.classList.remove('mobile-open');
      toggle.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
      mobileNav.setAttribute('aria-hidden', 'true');
    }
  });

  // Close mobile nav when a link inside it is tapped
  mobileNav.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', function () {
      mobileNav.classList.remove('mobile-open');
      toggle.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
      mobileNav.setAttribute('aria-hidden', 'true');
    });
  });
})();

// =============================================================
// PROFILE NAV DROPDOWN — click to toggle on mobile
// On desktop CSS :hover handles it; on mobile we need a click
// =============================================================
(function () {
  var menu = document.querySelector('.nav-user-menu');
  if (!menu) return;

  // On touch/small screens, toggle the dropdown on click
  menu.addEventListener('click', function (e) {
    // Only intercept clicks on the avatar/name area, not on dropdown links
    if (e.target.closest('.nav-dropdown')) return;
    menu.classList.toggle('nav-menu-open');
  });

  // Close when clicking outside
  document.addEventListener('click', function (e) {
    if (!menu.contains(e.target)) {
      menu.classList.remove('nav-menu-open');
    }
  });
})();

// =============================================================
// CRYPTO ADDRESS VALIDATION — real-time client-side validation
// Works on signup page and profile page
// =============================================================
(function () {
  var cryptoInputs = document.querySelectorAll('.crypto-input');
  if (!cryptoInputs.length) return;

  function validateBTC(addr) {
    addr = addr.trim();
    if (addr === '') return { valid: true, msg: '' };
    // Legacy: starts with 1, 26-34 chars
    if (/^1[a-km-zA-HJ-NP-Z1-9]{25,34}$/.test(addr)) return { valid: true, msg: 'Valid BTC address' };
    // SegWit: starts with 3, 26-34 chars
    if (/^3[a-km-zA-HJ-NP-Z1-9]{25,34}$/.test(addr)) return { valid: true, msg: 'Valid BTC address' };
    // Bech32: starts with bc1
    if (/^bc1[ac-hj-np-z02-9]{8,87}$/.test(addr)) return { valid: true, msg: 'Valid BTC (Bech32) address' };
    return { valid: false, msg: 'Invalid BTC address format' };
  }

  function validateUSDT(addr) {
    addr = addr.trim();
    if (addr === '') return { valid: true, msg: '' };
    // Tron address: starts with T, 34 chars, base58
    if (addr.length === 34 && addr[0] === 'T' && /^T[a-km-zA-HJ-NP-Z1-9]{33}$/.test(addr)) {
      return { valid: true, msg: 'Valid USDT (TRC-20) address' };
    }
    return { valid: false, msg: 'Invalid USDT address — must start with T and be 34 characters' };
  }

  cryptoInputs.forEach(function(input) {
    input.addEventListener('input', function() {
      var type = input.dataset.cryptoType;
      var hintEl = input.parentElement.querySelector('.crypto-hint');
      if (!hintEl) return;

      var defaultHint = hintEl.querySelector('.hint-default');
      var validHint = hintEl.querySelector('.hint-valid');
      var invalidHint = hintEl.querySelector('.hint-invalid');

      // Reset
      if (defaultHint) defaultHint.style.display = '';
      if (validHint) validHint.style.display = 'none';
      if (invalidHint) invalidHint.style.display = 'none';

      var value = input.value.trim();
      if (value === '') {
        input.style.borderColor = '';
        return;
      }

      var result;
      if (type === 'btc') {
        result = validateBTC(value);
      } else if (type === 'usdt') {
        result = validateUSDT(value);
      }

      if (result && result.valid) {
        input.style.borderColor = 'var(--green)';
        if (defaultHint) defaultHint.style.display = 'none';
        if (validHint) { validHint.style.display = ''; validHint.innerHTML = '<i class="fa-solid fa-check-circle"></i> ' + result.msg; }
      } else if (result && !result.valid) {
        input.style.borderColor = 'var(--red)';
        if (defaultHint) defaultHint.style.display = 'none';
        if (invalidHint) { invalidHint.style.display = ''; invalidHint.innerHTML = '<i class="fa-solid fa-exclamation-circle"></i> ' + result.msg; }
      }
    });
  });
})();

// =============================================================
// COPY TO CLIPBOARD — generic utility for deposit addresses
// =============================================================
(function () {
  // Make copyAddr available globally for inline onclick handlers
  window.copyAddr = function(btn, text) {
    navigator.clipboard.writeText(text).then(function() {
      btn.classList.add('copied');
      btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
      setTimeout(function() {
        btn.classList.remove('copied');
        btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy';
      }, 2000);
    }).catch(function() {
      // Fallback for older browsers
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
      setTimeout(function() {
        btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy';
      }, 2000);
    });
  };
})();

// =============================================================
// Three.js HERO SCENE — landing page only
// Guard with null check so it doesn't crash on other pages
// =============================================================
(function () {
  var canvas = document.getElementById('hero-canvas');
  if (!canvas) return; // not on landing page — stop here safely

  var container = canvas.parentElement;
  var w = container.clientWidth;
  var h = container.clientHeight;

  var scene    = new THREE.Scene();
  var camera   = new THREE.PerspectiveCamera(42, w / h, 0.1, 100);
  camera.position.set(0, 0.6, 7.5);

  var renderer = new THREE.WebGLRenderer({ canvas: canvas, antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 1.8));
  renderer.setSize(w, h);

  var key = new THREE.PointLight(0x5cb3f0, 1.4, 30);
  key.position.set(4, 4, 6);
  scene.add(key);
  var fill = new THREE.PointLight(0x8ad24a, 1.1, 30);
  fill.position.set(-4, -2, 4);
  scene.add(fill);
  scene.add(new THREE.AmbientLight(0x1a2c3a, 1.2));

  // Central wallet mesh
  var walletGroup = new THREE.Group();
  var walletMat   = new THREE.MeshStandardMaterial({
    color: 0x123047, metalness: 0.35, roughness: 0.35,
    emissive: 0x0a1b29, emissiveIntensity: 0.4
  });
  var walletGeo = new THREE.BoxGeometry(2.4, 1.5, 0.25);
  var wallet    = new THREE.Mesh(walletGeo, walletMat);
  walletGroup.add(wallet);
  var rim = new THREE.Mesh(
    new THREE.TorusGeometry(0.42, 0.045, 16, 60),
    new THREE.MeshStandardMaterial({
      color: 0x8ad24a, metalness: 0.6, roughness: 0.25,
      emissive: 0x2c4a12, emissiveIntensity: 0.5
    })
  );
  rim.position.set(0.55, 0, 0.16);
  walletGroup.add(rim);
  scene.add(walletGroup);

  // Orbiting coins
  var coins    = new THREE.Group();
  var coinGeo  = new THREE.CylinderGeometry(0.34, 0.34, 0.09, 40);
  var coinMatG = new THREE.MeshStandardMaterial({ color: 0x8ad24a, metalness: 0.55, roughness: 0.3, emissive: 0x2c4a12, emissiveIntensity: 0.35 });
  var coinMatB = new THREE.MeshStandardMaterial({ color: 0x2e8fd6, metalness: 0.55, roughness: 0.3, emissive: 0x0f2c40, emissiveIntensity: 0.35 });
  var coinData = [];
  var N = 7;
  for (var i = 0; i < N; i++) {
    var c      = new THREE.Mesh(coinGeo, i % 2 === 0 ? coinMatG : coinMatB);
    var radius = 2.6 + (i % 3) * 0.35;
    var angle  = (i / N) * Math.PI * 2;
    var speed  = 0.18 + (i % 3) * 0.05;
    var yOff   = Math.sin(i * 1.7) * 0.9;
    coinData.push({ mesh: c, radius: radius, angle: angle, speed: speed, yOff: yOff });
    coins.add(c);
  }
  scene.add(coins);

  // Faint ring
  var ring = new THREE.Mesh(
    new THREE.TorusGeometry(2.9, 0.006, 8, 120),
    new THREE.MeshBasicMaterial({ color: 0x5cb3f0, transparent: true, opacity: 0.18 })
  );
  ring.rotation.x = Math.PI / 2.1;
  scene.add(ring);

  window.addEventListener('resize', function () {
    w = container.clientWidth; h = container.clientHeight;
    camera.aspect = w / h; camera.updateProjectionMatrix();
    renderer.setSize(w, h);
  });

  var mouseX = 0, mouseY = 0;
  container.addEventListener('mousemove', function (e) {
    var r = container.getBoundingClientRect();
    mouseX = ((e.clientX - r.left) / r.width  - 0.5) * 2;
    mouseY = ((e.clientY - r.top)  / r.height - 0.5) * 2;
  });

  var clock = new THREE.Clock();
  function animate() {
    requestAnimationFrame(animate);
    var t = clock.getElapsedTime();

    walletGroup.rotation.y = Math.sin(t * 0.3) * 0.25 + mouseX * 0.2;
    walletGroup.rotation.x = Math.cos(t * 0.25) * 0.08 + mouseY * 0.1;
    rim.rotation.z = t * 0.6;

    coinData.forEach(function (d) {
      d.angle += 0.006 * (d.speed * 3);
      d.mesh.position.x = Math.cos(d.angle) * d.radius;
      d.mesh.position.z = Math.sin(d.angle) * d.radius * 0.55 - 1.5;
      d.mesh.position.y = d.yOff * 0.4 + Math.sin(t * 0.8 + d.angle) * 0.15;
      d.mesh.rotation.x = t * 0.8;
      d.mesh.rotation.y = t * 0.5;
    });

    ring.rotation.z = t * 0.05;
    camera.position.x = mouseX * 0.4;
    camera.position.y = 0.6 + mouseY * 0.2;
    camera.lookAt(0, 0, 0);

    renderer.render(scene, camera);
  }
  animate();
})();
