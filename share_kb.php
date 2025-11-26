<?php
// share_kb.php — 共享课表查看（免登录）
// 要求：GET ?t=<32位token>&p=<4位访问码>；也支持不带 p 时在页内输入
declare(strict_types=1);
mb_internal_encoding('UTF-8');

include_once __DIR__ . '/db.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function parse_weeks_string(string $s): array {
    $s = trim($s); if ($s === '') return [];
    $parts = preg_split('/\s*,\s*/u', $s);
    $weeks = [];
    foreach ($parts as $p) {
        if (preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', $p, $m)) {
            $a = (int)$m[1]; $b = (int)$m[2]; if ($a > $b) { [$a,$b] = [$b,$a]; }
            for ($i=$a; $i <= $b; $i++) $weeks[] = $i;
        } elseif (preg_match('/^\d{1,2}$/', $p)) {
            $weeks[] = (int)$p;
        }
    }
    $weeks = array_values(array_unique($weeks)); sort($weeks); return $weeks;
}

function calc_week_no(DateTime $now, string $startDateYmd, string $tz): int {
    $start = new DateTime($startDateYmd . ' 00:00:00', new DateTimeZone($tz));
    $now2  = (clone $now)->setTimezone(new DateTimeZone($tz));
    $diffDays = (int)$start->diff($now2)->format('%r%a'); if ($diffDays < 0) return 0; return (int)floor($diffDays / 7) + 1;
}


// ========== 读取参数与共享链接（兼容 token/pass & t/p） ==========
$rawToken = $_GET['t']     ?? $_GET['token'] ?? '';
$rawPass  = $_GET['p']     ?? $_GET['pass']  ?? null;

// 如果使用了 token/pass，规范化到 t/p 后再处理，避免后面生成链接时参数名不一致
if ((isset($_GET['token']) && !isset($_GET['t'])) || (isset($_GET['pass']) && !isset($_GET['p']))) {
    $qs = [];
    if ($rawToken !== '') $qs['t'] = $rawToken;
    if ($rawPass !== null) $qs['p'] = $rawPass;
    if (isset($_GET['all'])) $qs['all'] = $_GET['all'];
    header('Location: ?' . http_build_query($qs));
    exit;
}

$token = (string)$rawToken;
$pass  = is_string($rawPass) ? $rawPass : null;



if (!preg_match('/^[a-f0-9]{32}$/i', $token)) {
    $err = '无效链接（token 错误）';
    render_page(null, null, null, $err, $token);
    exit;
}

$link = (function($token){
    $st = db()->prepare('SELECT * FROM shared_links WHERE token = ? LIMIT 1');
    $st->execute([$token]);
    return $st->fetch() ?: null;
})($token);

if (!$link) {
    $err = '链接不存在或已被删除';
    render_page(null, null, null, $err, $token);
    exit;
}

// 基础有效性校验（禁用/过期/达上限）
$now = new DateTime('now', new DateTimeZone('UTC'));
$expired = false;
if (!empty($link['expires_at'])) {
    $exp = new DateTime($link['expires_at'], new DateTimeZone('UTC'));
    if ($now > $exp) $expired = true;
}
$disabled = (int)$link['disabled'] === 1;
$cap_reached = ($link['max_visits'] !== null && (int)$link['visit_count'] >= (int)$link['max_visits']);

if ($disabled || $expired || $cap_reached) {
    $why = $disabled ? '链接已被停用' : ($expired ? '链接已过期' : '已达最大访问次数');
    render_page($link, null, null, $why, $token);
    exit;
}

// 密码校验：缺少或不匹配时要求输入
if (!is_string($pass) || !preg_match('/^\d{4}$/', $pass) || $pass !== (string)$link['share_pass']) {
    render_page($link, null, null, null, $token, true); // 让页面显示输入密码框
    exit;
}

