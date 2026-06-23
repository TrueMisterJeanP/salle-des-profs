<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';

function contact_messages_ensure_table(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS contact_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            subject TEXT NOT NULL,
            message TEXT NOT NULL,
            ip_address TEXT,
            user_agent TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            replied_at TEXT,
            replied_by INTEGER,
            reply_subject TEXT,
            reply_message TEXT,
            FOREIGN KEY(replied_by) REFERENCES users(id) ON DELETE SET NULL
        )"
    );

    db_exec_schema(
        "CREATE INDEX IF NOT EXISTS idx_contact_messages_created_at
         ON contact_messages(created_at)"
    );

    db_exec_schema(
        "CREATE INDEX IF NOT EXISTS idx_contact_messages_replied_at
         ON contact_messages(replied_at)"
    );

    $done = true;
}

function contact_captcha_generate(): string
{
    csrf_start_session();

    $left = random_int(2, 19);
    $right = random_int(2, 19);
    $operation = random_int(0, 1) === 1 ? '+' : '-';

    if ($operation === '-' && $right > $left) {
        [$left, $right] = [$right, $left];
    }

    $_SESSION['contact_captcha_answer'] = $operation === '+'
        ? $left + $right
        : $left - $right;
    $_SESSION['contact_captcha_question'] = $left . ' ' . $operation . ' ' . $right;

    return (string)$_SESSION['contact_captcha_question'];
}

function contact_captcha_question(): string
{
    csrf_start_session();

    if (empty($_SESSION['contact_captcha_question']) || !isset($_SESSION['contact_captcha_answer'])) {
        return contact_captcha_generate();
    }

    return (string)$_SESSION['contact_captcha_question'];
}

function contact_captcha_verify(string $answer): bool
{
    csrf_start_session();

    if (!isset($_SESSION['contact_captcha_answer'])) {
        return false;
    }

    return (int)$answer === (int)$_SESSION['contact_captcha_answer'];
}

function contact_captcha_clear(): void
{
    csrf_start_session();
    unset($_SESSION['contact_captcha_answer'], $_SESSION['contact_captcha_question']);
}

function contact_message_ip_address(): string
{
    return function_exists('activity_log_ip_address') ? activity_log_ip_address() : '';
}
