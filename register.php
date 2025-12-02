<?php
// =============================
// register.php — 多步骤注册 & 导入（CSV / 文本粘贴 / 学校矩阵CSV / 可选AI解析）
// 兼容 PHP 8.2+，不依赖 Composer
// 1) 采集开学日期 → 2) 课表时间导入/模板/手填 → 3) 课程导入（通用CSV/矩阵CSV/AI） → 4) 核对 → 5) 邮箱(可选) → 6) 设置4位PIN → 完成
// 数据表：user_accounts(user_id,pin,profile,created_at)，user_schedule(user_id,data,updated_at)
// DB 连接常量在 db.php 中定义 DB_DSN / DB_USER / DB_PASS
// =============================

declare(strict_types=1);
mb_internal_encoding('UTF-8');
session_start();

/* ================= DeepSeek 配置（同页内置，已含重试与超时） ================= */
const DS_API_BASE      = 'https://api.deepseek.com';
const DS_MODEL         = 'deepseek-chat';   // 或 deepseek-reasoner
const DS_TEMPERATURE   = 0.1;
const DS_MAX_TOKENS    = 6000;
const DS_CONNECT_TIMEOUT = 20;              // 连接超时（秒）
const DS_REQUEST_TIMEOUT = 120;             // 整体请求超时（秒）
const DS_MAX_RETRIES     = 3;
const DS_RETRY_BASE_SEC  = 2;
const DS_FALLBACK_KEY    = 'sk-d35e6f478edb4d83b9220c52c1d883a9'; // 若没设置环境变量就用此常量（请替换）

function ds_api_key(): string {
    $key = getenv('DEEPSEEK_API_KEY');
    if ($key && trim($key) !== '') return trim($key);
    return DS_FALLBACK_KEY; // 请替换为你的 Key 或设置环境变量
}

function ds_chat(array $payload, int &$httpCode = 0, ?string &$rawResp = null): array {
    $url = rtrim(DS_API_BASE, '/') . '/chat/completions';
    $attempt = 0; $lastErr = null; $apiKey = ds_api_key();

    while ($attempt < DS_MAX_RETRIES) {
        $attempt++;
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'Expect:' // 禁用 100-continue
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => DS_REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => DS_CONNECT_TIMEOUT,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);
        if (defined('CURLOPT_TCP_KEEPALIVE')) curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        if (defined('CURLOPT_TCP_KEEPIDLE'))   curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 15);
        if (defined('CURLOPT_TCP_KEEPINTVL'))  curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 15);

        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $httpCode = (int)$http;
        $rawResp  = $resp;

        if ($errno === 0 && $http >= 200 && $http < 300 && is_string($resp) && $resp !== '') {
            $data = json_decode($resp, true);
            if (is_array($data)) return ['ok'=>true, 'data'=>$data];
            $lastErr = '返回内容不是合法 JSON';
        } else {
            $lastErr = $errno ? ("cURL错误: ".$err) : ("HTTP $http: ".$resp);
        }

        // 仅对 5xx 或超时进行重试（指数退避）
        if ($errno === CURLE_OPERATION_TIMEDOUT || ($http >= 500 && $http < 600)) {
            $delay = DS_RETRY_BASE_SEC * (2 ** ($attempt - 1));
            sleep($delay);
            continue;
        }
        break;
    }
    return ['ok'=>false,'error'=>$lastErr ?? '请求失败'];
}

