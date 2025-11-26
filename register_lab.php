<?php
// register_lab.php — 实验课表注册向导（绑定主课表）
// PHP 8.2+ / 无 Composer 依赖 / 不使用 SimpleXLS
// 数据表：user_lab_schedule（LONGTEXT JSON），lab_uploads（记录CSV解析，可选）
// 依赖：db.php (DB_DSN / DB_USER / DB_PASS)，college_lab.json（时段模板）

declare(strict_types=1);
mb_internal_encoding('UTF-8');
session_start();

require_once __DIR__ . '/db.php';

/* ============== 基础工具 ============== */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function json_out($arr, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============== 认证与主课表检查 ============== */
function is_logged_in(): bool { return isset($_SESSION['uid']) && is_numeric($_SESSION['uid']); }
function current_uid(): ?int { return is_logged_in() ? (int)$_SESSION['uid'] : null; }
function try_get_main_schedule(int $uid): array {
    $stmt = db()->prepare('SELECT data FROM user_schedule WHERE user_id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row) return [];
    $json = $row['data'] ?? '{}';
    $obj  = json_decode((string)$json, true);
    return is_array($obj) ? $obj : [];
}

/* ============== 读取时段模板 ============== */
function load_college_lab_templates(): array {
    $file = __DIR__ . '/college_lab.json';
    if (!is_file($file)) return [];
    $raw = file_get_contents($file);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

/* ============== CSV 解析（UTF-8/GBK 容错） ============== */
function detect_encoding(string $str): string {
    $enc = mb_detect_encoding($str, ['UTF-8','GB18030','GBK','CP936','BIG5','ISO-8859-1'], true);
    return $enc ?: 'UTF-8';
}
function csv_to_rows_from_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('文件上传失败（code='.$file['error'].'）');
    }
    $tmp = $file['tmp_name'];
    $raw = file_get_contents($tmp);
    if ($raw === false) throw new RuntimeException('无法读取上传文件');
    $enc = detect_encoding($raw);
    if ($enc !== 'UTF-8') $raw = mb_convert_encoding($raw, 'UTF-8', $enc);
    // 统一换行
    $raw = str_replace(["\r\n","\r"], "\n", $raw);
    $lines = explode("\n", $raw);

    $rows = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        // 用内存流给 fgetcsv 更稳健的引号处理
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, $line);
        rewind($fp);
        $arr = fgetcsv($fp);
        fclose($fp);
        if ($arr === false) continue;
        // 去除末尾空白列
        while (!empty($arr) && trim((string)end($arr)) === '') array_pop($arr);
        if (!empty($arr)) $rows[] = array_map(static fn($v)=>trim((string)$v), $arr);
    }
    return $rows;
}

