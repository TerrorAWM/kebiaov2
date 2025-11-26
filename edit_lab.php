<?php

declare(strict_types=1);
mb_internal_encoding('UTF-8');
session_start();

/* ===== 公共工具 ===== */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function json_out($arr, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}
function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    if (is_file(__DIR__ . '/db.php')) require_once __DIR__ . '/db.php';
    if (!defined('DB_DSN')) {
      http_response_code(500);
      exit('缺少 db.php 或 DB_DSN/DB_USER/DB_PASS 定义');
    }
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }
  return $pdo;
}
function is_logged_in(): bool { return isset($_SESSION['uid']) && is_numeric($_SESSION['uid']); }
function require_login(): int {
  if (!is_logged_in()) { json_out(['ok'=>false,'error'=>'未登录'], 401); }
  return (int)$_SESSION['uid'];
}
function valid_weeks_string(string $s): bool {
  $s = str_replace(' ', '', $s);
  return (bool)preg_match('/^\d{2}(?:-\d{2})?(?:,\d{2}(?:-\d{2})?)*$/', $s);
}

/* ====== API 入口 ====== */
if (isset($_GET['api'])) {
  $api = $_GET['api'];

  // 登录
  if ($api === 'login' && $_SERVER['REQUEST_METHOD']==='POST') {
    $uid = trim($_POST['uid'] ?? '');
    $pin = trim($_POST['pin'] ?? '');
    if (!preg_match('/^\d{4,6}$/', $uid)) json_out(['ok'=>false,'error'=>'用户ID格式不正确']);
    if (!preg_match('/^\d{4}$/', $pin))  json_out(['ok'=>false,'error'=>'PIN格式不正确']);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT pin FROM user_accounts WHERE user_id=? LIMIT 1');
    $stmt->execute([(int)$uid]);
    $row = $stmt->fetch();
    if (!$row || (string)$row['pin'] !== $pin) json_out(['ok'=>false,'error'=>'用户ID或PIN错误']);
    $_SESSION['uid'] = (int)$uid;
    json_out(['ok'=>true]);
  }

  // 退出
  if ($api === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: ?');
    exit;
  }

  // 加载
  if ($api === 'load') {
    $uid = require_login();
    $pdo = db();

    // 读取 lab schedule
    $stmt2 = $pdo->prepare('SELECT data FROM user_lab_schedule WHERE user_id=? LIMIT 1');
    $stmt2->execute([$uid]);
    $sch = $stmt2->fetch();
    $schedule = [
      'start_date'   => '',
      'tz'           => 'Asia/Shanghai',
      'enabled_days' => [1,2,3,4,5,6,7],
      'timeslots'    => [],
      'courses'      => [],
    ];
    if ($sch && isset($sch['data']) && $sch['data']!=='') {
      $tmp = json_decode((string)$sch['data'], true);
      if (is_array($tmp)) $schedule = array_merge($schedule, $tmp);
    }

    json_out(['ok'=>true, 'schedule'=>$schedule]);
  }

  // 保存
  if ($api === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
    $uid = require_login();
    $pdo = db();

    $schedule_json = $_POST['schedule_json'] ?? '';
    $schedule = json_decode($schedule_json, true);

    if (!is_array($schedule)) json_out(['ok'=>false,'error'=>'schedule_json 非法']);

    // 校验 schedule
    $start_date   = $schedule['start_date'] ?? '';
    $tz           = $schedule['tz'] ?? 'Asia/Shanghai';
    $enabled_days = $schedule['enabled_days'] ?? [];
    $timeslots    = $schedule['timeslots'] ?? [];
    $courses      = $schedule['courses'] ?? [];

    if (!$start_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
      json_out(['ok'=>false,'error'=>'开学日期不合法（YYYY-MM-DD）']);
    }
    if (!is_array($enabled_days) || !count($enabled_days)) {
      json_out(['ok'=>false,'error'=>'启用星期不能为空']);
    }
    if (!is_array($timeslots) || !count($timeslots)) {
      json_out(['ok'=>false,'error'=>'至少设置 1 个时段']);
    }
    foreach ($timeslots as $t) {
      if (!isset($t['idx'],$t['start'],$t['end'])) json_out(['ok'=>false,'error'=>'时段缺少 idx/start/end']);
      if (!preg_match('/^\d{1,2}$/', (string)$t['idx'])) json_out(['ok'=>false,'error'=>'时段 idx 必须为数字']);
      if (!preg_match('/^\d{2}:\d{2}$/', (string)$t['start']) || !preg_match('/^\d{2}:\d{2}$/', (string)$t['end'])) {
        json_out(['ok'=>false,'error'=>'时段时间必须为 HH:MM']);
      }
    }
    if (!is_array($courses)) $courses = [];
    foreach ($courses as $c) {
      foreach (['name','day','periods','weeks','week_type'] as $k) {
        if (!array_key_exists($k, $c)) json_out(['ok'=>false,'error'=>"课程字段缺失：{$k}"]);
      }
      $d = (int)$c['day'];
      if ($d<1 || $d>7) json_out(['ok'=>false,'error'=>'课程 day 需在 1-7']);
      if (!is_array($c['periods']) || !count($c['periods'])) json_out(['ok'=>false,'error'=>'课程 periods 需为非空数组']);
      foreach ($c['periods'] as $pi) { if (!is_numeric($pi)) json_out(['ok'=>false,'error'=>'periods 必须为数字数组']); }
      $weeks = str_replace(' ', '', (string)$c['weeks']);
      if (!valid_weeks_string($weeks)) json_out(['ok'=>false,'error'=>"weeks 格式不合法：{$weeks}（例：01-16 或 01,03,04-09）"]);
      $wt = strtolower((string)$c['week_type']);
      if (!in_array($wt, ['all','odd','even'], true)) json_out(['ok'=>false,'error'=>'week_type 必须为 all/odd/even']);
    }

    // 规范化
    $schedule['tz']           = (string)$tz;
    $schedule['enabled_days'] = array_values(array_unique(array_map('intval', $enabled_days)));
    $schedule['timeslots']    = array_values($timeslots);
    $schedule['courses']      = array_values($courses);

    $pdo->beginTransaction();
    try {
      // update lab schedule
      $stmtU = $pdo->prepare('INSERT INTO user_lab_schedule (user_id, data, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE data=VALUES(data), updated_at=NOW()');
      $ok1 = $stmtU->execute([$uid, json_encode($schedule, JSON_UNESCAPED_UNICODE)]);
      
      if (!$ok1) throw new RuntimeException('更新 user_lab_schedule 失败');

      $pdo->commit();
      json_out(['ok'=>true]);
    } catch (Throwable $e) {
      $pdo->rollBack();
      json_out(['ok'=>false,'error'=>$e->getMessage()]);
    }
  }



  // 未知 API
  json_out(['ok'=>false,'error'=>'未知API'], 404);
}