/* ================= 工具 / DB 连接 ================= */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (is_file(__DIR__ . '/db.php')) require_once __DIR__ . '/db.php';
        if (!defined('DB_DSN')) {
            http_response_code(500);
            exit('缺少 db.php 或 DB_DSN/DB_USER/DB_PASS 定义');
        }
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
function json_out($arr, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
function ensure_unique_user_id(PDO $pdo): int {
    do {
        $id = random_int(100000, 999999);
        $stmt = $pdo->prepare('SELECT 1 FROM ' . table('user_accounts') . ' WHERE user_id=? LIMIT 1');
        $stmt->execute([$id]);
        $exists = $stmt->fetchColumn();
    } while ($exists);
    return $id;
}
function valid_pin(string $pin): bool { return (bool)preg_match('/^\d{4}$/', $pin); }
function valid_email(?string $email): bool { return !$email || (bool)filter_var($email, FILTER_VALIDATE_EMAIL); }

/* ================== 接口：AI 解析学校 CSV（同页内置） ================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ai_parse_school_csv') {
    $prompt = trim($_POST['prompt'] ?? '');
    $csv    = $_POST['csv'] ?? '';

    if ($prompt === '' || $csv === '') json_out(['ok'=>false,'error'=>'缺少 prompt 或 csv']);
    $key = ds_api_key();
    if (!$key || $key === 'REPLACE_WITH_YOUR_DEEPSEEK_KEY') {
        json_out(['ok'=>false,'error'=>'DeepSeek 密钥未配置']);
    }

    $sys = "You are a data parser that outputs STRICT JSON only. No commentary.";
    $user = "[任务与规则]\n{$prompt}\n\n[输入数据：整表CSV（原样）]\n{$csv}";
    $payload = [
        'model'       => DS_MODEL,
        'messages'    => [
            ['role'=>'system','content'=>$sys],
            ['role'=>'user'  ,'content'=>$user],
        ],
        'temperature' => DS_TEMPERATURE,
        'max_tokens'  => DS_MAX_TOKENS,
        'stream'      => false,
    ];

    $http = 0; $raw = null;
    $res = ds_chat($payload, $http, $raw);
    if (!$res['ok']) json_out(['ok'=>false,'error'=>$res['error'] ?? 'AI 请求失败','http'=>$http]);

    $data = $res['data'];
    $content = $data['choices'][0]['message']['content'] ?? '';
    if (!is_string($content) || $content === '') json_out(['ok'=>false,'error'=>'AI 无返回内容','http'=>$http]);

    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $content, $m)) $decoded = json_decode($m[0], true);
    }
    if (!is_array($decoded) || !isset($decoded['courses']) || !is_array($decoded['courses'])) {
        json_out(['ok'=>false,'error'=>'AI 输出不是合法 JSON（缺少 courses）','http'=>$http,'raw'=>mb_substr($content,0,5000)]);
    }

    $courses = [];
    foreach ($decoded['courses'] as $c) {
        $courses[] = [
            'name'      => (string)($c['name'] ?? ''),
            'teacher'   => (string)($c['teacher'] ?? ''),
            'room'      => (string)($c['room'] ?? ''),
            'day'       => (int)($c['day'] ?? 1),
            'periods'   => array_values(array_map('intval', is_array($c['periods'] ?? null) ? $c['periods'] : [])),
            'weeks'     => (string)($c['weeks'] ?? '01-16'),
            'week_type' => in_array(($c['week_type'] ?? 'all'), ['all','odd','even'], true) ? $c['week_type'] : 'all',
            'note'      => (string)($c['note'] ?? '')
        ];
    }
    json_out(['ok'=>true,'courses'=>$courses]);
}

/* ================== 接口：注册保存 ================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register') {
    $raw   = $_POST['schedule_json'] ?? '';
    $pin   = trim($_POST['pin'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($raw === '') json_out(['ok'=>false,'error'=>'缺少日程数据']);
    if (!valid_pin($pin)) json_out(['ok'=>false,'error'=>'PIN 必须是 4 位数字']);
    if (!valid_email($email)) json_out(['ok'=>false,'error'=>'邮箱格式不正确']);

    $data = json_decode($raw, true);
    if (!is_array($data)) json_out(['ok'=>false,'error'=>'schedule_json 不是合法 JSON']);

    $start_date   = $data['start_date'] ?? null;
    $tz           = $data['tz'] ?? 'Asia/Shanghai';
    $enabled_days = $data['enabled_days'] ?? [1,2,3,4,5,6,7];
    $timeslots    = $data['timeslots'] ?? [];
    $courses      = $data['courses'] ?? [];

    if (!$start_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
        json_out(['ok'=>false,'error'=>'开学日期不合法，应为 YYYY-MM-DD']);
    }
    if (!is_array($timeslots) || count($timeslots) === 0) json_out(['ok'=>false,'error'=>'请至少提供 1 个上课时段']);
    foreach ($timeslots as $t) {
        if (!isset($t['idx'],$t['start'],$t['end'])) json_out(['ok'=>false,'error'=>'时段缺少 idx / start / end']);
        if (!preg_match('/^\d{1,2}$/', (string)$t['idx'])) json_out(['ok'=>false,'error'=>'idx 需为数字']);
        if (!preg_match('/^\d{2}:\d{2}$/', $t['start']) || !preg_match('/^\d{2}:\d{2}$/', $t['end'])) {
            json_out(['ok'=>false,'error'=>'时段时间格式应为 HH:MM']);
        }
    }
    if (!is_array($courses)) $courses = [];
    foreach ($courses as $c) {
        foreach (['name','day','periods','weeks','week_type'] as $k) {
            if (!array_key_exists($k, $c)) json_out(['ok'=>false,'error'=>'课程字段缺失: '.$k]);
        }
        $d = (int)$c['day'];
        if ($d < 1 || $d > 7) json_out(['ok'=>false,'error'=>'课程 day 需为 1-7']);
        if (!is_array($c['periods']) || count($c['periods'])===0) json_out(['ok'=>false,'error'=>'课程 periods 需为数组，示例 [1,2]']);
        if (!in_array($c['week_type'], ['all','odd','even'], true)) json_out(['ok'=>false,'error'=>'week_type 需为 all/odd/even']);
        if (!preg_match('/^\d{2}(-\d{2})?(,\d{2}(-\d{2})?)*$/', str_replace(' ', '', $c['weeks']))) {
            json_out(['ok'=>false,'error'=>'weeks 格式不合法（示例：01-16 或 01,03,04-09）']);
        }
    }

    $pdo = db(); $pdo->beginTransaction();
    try {
        $user_id = ensure_unique_user_id($pdo);
        $profile = [
            'tz_pref'      => 'timetable',
            'tz_client'    => $tz,
            'tz_custom'    => $tz,
            'tz_timetable' => $tz,
            'cell_fields'  => ['name','teacher','room'],
        ];
        $stmt = $pdo->prepare('INSERT INTO ' . table('user_accounts') . ' (user_id,email,pin,profile) VALUES (?,?,?,?)');
        $stmt->execute([$user_id, $email ?: null, $pin, json_encode($profile, JSON_UNESCAPED_UNICODE)]);

        $schedule = [
            'start_date'   => $start_date,
            'tz'           => $tz,
            'enabled_days' => array_values(array_unique(array_map('intval', $enabled_days))),
            'timeslots'    => array_values($timeslots),
            'courses'      => array_values($courses),
        ];
        $stmt2 = $pdo->prepare('INSERT INTO ' . table('user_schedule') . ' (user_id, data) VALUES (?,?)');
        $stmt2->execute([$user_id, json_encode($schedule, JSON_UNESCAPED_UNICODE)]);

        $pdo->commit();
        json_out(['ok'=>true,'user_id'=>$user_id,'pin'=>$pin]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_out(['ok'=>false,'error'=>'保存失败：'.$e->getMessage()]);
    }
}

/* ================== 页面 ================== */
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>注册课表</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <style>
    body{background:#f7f8fb}
    .step{display:none}
    .step.active{display:block}
    .chip{display:inline-block;padding:.25rem .5rem;border-radius:999px;background:#eef2ff;color:#3730a3;margin-right:.25rem}
    .code{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace}
    .table-sm input{width:100%;}
    .search-hit{cursor:pointer}
    .search-hit:hover{background:#f1f5f9}
    /* 处理中 Modal 动画 */
    .dot{display:inline-block;width:.45em;height:.45em;background:#6b7280;border-radius:50%;margin-left:.2em;opacity:0;animation:blink 1s infinite}
    .dot1{animation-delay:0s}.dot2{animation-delay:.2s}.dot3{animation-delay:.4s}
    @keyframes blink{0%{opacity:0}50%{opacity:1}100%{opacity:0}}
  </style>
</head>
<body>
<?php include __DIR__ . '/includes/github_badge.php'; ?>
<div class="container py-4">
  <div class="row justify-content-center">
    <div class="col-lg-10">
      <div class="card shadow-sm">
        <div class="card-body">
          <h3 class="mb-1">新用户注册</h3>
          <div class="progress mb-4" role="progressbar" aria-label="wizard">
            <div id="prog" class="progress-bar" style="width: 16%">步骤 1/6</div>
          </div>

          <!-- Step 1 -->
          <div class="step active" data-step="1">
            <h5 class="mb-3">① 开学日期</h5>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">开学日期（YYYY-MM-DD）</label>
                <input type="date" id="start_date" class="form-control" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">课表时区</label>
                <select id="tz" class="form-select"></select>
              </div>
              <div class="col-md-4">
                <label class="form-label">启用星期</label>
                <div class="d-flex flex-wrap gap-2">
                  <div class="form-check"><input class="form-check-input day" type="checkbox" value="1" checked id="d1"><label class="form-check-label" for="d1">一</label></div>
                  <div class="form-check"><input class="form-check-input day" type="checkbox" value="2" checked id="d2"><label class="form-check-label" for="d2">二</label></div>
                  <div class="form-check"><input class="form-check-input day" type="checkbox" value="3" checked id="d3"><label class="form-check-label" for="d3">三</label></div>
                  <div class="form-check"><input class="form-check-input day" type="checkbox" value="4" checked id="d4"><label class="form-check-label" for="d4">四</label></div>
                  <div class="form-check"><input class="form-check-input day" type="checkbox" value="5" checked id="d5"><label class="form-check-label" for="d5">五</label></div>
                  <div class="form-check"><input class="form-check-input day" type="checkbox" value="6" checked id="d6"><label class="form-check-label" for="d6">六</label></div>
                  <div class="form-check"><input class="form-check-input day" type="checkbox" value="7" checked id="d7"><label class="form-check-label" for="d7">日</label></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Step 2 -->
          <div class="step" data-step="2">
            <h5 class="mb-3">2. 课表时间（时段）</h5>
            <div class="row g-3">
              <div class="col-lg-6">
                <label class="form-label">学校模板搜索</label>
                <input id="school_search" class="form-control" placeholder="输入学校全名或关键字…">
                <div id="school_list" class="list-group mt-2" style="max-height:220px;overflow:auto"></div>
              </div>
              <div class="col-lg-6">
                <label class="form-label">从文件导入（CSV / 文本）</label>
                <input id="ts_csv" type="file" accept=".csv,text/csv,text/plain" class="form-control mb-2">
                <textarea id="ts_paste" class="form-control" rows="4" placeholder="可粘贴 CSV/TSV 文本…"></textarea>
                <div class="mt-2 d-flex gap-2">
                  <button class="btn btn-outline-secondary btn-sm" id="btn_parse_ts">加载数据</button>
                  <button class="btn btn-outline-primary btn-sm" id="btn_add_ts">新增一行</button>
                </div>
              </div>
            </div>
            <div class="table-responsive mt-3">
              <table class="table table-sm table-bordered align-middle" id="tbl_ts">
                <thead class="table-light"><tr><th style="width:80px">ID</th><th>开始时间</th><th>结束时间</th><th style="width:80px">删除</th></tr></thead>
                <tbody></tbody>
              </table>
            </div>
          </div>

          <!-- Step 3 -->
          <div class="step" data-step="3">
            <h5 class="mb-3">3. 课程信息</h5>
            <div class="row g-3">
              <div class="col-lg-6">
                <label class="form-label">课程 CSV（通用）</label>
                <input id="course_csv" type="file" accept=".csv,text/csv,text/plain" class="form-control mb-2">
                <textarea id="course_paste" class="form-control" rows="4" placeholder="可粘贴 CSV/TSV 文本…"></textarea>
                <div class="mt-2 d-flex gap-2">
                  <button class="btn btn-outline-secondary btn-sm" id="btn_parse_course">加载数据</button>
                  <button class="btn btn-outline-primary btn-sm" id="btn_add_course">新增一行</button>
                </div>
              </div>
              <div class="col-lg-6">
                <label class="form-label">学校课表 CSV</label>
                <select id="csv_algo" class="form-select mb-2"></select>
                <input id="school_course_csv" type="file" accept=".csv,text/csv,text/plain" class="form-control mb-2">
                <button class="btn btn-outline-secondary btn-sm" id="btn_parse_school_csv" type="button">加载数据</button>
              </div>
            </div>
            <div class="table-responsive mt-3">
              <table class="table table-sm table-bordered align-middle" id="tbl_course">
                <thead class="table-light">
                <tr>
                  <th>课程名</th><th>教师</th><th>教室</th><th style="width:90px">星期</th>
                  <th style="width:120px">节次</th><th>周次</th><th style="width:110px">单双周</th><th>备注</th><th style="width:80px">删除</th>
                </tr></thead>
                <tbody></tbody>
              </table>
            </div>
          </div>

          <!-- Step 4 -->
          <div class="step" data-step="4">
            <h5 class="mb-3">4. 核对</h5>
            <div id="review" class="p-3 border rounded bg-light small"></div>
          </div>

          <!-- Step 5 -->
          <div class="step" data-step="5">
            <h5 class="mb-3">5. 联系与安全</h5>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">找回邮箱（可选）</label>
                <input id="email" type="email" class="form-control" placeholder="例如 you@example.com">
              </div>
              <div class="col-md-3">
                <label class="form-label">4 位数字 PIN</label>
                <input id="pin" type="text" maxlength="4" pattern="\d{4}" class="form-control" placeholder="例如 0420">
              </div>
              <div class="col-md-3 d-flex align-items-end">
                <button class="btn btn-outline-secondary" id="btn_rand_pin" type="button">随机生成</button>
              </div>
            </div>
          </div>

          <!-- Step 6 -->
          <div class="step" data-step="6">
            <h5 class="mb-3">6. 完成</h5>
            <div id="done" class="alert alert-success">正在提交…</div>
          </div>

          <div class="d-flex justify-content-between mt-4">
            <button class="btn btn-light" id="prev" disabled>上一步</button>
            <div class="d-flex gap-2">
              <!-- <button class="btn btn-secondary" id="save_draft" type="button">保存草稿</button> -->
              <button class="btn btn-primary" id="next">下一步</button>
            </div>
          </div>

          <form id="post_form" method="post" class="d-none">
            <input name="action" value="register">
            <input id="schedule_json" name="schedule_json">
            <input id="post_email" name="email">
            <input id="post_pin" name="pin">
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<!-- 仅 AI 模式使用的“处理中” Modal -->
<div class="modal fade" id="busyModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-sm">
      <div class="modal-body d-flex align-items-center gap-3">
        <div class="spinner-border" role="status" aria-hidden="true"></div>
        <div>
          <div class="fw-semibold">
            处理中<span class="dot dot1"></span><span class="dot dot2"></span><span class="dot dot3"></span>
          </div>
          <div class="small text-muted">正在解析课程数据，请稍候…</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ========= 工具 =========
const $ = (q,root=document)=>root.querySelector(q);
const $$ = (q,root=document)=>Array.from(root.querySelectorAll(q));
function ensureInt(v, d=0){ const n = parseInt(v,10); return Number.isFinite(n)?n:d; }
function zpad2(n){ n = String(n); return n.length===1 ? '0'+n : n; }
function parseCSV(text){
  const delim = text.includes('\t') && !text.includes(',') ? '\t' : ',';
  const rows = [];
  let cur = '', row = [], inQ = false;
  for (let i=0;i<text.length;i++){
    const ch = text[i], next = text[i+1];
    if (inQ){
      if (ch==='"' && next==='"'){ cur+='"'; i++; }
      else if (ch==='"'){ inQ=false; }
      else cur+=ch;
    } else {
      if (ch==='"') inQ=true;
      else if (ch===delim){ row.push(cur.trim()); cur=''; }
      else if (ch==='\n'){ row.push(cur.trim()); rows.push(row); row=[]; cur=''; }
      else if (ch==='\r'){ /* ignore */ }
      else cur+=ch;
    }
  }
  if (cur.length || row.length) { row.push(cur.trim()); rows.push(row); }
  return rows.filter(r=>r.some(c=>c!==''));
}
function rowsToObjects(rows){
  if (!rows.length) return [];
  const header = rows[0].map(h=>h.trim().toLowerCase());
  return rows.slice(1).map(r=>{
    const o={}; for (let i=0;i<header.length;i++){ o[header[i]] = (r[i]??'').trim(); } return o;
  });
}
function fw2hw(s){ return s.replace(/[\uFF01-\uFF5E]/g, ch=>String.fromCharCode(ch.charCodeAt(0)-0xFEE0)).replace(/\u3000/g, ' '); }

// —— weeks 工具 ——
function parseWeeksToString(raw){
  if (!raw) return '01-16';
  let s = String(raw).replaceAll('，', ',').replace(/\s+/g, '');
  s = s.replace(/(^\[|\]$)/g, '');
  const segs = s.split(',').filter(Boolean).map(seg=>{
    const m = seg.split('-');
    if (m.length===2){
      const a = parseInt(m[0],10), b = parseInt(m[1],10);
      if (Number.isFinite(a) && Number.isFinite(b)) return `${zpad2(a)}-${zpad2(b)}`;
      return seg;
    } else {
      const k = parseInt(seg,10);
      return Number.isFinite(k) ? zpad2(k) : seg;
    }
  });
  return segs.join(',');
}
function normalizeWeeksPieces(pieces){
  const join = (pieces||[]).filter(Boolean).join(',').replaceAll('，',',').replace(/\s+/g,'');
  if (!join) return '01-16';
  const cleaned = join.replace(/^\s*[，,]+/,'').replace(/^\s*\[(.*?)\]\s*周\s*$/,'$1');
  return parseWeeksToString(cleaned);
}

// —— periods 工具 ——
function getMaxPeriodIdx(){
  if (!state.timeslots || !state.timeslots.length) return Infinity;
  return Math.max(...state.timeslots.map(t=>Number(t.idx)||0));
}
function periodsToArray(s, maxIdx = Infinity){
  s = (s||'').replaceAll('，',',').replaceAll('、',',').replaceAll(' ','');
  const ret = [];
  for (const seg of s.split(',')){
    if (!seg) continue;
    const p = seg.split('-').map(x=>parseInt(x,10));
    if (p.length===1 && Number.isInteger(p[0])) ret.push(p[0]);
    else if (p.length===2 && Number.isInteger(p[0]) && Number.isInteger(p[1])){
      for (let k=p[0]; k<=p[1]; k++) ret.push(k);
    }
  }
  let arr = Array.from(new Set(ret)).sort((a,b)=>a-b);
  if (Number.isFinite(maxIdx)) arr = arr.filter(x => x>=1 && x<=maxIdx);
  return arr;
}
const minPeriod = (arr)=> Array.isArray(arr)&&arr.length ? Math.min(...arr) : 999;

// ========= “处理中…” 轻量遮罩（仅 AI 启动时使用） =========
const Processing = (()=> {
  let overlay=null, timer=null, dots=0;
  function build(){
    overlay = document.createElement('div');
    overlay.id = 'ai-processing-overlay';
    overlay.style.cssText = `
      position:fixed;inset:0;background:rgba(15,23,42,.35);display:flex;align-items:center;justify-content:center;z-index:2000;
      backdrop-filter:saturate(120%) blur(2px);
    `;
    const card = document.createElement('div');
    card.style.cssText = 'background:#fff;padding:18px 22px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.15);min-width:240px;text-align:center;';
    card.innerHTML = `<div style="font-weight:600">处理中<span id="aiDots">…</span></div><div class="text-muted" style="margin-top:6px;font-size:.875rem">正在解析文件，请稍候</div>`;
    overlay.appendChild(card);
    document.body.appendChild(overlay);
  }
  function show(){
    if (!overlay) build();
    overlay.style.display = 'flex';
    dots=0;
    timer = setInterval(()=>{
      dots = (dots+1)%4;
      const s = '.'.repeat(dots);
      $('#aiDots').textContent = s || '…';
    }, 450);
  }
  function hide(){
    if (timer){ clearInterval(timer); timer=null; }
    if (overlay) overlay.style.display = 'none';
  }
  return { show, hide };
})();

// ========= 状态 =========
const DAY_LABEL = ['一','二','三','四','五','六','日'];
const state = {
  start_date: '', tz: 'Asia/Shanghai', enabled_days: [1,2,3,4,5,6,7],
  timeslots: [], courses: []
};
let STEP = 1; const MAX_STEP = 6;
const prog = $('#prog');

// ========= Step 导航 =========
function showStep(i){
  STEP=i;
  $$('.step').forEach(s=>s.classList.toggle('active', s.dataset.step==i));
  if (prog){ prog.style.width = (i/6*100)+'%'; prog.textContent = `步骤 ${i}/6`; }
  $('#prev').disabled = i===1;
  $('#next').textContent = (i===5?'提交':'下一步');
}

// ========= Step2：时段表格 =========
const tsTbody = $('#tbl_ts tbody');
function renderTimeslots(){
  if (!tsTbody) return;
  tsTbody.innerHTML = '';
  for (const t of state.timeslots){
    const tr = document.createElement('tr');
    tr.innerHTML = `<td><input class="form-control form-control-sm idx" value="${t.idx}"></td>
                    <td><input class="form-control form-control-sm start" value="${t.start}"></td>
                    <td><input class="form-control form-control-sm end" value="${t.end}"></td>
                    <td><button class="btn btn-sm btn-outline-danger del">删除</button></td>`;
    tsTbody.appendChild(tr);
  }
}
tsTbody?.addEventListener('input', (e)=>{
  const tr = e.target.closest('tr'); if (!tr) return; const i = Array.from(tsTbody.children).indexOf(tr);
  if (i<0) return; const t = state.timeslots[i];
  if (e.target.classList.contains('idx')) t.idx = ensureInt(e.target.value, t.idx);
  if (e.target.classList.contains('start')) t.start = e.target.value;
  if (e.target.classList.contains('end')) t.end = e.target.value;
});
tsTbody?.addEventListener('click', (e)=>{
  if (e.target.classList.contains('del')){
    const i = Array.from(tsTbody.children).indexOf(e.target.closest('tr'));
    state.timeslots.splice(i,1); renderTimeslots();
  }
});
$('#btn_add_ts')?.addEventListener('click', ()=>{
  const nextIdx = state.timeslots.length? Math.max(...state.timeslots.map(x=>x.idx))+1 : 1;
  state.timeslots.push({idx: nextIdx, start:'08:00', end:'08:45'}); renderTimeslots();
});
$('#btn_parse_ts')?.addEventListener('click', async ()=>{
  let text = $('#ts_paste').value.trim();
  if (!text && $('#ts_csv').files[0]) text = await $('#ts_csv').files[0].text();
  if (!text) return alert('请先选择 CSV 或粘贴文本');
  const rows = rowsToObjects(parseCSV(text));
  const out=[]; for (const r of rows){
    if (!r.idx && !r.start) continue;
    out.push({idx: ensureInt(r.idx, out.length+1), start: r.start||'', end: r.end||''});
  }
  if (!out.length) return alert('未解析到时段数据（需要表头 idx,start,end）');
  state.timeslots = out; renderTimeslots();
});

// ========= 学校模板（时段模板） =========
let collegeData = [];
fetch('college.json').then(r=>r.json()).then(j=>{ collegeData=j; }).catch(()=>{});
$('#school_search')?.addEventListener('input', ()=>{
  const q = $('#school_search').value.trim().toLowerCase();
  const list = $('#school_list'); if (!list) return; list.innerHTML='';
  if (!q) return;
  const hits = (collegeData||[]).filter(x=> (x.name||'').toLowerCase().includes(q) || (x.id||'').toLowerCase().includes(q)).slice(0,30);
  for (const h of hits){
    const a = document.createElement('a'); a.className='list-group-item list-group-item-action search-hit';
    a.innerHTML = `<div class="d-flex justify-content-between"><div><b>${h.name||h.id}</b></div><small class="text-muted">${(h.timeslots||[]).length} 节</small></div>`;
    a.addEventListener('click',()=>{
      state.tz = h.tz || state.tz; $('#tz').value = state.tz;
      state.timeslots = (h.timeslots||[]).map(x=>({idx: Number(x.idx), start: x.start, end: x.end}));
      renderTimeslots();
    });
    list.appendChild(a);
  }
});

// ========= Step3：课程表格 =========
const cTbody = $('#tbl_course tbody');
function sortedCourseIndices(){
  return state.courses
    .map((c,i)=>({i, day:+c.day||9, period:minPeriod(c.periods), name:c.name||''}))
    .sort((a,b)=> a.day-b.day || a.period-b.period || a.name.localeCompare(b.name,'zh-Hans-CN'))
    .map(x=>x.i);
}
function renderCourses(){
  if (!cTbody) return;
  cTbody.innerHTML='';
  const order = sortedCourseIndices();
  for (const idx of order){
    const c = state.courses[idx];
    const tr = document.createElement('tr');
    tr.dataset.idx = String(idx);
    tr.innerHTML = `
      <td><input class="form-control form-control-sm name" value="${c.name||''}"></td>
      <td><input class="form-control form-control-sm teacher" value="${c.teacher||''}"></td>
      <td><input class="form-control form-control-sm room" value="${c.room||''}"></td>
      <td>
        <select class="form-select form-select-sm day" title="星期">
          ${[1,2,3,4,5,6,7].map(d=>`<option value="${d}" ${Number(c.day)===d?'selected':''}>${DAY_LABEL[d-1]}</option>`).join('')}
        </select>
      </td>
      <td><input class="form-control form-control-sm periods" title="允许 1-2 或 1,3" placeholder="1-2 或 1,3" value="${(c.periods||[]).join(',')||''}"></td>
      <td><input class="form-control form-control-sm weeks" placeholder="01-16 或 01,03,04-09" value="${c.weeks||''}"></td>
      <td>
        <select class="form-select form-select-sm week_type" title="单双周">
          <option value="all" ${c.week_type==='all'?'selected':''}>否</option>
          <option value="odd" ${c.week_type==='odd'?'selected':''}>单周</option>
          <option value="even" ${c.week_type==='even'?'selected':''}>双周</option>
        </select>
      </td>
      <td><input class="form-control form-control-sm note" value="${c.note||''}"></td>
      <td><button class="btn btn-sm btn-outline-danger del">删除</button></td>
    `;
    cTbody.appendChild(tr);
  }
}
cTbody?.addEventListener('input', (e)=>{
  const tr = e.target.closest('tr'); if (!tr) return; const idx = Number(tr.dataset.idx);
  const c = state.courses[idx]; if (!c) return;
  if (e.target.classList.contains('name')) c.name = e.target.value;
  if (e.target.classList.contains('teacher')) c.teacher = e.target.value;
  if (e.target.classList.contains('room')) c.room = e.target.value;
  if (e.target.classList.contains('day')) c.day = ensureInt(e.target.value, 1);
  if (e.target.classList.contains('periods')) c.periods = periodsToArray(e.target.value, getMaxPeriodIdx());
  if (e.target.classList.contains('weeks')) c.weeks = e.target.value.trim();
  if (e.target.classList.contains('week_type')) c.week_type = e.target.value;
  if (e.target.classList.contains('note')) c.note = e.target.value;
});
cTbody?.addEventListener('change', (e)=>{
  const tr = e.target.closest('tr'); if (!tr) return; const idx = Number(tr.dataset.idx);
  const c = state.courses[idx]; if (!c) return;
  if (e.target.classList.contains('periods')) e.target.value = (c.periods||[]).join(',');
  if (e.target.classList.contains('day') || e.target.classList.contains('periods')) renderCourses();
});
cTbody?.addEventListener('click', (e)=>{
  if (e.target.classList.contains('del')){
    const tr = e.target.closest('tr'); const idx = Number(tr.dataset.idx);
    state.courses.splice(idx,1); renderCourses();
  }
});
$('#btn_add_course')?.addEventListener('click', ()=>{
  state.courses.push({name:'',teacher:'',room:'',day:1,periods:[1],weeks:'01-16',week_type:'all',note:''});
  renderCourses();
});
$('#btn_parse_course')?.addEventListener('click', async ()=>{
  let text = $('#course_paste').value.trim();
  if (!text && $('#course_csv').files[0]) text = await $('#course_csv').files[0].text();
  if (!text) return alert('请先选择 CSV 或粘贴文本');
  const rows = rowsToObjects(parseCSV(text));
  const out=[];
  const maxIdx = getMaxPeriodIdx();
  for (const r of rows){
    if (!r.name) continue;
    const ps = periodsToArray(r.periods||'', maxIdx);
    out.push({
      name: r.name||'', teacher: r.teacher||'', room: r.room||'', day: ensureInt(r.day,1),
      periods: ps, weeks: (r.weeks||'').replaceAll(' ','')||'01-16',
      week_type: (r.week_type||'all').toLowerCase(), note: r.note||''
    });
  }
  if (!out.length) return alert('未解析到课程数据 (需要表头 name,day,periods,weeks,week_type)');
  state.courses = out; renderCourses();
});

// ========= Step4：核对 =========
function doReview(){
  const div = $('#review'); if (!div) return;
  const tsBrief = state.timeslots.slice(0,5).map(t=>`${t.idx}:${t.start}-${t.end}`).join(' / ')+(state.timeslots.length>5?` …（共 ${state.timeslots.length} 节）`:``);
  const cBrief = state.courses
    .slice()
    .sort((a,b)=> (+a.day||9)-(+b.day||9) || minPeriod(a.periods)-minPeriod(b.periods))
    .slice(0,5)
    .map(c=>`${c.name} 周${DAY_LABEL[(+c.day||1)-1]} 节${(c.periods||[]).join(',')} 周数:${c.weeks}`)
    .join('；')
    +(state.courses.length>5?` …（共 ${state.courses.length} 门）`:``);
  div.innerHTML = `
    <div><b>开学日期：</b>${state.start_date}</div>
    <div><b>时区：</b>${state.tz}</div>
    <div><b>启用星期：</b>${state.enabled_days.map(d=>DAY_LABEL[d-1]).join(',')}</div>
    <div class="mt-2"><b>时段：</b>${tsBrief||'<span class="text-danger">未设置</span>'}</div>
    <div class="mt-2"><b>课程：</b>${cBrief||'<span class="text-warning">暂未添加</span>'}</div>
  `;
}

// ========= Step5：随机 PIN =========
$('#btn_rand_pin')?.addEventListener('click', ()=>{
  $('#pin').value = String(Math.floor(Math.random()*10000)).padStart(4,'0');
});

// ========= 导航按钮 =========
const prevBtn = $('#prev');
const nextBtn = $('#next');

prevBtn?.addEventListener('click', () => { if (STEP > 1) showStep(STEP - 1); });

nextBtn?.addEventListener('click', async () => {
  // ★ 注册成功后：再次点击“前往课表”直接跳转
  if (STEP === 6 && nextBtn?.dataset.mode === 'go-index') {
    location.href = 'index.php'; // 如需别的地址可改这里
    return;
  }

  if (STEP === 1) {
    const d = $('#start_date').value;
    if (!d) return alert('请选择开学日期');
    state.start_date = d;
    state.tz = $('#tz').value;
    state.enabled_days = $$('.day:checked').map(x => Number(x.value));
    showStep(2);

  } else if (STEP === 2) {
    if (!state.timeslots.length) return alert('请先设置“时段”');
    state.timeslots = state.timeslots
      .map(t => ({ idx: Number(t.idx), start: t.start, end: t.end }))
      .sort((a, b) => a.idx - b.idx);
    renderTimeslots();
    showStep(3);

  } else if (STEP === 3) {
    doReview();
    showStep(4);

  } else if (STEP === 4) {
    showStep(5);

  } else if (STEP === 5) {
    const pin = $('#pin').value.trim();
    const email = $('#email').value.trim();
    if (!/^\d{4}$/.test(pin)) return alert('PIN 必须是 4 位数字');
    if (email && !/^\S+@\S+\.[\S]+$/.test(email)) return alert('邮箱格式不正确');

    showStep(6);
    $('#done').textContent = '正在提交…';

    const payload = {
      start_date: state.start_date,
      tz: state.tz,
      enabled_days: state.enabled_days,
      timeslots: state.timeslots,
      courses: state.courses
    };

    const form = $('#post_form');
    $('#schedule_json').value = JSON.stringify(payload);
    $('#post_email').value = email;
    $('#post_pin').value = pin;

    const res = await fetch(location.href, { method: 'POST', body: new FormData(form) });
    const j = await res.json().catch(() => ({ ok: false, error: '服务器返回不是 JSON' }));

    if (!j.ok) {
      $('#done').className = 'alert alert-danger';
      $('#done').textContent = '失败：' + (j.error || '未知错误');
      return;
    }

    // 成功
    $('#done').className = 'alert alert-success';
    $('#done').innerHTML = `注册成功！<br>课表 ID: <b class="code">${j.user_id}</b> PIN: <b class="code">${j.pin}</b>
      <br><small class="text-muted">请拍照/保存。点击“前往课表”跳转查看。</small>
      <div class="mt-2"><a class="btn btn-success btn-sm" href="index.php">立即前往</a></div>`;

    // 按钮与状态（不禁用下一步；把它变成“前往课表”）
    prevBtn.disabled = true;
    nextBtn.disabled = false;
    nextBtn.textContent = '前往课表';
    nextBtn.dataset.mode = 'go-index';

    // 便捷：把账号缓存到本地，index 可自动填充
    try {
      localStorage.setItem('kbv2_recent_account', JSON.stringify({ uid: j.user_id, pin: j.pin }));
    } catch {}
  }
});

// ========= 草稿缓存 =========
$('#save_draft')?.addEventListener('click', ()=>{
  const cache = {
    start_date: $('#start_date').value, tz: $('#tz').value,
    enabled_days: $$('.day:checked').map(x=>Number(x.value)),
    timeslots: state.timeslots, courses: state.courses,
    email: $('#email').value, pin: $('#pin').value
  };
  localStorage.setItem('register_draft', JSON.stringify(cache));
  alert('已保存到本地浏览器');
});
window.addEventListener('load', ()=>{
  const s = localStorage.getItem('register_draft');
  if (!s) return; try{
    const d = JSON.parse(s);
    if (d.start_date) $('#start_date').value = d.start_date;
    if (d.tz) $('#tz').value = d.tz;
    if (Array.isArray(d.enabled_days)){
      $$('.day').forEach(x=>x.checked = d.enabled_days.includes(Number(x.value)));
    }
    state.timeslots = d.timeslots||[]; renderTimeslots();
    state.courses = d.courses||[]; renderCourses();
    if (d.email) $('#email').value = d.email;
    if (d.pin) $('#pin').value = d.pin;
  }catch{}
});

// ========= 学校 CSV/手动 TXT/手动行算法载入 =========
let csvAlgoList = [];
async function loadCsvAlgos(){
  try{
    const resp = await fetch('college_csv.json');
    csvAlgoList = await resp.json();
  }catch{ csvAlgoList = []; }
  const sel = $('#csv_algo');
  if (!sel) return;
  sel.innerHTML = '';
  if (!csvAlgoList.length){
    const o = document.createElement('option');
    o.value = ''; o.textContent = '未找到 college_csv.json';
    sel.appendChild(o);
    const btn = $('#btn_parse_school_csv'); if (btn) btn.disabled = true;
    return;
  }
  for (const a of csvAlgoList){
    const o = document.createElement('option');
    o.value = a.id; o.textContent = a.name || a.id;
    sel.appendChild(o);
  }
}
window.addEventListener('load', loadCsvAlgos);

// ========= 解析主入口（学校矩阵 / 手动TXT / 手动行 / AI） =========
async function readFileText(file){ if (!file) return ''; return await file.text(); }
function findHeaderRow(rows, must){
  for (let r=0;r<rows.length;r++){
    const row = rows[r].map(x=>String(x||'').trim());
    const ok = (must||[]).every(tok => row.includes(tok));
    if (ok) return r;
  }
  return -1;
}
function guessWeekType(s, rules){
  s = s || '';
  for (const r of (rules||[])){
    if (s.includes(r.contains)) return r.week_type || 'all';
  }
  return 'all';
}

async function parseSchoolMatrixCSVToCourses(fileText, algo){
  const cfg = algo.csv || {};

  // ★ 新增：手动 TXT 模式
  if (cfg.manual_txt) {
    return parseManualTxtToCourses(fileText, algo);
  }
  // ★ 新增：手动“行内”模式（星期一 3,4 课 …）
  if (cfg.manual_inline) {
    return parseManualInlineCSVToCourses(fileText, algo);
  }
  // ★ AI 模式：仅在 cfg.ai === true 时走，并显示“处理中…”
  if (cfg.ai === true) {
    try{
      Processing.show(); // 只在 AI 模式显示
      const parsed = await aiParseCoursesFromText(fileText, algo);
      return parsed;
    } finally {
      Processing.hide();
    }
  }

  // —— 默认：矩阵 CSV 解析（auto-hit 等） ——
  const rows = parseCSV(fileText);
  if (!rows.length) throw new Error('CSV 为空');

  let startRow = findHeaderRow(rows, cfg.header_row_must_include || []);
  if (startRow < 0) {
    startRow = findHeaderRow(rows.slice(cfg.title_rows_to_skip||0), cfg.header_row_must_include||[]);
    if (startRow >= 0) startRow += (cfg.title_rows_to_skip||0);
  }
  if (startRow < 0) throw new Error('未找到表头（需要包含：' + (cfg.header_row_must_include||[]).join('、') + '）');

  const dayStart   = cfg.day_cols_from ?? 2;
  const dayCnt     = cfg.day_cols_count ?? 7;
  const periodCol  = cfg.period_col ?? 1;
  const sectionCol = cfg.section_col ?? 0;

  const norm = (s)=>{
    s = s ?? '';
    if (cfg.normalize?.fullwidth_to_halfwidth) s = fw2hw(s);
    if (cfg.normalize?.replace_cn_comma_to_en) s = s.replaceAll('，', ',').replaceAll('、', ',');
    if (cfg.normalize?.replace_cn_dash_to_en)  s = s.replace(/[－—–‒―]/g, '-');
    return s.trim();
  };

  function parsePeriodsByPattern(text, periodPattern){
    const re = new RegExp(periodPattern || '第(?<nums>[0-9,]+)节');
    const m = re.exec(text||'');
    if (!m) return [];
    const nums = (m.groups?.nums || '').replaceAll('，',',').replaceAll('、',',');
    const out = [];
    for (const seg of nums.split(',').filter(Boolean)){
      if (seg.includes('-')){
        const [a,b] = seg.split('-').map(x=>parseInt(x,10));
        if (Number.isFinite(a) && Number.isFinite(b)){
          for (let k=a;k<=b;k++) out.push(k);
        }
      }else{
        const k = parseInt(seg,10); if (Number.isFinite(k)) out.push(k);
      }
    }
    return Array.from(new Set(out)).sort((a,b)=>a-b);
  }

  function looksLikeExtraWeeksLine(line){
    return /^\s*[，,]?\s*\[[^\]]+\]\s*周\s*$/i.test(line||'');
  }
  function looksLikeDetailLine(line){
    return /\[[^\]]+\]\s*周/.test(line||'');
  }
  function looksLikeRoomLine(line){
    const kw = (cfg.room_fallback_keywords||[]);
    if (kw.some(k => (line||'').includes(k))) return true;
    if (/(楼|教室|校区|馆)/.test(line||'')) return true;
    if (/[A-Za-z]?\d{2,4}/.test(line||'')) return true;
    return false;
  }
  function parseDetailLine(detail){
    const i = (detail||'').indexOf('[');
    const teacher = i>0 ? detail.slice(0,i).trim() : '';
    const weeksPieces = [];
    const reAllWeeks = /(?:[，,]\s*)?\[([^\]]+)\]\s*周/g;
    let m;
    while ((m = reAllWeeks.exec(detail||'')) !== null){ weeksPieces.push(m[1]); }
    let room = detail||'';
    if (teacher) room = room.replace(teacher,'');
    room = room.replace(/(?:[，,]\s*)?\[[^\]]+\]\s*周/g, '').trim();
    room = room.replace(/^[，,;:\s]+/,'').trim();
    if (!room) room = '';
    return { teacher, weeksPieces, room };
  }
  function parseCellCourses(cellText){
    const lines = (cellText||'')
      .split(new RegExp(cfg.split_cell_by || '\\n+'))
      .map(x=>norm(x))
      .filter(x=>!(cfg.skip_tokens||[]).includes(x));
    if (!lines.length) return [];
    const out = []; let cur=null;
    const flush = ()=>{ if (cur){ out.push(cur); cur=null; } };
    for (let idx=0; idx<lines.length; idx++){
      const L = lines[idx];

      if (looksLikeExtraWeeksLine(L)){
        if (!cur) continue;
        const mm = L.match(/\[([^\]]+)\]\s*周/);
        if (mm) cur._weeksPieces.push(mm[1]);
        continue;
      }
      if (looksLikeDetailLine(L)){
        if (!cur){
          const prev = lines[idx-1] || '';
          if (prev && !looksLikeDetailLine(prev) && !looksLikeExtraWeeksLine(prev) && !looksLikeRoomLine(prev)){
            cur = { name: prev, teacher:'', room:'', _weeksPieces: [] };
          } else {
            cur = { name:'', teacher:'', room:'', _weeksPieces: [] };
          }
        }
        const parsed = parseDetailLine(L);
        if (parsed.teacher) cur.teacher = parsed.teacher;
        if (parsed.weeksPieces?.length) cur._weeksPieces.push(...parsed.weeksPieces);
        if (parsed.room) cur.room = parsed.room;
        continue;
      }
      if (looksLikeRoomLine(L)){
        if (cur && !cur.room){ cur.room = L; }
        continue;
      }
      flush();
      cur = { name: L, teacher:'', room:'', _weeksPieces: [] };
    }
    flush();
    for (const c of out){ c.weeks = normalizeWeeksPieces(c._weeksPieces); delete c._weeksPieces; }
    return out;
  }

  const courses = [];
  for (let r=(startRow+1); r<rows.length; r++){
    const row = rows[r]; if (!row || row.every(c => !String(c||'').trim())) continue;
    const section   = norm(row[sectionCol] || '');
    const periodStr = norm(row[periodCol]  || '');
    const periods = parsePeriodsByPattern(periodStr, cfg.period_pattern);
    if (!periods.length) continue;

    for (let d=0; d<dayCnt; d++){
      const col = dayStart + d;
      const cellRaw = norm(row[col] || '');
      if (!cellRaw || (cfg.skip_tokens||[]).includes(cellRaw)) continue;

      const grouped = parseCellCourses(cellRaw);
      for (const g of grouped){
        const weekType = guessWeekType((g.name||'') + (g.teacher||''), cfg.week_type_rules || []);
        const weeksStr = g.weeks || '01-16';
        courses.push({
          name   : g.name || '',
          teacher: (g.teacher || '').trim(),
          room   : (g.room || '').trim(),
          day    : d+1,
          periods: periods.slice(),
          weeks  : weeksStr,
          week_type: weekType || 'all',
          note   : section || ''
        });
      }
    }
  }

  // 依据最大节次限制过滤所有课程的 periods
  const maxIdx = getMaxPeriodIdx();
  const filtered = courses.map(c=>({ ...c, periods: periodsToArray((c.periods||[]).join(','), maxIdx) }));
  return filtered;
}