// ======= 通过校验：自增访问计数（不超过上限，且未禁用） =======
try {
    $u = db()->prepare('UPDATE shared_links SET visit_count = visit_count + 1 
        WHERE id = ? AND disabled = 0 AND (max_visits IS NULL OR visit_count < max_visits)');
    $u->execute([$link['id']]);
} catch (Throwable $e) { /* 忽略自增异常，不影响展示 */ }

// ======= 读取课表数据 =======
$owner_id = (int)$link['user_id'];

// 用户课表 JSON
$sch = db()->prepare('SELECT data FROM user_schedule WHERE user_id = ?');
$sch->execute([$owner_id]);
$schRow = $sch->fetch();
$schedule = $schRow ? (json_decode($schRow['data'] ?? '{}', true) ?: []) : [];

// 用户 profile（可能包含 tz_timetable 之类）
$upro = db()->prepare('SELECT profile FROM user_accounts WHERE user_id = ?');
$upro->execute([$owner_id]);
$uproRow = $upro->fetch();
$profile = $uproRow ? (json_decode($uproRow['profile'] ?? '{}', true) ?: []) : [];

// 时区策略：计算统一用课表时区；显示根据共享设置
$tzTimetable = $schedule['tz'] ?? ($profile['tz_timetable'] ?? 'Asia/Shanghai');
$tzMode  = (string)$link['tz_mode'];
$tzValue = trim((string)($link['tz_value'] ?? ''));

// 显示字段：共享时固定（若为空则默认）
$displayFields = [];
if (!empty($link['display_fields'])) {
    $tmp = json_decode($link['display_fields'], true) ?: [];
    foreach (['name','teacher','room','weeks'] as $k) if (in_array($k, $tmp, true)) $displayFields[] = $k;
}
if (empty($displayFields)) $displayFields = ['name','teacher','room'];

render_page($link, $schedule, [
    'tzTimetable' => $tzTimetable,
    'tzMode'      => $tzMode,
    'tzValue'     => $tzValue,
    'displayFields' => $displayFields,
], null, $token);

// ======================= 页面渲染函数 =======================
function render_page($link, $schedule, $opts, ?string $errorMsg, string $token, bool $needPass=false) {
    $showAll = isset($_GET['all']) ? (bool)$_GET['all'] : false;

    $weekdayNames = [1=>'星期一','星期二','星期三','星期四','星期五','星期六','星期日'];
    $enabledDays  = $schedule['enabled_days'] ?? [1,2,3,4,5,6,7]; sort($enabledDays);
    $timeslots    = array_values($schedule['timeslots'] ?? []);
    $courses      = array_values($schedule['courses'] ?? []);
    $startDate    = $schedule['start_date'] ?? null;

    $tzTimetable  = $opts['tzTimetable'] ?? 'Asia/Shanghai';
    $tzMode       = $opts['tzMode']      ?? 'client_dynamic';
    $tzValue      = $opts['tzValue']     ?? '';
    $displayFields= $opts['displayFields']?? ['name','teacher','room'];

    $showName = in_array('name', $displayFields, true);
    $showTeacher = in_array('teacher', $displayFields, true);
    $showRoom = in_array('room', $displayFields, true);
    $showWeeks = in_array('weeks', $displayFields, true);

    // 预先准备 server 端“当前/下一节”粗略标记（JS 仍会实时刷新）
    $currentHighlight = [];
    $nextHighlight    = [];
    $nowCourses = [];
    $upcomingWithin15 = [];
    $upcomingDeadline = null;

    // 服务端只用于首屏：使用显示时区？这里与原站一致：计算用课表时区，显示另说
    $displayTzServer = ($tzMode === 'custom' || $tzMode === 'client_fixed') ? ($tzValue ?: $tzTimetable) : $tzTimetable;

    if ($startDate) {
        $nowDisplay = new DateTime('now', new DateTimeZone($displayTzServer));
        $nowCalc    = (clone $nowDisplay)->setTimezone(new DateTimeZone($tzTimetable));
        $weekNo     = calc_week_no($nowCalc, $startDate, $tzTimetable);
        $weekdayCalc= (int)$nowCalc->format('N'); // 1..7
        $nowHHMM    = $nowCalc->format('H:i');

        // 当前节
        $currentPeriods = [];
        foreach ($timeslots as $slot) {
            $st = $slot['start'] ?? '00:00'; $et = $slot['end'] ?? '00:00';
            if ($nowHHMM >= $st && $nowHHMM <= $et) $currentPeriods[] = (int)($slot['idx'] ?? 0);
        }
        foreach ($currentPeriods as $p) $currentHighlight[$weekdayCalc][$p] = true;

        // 当前课程（按是否显示全部周过滤）
        if (!empty($currentPeriods)) {
            foreach ($courses as $c) {
                if ((int)($c['day'] ?? 0) !== $weekdayCalc) continue;
                $periods = array_map('intval', $c['periods'] ?? []);
                if (!array_intersect($periods, $currentPeriods)) continue;
                $weeksArr = parse_weeks_string((string)($c['weeks'] ?? ''));
                $wtype = strtolower((string)($c['week_type'] ?? 'all'));
                $ok = true;
                if (!$showAll) {
                    $ok = in_array($weekNo, $weeksArr, true);
                    if ($ok && $wtype === 'odd'  && $weekNo % 2 === 0) $ok = false;
                    if ($ok && $wtype === 'even' && $weekNo % 2 === 1) $ok = false;
                }
                if ($ok) $nowCourses[] = $c;
            }
        }

        // 15分钟内下一节
        $earliestDiff = null; $nextPeriods = []; $nextStartHHMM = null;
        foreach ($timeslots as $slot) {
            $st = $slot['start'] ?? null; if (!$st) continue;
            if ($st > $nowHHMM) {
                $stDT = DateTime::createFromFormat('Y-m-d H:i', $nowCalc->format('Y-m-d').' '.$st, new DateTimeZone($tzTimetable));
                $diffMin = (int)floor(($stDT->getTimestamp() - $nowCalc->getTimestamp())/60);
                if ($diffMin >= 0 && $diffMin <= 15) {
                    if ($earliestDiff === null || $diffMin < $earliestDiff) {
                        $earliestDiff = $diffMin; $nextStartHHMM = $st; $nextPeriods = [(int)($slot['idx'] ?? 0)];
                    } elseif ($diffMin === $earliestDiff) {
                        $nextPeriods[] = (int)($slot['idx'] ?? 0);
                    }
                }
            }
        }
        if (!empty($nextPeriods)) $nextHighlight[$weekdayCalc] = array_fill_keys($nextPeriods, true);

        if ($nextStartHHMM) {
            $targetCalc = DateTime::createFromFormat('Y-m-d H:i', $nowCalc->format('Y-m-d').' '.$nextStartHHMM, new DateTimeZone($tzTimetable));
            $targetDisplay = (clone $targetCalc)->setTimezone(new DateTimeZone($displayTzServer));
            $upcomingDeadline = $targetDisplay->getTimestamp() * 1000;
            foreach ($courses as $c) {
                if ((int)($c['day'] ?? 0) !== $weekdayCalc) continue;
                $periods = array_map('intval', $c['periods'] ?? []);
                if (!in_array((int)$nextPeriods[0], $periods, true)) continue;
                $weeksArr = parse_weeks_string((string)($c['weeks'] ?? ''));
                $wtype = strtolower((string)($c['week_type'] ?? 'all'));
                $ok = true;
                if (!$showAll) {
                    $ok = in_array($weekNo, $weeksArr, true);
                    if ($ok && $wtype === 'odd'  && $weekNo % 2 === 0) $ok = false;
                    if ($ok && $wtype === 'even' && $weekNo % 2 === 1) $ok = false;
                }
                if ($ok) $upcomingWithin15[] = $c;
            }
        }
    } else {
        $weekNo = 0;
    }

    // 头部与主体输出（包含密码输入态 / 错误态）
    ?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="auto">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>共享课表</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { background: #f6f7fb; }
  @media (min-width: 992px){
    body{ font-size: .95rem; }
    .card .card-body{ padding: .9rem 1rem; }
    .table th, .table td{ padding:.3rem .35rem; }
    .capsule{ font-size: .95rem; }
    .capsule .cap-meta{ font-size: .78rem; }
  }
  .card { border-radius: 1rem; }
  .sticky-col { position: sticky; left: 0; z-index: 2; background: #fff; white-space: nowrap; }
  .slot-badge { font-size: .75rem; }
  .cell { min-width: 140px; }
  @media (max-width: 576px){ .cell{ min-width: 120px; } }
  .now-pill { background: #ffffffff; }
  thead.table-light th{text-align:center; white-space:nowrap; }
  .cell .cell-content{
    border-radius: .5rem;
    padding: .25rem .4rem;
    transition: border-color .2s, box-shadow .2s, background-color .2s;
  }
  .cell.cell-current .cell-content{ border: 2px solid #16a34a; }
  .cell.cell-next    .cell-content{ border: 2px solid #f59e0b; }

  .capsule{
    display:inline-flex; flex-direction:column; align-items:flex-start; gap:.15rem;
    border:1px solid var(--cap-bd, #cbd5e1);
    background: var(--cap-bg, #f8fafc);
    border-radius:15px;
    padding:.28rem .6rem .34rem .6rem;
    line-height:1.25; max-width:100%;
  }
  .capsule .cap-row{ display:inline-flex; align-items:center; gap:.4rem; white-space:nowrap; max-width:100%; }
  .capsule .cap-dot{ width:.6rem; height:.6rem; border-radius:50%; border:1px solid rgba(0,0,0,.06); flex:0 0 auto; background: var(--cap-bd, #94a3b8); }
  .capsule .cap-text{ font-weight:600; overflow:hidden; text-overflow:ellipsis; display:inline-block; max-width:16ch; }
  .capsule .cap-meta{ font-size:.78rem; opacity:.85; color:#475569; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

  .cell .cell-item .meta  { font-size: .8125rem; color: #6b7280; }
  .cell-pager { display:flex; align-items:center; justify-content:center; gap:.5rem; margin-top:.25rem; }
  .cell-pager .btn { padding: 0 .4rem; line-height: 1.2; }
  .cell-pager .page-indicator { font-size:.75rem; color:#6b7280; }
</style>
</head>
<body>
<div class="container py-4">

  <div class="mb-3 d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <span class="badge text-bg-primary">共享课表</span>
      <?php if ($link): ?>
        <!-- <span class="badge text-bg-light border">访问序号：<?= (int)$link['visit_count'] ?></span> -->
        <?php if ($link['max_visits'] !== null): ?>
          <span class="badge text-bg-light border">上限：<?= (int)$link['max_visits'] ?></span>
        <?php endif; ?>
        <?php if (!empty($link['expires_at'])): ?>
          <span class="badge text-bg-light border">到期：<?= h($link['expires_at']) ?> UTC</span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <div>
      <a class="btn btn-sm btn-outline-secondary" href="?t=<?=h($token)?><?= isset($_GET['all'])?'':'&all=1' ?><?= isset($_GET['p'])?('&p='.h($_GET['p'])):'' ?>">切换：<?= $showAll ? '仅本周' : '全部周' ?></a>
    </div>
  </div>

  <?php if ($errorMsg): ?>
    <div class="alert alert-warning"><?= h($errorMsg) ?></div>
  <?php endif; ?>

  <?php if ($needPass): ?>
    <div class="row justify-content-center">
      <div class="col-12 col-md-6 col-lg-4">
        <div class="card shadow-sm">
          <div class="card-body">
            <h5 class="card-title text-center mb-3">输入访问码</h5>
            <form method="get" class="vstack gap-3">
              <input type="hidden" name="t" value="<?= h($token) ?>">
              <div>
                <label class="form-label">4位数字访问码</label>
                <input class="form-control form-control-lg" name="p" inputmode="numeric" pattern="\d{4}" required placeholder="****">
              </div>
              <?php if ($showAll): ?><input type="hidden" name="all" value="1"><?php endif; ?>
              <button class="btn btn-primary btn-lg w-100" type="submit">进入</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  <?php elseif (!$schedule || empty($timeslots)): ?>
    <div class="alert alert-info">该用户尚未配置课表。</div>
  <?php else: ?>

    <?php
      $headerDays = array_values(array_intersect_key($weekdayNames, array_flip($enabledDays)));
      $headerIdxs = array_values($enabledDays);
      $showName   = in_array('name', $displayFields, true);
      $showTeacher= in_array('teacher', $displayFields, true);
      $showRoom   = in_array('room', $displayFields, true);
      $showWeeks  = in_array('weeks', $displayFields, true);
    ?>

    <!-- 顶部：时间与第几周 -->
    <div class="text-center mb-3">
      <h2 class="mb-1"><span id="nowTime" data-tz="<?= h(($tzMode==='custom'||$tzMode==='client_fixed') ? ($tzValue ?: $tzTimetable) : '') ?>"></span></h2>
      <div class="text-muted">
        <?php if ($startDate): ?>当前第 <b><?= (int)$weekNo ?></b> 周<?php else: ?><span class="text-warning">（尚未设置课表开始日期）</span><?php endif; ?>
        <!-- <span class="ms-2 badge text-bg-light border">计算时区：<?= h($tzTimetable) ?></span> -->
        <!-- <span class="ms-2 badge text-bg-light border">显示时区：<?= h(($tzMode==='custom'||$tzMode==='client_fixed') ? ($tzValue ?: $tzTimetable) : '客户端自动') ?></span> -->
      </div>
    </div>

    <!-- 正在上课 / 即将开始（只展示，不提供任何写入功能） -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <?php if (!empty($nowCourses)): ?>
          <?php
            $first = $nowCourses[0]; $extra = count($nowCourses) - 1;
            $capDay = (int)($first['day'] ?? 0);
            $firstPi = (int)(($first['periods'][0] ?? 1));
            $capStart = $timeslots[$firstPi - 1]['start'] ?? '';
            $teacherRoomTop = implode(' · ', array_filter([(string)($first['teacher'] ?? ''), (string)($first['room'] ?? '')], fn($x)=>$x!==''));
          ?>
          <div class="now-pill p-2 rounded d-flex align-items-center justify-content-between">
            <div>
              <span class="capsule" data-capsule
                    data-day="<?= $capDay ?>"
                    data-start="<?= h($capStart) ?>">
                <span class="cap-row"><i class="cap-dot"></i><span class="cap-text"><?= h($first['name'] ?? '课程') ?></span></span>
                <?php if ($teacherRoomTop !== ''): ?><span class="cap-meta"><?= h($teacherRoomTop) ?></span><?php endif; ?>
              </span>
              <?php if ($extra > 0): ?>
                <span class="ms-2 text-muted">+<?= $extra ?></span>
              <?php endif; ?>
            </div>
            <?php if ($upcomingDeadline): ?>
              <small class="text-muted ms-3">即将开始：<span id="nextCountdown" data-deadline="<?= (int)$upcomingDeadline ?>"></span></small>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="text-muted">
            当前无课程
            <?php if ($upcomingDeadline): ?>
              ，即将开始：<span id="nextCountdown" data-deadline="<?= (int)$upcomingDeadline ?>"></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- 课表 -->
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered align-middle table-sm">
            <thead class="table-light">
              <tr>
                <th class="sticky-col">时间 / 节次</th>
                <?php foreach ($headerDays as $dname): ?><th class="text-center"><?=h($dname)?></th><?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
            <?php
              // day -> period -> 课程列表（已按“仅本周/全部”过滤）
              $grid = []; foreach ($headerIdxs as $didx) $grid[$didx] = [];
              foreach ($courses as $c) {
                  $d = (int)($c['day'] ?? 0); if (!in_array($d, $headerIdxs, true)) continue;
                  $weeksArr = parse_weeks_string((string)($c['weeks'] ?? ''));
                  $wtype = strtolower((string)($c['week_type'] ?? 'all'));
                  $ok = true;
                  if (!$showAll && $startDate) {
                      $ok = in_array($weekNo, $weeksArr, true);
                      if ($ok && $wtype === 'odd'  && $weekNo % 2 === 0) $ok = false;
                      if ($ok && $wtype === 'even' && $weekNo % 2 === 1) $ok = false;
                  }
                  if (!$ok) continue;
                  $periods = array_map('intval', $c['periods'] ?? []);
                  foreach ($periods as $pi) { $grid[$d][$pi] = $grid[$d][$pi] ?? []; $grid[$d][$pi][] = $c; }
              }
            ?>

            <?php foreach ($timeslots as $slot): ?>
              <?php $pi = (int)$slot['idx']; $label = sprintf('%s-%s', $slot['start'], $slot['end']); ?>
              <tr>
                <th class="sticky-col bg-white">
                  <div class="d-flex flex-column">
                    <span class="fw-semibold"><?=h($label)?></span>
                    <span class="text-muted small">第 <?= $pi ?> 节</span>
                  </div>
                </th>

                <?php foreach ($headerIdxs as $didx): ?>
                  <?php
                    $list = $grid[$didx][$pi] ?? [];
                    // 过滤字段
                    if (!empty($list)) {
                        foreach ($list as &$cItem) {
                            if (!$showName) unset($cItem['name']);
                            if (!$showTeacher) unset($cItem['teacher']);
                            if (!$showRoom) unset($cItem['room']);
                            if (!$showWeeks) unset($cItem['weeks']);
                            // note 也不应该显示？原逻辑没提，但为了安全最好也过滤，或者看需求。
                            // 暂时只过滤 UI 上可配置的 4 项。
                        }
                        unset($cItem);
                    }

                    $cellCls = '';
                    if (!empty($currentHighlight[$didx][$pi] ?? null)) $cellCls = 'cell-current';
                    elseif (!empty($nextHighlight[$didx][$pi] ?? null)) $cellCls = 'cell-next';
                    $dataCourses = !empty($list) ? h(json_encode($list, JSON_UNESCAPED_UNICODE)) : '';
                  ?>
                  <td class="cell <?= $cellCls ?>" data-day="<?= $didx ?>" data-period="<?= $pi ?>" data-start="<?= h($slot['start'] ?? '') ?>"
                      <?= $dataCourses ? 'data-courses="'.$dataCourses.'"' : '' ?> data-page="0">
                    <div class="cell-content">
                      <?php if (empty($list)): ?>
                        <span class="text-muted"></span>
                      <?php else: ?>
                        <div class="small text-muted">加载中…</div>
                      <?php endif; ?>
                    </div>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  <?php endif; /* needPass vs content */ ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* =========== 共享页公共配置 =========== */
const DISPLAY_FIELDS = {
  name: <?= $showName?'true':'false' ?>,
  teacher: <?= $showTeacher?'true':'false' ?>,
  room: <?= $showRoom?'true':'false' ?>,
  weeks: <?= $showWeeks?'true':'false' ?>
};
const TIMESLOTS = <?= json_encode(array_values($timeslots ?? []), JSON_UNESCAPED_UNICODE) ?>;
const PERIOD_START = {}; for (const s of TIMESLOTS){ if (s && s.idx!=null && s.start) PERIOD_START[parseInt(s.idx,10)] = String(s.start); }
function periodStartFromList(periods){ const arr=(periods||[]).map(p=>PERIOD_START[p]).filter(Boolean).sort(); return arr.length?arr[0]:''; }

/* ======= 胶囊渲染与配色（与 index 同步） ======= */
const USER_ID = 0; // 共享页不绑定访问者账号，颜色仅用上课时间稳定
const NAME_MAX = 5;
function clampName(s, n=NAME_MAX){ if(!s) return ''; const arr = Array.from(s); return arr.length>n?arr.slice(0,n).join('')+'…':s; }
function djb2(str){ let h=5381; for (let i=0;i<str.length;i++){ h=((h<<5)+h)+str.charCodeAt(i); h|=0; } return h>>>0; }
function hsl(h,s,l){ return `hsl(${Math.round(h)}, ${Math.round(s)}%, ${Math.round(l)}%)`; }
function colorFromSeed(seed){ const hv=djb2(String(seed)); const h=hv%360; return { bg:hsl(h,70,92), bd:hsl(h,65,55) }; }
function buildCapsule(day, startHHMM, text, metaText, withMeta){
  const seed = `${USER_ID}|${day}|${startHHMM||''}`;
  const color = colorFromSeed(seed);
  const span = document.createElement('span');
  span.className='capsule';
  span.style.setProperty('--cap-bg', color.bg);
  span.style.setProperty('--cap-bd', color.bd);
  const row = document.createElement('span'); row.className='cap-row';
  const dot = document.createElement('i');   dot.className='cap-dot';
  const t   = document.createElement('span'); t.className='cap-text'; t.textContent = clampName(text || '');
  row.appendChild(dot); row.appendChild(t); span.appendChild(row);
  if (withMeta && metaText){ const m=document.createElement('span'); m.className='cap-meta'; m.textContent=metaText; span.appendChild(m); }
  return span;
}

/* ===== 顶部时间每30秒自动刷新（显示时区：custom / client_fixed 用 data-tz，client_dynamic 用客户端） ===== */
(function initClock(){
  const el = document.getElementById('nowTime'); if(!el) return;
  const tzAttr = el.getAttribute('data-tz');
  function fmtNow(){
    const now = new Date();
    const tz = tzAttr && tzAttr.trim() ? tzAttr.trim() : (Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC');
    const fmt = new Intl.DateTimeFormat('zh-CN',{ timeZone: tz, hour12:false, year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit' }).formatToParts(now);
    const parts = Object.fromEntries(fmt.map(p=>[p.type,p.value]));
    el.textContent = `${parts.year}年${parts.month}月${parts.day}日 ${parts.hour}:${parts.minute}`;
  }
  fmtNow(); setInterval(fmtNow, 30_000);
})();

/* ===== 单元格渲染（每格最多显示2门 + 分页器） ===== */
function buildCellItem(c, day){
  const wrap = document.createElement('div'); wrap.className='cell-item vstack gap-1';
  const periods = (c.periods||[]).map(x=>parseInt(x,10)).sort((a,b)=>a-b);
  const startHHMM = periodStartFromList(periods);
  const teacherRoom = [c.teacher||'', c.room||''].filter(Boolean).join(' · ');
  const cap = buildCapsule(day, startHHMM, c.name||'', teacherRoom, /*withMeta=*/true);
  wrap.appendChild(cap);
  if (DISPLAY_FIELDS.weeks && c.weeks){
    const metaWeeks = document.createElement('div'); metaWeeks.className='meta text-truncate';
    metaWeeks.textContent = '周: ' + String(c.weeks);
    wrap.appendChild(metaWeeks);
  }
  return wrap;
}
function renderCell(td){
  const holder = td.querySelector('.cell-content') || td;
  const raw = td.getAttribute('data-courses'); if(!raw){ holder.innerHTML = '<span class="text-muted"></span>'; return; }
  let list = []; try{ list = JSON.parse(raw); }catch(e){}
  const per = 2, totalPages = Math.max(1, Math.ceil(list.length / per));
  let page = parseInt(td.getAttribute('data-page')||'0', 10); if (isNaN(page)) page = 0;
  page = Math.max(0, Math.min(page, totalPages-1)); td.setAttribute('data-page', String(page));
  const start = page * per; const items = list.slice(start, start + per);
  const day = parseInt(td.getAttribute('data-day')||'0',10);
  const box = document.createElement('div'); box.className='vstack gap-2';
  if (!items.length){ box.innerHTML = '<span class="text-muted"></span>'; } else { items.forEach(c=> box.appendChild(buildCellItem(c, day))); }
  if (list.length > per){
    const pager = document.createElement('div'); pager.className='cell-pager';
    const prev = document.createElement('button'); prev.type='button'; prev.className='btn btn-sm btn-outline-secondary'; prev.textContent='‹';
    const next = document.createElement('button'); next.type='button'; next.className='btn btn-sm btn-outline-secondary'; next.textContent='›';
    const indi = document.createElement('span'); indi.className='page-indicator'; indi.textContent = (page+1) + '/' + totalPages;
    prev.onclick = (ev)=>{ ev.stopPropagation(); changeCellPage(td, -1); };
    next.onclick = (ev)=>{ ev.stopPropagation(); changeCellPage(td, +1); };
    pager.appendChild(prev); pager.appendChild(indi); pager.appendChild(next);
    box.appendChild(pager);
  }
  holder.innerHTML = ''; holder.appendChild(box);
}
function changeCellPage(td, dir){
  const total = (()=>{ const raw = td.getAttribute('data-courses'); if(!raw) return 1; let l=[]; try{l=JSON.parse(raw);}catch(e){} return Math.max(1, Math.ceil(l.length/2)); })();
  let p = parseInt(td.getAttribute('data-page')||'0', 10); if (isNaN(p)) p = 0;
  p += dir; if (p < 0) p = total - 1; if (p >= total) p = 0; td.setAttribute('data-page', String(p)); renderCell(td);
}
(function initCells(){
  document.querySelectorAll('td.cell[data-courses]').forEach(td => renderCell(td));
  document.querySelectorAll('[data-capsule]').forEach(el=>{
    const day = parseInt(el.getAttribute('data-day')||'0',10);
    const st  = el.getAttribute('data-start') || '';
    const color = colorFromSeed(`${USER_ID}|${day}|${st}`);
    el.style.setProperty('--cap-bg', color.bg);
    el.style.setProperty('--cap-bd', color.bd);
    const t = el.querySelector('.cap-text'); if (t){ t.textContent = clampName(t.textContent || ''); }
  });
})();

/* ===== 实时高亮（计算时区固定为课表时区） ===== */
const CALC_TZ  = "<?= h($tzTimetable) ?>";
const DAYS     = <?= json_encode(array_values($enabledDays ?? []), JSON_UNESCAPED_UNICODE) ?>;
function nowInTZ(tz){
  const f = new Intl.DateTimeFormat('en-GB', { timeZone: tz, hour12:false, year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', weekday:'short' });
  const parts = Object.fromEntries(f.formatToParts(new Date()).map(p=>[p.type,p.value]));
  const map = {Mon:1, Tue:2, Wed:3, Thu:4, Fri:5, Sat:6, Sun:7};
  return { hhmm: `${parts.hour}:${parts.minute}`, day: map[parts.weekday]||1 };
}
function getCell(day, period){ return document.querySelector(`td.cell[data-day="${day}"][data-period="${period}"]`); }
function cellHasCourse(cell){
  if(!cell) return false;
  const raw = cell.getAttribute('data-courses');
  if(!raw) return false;
  try{ const list = JSON.parse(raw); return Array.isArray(list) && list.length>0; }catch(e){ return false; }
}
function sortSlots(slots){ return [...slots].sort((a,b)=>{ if (a.start===b.start) return (a.idx||0)-(b.idx||0); return a.start>b.start?1:-1; }); }
function clearHL(){ document.querySelectorAll('td.cell.cell-current, td.cell.cell-next').forEach(td=> td.classList.remove('cell-current','cell-next')); }
function markCurrent(day, hhmm, slots){
  const curr = slots.filter(s => hhmm >= s.start && hhmm <= s.end).map(s => s.idx);
  curr.forEach(p=>{ const td = getCell(day, p); if (cellHasCourse(td)) td.classList.add('cell-current'); });
}
function findNext(dayToday, hhmm, slots){
  const todayIdx = Math.max(0, DAYS.indexOf(dayToday));
  for (let offset=0; offset < DAYS.length; offset++){
    const day = DAYS[(todayIdx + offset) % DAYS.length];
    const slotList = (offset === 0) ? slots.filter(s => s.start > hhmm) : slots;
    for (const s of slotList){ const td = getCell(day, s.idx); if (cellHasCourse(td)) return { day, period: s.idx }; }
  }
  return null;
}
(function liveHighlight(){
  const slots = sortSlots(TIMESLOTS);
  function tick(){
    clearHL();
    const {hhmm, day} = nowInTZ(CALC_TZ);
    markCurrent(day, hhmm, slots);
    const nxt = findNext(day, hhmm, slots);
    if (nxt){ const td = getCell(nxt.day, nxt.period); if (td) td.classList.add('cell-next'); }
  }
  tick(); setInterval(tick, 30 * 1000);
})();

/* ===== 倒计时（若存在） ===== */
(function initCountdown(){
  const el = document.getElementById('nextCountdown'); if(!el) return;
  const deadline = parseInt(el.getAttribute('data-deadline'),10); if(!deadline) return;
  function tick(){
    const now = Date.now(); let diff = Math.max(0, Math.floor((deadline - now)/1000));
    const m = Math.floor(diff/60), s = diff%60; el.textContent = (m.toString().padStart(2,'0')+':'+s.toString().padStart(2,'0'));
  }
  tick(); setInterval(tick, 1000);
})();
</script>
</body>
</html>
<?php
}
