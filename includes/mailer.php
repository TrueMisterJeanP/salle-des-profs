<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/settings.php';

function mail_default_from_email(): string
{
    $host = function_exists('app_request_host') ? app_request_host() : (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/:\d+$/', '', $host) ?: 'localhost';

    return 'no-reply@' . $host;
}

function mail_from_email(): string
{
    $email = setting_value('mail_from_email', '');

    return is_valid_email($email) ? $email : mail_default_from_email();
}

function mail_from_name(): string
{
    return setting_value('mail_from_name', site_name());
}

function mail_encryption_options(): array
{
    return ['none', 'ssl', 'tls'];
}

function mail_setting_encryption(string $key): string
{
    $encryption = setting_value($key, 'none');

    return in_array($encryption, mail_encryption_options(), true) ? $encryption : 'none';
}

function mail_smtp_is_configured(): bool
{
    return setting_value('smtp_host', '') !== '';
}

function mail_clean_header_value(string $value): string
{
    return trim(str_replace(["\r", "\n"], ' ', $value));
}

function mail_encode_header(string $value): string
{
    $value = mail_clean_header_value($value);

    if (preg_match('/^[\x20-\x7E]*$/', $value)) {
        return $value;
    }

    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function mail_send_text(string $toEmail, string $toName, string $subject, string $body, ?string $htmlBody = null): bool
{
    if (!is_valid_email($toEmail)) {
        return false;
    }

    if (mail_smtp_is_configured()) {
        return mail_send_text_smtp($toEmail, $toName, $subject, $body, $htmlBody);
    }

    return mail_send_text_native($toEmail, $toName, $subject, $body, $htmlBody);
}

function mail_send_text_native(string $toEmail, string $toName, string $subject, string $body, ?string $htmlBody = null): bool
{
    if (!function_exists('mail')) {
        return false;
    }

    $fromEmail = mail_from_email();
    $fromName = mail_from_name();
    $encodedSubject = mail_encode_header($subject);
    $encodedFromName = mail_encode_header($fromName);
    $toHeader = mail_clean_header_value($toEmail);

    if ($toName !== '') {
        $toHeader = mail_encode_header($toName) . ' <' . $toHeader . '>';
    }

    $headers = [
        'From: ' . $encodedFromName . ' <' . mail_clean_header_value($fromEmail) . '>',
        'Reply-To: ' . mail_clean_header_value($fromEmail),
        'MIME-Version: 1.0',
        'X-Mailer: ' . mail_clean_header_value(APP_NAME),
    ];

    if ($htmlBody !== null && trim($htmlBody) !== '') {
        $boundary = 'salle-des-profs-' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $message = mail_multipart_body($body, $htmlBody, $boundary);
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $message = str_replace(["\r\n", "\r"], "\n", $body);
    }

    return mail($toHeader, $encodedSubject, $message, implode("\r\n", $headers));
}

function mail_html_document(string $htmlBody): string
{
    return '<!doctype html><html><head><meta charset="utf-8"></head><body>'
        . '<div style="font-family: system-ui, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif; line-height: 1.5;">'
        . $htmlBody
        . '</div></body></html>';
}

function mail_multipart_body(string $textBody, string $htmlBody, string $boundary): string
{
    $textBody = str_replace(["\r\n", "\r"], "\n", $textBody);
    $htmlBody = mail_html_document($htmlBody);

    return "--$boundary\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . str_replace("\n", "\r\n", $textBody) . "\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . str_replace(["\r\n", "\r", "\n"], "\r\n", $htmlBody) . "\r\n"
        . "--$boundary--";
}

function mail_message_data(string $toEmail, string $toName, string $subject, string $body, ?string $htmlBody = null): string
{
    $fromEmail = mail_from_email();
    $fromName = mail_from_name();
    $toHeader = mail_clean_header_value($toEmail);

    if ($toName !== '') {
        $toHeader = mail_encode_header($toName) . ' <' . $toHeader . '>';
    }

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . mail_encode_header($fromName) . ' <' . mail_clean_header_value($fromEmail) . '>',
        'To: ' . $toHeader,
        'Subject: ' . mail_encode_header($subject),
        'Reply-To: ' . mail_clean_header_value($fromEmail),
        'MIME-Version: 1.0',
        'X-Mailer: ' . mail_clean_header_value(APP_NAME),
    ];

    if ($htmlBody !== null && trim($htmlBody) !== '') {
        $boundary = 'salle-des-profs-' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = mail_multipart_body($body, $htmlBody, $boundary);
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = str_replace("\n", "\r\n", $body);
    }

    $body = preg_replace('/^\./m', '..', $body) ?? $body;

    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

function mail_smtp_read($socket): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    return $response;
}

function mail_smtp_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    $response = mail_smtp_read($socket);
    $code = (int)substr($response, 0, 3);

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Réponse SMTP inattendue : ' . trim($response));
    }

    return $response;
}

