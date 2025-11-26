<?php
// pross_ai.php — DeepSeek AI 解析学校 CSV（返回 { ok, courses|error }）
// PHP 8.2+ / 无 Composer 依赖
declare(strict_types=1);
mb_internal_encoding('UTF-8');

@ini_set('default_socket_timeout', '120');
@set_time_limit(180);

header('Content-Type: application/json; charset=utf-8');

// ====== 配置区 ======
// 建议用环境变量保存密钥：putenv('DEEPSEEK_API_KEY=xxxx');
const DEEPSEEK_API_KEY = 'sk-d35e6f478edb4d83b9220c52c1d883a9';
const DEEPSEEK_API_BASE = 'https://api.deepseek.com';  // OpenAI兼容
const DEEPSEEK_MODEL    = 'deepseek-chat';             // 可改 deepseek-reasoner
const TEMPERATURE       = 0.1;
const MAX_TOKENS        = 6000;

// 超时与重试
const CONNECT_TIMEOUT   = 20;    // 连接超时（秒）
const REQUEST_TIMEOUT   = 120;   // 整体请求超时（秒）
const MAX_RETRIES       = 3;     // 最多重试次数
const RETRY_BASE_DELAY  = 2;     // 初始退避秒数

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Method Not Allowed']);
  exit;
}

$algo_id = trim($_POST['algo_id'] ?? '');
$prompt  = trim($_POST['prompt']  ?? '');
$csv     = $_POST['csv'] ?? '';

if ($csv === '' || $prompt === '') {
  echo json_encode(['ok'=>false,'error'=>'缺少 csv 或 prompt']);
  exit;
}

// 组装消息
$sys = "You are a data parser that outputs STRICT JSON only. No commentary.";
$user = <<<EOT
[任务与规则]
$prompt

[输入数据：整表CSV（原样）]
$csv
EOT;

$payload = [
  'model' => DEEPSEEK_MODEL,
  'messages' => [
    ['role'=>'system', 'content'=>$sys],
    ['role'=>'user',   'content'=>$user],
  ],
  'temperature' => TEMPERATURE,
  'max_tokens' => MAX_TOKENS,
  'stream' => false
];

function deepseek_request(array $payload, string $apiKey, int &$httpCode = 0, ?string &$rawResp = null): array {
  $url = rtrim(DEEPSEEK_API_BASE,'/') . '/chat/completions';
  $attempt = 0;
  $lastErr = null;
  while ($attempt < MAX_RETRIES) {
    $attempt++;
    $ch = curl_init($url);
    $headers = [
      'Authorization: Bearer ' . $apiKey,
      'Content-Type: application/json',
      'Accept: application/json',
      'Expect:' // 关闭 100-continue，减少握手等待
    ];
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
      CURLOPT_TIMEOUT => REQUEST_TIMEOUT,
      CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);

    // 尝试启用 TCP KeepAlive（新版本 libcurl 支持）
    if (defined('CURLOPT_TCP_KEEPALIVE')) {
      curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
    }
    if (defined('CURLOPT_TCP_KEEPIDLE')) {
      curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 15);
    }
    if (defined('CURLOPT_TCP_KEEPINTVL')) {
      curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 15);
    }

    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $httpCode = $http;
    $rawResp  = $resp;

    // 成功
    if ($errno === 0 && $http >= 200 && $http < 300 && is_string($resp) && $resp !== '') {
      $data = json_decode($resp, true);
      if (is_array($data)) return ['ok'=>true, 'data'=>$data];
      // JSON 解析失败也重试一次
      $lastErr = '返回内容不是合法 JSON';
    } else {
      $lastErr = $errno ? ("cURL错误: " . $err) : ("HTTP $http: $resp");
    }

    // 可重试的情况：超时 / 5xx
    if ($errno === CURLE_OPERATION_TIMEDOUT || ($http >= 500 && $http < 600)) {
      $delay = RETRY_BASE_DELAY * pow(2, $attempt-1); // 指数退避
      sleep((int)$delay);
      continue;
    }
    break; // 其他错误不重试
  }

  return ['ok'=>false, 'error'=>$lastErr ?? '请求失败'];
}

// 调用
$apiKey = DEEPSEEK_API_KEY; // 使用配置的 API Key（可改为从环境变量读取）
$httpCode = 0;
$rawResp = null;

$res = deepseek_request($payload, $apiKey, $httpCode, $rawResp);
if (!$res['ok']) {
  echo json_encode(['ok'=>false,'error'=>$res['error'] ?? 'AI 请求失败', 'http'=>$httpCode], JSON_UNESCAPED_UNICODE);
  exit;
}

$data = $res['data'];
$content = $data['choices'][0]['message']['content'] ?? '';

if (!is_string($content) || $content === '') {
  echo json_encode(['ok'=>false,'error'=>'AI 无返回内容', 'http'=>$httpCode], JSON_UNESCAPED_UNICODE);
  exit;
}

// 解析 JSON（若模型意外输出多余文本，尝试提取首个 JSON 对象）
$decoded = json_decode($content, true);
if (!is_array($decoded)) {
  if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $content, $m)) {
    $decoded = json_decode($m[0], true);
  }
}

if (!is_array($decoded) || !isset($decoded['courses']) || !is_array($decoded['courses'])) {
  echo json_encode([
    'ok'=>false,
    'error'=>'AI 输出不是合法 JSON（缺少 courses）',
    'http'=>$httpCode,
    'raw'=> mb_substr($content, 0, 5000)
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// 轻度清洗
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

echo json_encode(['ok'=>true, 'courses'=>$courses], JSON_UNESCAPED_UNICODE);
