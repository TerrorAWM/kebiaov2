<?php
// version_check.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
session_start();

include_once __DIR__ . '/db.php';

// Helper to get local version
function get_local_version(): string {
    $path = __DIR__ . '/config.json';
    if (!file_exists($path)) return '0.0.0';
    $json = json_decode(file_get_contents($path), true);
    return $json['version'] ?? '0.0.0';
}

// Helper to check remote version
function check_remote_version(): array {
    $url = 'https://kebiao.ricardozheng.com/version/';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false // For simplicity, though not recommended for prod
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && $resp) {
        $data = json_decode($resp, true);
        if (isset($data['version'])) {
            return ['ok' => true, 'data' => $data];
        }
    }
    return ['ok' => false, 'error' => 'Failed to fetch remote version'];
}

// Helper to perform update (git pull)
function perform_update(): array {
    // Check if .git exists
    if (!is_dir(__DIR__ . '/.git')) {
        return ['ok' => false, 'error' => 'Not a git repository. Please download updates manually.'];
    }

    // Try git pull
    $output = [];
    $return_var = 0;
    exec('git pull 2>&1', $output, $return_var);

    if ($return_var === 0) {
        return ['ok' => true, 'message' => implode("\n", $output)];
    } else {
        return ['ok' => false, 'error' => 'Git pull failed: ' . implode("\n", $output)];
    }
}

// API Handler
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];

    if ($action === 'check') {
        $local = get_local_version();
        $remoteRes = check_remote_version();
        
        if ($remoteRes['ok']) {
            $remote = $remoteRes['data']['version'];
            $hasUpdate = version_compare($remote, $local, '>');
            echo json_encode([
                'ok' => true,
                'local_version' => $local,
                'remote_version' => $remote,
                'has_update' => $hasUpdate,
                'remote_info' => $remoteRes['data']
            ]);
        } else {
            echo json_encode([
                'ok' => true, // Still ok, just failed to check remote
                'local_version' => $local,
                'error' => $remoteRes['error']
            ]);
        }
        exit;
    }

    if ($action === 'update') {
        // Security check: Only Super Admin should be able to update
        // We need to verify session role here.
        // Assuming index.php or login sets $_SESSION['role'] or we query DB.
        
        if (!isset($_SESSION['uid'])) {
            echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
            exit;
        }

        // Check role from DB
        try {
            $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $stmt = $pdo->prepare('SELECT role FROM user_accounts WHERE user_id = ?');
            $stmt->execute([$_SESSION['uid']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || $user['role'] !== 'super_admin') {
                echo json_encode(['ok' => false, 'error' => 'Permission denied']);
                exit;
            }

            $res = perform_update();
            echo json_encode($res);

        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