function mail_send_text_smtp(string $toEmail, string $toName, string $subject, string $body, ?string $htmlBody = null): bool
{
    $host = setting_value('smtp_host', '');
    $port = (int)setting_value('smtp_port', '0');
    $encryption = mail_setting_encryption('smtp_encryption');
    $username = setting_value('smtp_username', '');
    $password = setting_value('smtp_password', '');
    $fromEmail = mail_from_email();

    if ($host === '') {
        return false;
    }

    if ($port <= 0) {
        $port = $encryption === 'ssl' ? 465 : 587;
    }

    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);

    if (!is_resource($socket)) {
        return false;
    }

    stream_set_timeout($socket, 20);

    try {
        $greeting = mail_smtp_read($socket);

        if ((int)substr($greeting, 0, 3) !== 220) {
            throw new RuntimeException('Connexion SMTP refusée.');
        }

        $serverName = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        mail_smtp_command($socket, 'EHLO ' . $serverName, [250]);

        if ($encryption === 'tls') {
            mail_smtp_command($socket, 'STARTTLS', [220]);

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Impossible d’activer TLS.');
            }

            mail_smtp_command($socket, 'EHLO ' . $serverName, [250]);
        }

        if ($username !== '' || $password !== '') {
            mail_smtp_command($socket, 'AUTH LOGIN', [334]);
            mail_smtp_command($socket, base64_encode($username), [334]);
            mail_smtp_command($socket, base64_encode($password), [235]);
        }

        mail_smtp_command($socket, 'MAIL FROM:<' . mail_clean_header_value($fromEmail) . '>', [250]);
        mail_smtp_command($socket, 'RCPT TO:<' . mail_clean_header_value($toEmail) . '>', [250, 251]);
        mail_smtp_command($socket, 'DATA', [354]);
        mail_smtp_command($socket, mail_message_data($toEmail, $toName, $subject, $body, $htmlBody) . "\r\n.", [250]);
        mail_smtp_command($socket, 'QUIT', [221]);

        fclose($socket);

        return true;
    } catch (Throwable $e) {
        fclose($socket);

        return false;
    }
}

function mail_recipients_all_users(): array
{
    return db_fetch_all(
        "SELECT id, email, username, display_name
         FROM users
         WHERE is_active = 1
           AND email != ''
         ORDER BY display_name COLLATE NOCASE ASC, username COLLATE NOCASE ASC"
    );
}

function mail_recipients_by_user_ids(array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));

    if (!$userIds) {
        return [];
    }

    $placeholders = [];
    $params = [];

    foreach ($userIds as $index => $userId) {
        $key = 'user_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $userId;
    }

    return db_fetch_all(
        "SELECT id, email, username, display_name
         FROM users
         WHERE is_active = 1
           AND email != ''
           AND id IN (" . implode(',', $placeholders) . ")
         ORDER BY display_name COLLATE NOCASE ASC, username COLLATE NOCASE ASC",
        $params
    );
}

function mail_recipients_by_group_id(int $groupId): array
{
    if ($groupId <= 0) {
        return [];
    }

    return db_fetch_all(
        "SELECT users.id, users.email, users.username, users.display_name
         FROM group_members
         JOIN users ON users.id = group_members.user_id
         WHERE group_members.group_id = :group_id
           AND users.is_active = 1
           AND users.email != ''
         ORDER BY users.display_name COLLATE NOCASE ASC, users.username COLLATE NOCASE ASC",
        ['group_id' => $groupId]
    );
}

function mail_deduplicate_recipients(array $recipients): array
{
    $deduplicated = [];
    $seen = [];

    foreach ($recipients as $recipient) {
        $email = mb_strtolower((string)($recipient['email'] ?? ''), 'UTF-8');

        if ($email === '' || isset($seen[$email])) {
            continue;
        }

        $seen[$email] = true;
        $deduplicated[] = $recipient;
    }

    return $deduplicated;
}