// ========= 手动“行内”算法（星期一 3,4 课程 老师[周]教室 9,10 …） =========
function parseManualInlineCSVToCourses(fileText, algo){
  const cfg = algo.csv || {};
  const dayMap = { '星期一':1,'星期二':2,'星期三':3,'星期四':4,'星期五':5,'星期六':6,'星期日':7 };
  const reDay    = new RegExp(cfg.day_token_regex || '^(星期[一二三四五六日])\\s+');
  const rePeriod = new RegExp(cfg.period_token_regex || '(\\d{1,2}(?:-\\d{1,2}|(?:,\\d{1,2})*))', 'g');

  const norm = (s)=>{
    s = s ?? '';
    if (cfg.normalize?.fullwidth_to_halfwidth) s = fw2hw(s);
    if (cfg.normalize?.replace_cn_comma_to_en) s = s.replaceAll('，', ',').replaceAll('、', ',');
    if (cfg.normalize?.replace_cn_dash_to_en)  s = s.replace(/[－—–‒―]/g, '-');
    return s.trim();
  };

  const lines = fileText.split(/\r?\n/).map(l=>norm(l)).filter(Boolean);
  const out = []; const maxIdx = getMaxPeriodIdx();

  for (const lineRaw of lines){
    const line = lineRaw.replace(/\s+/g, ' ').trim();
    const mDay = line.match(reDay);
    if (!mDay) continue;
    const day = dayMap[mDay[1]] || 0; if (!day) continue;
    let rest = line.slice(mDay[0].length).trim();
    while (rest.length) {
      const head = rest.match(new RegExp('^' + (cfg.period_token_regex || '(\\d{1,2}(?:-\\d{1,2}|(?:,\\d{1,2})*))')));
      if (!head) break;
      const periods = periodsToArray(head[1], maxIdx);
      rest = rest.slice(head[0].length).trim();
      if (!rest) break;

      rePeriod.lastIndex = 0;
      const nextMatch = rePeriod.exec(rest);
      const cut = nextMatch ? nextMatch.index : rest.length;
      const block = rest.slice(0, cut).trim();
      rest = rest.slice(cut).trim();

      let name = '', teacher = '', weeks = '01-16', week_type = 'all', room = '';
      const iBracket = block.indexOf('[');
      if (iBracket > 0 && /\]\s*周/.test(block)) {
        const left = block.slice(0, iBracket).trim();     // "课程名 教师"
        const restDetail = block.slice(iBracket).trim();  // "[xx]周教室"
        const j = left.lastIndexOf(' ');
        if (j >= 0) { name = left.slice(0, j).trim(); teacher = left.slice(j+1).trim(); }
        else { name = left; teacher = ''; }

        const mSq = restDetail.match(/\[([^\]]+)\]\s*周(.*)$/);
        if (mSq) {
          weeks = parseWeeksToString(mSq[1] || '') || '01-16';
          room  = (mSq[2] || '').trim();
        }
        for (const r of (cfg.week_type_rules||[])) {
          if (block.includes(r.contains)) { week_type = r.week_type || 'all'; break; }
        }
      } else {
        name = block.trim();
      }
      if (name && periods.length){
        out.push({ name, teacher, room, day, periods, weeks, week_type, note: '' });
      }
    }
  }

  // 合并同课
  const keyOf = (c)=> [c.day, c.name, c.teacher, c.weeks, c.week_type, c.room].join('||');
  const map = new Map();
  for (const c of out){
    const k = keyOf(c);
    if (!map.has(k)) map.set(k, { ...c, periods: [] });
    map.get(k).periods.push(...c.periods);
  }
  const merged = [];
  for (const v of map.values()){
    v.periods = Array.from(new Set(v.periods)).sort((a,b)=>a-b);
    merged.push(v);
  }
  return merged;
}

