<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/protection.php';

require_login();
protection_ensure_schema();

$user = current_user();
$eventVisibility = protection_user_visibility_where($user, 'e');
$incidentVisibility = protection_user_visibility_where($user, 'i');
$actionVisibility = protection_user_visibility_where($user, 'a');
$stats = protection_fetch_stats($user);
$events = db_fetch_all(
    "SELECT e.*
     FROM protection_events e
     WHERE e.is_active = 1
       AND $eventVisibility
     ORDER BY e.starts_at DESC, e.created_at DESC
     LIMIT 10"
);
$incidents = db_fetch_all(
    "SELECT i.*
     FROM protection_incidents i
     WHERE i.is_active = 1
       AND $incidentVisibility
     ORDER BY i.occurred_at DESC, i.created_at DESC
     LIMIT 10"
);
$actions = db_fetch_all(
    "SELECT a.*
     FROM protection_action_plans a
     WHERE a.is_active = 1
       AND a.status NOT IN ('done', 'abandoned')
       AND $actionVisibility
     ORDER BY a.planned_at IS NULL ASC, a.planned_at ASC, a.created_at DESC
     LIMIT 10"
);
$flashes = get_flashes();
$formatShortDateTime = static function (?string $value): string {
    $timestamp = $value ? strtotime($value) : false;
    return $timestamp !== false ? date('d/m', $timestamp) . "\n" . date('H:i', $timestamp) : 'Date à préciser';
};
$formatShortDateTimeInline = static function (?string $value): string {
    $timestamp = $value ? strtotime($value) : false;
    return $timestamp !== false ? date('d/m H:i', $timestamp) : 'Date à préciser';
};
$formatFullDateTimeInline = static function (?string $value): string {
    $timestamp = $value ? strtotime($value) : false;
    return $timestamp !== false ? date('d/m/Y H:i', $timestamp) : 'Date à préciser';
};
$formatShortDateFull = static function (?string $value): string {
    $timestamp = $value ? strtotime($value) : false;
    return $timestamp !== false ? date('d/m/y', $timestamp) : 'Date à préciser';
};
$formatShortDate = static function (?string $value): string {
    $timestamp = $value ? strtotime($value) : false;
    return $timestamp !== false ? date('d/m', $timestamp) : 'Date à préciser';
};
$shortMonthNames = [
    1 => 'janv.',
    2 => 'févr.',
    3 => 'mars',
    4 => 'avr.',
    5 => 'mai',
    6 => 'juin',
    7 => 'juil.',
    8 => 'août',
    9 => 'sept.',
    10 => 'oct.',
    11 => 'nov.',
    12 => 'déc.',
];
$eventCount = count($events);
$eventPage = max(1, (int)get_value('event_page', '1'));
if ($eventCount > 0 && $eventPage > $eventCount) {
    $eventPage = $eventCount;
}
$selectedEvent = $eventCount > 0 ? $events[$eventPage - 1] : null;

$incidentCount = count($incidents);
$incidentPage = max(1, (int)get_value('incident_page', '1'));
if ($incidentCount > 0 && $incidentPage > $incidentCount) {
    $incidentPage = $incidentCount;
}
$selectedIncident = $incidentCount > 0 ? $incidents[$incidentPage - 1] : null;

$actionCount = count($actions);
$actionPage = max(1, (int)get_value('action_page', '1'));
if ($actionCount > 0 && $actionPage > $actionCount) {
    $actionPage = $actionCount;
}
$selectedAction = $actionCount > 0 ? $actions[$actionPage - 1] : null;

