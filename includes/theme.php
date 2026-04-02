<?php
declare(strict_types=1);

if (!function_exists('theme_head_script')) {
    function theme_head_script(): void
    {
        ?>
<script>
(function () {
  var KEY = 'kb_theme_mode';
  var VALID = { auto: 1, light: 1, dark: 1 };
  var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  function normalize(v) {
    return VALID[v] ? v : 'auto';
  }

  function loadMode() {
    try {
      return normalize(localStorage.getItem(KEY) || 'auto');
    } catch (e) {
      return 'auto';
    }
  }

  function saveMode(mode) {
    try {
      localStorage.setItem(KEY, mode);
    } catch (e) {}
  }

  function resolve(mode) {
    if (mode === 'auto') return (mq && mq.matches) ? 'dark' : 'light';
    return mode;
  }

  function apply(mode, persist) {
    mode = normalize(mode);
    var resolved = resolve(mode);
    document.documentElement.setAttribute('data-bs-theme', resolved);
    document.documentElement.setAttribute('data-theme-mode', mode);
    window.__kbTheme = window.__kbTheme || {};
    window.__kbTheme.mode = mode;
    window.__kbTheme.resolved = resolved;
    if (persist) saveMode(mode);
    window.dispatchEvent(new CustomEvent('kb-theme-change', { detail: { mode: mode, resolved: resolved } }));
  }

  apply(loadMode(), false);

  window.__kbTheme = window.__kbTheme || {};
  window.__kbTheme.getMode = function () {
    return normalize((window.__kbTheme && window.__kbTheme.mode) || loadMode());
  };
  window.__kbTheme.getResolved = function () {
    return document.documentElement.getAttribute('data-bs-theme') || resolve(window.__kbTheme.getMode());
  };
  window.__kbTheme.setMode = function (mode) {
    apply(mode, true);
  };
  window.__kbTheme.refreshAuto = function () {
    if (window.__kbTheme.getMode() === 'auto') apply('auto', false);
  };

  if (mq) {
    var onSystemChange = function () {
      if (window.__kbTheme.getMode() === 'auto') apply('auto', false);
    };
    if (mq.addEventListener) mq.addEventListener('change', onSystemChange);
    else if (mq.addListener) mq.addListener(onSystemChange);
  }
})();
</script>
<style>
html[data-bs-theme="dark"]{
  --kb-gh-bg: #0d1117;
  --kb-gh-panel: #161b22;
  --kb-gh-border: #30363d;
  --kb-gh-text: #c9d1d9;
  --kb-gh-muted: #8b949e;
}
html[data-bs-theme="dark"] body{
  background:var(--kb-gh-bg) !important;
  color:var(--kb-gh-text) !important;
}
html[data-bs-theme="dark"] .card{
  background-color:var(--kb-gh-panel) !important;
  border-color:var(--kb-gh-border) !important;
}
html[data-bs-theme="dark"] .table{
  --bs-table-bg: transparent;
  --bs-table-color: var(--kb-gh-text);
  --bs-table-border-color: var(--kb-gh-border);
}
html[data-bs-theme="dark"] .table-light,
html[data-bs-theme="dark"] .table-light > :not(caption) > * > *{
  --bs-table-bg: var(--kb-gh-panel) !important;
  --bs-table-color: var(--kb-gh-text) !important;
  color:var(--kb-gh-text) !important;
}
html[data-bs-theme="dark"] .table.table-bordered.align-middle.table-sm thead > tr > th{
  border-top-color: #4f5864 !important;
  border-bottom-color: #4f5864 !important;
}
html[data-bs-theme="dark"] .table.table-bordered.align-middle.table-sm tbody > tr:first-child > *{
  border-top-color: #4f5864 !important;
}
html[data-bs-theme="dark"] .sticky-col,
html[data-bs-theme="dark"] th.sticky-col{
  background:var(--kb-gh-panel) !important;
}
html[data-bs-theme="dark"] .now-pill{
  background:var(--kb-gh-panel) !important;
}
html[data-bs-theme="dark"] .gear-dropup,
html[data-bs-theme="dark"] .share-dropup{
  background:var(--kb-gh-panel) !important;
  border-color:var(--kb-gh-border) !important;
}
html[data-bs-theme="dark"] .badge.text-bg-light{
  background:var(--kb-gh-panel) !important;
  color:var(--kb-gh-text) !important;
  border-color:var(--kb-gh-border) !important;
}
html[data-bs-theme="dark"] .text-muted{
  color:var(--kb-gh-muted) !important;
}
html[data-bs-theme="dark"] .capsule .cap-text{
  color:#2f2f2f !important;
}
html[data-bs-theme="dark"] #shareCreateResult{
  background:#111827 !important;
  border-color:#3d444d !important;
}
html[data-bs-theme="dark"] #shareCreateResult a{
  color:#58a6ff !important;
}
html[data-bs-theme="dark"] #shareCreateResult .btn-outline-secondary{
  color:var(--kb-gh-text) !important;
  border-color:#3d444d !important;
}
html[data-bs-theme="dark"] #shareCreateResult .btn-outline-secondary:hover{
  background:#21262d !important;
}
</style>
        <?php
    }
}

if (!function_exists('theme_selector_control')) {
    function theme_selector_control(string $extraClass = ''): void
    {
        $class = trim('d-inline-flex align-items-center gap-1 ' . $extraClass);
        ?>
<div class="<?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>" data-theme-control>
  <label class="small text-muted" for="themeSelect">主题</label>
  <select id="themeSelect" class="form-select form-select-sm" data-theme-select style="width: 75px;">
    <option value="auto">自动</option>
    <option value="light">亮色</option>
    <option value="dark">深色</option>
  </select>
</div>
        <?php
    }
}

if (!function_exists('theme_controls_script')) {
    function theme_controls_script(): void
    {
        ?>
<script>
(function () {
  function modeValue() {
    return (window.__kbTheme && window.__kbTheme.getMode) ? window.__kbTheme.getMode() : 'auto';
  }

  function bindSelect(sel) {
    if (!sel || sel.dataset.themeBound === '1') return;
    sel.dataset.themeBound = '1';
    sel.value = modeValue();
    sel.addEventListener('change', function () {
      if (window.__kbTheme && window.__kbTheme.setMode) window.__kbTheme.setMode(sel.value);
    });
  }

  function syncAll() {
    var val = modeValue();
    document.querySelectorAll('[data-theme-select]').forEach(function (sel) {
      if (sel.value !== val) sel.value = val;
    });
  }

  function initThemeControls() {
    document.querySelectorAll('[data-theme-select]').forEach(bindSelect);
    syncAll();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initThemeControls);
  } else {
    initThemeControls();
  }
  window.addEventListener('kb-theme-change', syncAll);
})();
</script>
        <?php
    }
}