// ========= 手动 TXT 算法（星期一 换行 3,4 换行 课程名 换行 详情 … 下一个星期X） =========
function parseManualTxtToCourses(fileText, algo){
  const cfg = algo.csv || {};
  const dayMap = { '星期一':1,'星期二':2,'星期三':3,'星期四':4,'星期五':5,'星期六':6,'星期日':7 };
  const reDay    = new RegExp(cfg.day_token_regex || '^\\s*(星期[一二三四五六日])\\s*$');
  const rePeriod = new RegExp(cfg.period_token_regex || '^\\s*(\\d{1,2}(?:-\\d{1,2}|(?:,\\d{1,2})*))\\s*$');

  const norm = (s)=>{
    s = s ?? '';
    if (cfg.normalize?.fullwidth_to_halfwidth) s = fw2hw(s);
    if (cfg.normalize?.replace_cn_comma_to_en) s = s.replaceAll('，', ',').replaceAll('、', ',');
    if (cfg.normalize?.replace_cn_dash_to_en)  s = s.replace(/[－—–‒―]/g, '-');
    return s.trim();
  };

  const rawLines = fileText.split(/\r?\n/).map(l=>norm(l)); // 保留空行判断
  const out = []; const maxIdx = getMaxPeriodIdx();
  let i = 0, day = 0; const N = rawLines.length;

  while (i < N){
    const line = rawLines[i];

    // 新的一天
    const mDay = (line||'').match(reDay);
    if (mDay){ day = dayMap[mDay[1]] || 0; i++; continue; }

    if (!day){ i++; continue; } // 未指定星期

    // 跳过空行
    if (!line){ i++; continue; }

    // 如果遇到新“星期X”行则下一轮处理
    if (reDay.test(line)){ day = dayMap[line] || 0; i++; continue; }

    // 期望：节次
    const mPer = line.match(rePeriod);
    if (!mPer){ i++; continue; }
    const periods = periodsToArray(mPer[1], maxIdx); i++;

    // 课程名（跳空行）
    while (i<N && !rawLines[i]) i++;
    const name = (i<N) ? rawLines[i] : ''; i++;
    if (!name) continue;

    // 详情（可空；且不能是新星期或节次）
    while (i<N && !rawLines[i]) i++;
    let detail = '';
    if (i<N && !reDay.test(rawLines[i]) && !rePeriod.test(rawLines[i])){
      detail = rawLines[i]; i++;
    }

    let teacher='', weeks='01-16', week_type='all', room='';
    const mDet = (detail||'').match(/^(?<teacher>[^\[]+?)\[(?<weeks>[^\]]+)\]\s*周(?<room>.*)$/);
    if (mDet && mDet.groups){
      teacher = (mDet.groups.teacher||'').trim();
      weeks   = parseWeeksToString(mDet.groups.weeks||'') || '01-16';
      room    = (mDet.groups.room||'').trim();
    } else if (detail) {
      room = detail.trim();
    }
    for (const r of (cfg.week_type_rules||[])) {
      if ((detail||'').includes(r.contains)) { week_type = r.week_type || 'all'; break; }
    }

    if (name && periods.length && day>=1 && day<=7){
      out.push({ name, teacher, room, day, periods, weeks, week_type, note:'' });
    }
  }

  // 合并同课
  const keyOf = (c)=> [c.day, c.name, c.teacher, c.weeks, c.week_type, c.room].join('||');
  const map = new Map();
  for (const c of out){
    const k = keyOf(c);
    if (!map.has(k)) map.set(k, { ...c, periods: [] });
    map.get(k).periods.push(...c.periods);
  }
  const merged = [];
  for (const v of map.values()){
    v.periods = Array.from(new Set(v.periods)).sort((a,b)=>a-b);
    merged.push(v);
  }
  return merged;
}