$dashboardPageUrl = static function (
    int $targetEventPage,
    int $targetIncidentPage,
    int $targetActionPage,
    string $anchor
): string {
    return url('protection.php?' . http_build_query([
        'event_page' => $targetEventPage,
        'incident_page' => $targetIncidentPage,
        'action_page' => $targetActionPage,
    ]) . $anchor);
};
?>
<!doctype html>
<html lang="fr">
<head>
    <meta name="theme-color" content="#ffffff">
    <meta charset="utf-8">
    <title>Protection enseignants — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:FILL@1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body>
    <?php require __DIR__ . '/../templates/header.php'; ?>

    <main class="container page protection-page">
        <?php foreach ($flashes as $flash): ?>
            <div class="<?= e(flash_class($flash['type'])) ?>"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>

        <section class="protection-hero">
            <div>
                <h1>Établissement</h1>
                <p>
                    Centralisez les événements, incidents, actions, textes officiels,
                    échanges entre enseignants et publications publiques ou ActivityPub.
                </p>
            </div>
            <nav class="protection-quick-actions" aria-label="Actions principales">
                <a class="button-primary" href="<?= e(url('events.php')) ?>">Ajouter un événement</a>
                <a class="button-secondary" href="<?= e(url('incidents.php')) ?>">Déclarer un incident</a>
                <a class="button-secondary" href="<?= e(url('actions.php')) ?>">Ajouter une action</a>
            </nav>
        </section>

        <section class="grid grid-4 dashboard-grid protection-stats-grid">
            <article class="card dashboard-card"><h2>Événements</h2><p class="stat"><?= e((string)$stats['events']) ?></p><p class="muted">faits et réponses calendaires</p></article>
            <article class="card dashboard-card"><h2>Incidents</h2><p class="stat"><?= e((string)$stats['open_incidents']) ?></p><p class="muted">à suivre ou signalés</p></article>
            <article class="card dashboard-card"><h2>Actions</h2><p class="stat"><?= e((string)$stats['actions']) ?></p><p class="muted">mobilisations en cours</p></article>
        </section>

        <section class="grid grid-2 protection-dashboard-grid">
                <article class="card protection-calendar-card protection-dashboard-calendar">
                    <div class="section-heading-row">
                        <h2>Calendrier des événements</h2>
                    </div>
                    <div class="compact-list protection-summary-list" id="protection-calendar-list">
                        <?php if ($selectedEvent === null): ?>
                            <p class="muted">Aucun événement enregistré.</p>
                        <?php else: ?>
                            <?php
                                $event = $selectedEvent;
                                $eventSummary = (string)($event['description'] ?: '');
                                $eventHref = url('events.php?month=' . urlencode(substr((string)$event['starts_at'], 0, 7)) . '&event=' . (int)$event['id'] . '#month-event-detail');
                                $eventStartTimestamp = strtotime((string)$event['starts_at']);
                                $eventEndTimestamp = $event['ends_at'] ? strtotime((string)$event['ends_at']) : false;
                                $eventTimeRangeLabel = $eventStartTimestamp !== false ? date('H:i', $eventStartTimestamp) : '';
                                if ($eventTimeRangeLabel !== '' && $eventEndTimestamp !== false) {
                                    $eventTimeRangeLabel .= ' ' . date('H:i', $eventEndTimestamp);
                                }
                            ?>
                            <article class="protection-brief-item">
                                <div class="calendar-date protection-calendar-date">
                                    <strong><?= e($eventStartTimestamp !== false ? date('d', $eventStartTimestamp) : '--') ?></strong>
                                    <span><?= e($eventStartTimestamp !== false ? ($shortMonthNames[(int)date('n', $eventStartTimestamp)] ?? '') : '') ?></span>
                                    <?php if ($eventTimeRangeLabel !== ''): ?>
                                        <small><?= e($eventTimeRangeLabel) ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="protection-brief-main">
                                    <div class="protection-brief-head">
                                        <a class="protection-brief-link" href="<?= e($eventHref) ?>"><strong class="protection-summary-title"><?= e($event['title']) ?></strong></a>
                                        <span class="protection-badge-row">
                                            <span class="badge"><?= e(protection_label(PROTECTION_EVENT_TYPES, (string)$event['event_type'])) ?></span>
                                            <?php if (($event['visibility'] ?? '') === 'public'): ?>
                                                <span class="badge badge-public" title="Public">
                                                    <span class="material-symbols-outlined" aria-hidden="true">public</span>
                                                    <span class="sr-only">Public</span>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </div>

                                    <?php if ($eventSummary !== ''): ?><p class="protection-brief-text"><?= e(excerpt($eventSummary, 150)) ?></p><?php endif; ?>
                                    
                                    <p class="protection-brief-meta">
                                        <?= e($event['location'] ?: 'Lieu non renseigné') ?>
                                        <?php if ($event['response_owner']): ?><?php endif; ?>
                                    </p>
                                
                                </div>
                            </article>
                        <?php endif; ?>
                    </div>
                    <?php if ($eventCount > 1): ?>
                        <nav class="pagination protection-brief-pagination" aria-label="Pages du calendrier">
                            <?php for ($pageNumber = 1; $pageNumber <= $eventCount; $pageNumber++): ?>
                                <?php if ($pageNumber === $eventPage): ?>
                                    <span class="button-primary pagination-current" aria-current="page"><?= e((string)$pageNumber) ?></span>
                                <?php else: ?>
                                    <a class="button-secondary" href="<?= e($dashboardPageUrl($pageNumber, $incidentPage, $actionPage, '#protection-calendar-list')) ?>"><?= e((string)$pageNumber) ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </nav>
                    <?php endif; ?>
                    <div class="dashboard-card-more">
                        <a class="button-secondary" href="<?= e(url('events.php')) ?>">Tout voir</a>
                    </div>
                </article>

                <article class="card protection-dashboard-incident">
                    <div class="section-heading-row">
                        <h2>Incidents signalés</h2>
                    </div>
                    <?php if ($selectedIncident === null): ?>
                        <p class="muted">Aucun incident répertorié.</p>
                    <?php else: ?>
                        <div class="compact-list protection-summary-list" id="protection-incident-list">
                            <?php
                                $incident = $selectedIncident;
                                $incidentSummary = (string)($incident['description']);
                            ?>
                            <article class="protection-brief-item protection-brief-item-alert protection-brief-item-no-date">
                                <div class="protection-brief-main">
                                    <div class="protection-brief-head">
                                        <a href="<?= e(url('incidents.php')) ?>"><strong class="protection-summary-title"><?= e($incident['title']) ?></strong></a>
                                        <span class="protection-badge-row">
                                            <span class="badge"><?= e(protection_label(PROTECTION_SEVERITIES, (string)$incident['severity'])) ?></span>
                                            <span class="badge badge-muted"><?= e(protection_label(PROTECTION_INCIDENT_TYPES, (string)$incident['incident_type'])) ?></span>
                                            <?php if (($incident['visibility'] ?? '') === 'public'): ?>
                                                <span class="badge badge-public" title="Public">
                                                    <span class="material-symbols-outlined" aria-hidden="true">public</span>
                                                    <span class="sr-only">Public</span>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    
                                    <p class="protection-brief-text"><?= e(excerpt($incidentSummary, 155)) ?></p>
                                    
                                    <p class="protection-brief-meta protection-brief-meta-row">
                                        <?= e(protection_label(PROTECTION_INCIDENT_SOURCES, (string)$incident['source_type'])) ?>
                                        <span class="protection-brief-meta-date"><?= e($formatFullDateTimeInline((string)$incident['occurred_at'])) ?></span>
                                        <?php if ($incident['location']): ?><?php endif; ?>

                                    </p>
                                    
                                </div>
                            </article>
                        </div>
                        <?php if ($incidentCount > 1): ?>
                            <nav class="pagination protection-brief-pagination" aria-label="Pages des incidents signalés">
                                <?php for ($pageNumber = 1; $pageNumber <= $incidentCount; $pageNumber++): ?>
                                    <?php if ($pageNumber === $incidentPage): ?>
                                        <span class="button-primary pagination-current" aria-current="page"><?= e((string)$pageNumber) ?></span>
                                    <?php else: ?>
                                        <a class="button-secondary" href="<?= e($dashboardPageUrl($eventPage, $pageNumber, $actionPage, '#protection-incident-list')) ?>"><?= e((string)$pageNumber) ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="dashboard-card-more">
                        <a class="button-secondary" href="<?= e(url('incidents.php')) ?>">Consulter l'inventaire</a>
                    </div>
                </article>

                <article class="card protection-dashboard-action">
                    <div class="section-heading-row">
                        <h2>Actions collectives</h2>
                    </div>
                    <?php if ($selectedAction === null): ?>
                        <p class="muted">Aucune action active.</p>
                    <?php else: ?>
                        <div class="compact-list protection-summary-list" id="protection-action-list">
                            <?php
                                $action = $selectedAction;
                                $actionSummary = (string)($action['steps'] ?: $action['risks'] ?: $action['legal_frame'] ?: $action['objective']);
                                $actionMonthSource = $action['planned_at'];
                            ?>
                            <article class="protection-brief-item protection-brief-item-action protection-brief-item-no-date">
                                <div class="protection-brief-main">
                                    <div class="protection-brief-head">
                                        <a href="<?= e(url('actions.php?edit=' . (int)$action['id'] . '#title')) ?>"><strong class="protection-summary-title"><?= e($action['title']) ?></strong></a>
                                        <span class="protection-badge-row">
                                            <span class="badge"><?= e(protection_label(PROTECTION_ACTION_STATUSES, (string)$action['status'])) ?></span>
                                            <?php if (($action['visibility'] ?? '') === 'public'): ?>
                                                <span class="badge badge-public" title="Public">
                                                    <span class="material-symbols-outlined" aria-hidden="true">public</span>
                                                    <span class="sr-only">Public</span>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    
                                    <p class="protection-brief-text"><?= e(excerpt((string)$action['objective'], 120)) ?></p>
                                    
                                    <p class="protection-brief-meta protection-brief-meta-row">
                                        <?= e(protection_label(PROTECTION_ACTION_CATEGORIES, (string)$action['category'])) ?>
                                        <span class="protection-brief-meta-date"><?= e($formatFullDateTimeInline((string)$actionMonthSource)) ?></span>
                                    </p>
                                    
                                </div>
                            </article>
                        </div>
                        <?php if ($actionCount > 1): ?>
                            <nav class="pagination protection-brief-pagination" aria-label="Pages des actions collectives">
                                <?php for ($pageNumber = 1; $pageNumber <= $actionCount; $pageNumber++): ?>
                                    <?php if ($pageNumber === $actionPage): ?>
                                        <span class="button-primary pagination-current" aria-current="page"><?= e((string)$pageNumber) ?></span>
                                    <?php else: ?>
                                        <a class="button-secondary" href="<?= e($dashboardPageUrl($eventPage, $incidentPage, $pageNumber, '#protection-action-list')) ?>"><?= e((string)$pageNumber) ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="dashboard-card-more">
                        <a class="button-secondary" href="<?= e(url('actions.php')) ?>">Piloter les actions</a>
                    </div>
                </article>
        </section>

        <section class="card">
            <h2>Communication entre enseignants</h2>
            <p class="muted">
                Les groupes et la messagerie existants servent d’espaces sécurisés pour les collectifs,
                équipes pédagogiques, heures d’information syndicale et suivis d’établissement.
            </p>
            <div class="form-actions">
                <a class="button-secondary" href="<?= e(url('chat.php')) ?>">Messages privés</a>
                <a class="button-secondary" href="<?= e(url('groups.php')) ?>">Messages de groupes</a>
            </div>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
    <script src="<?= e(url('assets/app.js') . '?v=' . filemtime(__DIR__ . '/assets/app.js')) ?>"></script>
</body>
</html>
