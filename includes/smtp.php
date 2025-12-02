<?php
/**
 * Simple SMTP Client for mail.ricardozheng.com
 * 支持：
 *  - 465 端口 SMTPS（SSL）
 *  - 587 端口 STARTTLS
 *  - AUTH PLAIN 登录
 */
class SmtpMailer {
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private int $timeout = 30;
    private string $secure; // ssl / tls / none
    private bool $debug = true;
    private array $log = [];

    public function __construct(
        string $host,
        int    $port,
        string $user,
        string $pass,
        string $secure = 'ssl'
    ) {
        $this->host   = $host;
        $this->port   = $port;
        $this->user   = $user;
        $this->pass   = $pass;
        $this->secure = $secure;
    }

    private function log(string $msg): void {
        if ($this->debug) {
            $this->log[] = sprintf('[%s] %s', date('Y-m-d H:i:s'), $msg);
        }
    }

    public function getLog(): string {
        return implode("\n", $this->log);
    }

    /**
     * 发送一封 HTML 邮件
     */
    public function send(string $to, string $subject, string $htmlBody, string $fromName = 'Kebiao System'): bool {
        // 465: SMTPS (implicit SSL)
        $hostname = $this->host;
        if ($this->secure === 'ssl' || $this->port === 465) {
            $hostname = 'ssl://' . $this->host;
        }

        $this->log("Connecting to {$hostname}:{$this->port} as {$this->user}");

        $errno  = 0;
        $errstr = '';
        $socket = fsockopen($hostname, $this->port, $errno, $errstr, $this->timeout);
        if (!$socket) {
            $this->log("fsockopen error: $errstr ($errno)");
            throw new Exception("连接失败: $errstr ($errno)");
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            // 220 welcome
            $this->read($socket);

            $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
            $this->cmd($socket, "EHLO {$serverName}", [250]);

            // 如果是 587 + STARTTLS
            if ($this->secure === 'tls' || $this->port === 587) {
                $this->cmd($socket, "STARTTLS", [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception("启用TLS加密失败");
                }
                // 加密之后需要重新 EHLO
                $this->cmd($socket, "EHLO {$serverName}", [250]);
            }

            // AUTH PLAIN（和你日志里一样）
            $authStr = base64_encode("\0" . $this->user . "\0" . $this->pass);
            $this->cmd($socket, "AUTH PLAIN {$authStr}", [235]);

            // MAIL FROM / RCPT TO
            $this->cmd($socket, "MAIL FROM:<{$this->user}>", [250]);
            $this->cmd($socket, "RCPT TO:<{$to}>", [250, 251]);

            // DATA
            $this->cmd($socket, "DATA", [354]);

            // 构造头部
            $fromNameEncoded    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
            $subjectEncoded     = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $messageIdDomain    = $serverName;
            $messageId          = sprintf('<%s@%s>', uniqid('kb_', true), $messageIdDomain);

            $headers  = "From: {$fromNameEncoded} <{$this->user}>\r\n";
            $headers .= "To: <{$to}>\r\n";
            $headers .= "Subject: {$subjectEncoded}\r\n";
            $headers .= "Date: " . date('r') . "\r\n";
            $headers .= "Message-ID: {$messageId}\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: 8bit\r\n";

            // 注意：头和正文之间需要空行；最后以 \r\n.\r\n 结束
            $data  = $headers . "\r\n" . $htmlBody . "\r\n.\r\n";

            $this->log("C: [DATA headers+body]");
            fwrite($socket, $data);

            // 服务器应返回 250
            $this->readAndCheck($socket, [250]);

            // QUIT
            $this->cmd($socket, "QUIT", [221]);

            fclose($socket);
            return true;
        } catch (Exception $e) {
            fclose($socket);
            throw $e;
        }
    }

    /**
     * 读服务器响应（支持多行 250-xxx 风格）
     */
    private function read($socket): string {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            // SMTP 多行响应：前三位数字 + 空格 结束；前三位数字 + '-' 继续
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        $this->log("S: " . trim($response));
        return $response;
    }

    /**
     * 发命令 + 检查返回码
     */
    private function cmd($socket, string $cmd, array $expectCodes = [220, 221, 235, 250, 251, 334, 354]): string {
        $this->log("C: {$cmd}");
        fwrite($socket, $cmd . "\r\n");
        return $this->readAndCheck($socket, $expectCodes);
    }

    private function readAndCheck($socket, array $expectCodes): string {
        $resp = $this->read($socket);
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $expectCodes, true)) {
            throw new Exception("SMTP错误 (代码 {$code}): " . trim($resp));
        }
        return $resp;
    }
}