// ========= AI 解析（DeepSeek）=========
// 说明：为避免默认触发“处理中…”，只有当选中算法 cfg.ai === true 时才会调用本函数。
const DS_CONF = {
  // 建议：将 endpoint 反代到你自己域名，避免浏览器跨域与暴露；此处为直连示例：
  endpoint: 'https://api.deepseek.com/chat/completions',
  apiKey: '',            // ← 在此填入你的 API Key（前端暴露有风险，生产环境请改为后端代理）
  model: 'deepseek-chat',
  timeoutMs: 90000       // 超时 90s（避免 60s 超时问题）
};
function withTimeout(promise, ms){
  const ctrl = new AbortController();
  const t = setTimeout(()=>ctrl.abort(), ms);
  return {
    run: (input)=>Promise.race([
      promise(input, ctrl.signal),
      new Promise((_,rej)=>setTimeout(()=>rej(new Error('请求超时')), ms+50))
    ]).finally(()=>clearTimeout(t)),
    signal: ctrl.signal
  };
}
function extractJsonBlock(s){
  // 从大段文本中提取最外层 JSON
  const first = s.indexOf('{'); const last = s.lastIndexOf('}');
  if (first>=0 && last>first) return s.slice(first, last+1);
  return s;
}
async function aiParseCoursesFromText(fileText, algo){
  if (!DS_CONF.apiKey) throw new Error('未配置 DeepSeek API Key');
  const cfg = algo.csv || {};
  const maxIdx = getMaxPeriodIdx();

  // 拼提示词（algo.ai_prompt 可覆盖/追加）
  const systemPrompt = (cfg.ai_prompt && cfg.ai_prompt.trim())
    ? cfg.ai_prompt.trim()
    : `你是课表解析助手。请把下面完整文本解析成 {"courses":[{...}]}，仅输出严格 JSON，无解释。
字段：name,teacher,room,day(1-7),periods([1,2]),weeks("01-16"),week_type(all|odd|even),note。`;

  const userContent =
`【时段上限】最多 ${Number.isFinite(maxIdx)?maxIdx:'N/A'} 节（超出请忽略）
【全文】\n${fileText}`;

  const reqBody = {
    model: DS_CONF.model,
    messages: [
      { role: 'system', content: systemPrompt },
      { role: 'user',   content: userContent }
    ],
    temperature: 0.0,
    stream: false
  };

  const doFetch = async (_unused, signal)=>{
    const res = await fetch(DS_CONF.endpoint, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${DS_CONF.apiKey}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(reqBody),
      signal
    });
    if (!res.ok){
      const t = await res.text().catch(()=>String(res.status));
      throw new Error(`AI 接口错误 ${res.status}: ${t.slice(0,200)}`);
    }
    const data = await res.json();
    const content = data?.choices?.[0]?.message?.content ?? '';
    let jsonText = content;
    try { jsonText = extractJsonBlock(content); } catch {}
    let parsed;
    try { parsed = JSON.parse(jsonText); }
    catch(e){ throw new Error('AI 输出不是合法 JSON'); }

    let list = Array.isArray(parsed?.courses) ? parsed.courses : [];
    // 规整 periods / weeks / 限制上限
    list = list.map(c=>{
      const periods = periodsToArray((Array.isArray(c.periods)?c.periods.join(','):String(c.periods||'')), maxIdx);
      return {
        name: String(c.name||'').trim(),
        teacher: String(c.teacher||'').trim(),
        room: String(c.room||'').trim(),
        day: ensureInt(c.day, 1),
        periods,
        weeks: parseWeeksToString(String(c.weeks||'01-16')),
        week_type: (String(c.week_type||'all').toLowerCase()==='odd'?'odd':(String(c.week_type||'all').toLowerCase()==='even'?'even':'all')),
        note: String(c.note||'').trim()
      };
    });
    return list;
  };

  const { run } = withTimeout(doFetch, DS_CONF.timeoutMs);
  return await run();
}

