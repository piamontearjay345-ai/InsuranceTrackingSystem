<?php
namespace App\Services;

use App\Config\Env;

/**
 * Email notifications via SMTP.
 */
class EmailService
{
    private SupabaseClient $db;
    private string $lastError = '';

    public function __construct()
    {
        $this->db = new SupabaseClient();
    }

    public function send(string $to, string $subject, string $body, ?string $userId = null): bool
    {
        $this->lastError = '';
        $sent = $this->sendSmtp($to, $subject, $body);

        if ($userId) {
            $this->db->from('notifications', 'POST', [
                'user_id' => $userId,
                'title' => $subject,
                'message' => $body,
                'delivery_status' => $sent ? 'sent' : 'failed',
            ], null, true);
        }

        if (!$sent) {
            $this->db->from('failed_notifications', 'POST', [
                'recipient_email' => $to,
                'payload' => json_encode(['subject' => $subject, 'body' => $body]),
                'error_reason' => $this->lastError ?: 'SMTP send failed',
            ], null, true);
        }

        return $sent;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    private function sendSmtp(string $to, string $subject, string $body): bool
    {
        $host = Env::get('MAIL_HOST', '');
        $port = Env::getInt('MAIL_PORT', 587);
        $username = Env::get('MAIL_USERNAME', '');
        $password = str_replace(' ', '', Env::get('MAIL_PASSWORD', ''));
        $from = Env::get('MAIL_FROM_ADDRESS', $username ?: 'noreply@localhost');
        $fromName = Env::get('MAIL_FROM_NAME', Env::get('APP_NAME', 'Insurance Tracking System'));
        $encryption = strtolower((string) Env::get('MAIL_ENCRYPTION', $port === 465 ? 'ssl' : 'tls'));
        $timeout = Env::getInt('MAIL_TIMEOUT', 20);

        if ($host === '' || $username === '' || $password === '') {
            $this->lastError = 'SMTP is not configured. Set MAIL_HOST, MAIL_USERNAME, and MAIL_PASSWORD in .env.';
            return false;
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = 'Recipient email address is invalid.';
            return false;
        }

        if (str_contains(strtolower($host), 'gmail.com') && strcasecmp($this->addressOnly($from), $username) !== 0) {
            $from = $username;
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            $this->lastError = 'Could not connect to SMTP server: ' . ($errstr ?: 'unknown error');
            return false;
        }

        stream_set_timeout($socket, $timeout);

        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO ' . $this->serverName(), [250]);

            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('Could not enable TLS encryption for SMTP connection.');
                }
                $this->command($socket, 'EHLO ' . $this->serverName(), [250]);
            }

            $this->command($socket, 'AUTH LOGIN', [334]);
            $this->command($socket, base64_encode($username), [334]);
            $this->command($socket, base64_encode($password), [235]);

            $this->command($socket, 'MAIL FROM:<' . $this->addressOnly($from) . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $this->addressOnly($to) . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $this->write($socket, $this->message($to, $subject, $body, $from, $fromName) . "\r\n.");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
            fclose($socket);
            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->write($socket, 'QUIT');
            fclose($socket);
            return false;
        }
    }

    /**
     * @param resource $socket
     * @param array<int> $expectedCodes
     */
    private function command($socket, string $command, array $expectedCodes): string
    {
        $this->write($socket, $command);
        return $this->expect($socket, $expectedCodes);
    }

    /** @param resource $socket */
    private function write($socket, string $line): void
    {
        fwrite($socket, $line . "\r\n");
    }

    /**
     * @param resource $socket
     * @param array<int> $expectedCodes
     */
    private function expect($socket, array $expectedCodes): string
    {
        $response = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                throw new \RuntimeException(!empty($meta['timed_out']) ? 'SMTP server timed out.' : 'SMTP server closed the connection.');
            }
            $response .= $line;
            $code = (int) substr($line, 0, 3);
            $more = isset($line[3]) && $line[3] === '-';
        } while ($more);

        if (!in_array($code, $expectedCodes, true)) {
            $cleanResponse = trim($response);
            if (str_contains($cleanResponse, '535-5.7.8') || str_contains($cleanResponse, 'Username and Password not accepted')) {
                throw new \RuntimeException(
                    'Gmail rejected the SMTP username or app password. Generate a new Google App Password for the exact MAIL_USERNAME account, then update MAIL_PASSWORD in .env.'
                );
            }
            throw new \RuntimeException('SMTP error: ' . $cleanResponse);
        }

        return $response;
    }

    private function message(string $to, string $subject, string $body, string $from, string $fromName): string
    {
        $html = '<html><body><h2>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h2><p>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</p></body></html>';
        $plain = preg_replace("/\R/u", "\r\n", $body) ?: $body;
        $boundary = 'b' . bin2hex(random_bytes(16));
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $this->mailbox($from, $fromName),
            'To: ' . $this->mailbox($to, ''),
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->serverName() . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n";
        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $plain . "\r\n\r\n";
        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $html . "\r\n\r\n";
        $message .= '--' . $boundary . "--\r\n";

        return preg_replace('/^\./m', '..', $message) ?: $message;
    }

    private function mailbox(string $email, string $name): string
    {
        $address = $this->addressOnly($email);
        if ($name === '') {
            return '<' . $address . '>';
        }
        return $this->encodeHeader($name) . ' <' . $address . '>';
    }

    private function addressOnly(string $email): string
    {
        return trim(preg_replace('/[\r\n<>]/', '', $email) ?: $email);
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function serverName(): string
    {
        $host = parse_url((string) Env::get('APP_URL', ''), PHP_URL_HOST);
        return $host ?: 'localhost';
    }

    public function retryFailed(string $failedId): array
    {
        $res = $this->db->from('failed_notifications', 'GET', null, 'id=eq.' . $failedId, true);
        if (!$res['ok'] || empty($res['data'][0])) {
            return ['success' => false, 'message' => 'Record not found.'];
        }
        $row = $res['data'][0];
        $payload = is_string($row['payload']) ? json_decode($row['payload'], true) : $row['payload'];
        $sent = $this->send(
            $row['recipient_email'],
            $payload['subject'] ?? 'Notification',
            $payload['body'] ?? ''
        );
        if ($sent) {
            $this->db->from('failed_notifications', 'DELETE', null, 'id=eq.' . $failedId, true);
        } else {
            $this->db->from('failed_notifications', 'PATCH', [
                'retry_count' => ((int) ($row['retry_count'] ?? 0)) + 1,
            ], 'id=eq.' . $failedId, true);
        }
        return ['success' => $sent, 'message' => $sent ? 'Email sent.' : 'Retry failed.'];
    }
}