/* ============== API 区域 ============== */
if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];

    // 登录（页内表单，无跳转）
    if ($api === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $uid = $_POST['uid'] ?? '';
        $pin = $_POST['pin'] ?? '';
        if (!preg_match('/^\d{4,6}$/', (string)$uid)) json_out(['ok'=>false,'error'=>'ID 必须为 4-6 位数字'], 400);
        if (!preg_match('/^\d{4}$/', (string)$pin))  json_out(['ok'=>false,'error'=>'密码必须为 4 位数字'], 400);
        $stmt = db()->prepare('SELECT user_id, pin FROM user_accounts WHERE user_id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        if (!$row || (string)$row['pin'] !== (string)$pin) json_out(['ok'=>false,'error'=>'账号或密码错误'], 403);
        $_SESSION['uid'] = (int)$row['user_id'];
        json_out(['ok'=>true]);
    }

    if ($api === 'logout') { session_destroy(); json_out(['ok'=>true]); }

    // 模板列表（实时搜索，支持 name/school/id）
    if ($api === 'college_list') {
        $q = trim((string)($_GET['q'] ?? ''));
        $q_lc = mb_strtolower($q);
        $all = load_college_lab_templates();
        $list = [];
        foreach ($all as $tpl) {
            $name   = (string)($tpl['name']   ?? '');
            $school = (string)($tpl['school'] ?? '');
            $id     = (string)($tpl['id']     ?? '');
            // 统一到小写，允许任意子串匹配（例如：输入 'h' 或 't' 也能匹配 'hit' / 'default12'）
            $hay = mb_strtolower($name . ' ' . $school . ' ' . $id);
            if ($q === '' || mb_strpos($hay, $q_lc) !== false) {
                $list[] = $tpl;
            }
        }
        json_out(['ok'=>true,'list'=>$list]);
    }

    // 解析 CSV 上传（并可记录 lab_uploads）
    if ($api === 'preview_csv' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $uid = current_uid();
        if (!$uid) json_out(['ok'=>false,'error'=>'未登录'], 401);

        try {
            if (!isset($_FILES['file'])) throw new RuntimeException('未收到文件');
            $rows = csv_to_rows_from_upload($_FILES['file']);
            if (!$rows) throw new RuntimeException('CSV 内容为空');

            // 头行映射：允许中英混合列名
            $header = array_map('trim', $rows[0]);
            $map = []; // 逻辑字段 -> 列索引
            $norm = static function(string $s): string {
                $s = trim(mb_strtolower($s));
                $s = str_replace(['（','）','：',':',' '], ['(',')',':',':',''], $s);
                return $s;
            };
            $aliases = [
                'name'    => ['课程','课程名','name','课程名称','课名'],
                'teacher' => ['老师','教师','teacher','授课教师'],
                'room'    => ['教室','地点','room','位置','上课地点'],
                'day'     => ['星期','周几','day','weekday'],
                'periods' => ['节次','节','periods','节段'],
                'weeks'   => ['周次','周数','weeks'],
                'week_type'=>['单双周','单双','week_type'],
            ];
            foreach ($header as $i=>$hname) {
                $h = $norm($hname);
                foreach ($aliases as $key=>$cands) {
                    foreach ($cands as $cand) {
                        if ($h === $norm($cand)) { $map[$key] = $i; break 2; }
                    }
                }
            }
            foreach (['name','day','periods'] as $must) {
                if (!isset($map[$must])) throw new RuntimeException('缺少必要列：'.$must);
            }

            $courses = [];
            for ($r=1; $r<count($rows); $r++) {
                $row = $rows[$r];
                $get = static fn($k)=> isset($map[$k], $row[$map[$k]]) ? trim((string)$row[$map[$k]]) : '';
                $name = $get('name');
                $day  = (int)$get('day');
                $per  = $get('periods');
                if ($name === '' || $day < 1 || $day > 7 || $per === '') continue;

                // periods: "3,4" 或 "3-4"
                $ps = [];
                foreach (preg_split('/\s*,\s*/', str_replace('，', ',', $per)) as $seg) {
                    if ($seg === '') continue;
                    if (preg_match('/^(\d{1,2})-(\d{1,2})$/', $seg, $m)) {
                        $a=(int)$m[1]; $b=(int)$m[2]; if ($a>$b){[$a,$b]=[$b,$a];}
                        for($i=$a;$i<=$b;$i++) $ps[]=$i;
                    } elseif (preg_match('/^\d{1,2}$/', $seg)) {
                        $ps[] = (int)$seg;
                    }
                }
                sort($ps); $ps = array_values(array_unique($ps));
                if (!$ps) continue;

                $courses[] = [
                    'name'      => $name,
                    'teacher'   => $get('teacher'),
                    'room'      => $get('room'),
                    'day'       => $day,
                    'periods'   => $ps,
                    'weeks'     => $get('weeks'),
                    'week_type' => strtolower($get('week_type') ?: 'all'), // all/odd/even
                ];
            }
            if (!$courses) throw new RuntimeException('未解析出有效课程条目');

            // 记录 lab_uploads（可选）
            try {
                $ins = db()->prepare('INSERT INTO lab_uploads (user_id, filename, mimetype, size_bytes, parsed_json) VALUES (?, ?, ?, ?, ?)');
                $ins->execute([
                    (int)$uid,
                    (string)($_FILES['file']['name'] ?? ''),
                    (string)($_FILES['file']['type'] ?? ''),
                    (int)($_FILES['file']['size'] ?? 0),
                    json_encode($courses, JSON_UNESCAPED_UNICODE),
                ]);
            } catch (Throwable) { /* 可忽略，无此表时不报错中断 */ }

            json_out(['ok'=>true,'courses'=>$courses]);
        } catch (Throwable $e) {
            json_out(['ok'=>false,'error'=>$e->getMessage()], 400);
        }
    }

    // 文本粘贴解析（CSV 文本）
    if ($api === 'preview_text' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $uid = current_uid();
        if (!$uid) json_out(['ok'=>false,'error'=>'未登录'], 401);
        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $text = (string)($payload['text'] ?? '');
        if (trim($text) === '') json_out(['ok'=>false,'error'=>'内容为空'], 400);

        $raw = str_replace(["\r\n","\r"], "\n", $text);
        $lines = explode("\n", $raw);
        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') continue;
            $fp = fopen('php://memory', 'r+'); fwrite($fp, $line); rewind($fp);
            $arr = fgetcsv($fp); fclose($fp);
            if ($arr === false) continue;
            while (!empty($arr) && trim((string)end($arr)) === '') array_pop($arr);
            if (!empty($arr)) $rows[] = array_map(static fn($v)=>trim((string)$v), $arr);
        }
        if (!$rows) json_out(['ok'=>false,'error'=>'无法解析文本为 CSV'], 400);

        $header = array_map('trim', $rows[0]);
        $norm = static fn($s)=>strtolower(trim(str_replace(['（','）','：',':',' '], ['(',')',':',':',''], $s)));
        $aliases = [
            'name'    => ['课程','课程名','name','课程名称','课名'],
            'teacher' => ['老师','教师','teacher','授课教师'],
            'room'    => ['教室','地点','room','位置','上课地点'],
            'day'     => ['星期','周几','day','weekday'],
            'periods' => ['节次','节','periods','节段'],
            'weeks'   => ['周次','周数','weeks'],
            'week_type'=>['单双周','单双','week_type'],
        ];
        $map = [];
        foreach ($header as $i=>$hname) {
            $h = $norm($hname);
            foreach ($aliases as $key=>$cands) {
                foreach ($cands as $cand) {
                    if ($h === $norm($cand)) { $map[$key] = $i; break 2; }
                }
            }
        }
        foreach (['name','day','periods'] as $must) {
            if (!isset($map[$must])) json_out(['ok'=>false,'error'=>'缺少必要列：'.$must], 400);
        }

        $courses=[];
        for ($r=1; $r<count($rows); $r++) {
            $row=$rows[$r];
            $get=static fn($k)=> isset($map[$k], $row[$map[$k]]) ? trim((string)$row[$map[$k]]) : '';
            $name=$get('name'); $day=(int)$get('day'); $per=$get('periods');
            if ($name==='' || $day<1 || $day>7 || $per==='') continue;

            $ps=[];
            foreach (preg_split('/\s*,\s*/', str_replace('，', ',', $per)) as $seg) {
                if ($seg==='') continue;
                if (preg_match('/^(\d{1,2})-(\d{1,2})$/', $seg, $m)) {
                    $a=(int)$m[1]; $b=(int)$m[2]; if ($a>$b){[$a,$b]=[$b,$a];}
                    for($i=$a;$i<=$b;$i++) $ps[]=$i;
                } elseif (preg_match('/^\d{1,2}$/', $seg)) {
                    $ps[]=(int)$seg;
                }
            }
            sort($ps); $ps=array_values(array_unique($ps)); if (!$ps) continue;

            $courses[] = [
                'name'=>$name,'teacher'=>$get('teacher'),'room'=>$get('room'),
                'day'=>$day,'periods'=>$ps,'weeks'=>$get('weeks'),
                'week_type'=> strtolower($get('week_type') ?: 'all'),
            ];
        }
        if (!$courses) json_out(['ok'=>false,'error'=>'未解析出有效课程条目'], 400);
        json_out(['ok'=>true,'courses'=>$courses]);
    }

    // 保存到 user_lab_schedule（存在则覆盖）
    if ($api === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $uid = current_uid();
        if (!$uid) json_out(['ok'=>false,'error'=>'未登录'], 401);

        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $start_date  = (string)($payload['start_date'] ?? '');
        $tz          = (string)($payload['tz'] ?? 'Asia/Shanghai');
        $enabled_days= $payload['enabled_days'] ?? [];
        $timeslots   = $payload['timeslots'] ?? [];
        $courses     = $payload['courses'] ?? [];
        // 可选：若你在前端传了 tz_sync，这里也可接收但目前不入库（仅保留 tz）
        // $tz_sync     = !empty($payload['tz_sync']);

        // 基础校验
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) json_out(['ok'=>false,'error'=>'开学日期无效（YYYY-MM-DD）'], 400);
        try { new DateTimeZone($tz); } catch(Throwable) { json_out(['ok'=>false,'error'=>'时区无效'], 400); }
        if (!is_array($enabled_days) || empty($enabled_days)) $enabled_days = [1,2,3,4,5,6,7];

        // timeslots 必须含 idx/start/end
        $okSlots = [];
        foreach ($timeslots as $s) {
            $idx = (int)($s['idx'] ?? 0);
            $st  = (string)($s['start'] ?? '');
            $et  = (string)($s['end'] ?? '');
            if ($idx<=0 || !preg_match('/^\d{2}:\d{2}$/', $st) || !preg_match('/^\d{2}:\d{2}$/', $et)) continue;
            $okSlots[] = ['idx'=>$idx,'start'=>$st,'end'=>$et];
        }
        if (!$okSlots) json_out(['ok'=>false,'error'=>'请先选择或设置时段'], 400);

        // courses 基本清洗（周次 weeks 不强校验）
        $okCourses = [];
        foreach ($courses as $c) {
            $name = trim((string)($c['name'] ?? ''));
            $day  = (int)($c['day'] ?? 0);
            $per  = $c['periods'] ?? [];
            if ($name==='' || $day<1 || $day>7 || !is_array($per) || empty($per)) continue;
            $okCourses[] = [
                'name'=>$name,
                'teacher'=> (string)($c['teacher'] ?? ''),
                'room'=> (string)($c['room'] ?? ''),
                'day'=>$day,
                'periods'=> array_values(array_unique(array_map('intval',$per))),
                'weeks'=> (string)($c['weeks'] ?? ''),
                'week_type'=> strtolower((string)($c['week_type'] ?? 'all')), // all/odd/even
            ];
        }
        if (!$okCourses) json_out(['ok'=>false,'error'=>'请至少添加一门课程'], 400);

        $doc = [
            'source'       => 'lab',
            'start_date'   => $start_date,
            'tz'           => $tz,
            'enabled_days' => array_values(array_unique(array_map('intval',$enabled_days))),
            'timeslots'    => $okSlots,
            'courses'      => $okCourses,
            'updated_at_ts'=> time(),
        ];
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE);

        $sql = 'INSERT INTO user_lab_schedule (user_id, data) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = CURRENT_TIMESTAMP';
        db()->prepare($sql)->execute([(int)$uid, $json]);
        json_out(['ok'=>true]);
    }

    // 拉取当前用户信息（用于首屏预填）
    if ($api === 'me') {
        $uid = current_uid();
        if (!$uid) json_out(['ok'=>false,'error'=>'未登录'], 401);
        $main = try_get_main_schedule($uid);
        json_out(['ok'=>true,'uid'=>$uid,'main'=>$main]);
    }

    json_out(['ok'=>false,'error'=>'unknown api'], 404);
}