/* ===== 视图：登录或编辑器 ===== */
$logged = is_logged_in();
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="auto">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>编辑实验课表</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { background: #f6f7fb; }
  @media (min-width: 992px){
    body{ font-size: .95rem; }
    .card .card-body{ padding: .9rem 1rem; }
    .table th, .table td{ padding:.35rem .45rem; }
  }
  .card { border-radius: 1rem; }
  .table-sm input, .table-sm select, .table-sm textarea { width: 100%; }
  .code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }

  .toolbar{ display:flex; gap:.5rem; align-items:center; justify-content:space-between; margin-bottom:.75rem; }
  .nav-tabs .nav-link{ font-weight:600; }
  .smallmuted{ font-size:.84rem; color:#6b7280; }
</style>
</head>
<body>
<div class="container py-4">

<?php if (!$logged): ?>
  <!-- 登录卡片 -->
  <div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-3 text-center">登录编辑实验课表</h5>
          <form id="loginForm" class="vstack gap-3" onsubmit="return false;">
            <div>
              <label class="form-label">用户 ID（4-6 位数字）</label>
              <input class="form-control form-control-lg" name="uid" inputmode="numeric" pattern="\d{4,6}" required>
            </div>
            <div>
              <label class="form-label">密码（4 位数字）</label>
              <input class="form-control form-control-lg" name="pin" inputmode="numeric" pattern="\d{4}" required>
            </div>
            <button class="btn btn-primary btn-lg w-100" onclick="doLogin()">登录</button>
          </form>
        </div>
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- 顶部工具栏 -->
  <div class="toolbar">
    <div class="d-flex align-items-center gap-2">
      <span class="badge text-bg-info border">实验课表</span>
      <span class="badge text-bg-light border">已登录：<b class="code"><?=(int)$_SESSION['uid']?></b></span>
    </div>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="?api=logout">退出</a>
      <button class="btn btn-primary btn-sm" id="btnSaveAll">保存全部</button>
    </div>
  </div>

  <!-- 标签页 -->
  <ul class="nav nav-tabs" id="editTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="tab-courses" data-bs-toggle="tab" data-bs-target="#pane-courses" type="button" role="tab">课程信息</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-times" data-bs-toggle="tab" data-bs-target="#pane-times" type="button" role="tab">时间信息</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-settings" data-bs-toggle="tab" data-bs-target="#pane-settings" type="button" role="tab">开学与时区</button>
    </li>
  </ul>

  <div class="tab-content pt-3">
    <!-- 课程信息 -->
    <div class="tab-pane fade show active" id="pane-courses" role="tabpanel">
      <div class="d-flex flex-wrap gap-2 mb-2">
        <button class="btn btn-outline-primary btn-sm" id="btnAddCourse">新增课程</button>
        <span class="smallmuted">CSV/文本导入请使用注册向导</span>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle" id="tblCourse">
          <thead class="table-light">
            <tr>
              <th style="min-width:12ch;">课程名</th>
              <th style="min-width:10ch;">教师</th>
              <th style="min-width:10ch;">教室</th>
              <th style="width:90px;">星期</th>
              <th style="min-width:12ch;">节次</th>
              <th style="min-width:14ch;">周次</th>
              <th style="width:110px;">单双周</th>
              <th style="min-width:12ch;">备注</th>
              <th style="width:80px;">删除</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <!-- 时间信息 -->
    <div class="tab-pane fade" id="pane-times" role="tabpanel">
      <div class="d-flex flex-wrap gap-2 mb-2">
        <button class="btn btn-outline-primary btn-sm" id="btnAddTime">新增时段</button>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle" id="tblTime">
          <thead class="table-light">
            <tr>
              <th style="width:80px;">ID</th>
              <th>开始时间</th>
              <th>结束时间</th>
              <th style="width:80px;">删除</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <!-- 设置 -->
    <div class="tab-pane fade" id="pane-settings" role="tabpanel">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">开学日期（YYYY-MM-DD）</label>
          <input type="date" id="start_date" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">课表时区</label>
          <select id="tz" class="form-select">
            <option value="Asia/Shanghai">Asia/Shanghai（中国标准时间）</option>
            <option value="Asia/Tokyo">Asia/Tokyo</option>
            <option value="UTC">UTC</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">启用星期</label>
          <div class="d-flex flex-wrap gap-2" id="enabled_days">
            <?php for($i=1;$i<=7;$i++): ?>
              <div class="form-check">
                <input class="form-check-input day" type="checkbox" value="<?=$i?>" id="d<?=$i?>">
                <label class="form-check-label" for="d<?=$i?>">周<?=['一','二','三','四','五','六','日'][$i-1]?></label>
              </div>
            <?php endfor; ?>
          </div>
        </div>
      </div>

      <div class="mt-4">
        <button class="btn btn-primary" id="btnSaveInTab">保存修改</button>
      </div>
    </div>
  </div>

<?php endif; ?>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ========= 登录 ========= */
async function doLogin(){
  const f = document.getElementById('loginForm');
  const fd = new FormData(f);
  const res = await fetch('?api=login', {method:'POST', body:fd});
  const j = await res.json().catch(()=>({ok:false,error:'网络错误'}));
  if(!j.ok){ alert(j.error||'登录失败'); return; }
  location.reload();
}

/* ========= 编辑逻辑 ========= */
<?php if ($logged): ?>

const DAY_LABEL = ['一','二','三','四','五','六','日'];

const state = {
  schedule: {
    start_date: '',
    tz: 'Asia/Shanghai',
    enabled_days: [1,2,3,4,5,6,7],
    timeslots: [],
    courses: []
  }
};

/* ===== 通用小工具 ===== */
const $  = (q,root=document)=>root.querySelector(q);
const $$ = (q,root=document)=>Array.from(root.querySelectorAll(q));

function ensureInt(v,d=0){ const n=parseInt(v,10); return Number.isFinite(n)?n:d; }
function getMaxPeriodIdx(){
  if (!state.schedule.timeslots.length) return Infinity;
  return Math.max(...state.schedule.timeslots.map(t=>Number(t.idx)||0));
}
function periodsToArray(s, maxIdx=Infinity){
  s = (s||'').replaceAll('，',',').replaceAll('、',',').replaceAll(' ','');
  const ret=[];
  for(const seg of s.split(',')){
    if(!seg) continue;
    const p = seg.split('-').map(x=>parseInt(x,10));
    if (p.length===1 && Number.isInteger(p[0])) ret.push(p[0]);
    else if (p.length===2 && Number.isInteger(p[0]) && Number.isInteger(p[1])){
      for(let k=p[0]; k<=p[1]; k++) ret.push(k);
    }
  }
  let arr = Array.from(new Set(ret)).sort((a,b)=>a-b);
  if (Number.isFinite(maxIdx)) arr = arr.filter(x=>x>=1 && x<=maxIdx);
  return arr;
}

/* ===== 渲染：课程 ===== */
const tbodyCourse = $('#tblCourse tbody');
function renderCourses(){
  const order = state.schedule.courses
    .map((c,i)=>({i, day:+c.day||9, p: (Array.isArray(c.periods)&&c.periods.length? Math.min(...c.periods):999), name:c.name||''}))
    .sort((a,b)=> a.day-b.day || a.p-b.p || a.name.localeCompare(b.name,'zh-Hans-CN'))
    .map(x=>x.i);

  tbodyCourse.innerHTML = '';
  for(const idx of order){
    const c = state.schedule.courses[idx] || {};
    const tr = document.createElement('tr');
    tr.dataset.idx = String(idx);
    tr.innerHTML = `
      <td><input class="form-control form-control-sm name" value="${c.name||''}"></td>
      <td><input class="form-control form-control-sm teacher" value="${c.teacher||''}"></td>
      <td><input class="form-control form-control-sm room" value="${c.room||''}"></td>
      <td>
        <select class="form-select form-select-sm day">
          ${[1,2,3,4,5,6,7].map(d=>`<option value="${d}" ${Number(c.day)===d?'selected':''}>${DAY_LABEL[d-1]}</option>`).join('')}
        </select>
      </td>
      <td><input class="form-control form-control-sm periods" placeholder="1-2 或 1,3" value="${(c.periods||[]).join(',')}"></td>
      <td><input class="form-control form-control-sm weeks" placeholder="01-16 或 01,03,04-09" value="${c.weeks||''}"></td>
      <td>
        <select class="form-select form-select-sm week_type">
          <option value="all"  ${c.week_type==='all'?'selected':''}>否</option>
          <option value="odd"  ${c.week_type==='odd'?'selected':''}>单周</option>
          <option value="even" ${c.week_type==='even'?'selected':''}>双周</option>
        </select>
      </td>
      <td><input class="form-control form-control-sm note" value="${c.note||''}"></td>
      <td><button class="btn btn-sm btn-outline-danger del">删除</button></td>
    `;
    tbodyCourse.appendChild(tr);
  }
}
tbodyCourse.addEventListener('input', (e)=>{
  const tr = e.target.closest('tr'); if(!tr) return; const idx = Number(tr.dataset.idx);
  const c = state.schedule.courses[idx]; if(!c) return;
  if (e.target.classList.contains('name')) c.name = e.target.value;
  if (e.target.classList.contains('teacher')) c.teacher = e.target.value;
  if (e.target.classList.contains('room')) c.room = e.target.value;
  if (e.target.classList.contains('day')) c.day = ensureInt(e.target.value, 1);
  if (e.target.classList.contains('periods')) c.periods = periodsToArray(e.target.value, getMaxPeriodIdx());
  if (e.target.classList.contains('weeks')) c.weeks = e.target.value.trim();
  if (e.target.classList.contains('week_type')) c.week_type = e.target.value;
  if (e.target.classList.contains('note')) c.note = e.target.value;
});
tbodyCourse.addEventListener('change', (e)=>{
  const tr = e.target.closest('tr'); if(!tr) return;
  const idx = Number(tr.dataset.idx); const c = state.schedule.courses[idx]; if(!c) return;
  if (e.target.classList.contains('periods')) e.target.value = (c.periods||[]).join(',');
  if (e.target.classList.contains('day') || e.target.classList.contains('periods')) renderCourses();
});
tbodyCourse.addEventListener('click', (e)=>{
  if (e.target.classList.contains('del')){
    const tr = e.target.closest('tr'); const idx = Number(tr.dataset.idx);
    state.schedule.courses.splice(idx,1); renderCourses();
  }
});
$('#btnAddCourse').addEventListener('click', ()=>{
  state.schedule.courses.push({name:'',teacher:'',room:'',day:1,periods:[1],weeks:'01-16',week_type:'all',note:''});
  renderCourses();
});

/* ===== 渲染：时间 ===== */
const tbodyTime = $('#tblTime tbody');
function renderTimes(){
  tbodyTime.innerHTML = '';
  for(const t of state.schedule.timeslots){
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input class="form-control form-control-sm t-idx" value="${t.idx}"></td>
      <td><input class="form-control form-control-sm t-start" value="${t.start}"></td>
      <td><input class="form-control form-control-sm t-end" value="${t.end}"></td>
      <td><button class="btn btn-sm btn-outline-danger t-del">删除</button></td>
    `;
    tbodyTime.appendChild(tr);
  }
}
tbodyTime.addEventListener('input', (e)=>{
  const tr = e.target.closest('tr'); if(!tr) return; const i = Array.from(tbodyTime.children).indexOf(tr);
  const t = state.schedule.timeslots[i]; if(!t) return;
  if (e.target.classList.contains('t-idx'))   t.idx = ensureInt(e.target.value, t.idx);
  if (e.target.classList.contains('t-start')) t.start = e.target.value;
  if (e.target.classList.contains('t-end'))   t.end = e.target.value;
});
tbodyTime.addEventListener('click', (e)=>{
  if (e.target.classList.contains('t-del')){
    const tr = e.target.closest('tr'); const i = Array.from(tbodyTime.children).indexOf(tr);
    state.schedule.timeslots.splice(i,1); renderTimes();
  }
});
$('#btnAddTime').addEventListener('click', ()=>{
  const nxt = state.schedule.timeslots.length? Math.max(...state.schedule.timeslots.map(x=>x.idx))+1 : 1;
  state.schedule.timeslots.push({idx:nxt,start:'08:00',end:'08:45'}); renderTimes();
});

/* ===== 渲染：设置 ===== */
function renderSettings(){
  $('#start_date').value = state.schedule.start_date || '';
  $('#tz').value = state.schedule.tz || 'Asia/Shanghai';
  $$('#enabled_days .day').forEach(cb=>{
    cb.checked = state.schedule.enabled_days.includes(Number(cb.value));
  });
}

/* ===== 载入 + 保存 ===== */
async function loadAll(){
  const res = await fetch('?api=load');
  const j = await res.json().catch(()=>({ok:false,error:'加载失败'}));
  if(!j.ok){ alert(j.error||'加载失败'); return; }
  state.schedule = j.schedule || state.schedule;

  // 渲染三页
  renderCourses();
  renderTimes();
  renderSettings();
}
async function saveAll(){
  // 拉取设置页的勾选变更
  state.schedule.start_date = $('#start_date').value;
  state.schedule.tz = $('#tz').value;
  state.schedule.enabled_days = $$('#enabled_days .day:checked').map(x=>Number(x.value));

  // 规范化 timeslots：按 idx 升序
  state.schedule.timeslots = state.schedule.timeslots
    .map(t=>({idx:Number(t.idx), start:String(t.start||''), end:String(t.end||'')}))
    .sort((a,b)=>a.idx-b.idx);

  const fd = new FormData();
  fd.append('schedule_json', JSON.stringify(state.schedule));
  const res = await fetch('?api=save', {method:'POST', body:fd});
  const j = await res.json().catch(()=>({ok:false,error:'保存失败'}));
  if (!j.ok){ alert(j.error||'保存失败'); return; }
  // 保存成功提示
  const btn = $('#btnSaveAll');
  const old = btn.textContent;
  btn.textContent = '保存成功';
  btn.classList.remove('btn-primary'); btn.classList.add('btn-success');
  setTimeout(()=>{ btn.textContent=old; btn.classList.add('btn-primary'); btn.classList.remove('btn-success'); }, 1500);
}



document.getElementById('btnSaveAll').addEventListener('click', saveAll);
document.getElementById('btnSaveInTab').addEventListener('click', saveAll);

window.addEventListener('load', loadAll);

<?php endif; ?>
</script>
</body>
</html>
