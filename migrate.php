<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function load_schema_from_sql(string $sqlPath): array {
    $sql = file_get_contents($sqlPath);
    if ($sql === false) {
        throw new RuntimeException('无法读取 db.sql');
    }

    $tables = [];
    if (!preg_match_all('/CREATE\\s+TABLE\\s+`([^`]+)`\\s*\\((.*?)\\)\\s*ENGINE=.*?;/si', $sql, $matches, PREG_SET_ORDER)) {
        throw new RuntimeException('未在 db.sql 中找到 CREATE TABLE 定义');
    }

    foreach ($matches as $m) {
        $table = $m[1];
        $body = $m[2];
        $createSql = trim($m[0]);

        $columns = [];
        $lines = preg_split('/\\R/', $body) ?: [];
        foreach ($lines as $lineRaw) {
            $line = trim($lineRaw);
            if ($line === '') {
                continue;
            }
            $line = rtrim($line, ',');
            if (preg_match('/^`([^`]+)`\\s+(.*)$/s', $line, $cm)) {
                $col = $cm[1];
                $columns[$col] = '`' . $col . '` ' . $cm[2];
            }
        }

        $tables[$table] = [
            'create_sql' => $createSql,
            'columns' => $columns,
        ];
    }

    return ['tables' => $tables];
}

function table_exists(PDO $pdo, string $tableName): bool {
    $q = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($tableName));
    return (bool)$q->fetchColumn();
}

function table_columns(PDO $pdo, string $tableName): array {
    $rows = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $tableName) . '`')->fetchAll(PDO::FETCH_ASSOC);
    $cols = [];
    foreach ($rows as $r) {
        $cols[] = (string)($r['Field'] ?? '');
    }
    return $cols;
}

function detect_missing(PDO $pdo, array $schemaTables, string $prefix): array {
    $missingTables = [];
    $missingColumns = [];

    foreach ($schemaTables as $baseTable => $tableSpec) {
        $realTable = $prefix . $baseTable;
        if (!table_exists($pdo, $realTable)) {
            $missingTables[] = [
                'base_table' => $baseTable,
                'real_table' => $realTable,
            ];
            continue;
        }

        $existingCols = array_flip(table_columns($pdo, $realTable));
        foreach ($tableSpec['columns'] as $col => $definition) {
            if (!isset($existingCols[$col])) {
                $missingColumns[] = [
                    'base_table' => $baseTable,
                    'real_table' => $realTable,
                    'column' => $col,
                    'definition' => $definition,
                ];
            }
        }
    }

    return [
        'tables' => $missingTables,
        'columns' => $missingColumns,
    ];
}

function repair_missing(PDO $pdo, array $missing, array $schemaTables, string $prefix): array {
    $results = [];

    foreach ($missing['tables'] as $item) {
        $base = $item['base_table'];
        $real = $item['real_table'];
        if (!isset($schemaTables[$base])) {
            $results[] = [
                'ok' => false,
                'kind' => 'table',
                'target' => $real,
                'message' => 'db.sql 中不存在该表定义',
            ];
            continue;
        }

        $createSql = $schemaTables[$base]['create_sql'];
        $pattern = '/^CREATE\\s+TABLE\\s+`' . preg_quote($base, '/') . '`/i';
        $createSql = preg_replace($pattern, 'CREATE TABLE `' . $real . '`', $createSql, 1) ?? $createSql;

        try {
            $pdo->exec($createSql);
            $results[] = [
                'ok' => true,
                'kind' => 'table',
                'target' => $real,
                'message' => '已创建',
            ];
        } catch (Throwable $e) {
            $results[] = [
                'ok' => false,
                'kind' => 'table',
                'target' => $real,
                'message' => $e->getMessage(),
            ];
        }
    }

    foreach ($missing['columns'] as $item) {
        $real = $item['real_table'];
        $column = $item['column'];
        $definition = $item['definition'];

        try {
            $pdo->exec('ALTER TABLE `' . $real . '` ADD COLUMN ' . $definition);
            $results[] = [
                'ok' => true,
                'kind' => 'column',
                'target' => $real . '.' . $column,
                'message' => '已添加',
            ];
        } catch (Throwable $e) {
            $results[] = [
                'ok' => false,
                'kind' => 'column',
                'target' => $real . '.' . $column,
                'message' => $e->getMessage(),
            ];
        }
    }

    return $results;
}