/* ============== 首屏数据（仅用于 UI 文案/状态） ============== */
$logged = is_logged_in();
$uid    = current_uid();
$hasMain = false;
$mainStartDate = '';
if ($logged) {
    $main = try_get_main_schedule($uid);
    if ($main) {
        $hasMain = true;
        $mainStartDate = (string)($main['start_date'] ?? '');
    }
}
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="auto">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>实验课表注册</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{ background:#f6f7fb; }
  .card{ border-radius:1rem; }
  .step{ display:none; }
  .step.active{ display:block; }
  .template-item{ cursor:pointer; }
  .template-item.active{ border-color:#0d6efd; box-shadow:0 0 0 .15rem rgba(13,110,253,.15); }
  .table-sm td, .table-sm th { padding: .35rem .5rem; }
  .capsule{display:inline-flex;flex-direction:column;gap:.15rem;border:1px solid #cbd5e1;background:#f8fafc;border-radius:14px;padding:.28rem .6rem;}
  .capsule .cap-row{display:inline-flex;gap:.4rem;align-items:center;}
  .capsule .cap-dot{width:.6rem;height:.6rem;border-radius:50%;background:#94a3b8;border:1px solid rgba(0,0,0,.06);}
  .capsule .cap-text{font-weight:600;max-width:18ch;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;}
  #tz_select { max-height: 260px; overflow-y: auto; }
</style>
</head>
<body>
<div class="container py-4">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">实验课表注册</h3>
    <div class="d-flex align-items-center gap-2">
      <?php if ($logged): ?>
        <span class="badge text-bg-success">已登录：<?=h((string)$uid)?></span>
        <button class="btn btn-outline-secondary btn-sm" onclick="logout()">退出</button>
      <?php else: ?>
        <span class="badge text-bg-warning">未登录</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if(!$logged): ?>
    <!-- 未登录：页内登录卡片 -->
    <div class="row justify-content-center mb-4">
      <div class="col-12 col-md-7 col-lg-5">
        <div class="card shadow-sm">
          <div class="card-body">
            <h5 class="card-title text-center mb-3">登录以开始注册</h5>
            <form id="loginForm" onsubmit="return false;" class="vstack gap-3">
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
            <div class="text-muted small mt-2">* 登录成功后，本页将自动启用注册步骤。</div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if($logged && !$hasMain): ?>
    <!-- 已登录但无主课表 -->
    <div class="alert alert-warning d-flex align-items-center justify-content-between">
      <div>检测到你的主课表尚未注册。请先在 <b>主课表注册</b> 完成设置后再回来绑定实验课表。</div>
      <a class="btn btn-sm btn-warning" href="register.php">前往主课表注册</a>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">

      <!-- 进度条 -->
      <div class="progress mb-3">
        <div id="wizardProg" class="progress-bar" style="width:20%">步骤 1/5</div>
      </div>

      <!-- Step 1 -->
      <div class="step active" id="step1">
        <div class="vstack gap-3">
          <div class="alert <?= ($logged && $hasMain)?'alert-success':'alert-secondary' ?>">
            <div>登录状态：<?= $logged ? '<b>已登录</b>' : '未登录' ?>，
                主课表：<?= $hasMain ? '<b>已存在</b>' : '未检测到' ?>。</div>
            <div class="small text-muted">* 必须“已登录 + 已有主课表”才可继续下一步。</div>
          </div>

          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">开学日期</label>
              <input type="date" class="form-control" id="start_date" value="<?= h($mainStartDate ?: '') ?>">
              <?php if($mainStartDate): ?>
                <div class="form-text">已从你的主课表预填：<?= h($mainStartDate) ?></div>
              <?php else: ?>
                <div class="form-text">未在主课表找到开学日期，请手动选择。</div>
              <?php endif; ?>
            </div>

            <!-- 分区时区选择 + 同步 -->
            <div class="col-12 col-md-4">
              <label class="form-label d-flex align-items-center justify-content-between">
                <span>时区</span>
                <span class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="tz_sync_switch">
                  <label class="form-check-label small" for="tz_sync_switch">跟随主课表</label>
                </span>
              </label>
              <select class="form-select" id="tz_select"></select>
              <div class="form-text">分区：<b>同步 / 推荐 / IANA</b>。勾选“跟随主课表”时，时区将与主课表保持一致并灰显。</div>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">启用星期</label>
              <div class="d-flex flex-wrap gap-2" id="daysWrap">
                <?php for($i=1;$i<=7;$i++): ?>
                  <label class="form-check">
                    <input class="form-check-input" type="checkbox" value="<?=$i?>" checked> 周<?=$i===7?'日':$i?>
                  </label>
                <?php endfor; ?>
              </div>
            </div>
          </div>

          <div class="d-flex justify-content-end">
            <button class="btn btn-primary" onclick="goStep(2)" <?= (!($logged && $hasMain))?'disabled':'' ?>>下一步</button>
          </div>
        </div>
      </div>

      <!-- Step 2 -->
      <div class="step" id="step2">
        <div class="vstack gap-3">
          <div class="d-flex align-items-center gap-2">
            <input class="form-control" id="tplSearch" placeholder="搜索 模板名/学校/id">
          </div>
          <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3" id="tplList"></div>

          <div class="card border-0">
            <div class="card-body p-0">
              <h6 class="mb-2">已选择的时段（可编辑）</h6>
              <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle">
                  <thead class="table-light">
                    <tr><th style="width:80px">序号</th><th>开始</th><th>结束</th><th style="width:120px">操作</th></tr>
                  </thead>
                  <tbody id="slotTbody"></tbody>
                </table>
              </div>
              <div class="d-flex gap-2">
                <button class="btn btn-outline-success btn-sm" onclick="addSlot()">新增时段</button>
                <button class="btn btn-outline-secondary btn-sm" onclick="sortSlots()">按时间排序</button>
                <button class="btn btn-outline-danger btn-sm" onclick="clearSlots()">清空</button>
              </div>
            </div>
          </div>

          <div class="d-flex justify-content-between">
            <button class="btn btn-outline-secondary" onclick="goStep(1)">上一步</button>
            <button class="btn btn-primary" onclick="goStep(3)">下一步</button>
          </div>
        </div>
      </div>

      <!-- Step 3 -->
      <div class="step" id="step3">
        <div class="vstack gap-3">
          <div class="row g-3">
            <div class="col-12 col-lg-4">
              <div class="card h-100">
                <div class="card-body">
                  <h6>方式 A：上传 CSV</h6>
                  <form id="csvForm" onsubmit="return false;">
                    <input type="file" class="form-control" name="file" accept=".csv">
                    <div class="form-text">需包含：课程/星期/节次（必填）；可含 教师/教室/周次/单双周。</div>
                    <button class="btn btn-sm btn-primary mt-2" onclick="uploadCSV()">解析预览</button>
                  </form>
                </div>
              </div>
            </div>
            <div class="col-12 col-lg-4">
              <div class="card h-100">
                <div class="card-body">
                  <h6>方式 B：粘贴 CSV 文本</h6>
                  <textarea id="csvText" class="form-control" rows="7" placeholder="第一行为表头，例如：
课程,教师,教室,星期,节次,周次,单双周
计算机网络C,聂兰顺,正心527,3,1-2,1-16,all"></textarea>
                  <button class="btn btn-sm btn-primary mt-2" onclick="parseText()">解析预览</button>
                </div>
              </div>
            </div>
            <div class="col-12 col-lg-4">
              <div class="card h-100">
                <div class="card-body">
                  <h6>方式 C：手动添加</h6>
                  <div class="vstack gap-2">
                    <input class="form-control form-control-sm" id="m_name" placeholder="课程名">
                    <input class="form-control form-control-sm" id="m_teacher" placeholder="教师">
                    <input class="form-control form-control-sm" id="m_room" placeholder="教室">
                    <select class="form-select form-select-sm" id="m_day">
                      <option value="">星期</option>
                      <?php for($i=1;$i<=7;$i++): ?>
                        <option value="<?=$i?>">星期<?=$i===7?'日':$i?></option>
                      <?php endfor; ?>
                    </select>
                    <input class="form-control form-control-sm" id="m_periods" placeholder="节次（如 3,4 或 3-4）">
                    <input class="form-control form-control-sm" id="m_weeks" placeholder="周次（如 1-16,8,10）">
                    <select class="form-select form-select-sm" id="m_weektype">
                      <option value="all">全部</option>
                      <option value="odd">单周</option>
                      <option value="even">双周</option>
                    </select>
                    <button class="btn btn-sm btn-success" onclick="addManual()">添加到列表</button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-body">
              <h6 class="mb-2">课程列表</h6>
              <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle">
                  <thead class="table-light">
                    <tr>
                      <th>课程</th><th>教师</th><th>教室</th><th>星期</th><th>节次</th><th>周次</th><th>单双周</th><th style="width:100px">操作</th>
                    </tr>
                  </thead>
                  <tbody id="courseTbody"></tbody>
                </table>
              </div>
              <div class="text-muted small">* 点击“核对 & 保存”前请确认列表无误。</div>
            </div>
          </div>

          <div class="d-flex justify-content-between">
            <button class="btn btn-outline-secondary" onclick="goStep(2)">上一步</button>
            <button class="btn btn-primary" onclick="goStep(4)">下一步</button>
          </div>
        </div>
      </div>

      <!-- Step 4 -->
      <div class="step" id="step4">
        <div class="vstack gap-3">
          <div class="alert alert-info">请再次核对以下关键信息，确认无误后点击保存。</div>

          <div>
            <h6>基础信息</h6>
            <div>开学日期：<b id="chk_start"></b>；时区：<b id="chk_tz"></b>；启用星期：<b id="chk_days"></b></div>
          </div>

          <div>
            <h6>时段（前 8 条示例显示）</h6>
            <div id="chk_slots"></div>
          </div>

          <div>
            <h6>课程统计</h6>
            <div id="chk_stats"></div>
          </div>

          <div class="d-flex justify-content-between">
            <button class="btn btn-outline-secondary" onclick="goStep(3)">上一步</button>
            <button class="btn btn-success" onclick="saveAll()">保存</button>
          </div>
        </div>
      </div>

      <!-- Step 5 -->
      <div class="step" id="step5">
        <div class="text-center py-5">
          <h4 class="mb-3">实验课表已保存！</h4>
          <p class="text-muted">你可以随时返回首页查看课表。</p>
          <a class="btn btn-primary" href="index.php">跳转到 index</a>
        </div>
      </div>

    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ===== 全局状态 ===== */
let STATE = {
  start_date: <?= json_encode($mainStartDate ?: '') ?>,
  tz: 'Asia/Shanghai',
  tz_sync: false,      // 是否跟随主课表时区
  enabled_days: [1,2,3,4,5,6,7],
  timeslots: [],       // [{idx,start,end}]
  courses: []          // [{name,teacher,room,day,periods,weeks,week_type}]
};

const TOTAL_STEPS = 5;
function updateWizardProgress(n){
  const bar = document.getElementById('wizardProg');
  if (!bar) return;
  const pct = Math.max(1, Math.min(TOTAL_STEPS, n)) / TOTAL_STEPS * 100;
  bar.style.width = pct + '%';
  bar.textContent = `步骤 ${n}/${TOTAL_STEPS}`;
}

/* ===== 步骤切换 ===== */
function goStep(n){
  // 限制：必须登录且有主课表才允许 > 1
  const must = <?= ($logged && $hasMain) ? 'true':'false' ?>;
  if (n > 1 && !must) { alert('请先登录并完成主课表注册'); return; }

  // Step1 -> 读取控件到 STATE
  if (n >= 2){
    STATE.start_date = document.getElementById('start_date').value.trim();

    // 时区：如果同步，tz 直接取 mainTz；否则取选择器值
    (() => {
      const sel = document.getElementById('tz_select');
      const sw  = document.getElementById('tz_sync_switch');
      const mainTz = window.__MAIN_TZ__ || 'Asia/Shanghai';
      if (sw && sw.checked) {
        STATE.tz_sync = true;
        STATE.tz = mainTz;
      } else if (sel) {
        STATE.tz_sync = false;
        const v = sel.value || 'Asia/Shanghai';
        STATE.tz = v.startsWith('__SYNC__:') ? mainTz : v;
      }
    })();

    STATE.enabled_days = Array.from(document.querySelectorAll('#daysWrap input:checked')).map(i=>parseInt(i.value,10));
    if (!/^\d{4}-\d{2}-\d{2}$/.test(STATE.start_date)) { alert('请设置有效的开学日期'); return; }
    if (!STATE.enabled_days.length) { alert('请至少启用一个星期'); return; }
  }

  // Step2 进入时加载一次模板（空查询）
  if (n === 2) ensureTemplatesLoadedOnce();

  // Step2 -> 3: 必须有时段
  if (n === 3) {
    if (!STATE.timeslots || !STATE.timeslots.length) { alert('请至少添加一个时段'); return; }
  }

  // Step3 -> 4: 必须有课程
  if (n === 4) {
    if (!STATE.courses || !STATE.courses.length) { alert('请至少添加一门课程'); return; }
  }

  // Step4 展示核对信息
  if (n === 4){
    document.getElementById('chk_start').textContent = STATE.start_date;
    document.getElementById('chk_tz').textContent = STATE.tz_sync ? (STATE.tz + '（跟随主课表）') : STATE.tz;
    document.getElementById('chk_days').textContent = STATE.enabled_days.join(',');
    const slotText = STATE.timeslots.slice().sort((a,b)=> (a.start===b.start ? a.idx-b.idx : (a.start>b.start?1:-1)))
      .slice(0,8).map(s=>`第${s.idx}节 ${s.start}-${s.end}`).join('；');
    document.getElementById('chk_slots').textContent = slotText || '（未设置时段）';
    const byDay = {};
    for (const c of STATE.courses){ byDay[c.day] = (byDay[c.day]||0) + 1; }
    const stats = `共 ${STATE.courses.length} 门；各星期分布：` + (Object.keys(byDay).sort((a,b)=>a-b).map(d=>`星期${d==='7'?'日':d}:${byDay[d]}`).join('，') || '（无）');
    document.getElementById('chk_stats').textContent = stats;
  }

  document.querySelectorAll('.step').forEach(s=>s.classList.remove('active'));
  const box = document.getElementById('step'+n);
  if (box) box.classList.add('active');
  updateWizardProgress(n);
}
updateWizardProgress(1);

/* ===== 登录/退出 ===== */
async function doLogin(){
  const f = document.getElementById('loginForm'); const fd = new FormData(f);
  try{
    const r = await fetch('?api=login', {method:'POST', body:fd});
    const j = await r.json();
    if (!j.ok) throw new Error(j.error||'登录失败');
    location.reload();
  }catch(e){ alert(e.message||'登录失败'); }
}
async function logout(){
  try{ await fetch('?api=logout'); location.reload(); }catch(_){ location.reload(); }
}

/* ===== Step2 实时搜索（带防抖） ===== */
let TPL_LAST_Q = '';
let TPL_DEBOUNCE_TIMER = null;

function triggerSearch(){
  const q = document.getElementById('tplSearch').value.trim();
  doSearchTemplates(q);
}
function doSearchTemplates(q){
  if (q === TPL_LAST_Q) return; // 避免重复请求
  TPL_LAST_Q = q;
  fetch(`?api=college_list&q=${encodeURIComponent(q)}`)
    .then(r=>r.json())
    .then(j=>{
      if(!j.ok) throw new Error(j.error||'加载失败');
      renderTemplateGrid(j.list || []);
    })
    .catch(e=>{
      const grid = document.getElementById('tplList');
      grid.innerHTML = `<div class="text-danger small">加载失败：${e.message||e}</div>`;
    });
}
function renderTemplateGrid(list){
  const grid = document.getElementById('tplList');
  grid.innerHTML = '';
  if (!list.length){
    grid.innerHTML = '<div class="text-muted">未找到匹配模板</div>';
    return;
  }
  list.forEach((tpl)=>{
    const col = document.createElement('div'); col.className='col';
    const card = document.createElement('div'); card.className='card template-item h-100';
    card.onclick = ()=>applyTemplate(tpl, card);
    const body = document.createElement('div'); body.className='card-body';
    const title = document.createElement('h6');
    const idTxt = tpl.id ? ` [${tpl.id}]` : '';
    title.textContent = (tpl.name||'模板') + (tpl.school?`（${tpl.school}）`:'') + idTxt;
    const meta = document.createElement('div'); meta.className='text-muted small';
    meta.textContent = `时段条目：${(tpl.timeslots||[]).length}`;
    body.appendChild(title); body.appendChild(meta); card.appendChild(body); col.appendChild(card);
    grid.appendChild(col);
  });
}
// 输入实时搜索：300ms 防抖
document.getElementById('tplSearch')?.addEventListener('input', ()=>{
  clearTimeout(TPL_DEBOUNCE_TIMER);
  TPL_DEBOUNCE_TIMER = setTimeout(()=>{ triggerSearch(); }, 300);
});
// 进入 Step2 时自动触发一次（空查询）
function ensureTemplatesLoadedOnce(){
  if (TPL_LAST_Q === '') doSearchTemplates('');
}

/* ===== 时段渲染/编辑 ===== */
function applyTemplate(tpl, cardEl){
  document.querySelectorAll('.template-item').forEach(x=>x.classList.remove('active'));
  if (cardEl) cardEl.classList.add('active');
  const slots = (tpl.timeslots||[]).map(s=>({idx:parseInt(s.idx,10)||0, start:String(s.start||''), end:String(s.end||'')}));
  STATE.timeslots = slots.filter(s=>s.idx>0 && /^\d{2}:\d{2}$/.test(s.start) && /^\d{2}:\d{2}$/.test(s.end));
  renderSlots();
}
function renderSlots(){
  const tb = document.getElementById('slotTbody'); tb.innerHTML='';
  STATE.timeslots.slice().sort((a,b)=> a.idx-b.idx).forEach((s,i)=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input class="form-control form-control-sm" value="${s.idx}" oninput="slotEdit(${i}, 'idx', this.value)"></td>
      <td><input class="form-control form-control-sm" value="${s.start}" oninput="slotEdit(${i}, 'start', this.value)"></td>
      <td><input class="form-control form-control-sm" value="${s.end}" oninput="slotEdit(${i}, 'end', this.value)"></td>
      <td>
        <div class="btn-group btn-group-sm">
          <button class="btn btn-outline-secondary" onclick="slotUp(${i})">上移</button>
          <button class="btn btn-outline-secondary" onclick="slotDown(${i})">下移</button>
          <button class="btn btn-outline-danger" onclick="slotDel(${i})">删除</button>
        </div>
      </td>`;
    tb.appendChild(tr);
  });
}
function slotEdit(i, k, v){
  if (!STATE.timeslots[i]) return;
  if (k==='idx') STATE.timeslots[i][k] = parseInt(v,10)||0;
  else STATE.timeslots[i][k] = String(v||'');
}
function slotUp(i){ if (i<=0) return; const a=STATE.timeslots; [a[i-1],a[i]]=[a[i],a[i-1]]; renderSlots(); }
function slotDown(i){ const a=STATE.timeslots; if (i>=a.length-1) return; [a[i+1],a[i]]=[a[i],a[i+1]]; renderSlots(); }
function slotDel(i){ STATE.timeslots.splice(i,1); renderSlots(); }
function addSlot(){ STATE.timeslots.push({idx:STATE.timeslots.length+1, start:'08:00', end:'08:45'}); renderSlots(); }
function sortSlots(){ STATE.timeslots.sort((a,b)=> (a.start===b.start ? a.idx-b.idx : (a.start>b.start?1:-1))); renderSlots(); }
function clearSlots(){ if (confirm('清空所有时段？')) { STATE.timeslots=[]; renderSlots(); } }

/* ===== 课程列表渲染/编辑 ===== */
function renderCourses(){
  const tb = document.getElementById('courseTbody'); tb.innerHTML='';
  STATE.courses.forEach((c, i)=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input class="form-control form-control-sm" value="${c.name||''}" oninput="courseEdit(${i},'name',this.value)"></td>
      <td><input class="form-control form-control-sm" value="${c.teacher||''}" oninput="courseEdit(${i},'teacher',this.value)"></td>
      <td><input class="form-control form-control-sm" value="${c.room||''}" oninput="courseEdit(${i},'room',this.value)"></td>
      <td><input class="form-control form-control-sm" value="${c.day||''}" oninput="courseEdit(${i},'day',this.value)"></td>
      <td><input class="form-control form-control-sm" value="${(c.periods||[]).join(',')}" oninput="courseEdit(${i},'periods',this.value)"></td>
      <td><input class="form-control form-control-sm" value="${c.weeks||''}" oninput="courseEdit(${i},'weeks',this.value)"></td>
      <td>
        <select class="form-select form-select-sm" onchange="courseEdit(${i},'week_type',this.value)">
          <option value="all"  ${c.week_type==='all'?'selected':''}>全部</option>
          <option value="odd"  ${c.week_type==='odd'?'selected':''}>单周</option>
          <option value="even" ${c.week_type==='even'?'selected':''}>双周</option>
        </select>
      </td>
      <td>
        <div class="btn-group btn-group-sm">
          <button class="btn btn-outline-secondary" onclick="courseUp(${i})">上移</button>
          <button class="btn btn-outline-secondary" onclick="courseDown(${i})">下移</button>
          <button class="btn btn-outline-danger" onclick="courseDel(${i})">删除</button>
        </div>
      </td>`;
    tb.appendChild(tr);
  });
}
function courseEdit(i, k, v){
  if (!STATE.courses[i]) return;
  if (k==='day') STATE.courses[i][k] = parseInt(v,10)||0;
  else if (k==='periods') {
    const ps=[]; String(v||'').split(',').forEach(seg=>{
      seg = seg.trim();
      if (/^\d{1,2}$/.test(seg)) ps.push(parseInt(seg,10));
      else if (/^(\d{1,2})-(\d{1,2})$/.test(seg)) {
        const m=seg.match(/^(\d{1,2})-(\d{1,2})$/);
        let a=parseInt(m[1],10), b=parseInt(m[2],10); if (a>b){const t=a;a=b;b=t;}
        for(let x=a;x<=b;x++) ps.push(x);
      }
    });
    STATE.courses[i].periods = Array.from(new Set(ps)).sort((a,b)=>a-b);
  } else STATE.courses[i][k] = String(v||'');
}
function courseUp(i){ if (i<=0) return; const a=STATE.courses; [a[i-1],a[i]]=[a[i],a[i-1]]; renderCourses(); }
function courseDown(i){ const a=STATE.courses; if (i>=a.length-1) return; [a[i+1],a[i]]=[a[i],a[i+1]]; renderCourses(); }
function courseDel(i){ STATE.courses.splice(i,1); renderCourses(); }
function addManual(){
  const n = document.getElementById('m_name').value.trim();
  const t = document.getElementById('m_teacher').value.trim();
  const r = document.getElementById('m_room').value.trim();
  const d = parseInt(document.getElementById('m_day').value||'0',10);
  const p = document.getElementById('m_periods').value.trim();
  const w = document.getElementById('m_weeks').value.trim();
  const wt= document.getElementById('m_weektype').value || 'all';
  if (!n || !d || !p){ alert('请至少填写 课程名/星期/节次'); return; }
  // 解析节次
  let ps=[]; p.split(',').forEach(seg=>{
    seg = seg.trim();
    if (/^\d{1,2}$/.test(seg)) ps.push(parseInt(seg,10));
    else if (/^(\d{1,2})-(\d{1,2})$/.test(seg)) {
      const m=seg.match(/^(\d{1,2})-(\d{1,2})$/);
      let a=parseInt(m[1],10), b=parseInt(m[2],10); if (a>b){const t=a;a=b;b=t;}
      for(let x=a;x<=b;x++) ps.push(x);
    }
  });
  ps = Array.from(new Set(ps)).sort((a,b)=>a-b);
  if (!ps.length){ alert('节次格式无效'); return; }
  STATE.courses.push({name:n,teacher:t,room:r,day:d,periods:ps,weeks:w,week_type:wt});
  renderCourses();
}

/* ===== 时区选择器：同步 / 推荐 / IANA ===== */

const TZ_RECOMMENDED = [
  'Asia/Shanghai','Asia/Tokyo','Asia/Hong_Kong','Asia/Taipei','Asia/Seoul',
  'Europe/London','America/New_York','America/Los_Angeles','Europe/Berlin',
  'Australia/Sydney','UTC'
];

// 精选常见 IANA（可按需扩展）
const TZ_IANA = [
  'Africa/Cairo','Africa/Johannesburg','Africa/Nairobi',
  'America/Argentina/Buenos_Aires','America/Bogota','America/Chicago',
  'America/Denver','America/Guatemala','America/Lima','America/Mexico_City',
  'America/New_York','America/Panama','America/Los_Angeles','America/Toronto',
  'America/Vancouver','America/Sao_Paulo',
  'Asia/Bangkok','Asia/Colombo','Asia/Dhaka','Asia/Dubai','Asia/Hong_Kong',
  'Asia/Jakarta','Asia/Karachi','Asia/Kathmandu','Asia/Kolkata','Asia/Kuala_Lumpur',
  'Asia/Manila','Asia/Riyadh','Asia/Seoul','Asia/Shanghai','Asia/Singapore',
  'Asia/Taipei','Asia/Tashkent','Asia/Tehran','Asia/Tokyo','Asia/Ulaanbaatar',
  'Australia/Brisbane','Australia/Perth','Australia/Sydney',
  'Europe/Amsterdam','Europe/Athens','Europe/Barcelona','Europe/Berlin',
  'Europe/Brussels','Europe/Bucharest','Europe/Budapest','Europe/Copenhagen',
  'Europe/Dublin','Europe/Helsinki','Europe/Istanbul','Europe/Kyiv','Europe/Lisbon',
  'Europe/London','Europe/Madrid','Europe/Moscow','Europe/Oslo','Europe/Paris',
  'Europe/Prague','Europe/Stockholm','Europe/Vienna','Europe/Warsaw','Europe/Zurich',
  'Pacific/Auckland','Pacific/Guam','Pacific/Honolulu','Pacific/Port_Moresby',
  'UTC'
];

function buildTimezoneSelect(mainTz = 'Asia/Shanghai') {
  const sel = document.getElementById('tz_select');
  if (!sel) return;
  sel.innerHTML = '';

  // 同步分区
  const ogSync = document.createElement('optgroup');
  ogSync.label = '同步';
  const optSync = document.createElement('option');
  optSync.value = `__SYNC__:${mainTz}`;
  optSync.textContent = `跟随主课表（${mainTz || '未设置'}）`;
  ogSync.appendChild(optSync);
  sel.appendChild(ogSync);

  // 推荐分区
  const ogRec = document.createElement('optgroup');
  ogRec.label = '推荐';
  for (const tz of TZ_RECOMMENDED) {
    const o = document.createElement('option');
    o.value = tz;
    o.textContent = tz;
    ogRec.appendChild(o);
  }
  sel.appendChild(ogRec);

  // IANA 分区
  const ogIana = document.createElement('optgroup');
  ogIana.label = 'IANA';
  for (const tz of TZ_IANA) {
    if (TZ_RECOMMENDED.includes(tz)) continue; // 避免重复
    const o = document.createElement('option');
    o.value = tz;
    o.textContent = tz;
    ogIana.appendChild(o);
  }
  sel.appendChild(ogIana);
}

function applyTimezoneSyncUI(mainTz = 'Asia/Shanghai') {
  const sel = document.getElementById('tz_select');
  const sw  = document.getElementById('tz_sync_switch');
  if (!sel || !sw) return;

  if (STATE.tz_sync) {
    sel.value = `__SYNC__:${mainTz}`;
    sel.disabled = true;
    STATE.tz = mainTz || 'Asia/Shanghai';
  } else {
    sel.disabled = false;
    sel.value = (TZ_RECOMMENDED.includes(STATE.tz) || TZ_IANA.includes(STATE.tz))
      ? STATE.tz
      : 'Asia/Shanghai';
  }
}

function bindTimezoneEvents(mainTz = 'Asia/Shanghai') {
  const sel = document.getElementById('tz_select');
  const sw  = document.getElementById('tz_sync_switch');
  if (!sel || !sw) return;

  sel.addEventListener('change', () => {
    const v = sel.value;
    if (v.startsWith('__SYNC__:')) {
      STATE.tz_sync = true;
      STATE.tz = mainTz || 'Asia/Shanghai';
      sw.checked = true;
      applyTimezoneSyncUI(mainTz);
    } else {
      STATE.tz_sync = false;
      STATE.tz = v;
    }
  });

  sw.addEventListener('change', () => {
    STATE.tz_sync = !!sw.checked;
    applyTimezoneSyncUI(mainTz);
  });
}

/* ===== CSV 上传/文本粘贴解析 ===== */
async function uploadCSV(){
  const f = document.getElementById('csvForm'); const fd = new FormData(f);
  try{
    const r = await fetch('?api=preview_csv', {method:'POST', body:fd});
    const j = await r.json(); if(!j.ok) throw new Error(j.error||'解析失败');
    STATE.courses = (STATE.courses||[]).concat(j.courses||[]);
    renderCourses();
    alert(`解析成功，新增 ${j.courses.length} 门课程`);
  }catch(e){ alert(e.message||'解析失败'); }
}
async function parseText(){
  const text = document.getElementById('csvText').value;
  try{
    const r = await fetch('?api=preview_text', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({text})});
    const j = await r.json(); if(!j.ok) throw new Error(j.error||'解析失败');
    STATE.courses = (STATE.courses||[]).concat(j.courses||[]);
    renderCourses();
    alert(`解析成功，新增 ${j.courses.length} 门课程`);
  }catch(e){ alert(e.message||'解析失败'); }
}

/* ===== 保存 ===== */
async function saveAll(){
  if (!STATE.start_date || !/^\d{4}-\d{2}-\d{2}$/.test(STATE.start_date)) { alert('开学日期无效'); return; }
  if (!STATE.timeslots.length){ alert('请先设置时段'); return; }
  if (!STATE.courses.length){ alert('至少添加一门课程'); return; }

  // Final validation confirmation
  if (!confirm('确认保存当前实验课表配置？\n保存后将覆盖原有的实验课表数据。')) return;

  // 保证 tz 与 tz_sync 与 UI 一致
  {
    const sel = document.getElementById('tz_select');
    const sw  = document.getElementById('tz_sync_switch');
    const mainTz = window.__MAIN_TZ__ || 'Asia/Shanghai';
    if (sw && sw.checked) {
      STATE.tz_sync = true;
      STATE.tz = mainTz;
    } else if (sel) {
      STATE.tz_sync = false;
      const v = sel.value || 'Asia/Shanghai';
      STATE.tz = v.startsWith('__SYNC__:') ? mainTz : v;
    }
  }

  try{
    const payload = {...STATE}; // 若后端不需要 tz_sync，也无妨
    const r = await fetch('?api=save', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    const j = await r.json(); if(!j.ok) throw new Error(j.error||'保存失败');
    goStep(5);
  }catch(e){ alert(e.message||'保存失败'); }
}

/* ===== 初始化：从主课表预填（含主课表时区） ===== */
(function init(){
  const defaultMainTz = 'Asia/Shanghai';
  let mainTz = defaultMainTz;

  // 先构建下拉（默认主课表时区），避免闪烁
  buildTimezoneSelect(mainTz);
  bindTimezoneEvents(mainTz);
  STATE.tz_sync = false;    // 默认不同步
  STATE.tz = 'Asia/Shanghai';
  applyTimezoneSyncUI(mainTz);

  <?php if ($logged): ?>
  fetch('?api=me').then(r=>r.json()).then(j=>{
    if (!j.ok) return;
    const main = j.main || {};

    // 开学日期预填
    if (!STATE.start_date && main.start_date) {
      STATE.start_date = main.start_date;
      const el = document.getElementById('start_date'); if (el) el.value = main.start_date;
    }

    // 主课表时区（若存在）
    if (main.tz && typeof main.tz === 'string') {
      mainTz = main.tz;
      window.__MAIN_TZ__ = mainTz;
      buildTimezoneSelect(mainTz);
      bindTimezoneEvents(mainTz);

      // 如需默认就开启同步，可解开以下两行：
      // STATE.tz_sync = true;
      // STATE.tz = mainTz;

      applyTimezoneSyncUI(mainTz);
    } else {
      window.__MAIN_TZ__ = mainTz;
    }
  }).catch(_=>{
    window.__MAIN_TZ__ = mainTz;
  });
  <?php else: ?>
  window.__MAIN_TZ__ = mainTz;
  <?php endif; ?>
})();
</script>
</body>
</html>