// ========= 触发“学校课表 CSV/TXT 导入”按钮 =========
$('#btn_parse_school_csv')?.addEventListener('click', async ()=>{
  try{
    if (!state.timeslots.length){
      alert('请先在步骤②导入/设置“时段”，再导入学校 CSV/TXT。');
      return;
    }
    const algoId = $('#csv_algo')?.value;
    const algo = csvAlgoList.find(a=>a.id===algoId) || csvAlgoList[0];
    const f = $('#school_course_csv')?.files?.[0];
    if (!f){ alert('请选择 CSV/TXT 文件'); return; }
    const text = await readFileText(f);

    let parsed = await parseSchoolMatrixCSVToCourses(text, algo);
    // 统一过滤到最大节次（双重保险）
    const maxIdx = getMaxPeriodIdx();
    parsed = parsed.map(c=>({ ...c, periods: periodsToArray((c.periods||[]).join(','), maxIdx) }));

    if (!parsed.length){
      alert('未解析到课程，请检查文件与算法是否匹配。');
      return;
    }
    state.courses = parsed;
    renderCourses();
    alert(`已解析 ${parsed.length} 门课程到下表。`);
  }catch(err){
    Processing.hide(); // 只有 AI 模式会显示；这里安全调用
    alert('解析失败：' + (err?.message || err));
  }
});
// ====== 时区下拉：自动填充所有 IANA 时区（含常用分组）======
function populateTimezones() {
  const sel = $('#tz');
  if (!sel) return;

  sel.innerHTML = '';

  // 常用置顶，可按需增删
  const common = [
    'Asia/Shanghai', 'Asia/Harbin', // Asia/Harbin 兼容选项
    'UTC', 'America/New_York', 'Europe/London', 'Europe/Berlin',
    'Asia/Hong_Kong', 'Asia/Singapore', 'Asia/Seoul'
  ];

  // 客户端本机时区
  let clientTz = '';
  try { clientTz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch {}

  // 1) 常用分组
  const ogCommon = document.createElement('optgroup');
  ogCommon.label = '常用';
  for (const tz of common) {
    const opt = document.createElement('option');
    opt.value = tz;
    opt.textContent = tz + (tz === clientTz ? '(当前时区)' : '');
    ogCommon.appendChild(opt);
  }
  sel.appendChild(ogCommon);

  // 2) 全部 IANA 列表（浏览器支持则完整；否则回退一份精简列表）
  let all = [];
  if (typeof Intl.supportedValuesOf === 'function') {
    try { all = Intl.supportedValuesOf('timeZone'); } catch {}
  }
  if (!all || !all.length) {
    // 回退：精简常见时区（如果浏览器不支持 supportedValuesOf）
    all = Array.from(new Set([
      ...common,
      'Africa/Abidjan','Africa/Cairo','Africa/Johannesburg',
      'America/Chicago','America/Los_Angeles','America/Mexico_City','America/Sao_Paulo','America/Toronto',
      'Asia/Bangkok','Asia/Dubai','Asia/Jakarta','Asia/Kolkata','Asia/Kuala_Lumpur','Asia/Taipei','Asia/Ulaanbaatar',
      'Australia/Melbourne','Australia/Sydney',
      'Europe/Amsterdam','Europe/Athens','Europe/Madrid','Europe/Moscow','Europe/Paris','Europe/Rome',
      'Pacific/Auckland','Pacific/Honolulu'
    ]));
  }

  const ogAll = document.createElement('optgroup');
  ogAll.label = '全部时区';
  for (const tz of all) {
    if (common.includes(tz)) continue; // 避免重复
    const opt = document.createElement('option');
    opt.value = tz;
    opt.textContent = tz;
    ogAll.appendChild(opt);
  }
  sel.appendChild(ogAll);

  // 默认选中：优先本机，其次 Asia/Shanghai
  const canUseClient = all.includes(clientTz) || common.includes(clientTz);
  sel.value = canUseClient ? clientTz : 'Asia/Shanghai';

  // 同步到 state
  state.tz = sel.value;
}

// 调用与变更同步
window.addEventListener('load', populateTimezones);
$('#tz')?.addEventListener('change', e => { state.tz = e.target.value; });

</script>
</body>
</html>