$errors = [];
$repairResults = [];
$didRepair = false;
$missingBefore = ['tables' => [], 'columns' => []];
$missingAfter = ['tables' => [], 'columns' => []];
$schemaTables = [];

try {
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $schema = load_schema_from_sql(__DIR__ . '/db.sql');
    $schemaTables = $schema['tables'];

    $missingBefore = detect_missing($pdo, $schemaTables, DB_PREFIX);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'repair') {
        $didRepair = true;
        if (!empty($missingBefore['tables']) || !empty($missingBefore['columns'])) {
            $repairResults = repair_missing($pdo, $missingBefore, $schemaTables, DB_PREFIX);
        }
    }

    $missingAfter = detect_missing($pdo, $schemaTables, DB_PREFIX);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

$missingTableCountBefore = count($missingBefore['tables']);
$missingColumnCountBefore = count($missingBefore['columns']);
$missingTableCountAfter = count($missingAfter['tables']);
$missingColumnCountAfter = count($missingAfter['columns']);
$hasMissingAfter = ($missingTableCountAfter + $missingColumnCountAfter) > 0;
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>数据库迁移检测</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #f6f7fb; }
    .card { border-radius: 1rem; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  </style>
</head>
<body>
<div class="container py-4">
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <h4 class="mb-3">数据库迁移检测</h4>
      <div class="small text-muted mb-2">检测基准：<span class="mono">db.sql</span>（当前仓库最新结构）</div>
      <div class="small text-muted">数据库：<span class="mono"><?= h(DB_NAME) ?></span>，表前缀：<span class="mono"><?= h(DB_PREFIX) ?></span></div>
    </div>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <div class="fw-semibold mb-1">检测失败</div>
      <?php foreach ($errors as $err): ?>
        <div><?= h($err) ?></div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex flex-wrap gap-3 align-items-center">
          <span class="badge text-bg-light border">缺失表：<?= (int)$missingTableCountAfter ?></span>
          <span class="badge text-bg-light border">缺失字段：<?= (int)$missingColumnCountAfter ?></span>
          <?php if (!$hasMissingAfter): ?>
            <span class="badge text-bg-success">数据库结构完整</span>
          <?php else: ?>
            <span class="badge text-bg-warning">存在缺失项</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h6 class="mb-2">缺失表</h6>
        <?php if (empty($missingAfter['tables'])): ?>
          <div class="text-muted">无</div>
        <?php else: ?>
          <ul class="mb-0">
            <?php foreach ($missingAfter['tables'] as $t): ?>
              <li><span class="mono"><?= h($t['real_table']) ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h6 class="mb-2">缺失字段</h6>
        <?php if (empty($missingAfter['columns'])): ?>
          <div class="text-muted">无</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>表</th>
                  <th>字段</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($missingAfter['columns'] as $c): ?>
                  <tr>
                    <td class="mono"><?= h($c['real_table']) ?></td>
                    <td class="mono"><?= h($c['column']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($didRepair): ?>
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h6 class="mb-2">本次修复结果</h6>
          <?php if (empty($repairResults)): ?>
            <div class="text-muted">无需修复（修复前未检测到缺失项）。</div>
          <?php else: ?>
            <ul class="mb-0">
              <?php foreach ($repairResults as $r): ?>
                <li>
                  <?php if ($r['ok']): ?>
                    <span class="text-success">[成功]</span>
                  <?php else: ?>
                    <span class="text-danger">[失败]</span>
                  <?php endif; ?>
                  <span class="mono"><?= h($r['target']) ?></span>
                  <span class="text-muted">- <?= h($r['message']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card shadow-sm">
      <div class="card-body d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div class="small text-muted">点击“修复缺失项”才会执行数据库变更。</div>
        <div class="d-flex gap-2">
          <?php if ($hasMissingAfter): ?>
            <form method="post" class="m-0">
              <input type="hidden" name="action" value="repair">
              <button type="submit" class="btn btn-primary">修复缺失项</button>
            </form>
          <?php else: ?>
            <button type="button" class="btn btn-success" disabled>无需修复</button>
          <?php endif; ?>
          <a class="btn btn-outline-secondary" href="index.php">返回首页</a>
        </div>
      </div>
    </div>

  <?php endif; ?>
</div>
</body>
</html>
