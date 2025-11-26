<?php
// settings.php — 用户设置（资料/密码/危险操作）
declare(strict_types=1);
mb_internal_encoding('UTF-8');
session_start();

require_once __DIR__ . '/db.php';

/* ============== 工具 ============== */
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
function json_out($arr, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
function is_logged_in(): bool { return isset($_SESSION['uid']) && is_numeric($_SESSION['uid']); }
function require_login(): int {
    if (!is_logged_in()) { json_out(['ok'=>false,'error'=>'未登录'], 401); }
    return (int)$_SESSION['uid'];
}

/* ============== API ============== */
if (isset($_GET['api'])) {
    $api = $_GET['api'];
    $uid = require_login();
    $pdo = db();

    // 获取当前信息
    if ($api === 'info') {
        $stmt = $pdo->prepare('SELECT user_id, email FROM user_accounts WHERE user_id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        if (!$row) json_out(['ok'=>false,'error'=>'用户不存在'], 404);
        json_out(['ok'=>true, 'uid'=>$row['user_id'], 'email'=>(string)($row['email']??'')]);
    }

    // 更新邮箱
    if ($api === 'update_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim($_POST['email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_out(['ok'=>false,'error'=>'邮箱格式无效'], 400);
        }
        $stmt = $pdo->prepare('UPDATE user_accounts SET email = ? WHERE user_id = ?');
        $stmt->execute([$email, $uid]);
        json_out(['ok'=>true]);
    }

    // 修改密码
    if ($api === 'change_pwd' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $old = trim($_POST['old_pin'] ?? '');
        $new1= trim($_POST['new_pin'] ?? '');
        $new2= trim($_POST['new_pin2'] ?? '');

        if (!preg_match('/^\d{4}$/', $old)) json_out(['ok'=>false,'error'=>'原密码格式错误（4位数字）'], 400);
        if (!preg_match('/^\d{4}$/', $new1)) json_out(['ok'=>false,'error'=>'新密码必须为4位数字'], 400);
        if ($new1 !== $new2) json_out(['ok'=>false,'error'=>'两次新密码输入不一致'], 400);

        // 验证旧密码
        $stmt = $pdo->prepare('SELECT pin FROM user_accounts WHERE user_id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        if (!$row || (string)$row['pin'] !== $old) json_out(['ok'=>false,'error'=>'原密码错误'], 403);

        // 更新
        $stmt = $pdo->prepare('UPDATE user_accounts SET pin = ? WHERE user_id = ?');
        $stmt->execute([$new1, $uid]);
        json_out(['ok'=>true]);
    }

    // 危险操作：清空主课表
    if ($api === 'clear_main' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $stmt = $pdo->prepare('DELETE FROM user_schedule WHERE user_id = ?');
        $stmt->execute([$uid]);
        json_out(['ok'=>true]);
    }

    // 危险操作：清空实验课表
    if ($api === 'clear_lab' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $stmt = $pdo->prepare('DELETE FROM user_lab_schedule WHERE user_id = ?');
        $stmt->execute([$uid]);
        json_out(['ok'=>true]);
    }

    json_out(['ok'=>false,'error'=>'unknown api'], 404);
}

// 页面渲染
if (!is_logged_in()) { header('Location: index.php'); exit; }
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="auto">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>账户设置</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { background: #f6f7fb; }
  .card { border-radius: 1rem; border:none; box-shadow:0 2px 6px rgba(0,0,0,.04); }
  .section-title { font-size:.9rem; font-weight:600; color:#6c757d; margin-bottom:.8rem; text-transform:uppercase; letter-spacing:.5px; }
</style>
</head>
<body>
<div class="container py-4" style="max-width: 720px;">
  <div class="d-flex align-items-center justify-content-between mb-4">
    <h3 class="mb-0">账户设置</h3>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">返回首页</a>
  </div>

  <!-- 基本资料 -->
  <div class="mb-4">
    <div class="section-title">基本资料</div>
    <div class="card">
      <div class="card-body vstack gap-3">
        <div>
          <label class="form-label">课表 ID</label>
          <input class="form-control" value="<?=(int)$_SESSION['uid']?>" readonly disabled>
        </div>
        <div>
          <label class="form-label">邮箱（可选）</label>
          <div class="input-group">
            <input class="form-control" id="inp_email" placeholder="用于找回密码（暂未开放）">
            <button class="btn btn-outline-primary" onclick="saveEmail()">保存</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 修改密码 -->
  <div class="mb-4">
    <div class="section-title">安全设置</div>
    <div class="card">
      <div class="card-body">
        <h6 class="mb-3">修改密码</h6>
        <div class="vstack gap-3">
          <input type="password" class="form-control" id="pwd_old" placeholder="原密码（4位数字）" inputmode="numeric">
          <input type="password" class="form-control" id="pwd_new1" placeholder="新密码（4位数字）" inputmode="numeric">
          <input type="password" class="form-control" id="pwd_new2" placeholder="确认新密码" inputmode="numeric">
          <button class="btn btn-primary w-100" onclick="changePwd()">确认修改</button>
        </div>
      </div>
    </div>
  </div>

  <!-- 危险区域 -->
  <div class="mb-5 border border-danger border-opacity-25 rounded p-3">
    <div class="d-flex align-items-center justify-content-between">
      <div class="section-title text-danger mb-0">危险区域</div>
      <button class="btn btn-sm btn-outline-danger border-0" type="button" data-bs-toggle="collapse" data-bs-target="#dangerZone" aria-expanded="false">
        展开 / 收起
      </button>
    </div>
    <div class="collapse mt-3" id="dangerZone">
      <div class="card border-0 bg-danger bg-opacity-10">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
              <h6 class="text-danger mb-1">清空主课表</h6>
              <div class="small text-muted">删除所有主课表课程数据，不可恢复。</div>
            </div>
            <button class="btn btn-outline-danger btn-sm" onclick="clearMain()">清空主课表</button>
          </div>
          <hr class="border-danger border-opacity-25">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <h6 class="text-danger mb-1">清空实验课表</h6>
              <div class="small text-muted">删除所有实验课表课程数据，不可恢复。</div>
            </div>
            <button class="btn btn-outline-danger btn-sm" onclick="clearLab()">清空实验课表</button>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
async function loadInfo(){
    try{
        const r = await fetch('?api=info');
        const j = await r.json();
        if(j.ok){
            document.getElementById('inp_email').value = j.email || '';
        }
    }catch(e){}
}
async function saveEmail(){
    const email = document.getElementById('inp_email').value.trim();
    try{
        const fd = new FormData(); fd.append('email', email);
        const r = await fetch('?api=update_email', {method:'POST', body:fd});
        const j = await r.json();
        if(j.ok) alert('邮箱已保存');
        else alert(j.error||'保存失败');
    }catch(e){ alert('保存失败'); }
}
async function changePwd(){
    const old = document.getElementById('pwd_old').value;
    const n1  = document.getElementById('pwd_new1').value;
    const n2  = document.getElementById('pwd_new2').value;
    if(!old || !n1 || !n2) { alert('请填写完整'); return; }
    try{
        const fd = new FormData();
        fd.append('old_pin', old); fd.append('new_pin', n1); fd.append('new_pin2', n2);
        const r = await fetch('?api=change_pwd', {method:'POST', body:fd});
        const j = await r.json();
        if(j.ok) { alert('密码修改成功，请重新登录'); location.href='index.php'; }
        else alert(j.error||'修改失败');
    }catch(e){ alert('修改失败'); }
}
async function clearMain(){
    if(!confirm('【高危】确定要清空主课表所有数据吗？\n此操作不可撤销！')) return;
    if(!confirm('再次确认：真的要清空主课表吗？')) return;
    try{
        const r = await fetch('?api=clear_main', {method:'POST'});
        const j = await r.json();
        if(j.ok) alert('主课表已清空');
        else alert(j.error||'操作失败');
    }catch(e){ alert('操作失败'); }
}
async function clearLab(){
    if(!confirm('【高危】确定要清空实验课表所有数据吗？\n此操作不可撤销！')) return;
    if(!confirm('再次确认：真的要清空实验课表吗？')) return;
    try{
        const r = await fetch('?api=clear_lab', {method:'POST'});
        const j = await r.json();
        if(j.ok) alert('实验课表已清空');
        else alert(j.error||'操作失败');
    }catch(e){ alert('操作失败'); }
}
loadInfo();
</script>
</body>
</html>
