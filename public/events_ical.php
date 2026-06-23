<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/protection.php';

function ical_escape_text(?string $value): string
{
    $value = trim((string)$value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(';', '\;', $value);
    $value = str_replace(',', '\,', $value);

    return str_replace("\n", '\n', $value);
}

function ical_fold_line(string $line): string
{
    if (mb_strlen($line, 'UTF-8') <= 73) {
        return $line;
    }

    $folded = [];
    $folded[] = mb_substr($line, 0, 73, 'UTF-8');
    $line = mb_substr($line, 73, null, 'UTF-8');

    while ($line !== '') {
        $folded[] = ' ' . mb_substr($line, 0, 72, 'UTF-8');
        $line = mb_substr($line, 72, null, 'UTF-8');
    }

    return implode("\r\n", $folded);
}

function ical_line(string $name, string $value): string
{
    return ical_fold_line($name . ':' . $value);
}

function ical_datetime(?string $value): ?string
{
    $timestamp = $value ? strtotime($value) : false;

    if ($timestamp === false) {
        return null;
    }

    return gmdate('Ymd\THis\Z', $timestamp);
}

function ical_uid_host(): string
{
    $host = parse_url(site_canonical_base_url(), PHP_URL_HOST);

    return is_string($host) && $host !== '' ? $host : 'localhost';
}

function ical_event_description(array $event): string
{
    $parts = [];
    $description = trim((string)($event['description'] ?? ''));
    $responseOwner = trim((string)($event['response_owner'] ?? ''));
    $responseSummary = trim((string)($event['response_summary'] ?? ''));

    if ($description !== '') {
        $parts[] = $description;
    }

    if ($responseOwner !== '') {
        $parts[] = 'Réponse portée par : ' . $responseOwner;
    }

    if ($responseSummary !== '') {
        $parts[] = 'Réponse ou décision : ' . $responseSummary;
    }

    return implode("\n\n", $parts);
}

$configuredToken = ical_token();
$receivedToken = trim(get_value('token'));

if (
    !ical_enabled()
    || !ical_token_is_valid($configuredToken)
    || !ical_token_is_valid($receivedToken)
    || !hash_equals($configuredToken, $receivedToken)
) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Calendrier indisponible.';
    exit;
}

try {
    protection_ensure_schema();

    $events = db_fetch_all(
        "SELECT *
         FROM protection_events
         WHERE is_active = 1
         ORDER BY starts_at ASC, created_at ASC"
    );
} catch (Throwable) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Calendrier indisponible.';
    exit;
}

$host = ical_uid_host();
$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Salle des profs//Calendrier iCal//FR',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'X-PUBLISHED-TTL:PT15M',
    ical_line('X-WR-CALNAME', ical_escape_text(site_name() . ' - Événements')),
    ical_line('X-WR-CALDESC', ical_escape_text('Calendrier des événements de ' . site_name())),
];

foreach ($events as $event) {
    $startDate = ical_datetime((string)$event['starts_at']);

    if ($startDate === null) {
        continue;
    }

    $endDate = ical_datetime((string)($event['ends_at'] ?? ''));
    $updatedDate = ical_datetime((string)($event['updated_at'] ?: $event['created_at'])) ?? gmdate('Ymd\THis\Z');
    $eventUrl = url('events.php?month=' . urlencode(substr((string)$event['starts_at'], 0, 7)) . '&event=' . (int)$event['id'] . '#month-event-detail');
    $description = ical_event_description($event);

    $lines[] = 'BEGIN:VEVENT';
    $lines[] = ical_line('UID', 'protection-event-' . (int)$event['id'] . '@' . $host);
    $lines[] = ical_line('DTSTAMP', gmdate('Ymd\THis\Z'));
    $lines[] = ical_line('LAST-MODIFIED', $updatedDate);
    $lines[] = ical_line('DTSTART', $startDate);

    if ($endDate !== null) {
        $lines[] = ical_line('DTEND', $endDate);
    }

    $lines[] = ical_line('SUMMARY', ical_escape_text((string)$event['title']));

    if ($description !== '') {
        $lines[] = ical_line('DESCRIPTION', ical_escape_text($description));
    }

    if ((string)$event['location'] !== '') {
        $lines[] = ical_line('LOCATION', ical_escape_text((string)$event['location']));
    }

    $lines[] = ical_line('CATEGORIES', ical_escape_text(protection_label(PROTECTION_EVENT_TYPES, (string)$event['event_type'])));
    $lines[] = ical_line('URL', $eventUrl);
    $lines[] = 'END:VEVENT';
}

$lines[] = 'END:VCALENDAR';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="evenements.ics"');
header('Cache-Control: no-store');

echo implode("\r\n", $lines) . "\r\n";
