<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/protection.php';

require_login();
protection_ensure_schema();

$user = current_user();
$errors = [];
$editId = (int)get_value('edit', '0');
$editEvent = null;
$groupOptions = user_group_options((int)$user['id']);
$allowedVisibility = array_keys(content_visibility_options(true));

function event_datetime_from_parts(string $date, string $time): string
{
    $date = trim($date);
    $time = trim($time);

    return $date !== '' && $time !== '' ? $date . ' ' . $time : '';
}

function event_user_can_manage(array $record, array $user): bool
{
    return ($user['role'] ?? '') === 'admin'
        || (
            !empty($user['id'])
            && (int)($record['created_by'] ?? 0) === (int)$user['id']
        );
}

if ($editId > 0) {
    $editEvent = db_fetch_one(
        "SELECT * FROM protection_events WHERE id = :id AND is_active = 1 LIMIT 1",
        ['id' => $editId]
    );
    if ($editEvent && !event_user_can_manage($editEvent, $user)) {
        set_flash('error', 'Vous ne pouvez pas modifier cet événement.');
        redirect(url('events.php'));
    }
}

if (is_post()) {
    require_csrf();
    $action = post_value('action', 'create');
    $id = (int)post_value('id', '0');
    $startDate = post_value('starts_at_date');
    $startTime = post_value('starts_at_time');
    $endDate = post_value('ends_at_date');
    $endTime = post_value('ends_at_time');

    if ($endDate === '' && $endTime !== '') {
        $endDate = $startDate;
    } elseif ($endDate !== '' && $endTime === '') {
        $endTime = $startTime !== '' ? $startTime : '23:59';
    }

    if ($action === 'delete' && $id > 0) {
        $event = db_fetch_one("SELECT * FROM protection_events WHERE id = :id AND is_active = 1 LIMIT 1", ['id' => $id]);
        if (!$event || !event_user_can_manage($event, $user)) {
            $errors[] = 'Événement introuvable ou non modifiable.';
        } else {
            db_query(
                "UPDATE protection_events SET is_active = 0, updated_at = :updated_at WHERE id = :id",
                ['updated_at' => now(), 'id' => $id]
            );
            set_flash('success', 'Événement supprimé.');
            redirect(url('events.php'));
        }
    }

    $data = [
        'title' => post_value('title'),
        'event_type' => post_value('event_type', 'incident'),
        'starts_at' => event_datetime_from_parts($startDate, $startTime),
        'ends_at' => event_datetime_from_parts($endDate, $endTime),
        'location' => post_value('location'),
        'description' => post_value('description'),
        'response_owner' => post_value('response_owner'),
        'response_summary' => post_value('response_summary'),
        'visibility' => post_value('visibility', 'members'),
        'group_id' => null,
    ];
    $groupId = (int)post_value('group_id', '0');

    if ($data['title'] === '') {
        $errors[] = 'Le titre est obligatoire.';
    }
    if ($startDate === '' || $startTime === '') {
        $errors[] = 'La date et l’heure de début sont obligatoires.';
    } elseif (strtotime($data['starts_at']) === false) {
        $errors[] = 'La date ou l’heure de début est invalide.';
    }
    if ($data['ends_at'] !== '' && strtotime($data['ends_at']) === false) {
        $errors[] = 'La date ou l’heure de fin est invalide.';
    } elseif ($data['ends_at'] !== '' && strtotime($data['starts_at']) !== false && strtotime($data['ends_at']) < strtotime($data['starts_at'])) {
        $errors[] = 'La fin de l’évènement doit être postérieure au début.';
    }
    if (!isset(PROTECTION_EVENT_TYPES[$data['event_type']])) {
        $errors[] = 'Type d’événement invalide.';
    }
    if (!in_array($data['visibility'], $allowedVisibility, true)) {
        $errors[] = 'Visibilité invalide.';
    }
    $data['group_id'] = normalize_group_visibility($data['visibility'], $groupId, $errors);
    if ($data['group_id'] !== null && !user_can_use_group((int)$user['id'], (int)$data['group_id'])) {
        $errors[] = 'Groupe invalide.';
    }

    if (!$errors) {
        if ($action === 'update' && $id > 0) {
            $event = db_fetch_one("SELECT * FROM protection_events WHERE id = :id AND is_active = 1 LIMIT 1", ['id' => $id]);
            if (!$event || !event_user_can_manage($event, $user)) {
                $errors[] = 'Événement introuvable ou non modifiable.';
            } else {
                db_query(
                    "UPDATE protection_events
                     SET title = :title, event_type = :event_type, starts_at = :starts_at, ends_at = :ends_at,
                         location = :location, description = :description, response_owner = :response_owner,
                         response_summary = :response_summary, visibility = :visibility, group_id = :group_id,
                         updated_at = :updated_at
                     WHERE id = :id",
                    $data + ['updated_at' => now(), 'id' => $id]
                );
                set_flash('success', 'Événement mis à jour.');
                redirect(url('events.php'));
            }
        } else {
            db_insert(
                "INSERT INTO protection_events
                 (title, event_type, starts_at, ends_at, location, description, response_owner,
                  response_summary, visibility, group_id, created_by, created_at)
                 VALUES (:title, :event_type, :starts_at, :ends_at, :location, :description,
                  :response_owner, :response_summary, :visibility, :group_id, :created_by, :created_at)",
                $data + ['created_by' => (int)$user['id'], 'created_at' => now()]
            );
            set_flash('success', 'Événement ajouté au calendrier.');
            redirect(url('events.php'));
        }
    }

    if (!$errors) {
        redirect(url('events.php'));
    }
}

$month = get_value('month', date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$start = $month . '-01 00:00:00';
$end = date('Y-m-d 23:59:59', strtotime($start . ' +1 month -1 day'));
$monthTimestamp = strtotime($start);
$monthNames = [
    1 => 'janvier',
    2 => 'février',
    3 => 'mars',
    4 => 'avril',
    5 => 'mai',
    6 => 'juin',
    7 => 'juillet',
    8 => 'août',
    9 => 'septembre',
    10 => 'octobre',
    11 => 'novembre',
    12 => 'décembre',
];
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
$monthLabel = ucfirst($monthNames[(int)date('n', $monthTimestamp)] ?? $month) . ' ' . date('Y', $monthTimestamp);
$prevMonth = date('Y-m', strtotime($start . ' -1 month'));
$nextMonth = date('Y-m', strtotime($start . ' +1 month'));
$firstWeekday = (int)date('N', $monthTimestamp);
$daysInMonth = (int)date('t', $monthTimestamp);
$calendarCells = [];
$eventsByDay = [];
$events = db_fetch_all(
    "SELECT e.*, u.username, u.display_name, g.name AS group_name
     FROM protection_events e
     JOIN users u ON u.id = e.created_by
     LEFT JOIN groups g ON g.id = e.group_id
     WHERE (
         e.starts_at BETWEEN :start AND :end
         OR (
             e.ends_at IS NOT NULL
             AND e.ends_at <> ''
             AND e.starts_at <= :end
             AND e.ends_at >= :start
         )
     )
       AND e.is_active = 1
     ORDER BY e.starts_at ASC",
    ['start' => $start, 'end' => $end]
);

$monthStartDate = substr($start, 0, 10);
$monthEndDate = substr($end, 0, 10);
foreach ($events as $event) {
    $eventStartTimestamp = strtotime((string)$event['starts_at']);
    if ($eventStartTimestamp === false) {
        continue;
    }
    $eventEndTimestamp = $event['ends_at'] ? strtotime((string)$event['ends_at']) : false;
    if ($eventEndTimestamp === false || $eventEndTimestamp < $eventStartTimestamp) {
        $eventEndTimestamp = $eventStartTimestamp;
    }

    $firstEventDay = max(date('Y-m-d', $eventStartTimestamp), $monthStartDate);
    $lastEventDay = min(date('Y-m-d', $eventEndTimestamp), $monthEndDate);
    $currentDayTimestamp = strtotime($firstEventDay);
    $lastDayTimestamp = strtotime($lastEventDay);
    while ($currentDayTimestamp !== false && $lastDayTimestamp !== false && $currentDayTimestamp <= $lastDayTimestamp) {
        $dayKey = date('Y-m-d', $currentDayTimestamp);
        $eventsByDay[$dayKey][] = $event;
        $currentDayTimestamp = strtotime('+1 day', $currentDayTimestamp);
    }
}

$selectedEventId = (int)get_value('event', '0');
$selectedEvent = null;
foreach ($events as $event) {
    if ((int)$event['id'] === $selectedEventId) {
        $selectedEvent = $event;
        break;
    }
}
if ($selectedEvent === null && $events) {
    $todayStartTimestamp = strtotime(date('Y-m-d 00:00:00'));

    foreach ($events as $event) {
        $eventStartTimestamp = strtotime((string)$event['starts_at']);
        if ($eventStartTimestamp === false) {
            continue;
        }

        $eventEndTimestamp = $event['ends_at'] ? strtotime((string)$event['ends_at']) : false;
        if ($eventEndTimestamp === false || $eventEndTimestamp < $eventStartTimestamp) {
            $eventEndTimestamp = $eventStartTimestamp;
        }

        if ($todayStartTimestamp !== false && $eventEndTimestamp >= $todayStartTimestamp) {
            $selectedEvent = $event;
            break;
        }
    }

    if ($selectedEvent === null) {
        $selectedEvent = $events[0];
    }

    $selectedEventId = (int)$selectedEvent['id'];
}

for ($i = 1; $i < $firstWeekday; $i++) {
    $calendarCells[] = null;
}

for ($day = 1; $day <= $daysInMonth; $day++) {
    $calendarCells[] = date('Y-m-d', strtotime($month . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT)));
}

while (count($calendarCells) % 7 !== 0) {
    $calendarCells[] = null;
}

$flashes = get_flashes();
$form = $editEvent ?: [];
$formStartValue = (string)($form['starts_at'] ?? '');
$formEndValue = (string)($form['ends_at'] ?? '');
$startDateValue = is_post() ? post_value('starts_at_date') : substr($formStartValue, 0, 10);
$startTimeValue = is_post() ? post_value('starts_at_time') : substr($formStartValue, 11, 5);
$endDateValue = is_post() ? post_value('ends_at_date') : substr($formEndValue, 0, 10);
$endTimeValue = is_post() ? post_value('ends_at_time') : substr($formEndValue, 11, 5);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Calendrier d’établissement — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body>
<?php require __DIR__ . '/../templates/header.php'; ?>
<main class="container page">
    <?php foreach ($flashes as $flash): ?><div class="<?= e(flash_class($flash['type'])) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?>
    <?php if ($errors): ?><div class="flash flash-error"><strong>Erreur :</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="card">
        <h1>Calendrier</h1>
        <p class="muted">Suivez les faits se produisant dans l’établissement, les échéances et les réponses des enseignants, syndicats ou services.</p>
    </section>

    <section class="events-layout">
        <div class="events-main-column">
            <article class="card events-calendar-card">
                <h2>Calendrier <?= e($monthLabel) ?></h2>
                <div class="calendar-toolbar">
                    <a class="button-secondary" href="<?= e(url('events.php?month=' . $prevMonth)) ?>">Mois précédent</a>
                    <form class="inline-filter" method="get" action=""><label class="sr-only" for="month">Mois</label><input id="month" name="month" type="month" value="<?= e($month) ?>"><button type="submit">Filtrer</button></form>
                    <a class="button-secondary" href="<?= e(url('events.php?month=' . $nextMonth)) ?>">Mois suivant</a>
                </div>

                <div class="calendar-month" role="grid" aria-label="Calendrier <?= e($monthLabel) ?>">
                    <?php foreach (['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $dayName): ?>
                        <div class="calendar-weekday" role="columnheader"><?= e($dayName) ?></div>
                    <?php endforeach; ?>

                    <?php foreach ($calendarCells as $dayKey): ?>
                        <?php
                            $dayEvents = $dayKey !== null ? ($eventsByDay[$dayKey] ?? []) : [];
                            $isToday = $dayKey === date('Y-m-d');
                            $cellClass = 'calendar-day' . ($dayKey === null ? ' calendar-day-empty' : '') . ($isToday ? ' calendar-day-today' : '');
                        ?>
                        <article class="<?= e($cellClass) ?>" role="gridcell">
                            <?php if ($dayKey !== null): ?>
                                <div class="calendar-day-number"><?= e(date('j', strtotime($dayKey))) ?></div>
                                <?php if (!$dayEvents): ?>
                                    <span class="calendar-day-empty-label">Aucun fait</span>
                                <?php else: ?>
                                    <div class="calendar-day-events">
                                        <?php foreach ($dayEvents as $event): ?>
                                            <?php $isSelectedEvent = (int)$event['id'] === $selectedEventId; ?>
                                            <a class="calendar-event-pill<?= $isSelectedEvent ? ' calendar-event-pill-selected' : '' ?>" href="<?= e(url('events.php?month=' . urlencode($month) . '&event=' . (int)$event['id'] . '#month-event-detail')) ?>" <?= $isSelectedEvent ? 'aria-current="true"' : '' ?>>
                                                <?= e($event['title']) ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </article>
        </div>

        <article class="card events-month-card" id="month-event-detail">
            <h2>Évènements du mois</h2>
            <?php if (!$events): ?><p class="muted">Aucun événement sur ce mois.</p><?php else: ?>
            <?php
                $event = $selectedEvent;
                $startTimestamp = strtotime((string)$event['starts_at']);
                $endTimestamp = $event['ends_at'] ? strtotime((string)$event['ends_at']) : false;
                $startLabel = $startTimestamp !== false ? date('d/m/Y à H:i', $startTimestamp) : (string)$event['starts_at'];
                $endLabel = $endTimestamp !== false ? date('d/m/Y à H:i', $endTimestamp) : '';
                $timeRangeLabel = $startTimestamp !== false ? date('H:i', $startTimestamp) : '';
                if ($timeRangeLabel !== '' && $endTimestamp !== false) {
                    $timeRangeLabel .= ' ' . date('H:i', $endTimestamp);
                }
                $eventDescription = trim((string)($event['description'] ?? ''));
            ?>
            <article class="calendar-event" id="event-<?= e((string)$event['id']) ?>">
                <div class="calendar-date">
                    <strong><?= e($startTimestamp !== false ? date('d', $startTimestamp) : '--') ?></strong>
                    <span><?= e($startTimestamp !== false ? ($shortMonthNames[(int)date('n', $startTimestamp)] ?? '') : '') ?></span>
                    <?php if ($timeRangeLabel !== ''): ?>
                        <small><?= e($timeRangeLabel) ?></small>
                    <?php endif; ?>
                </div>
                <div class="calendar-event-body">
                    <dl class="event-field-grid">
                        <div class="event-field event-field-title">
                            <dt>Titre</dt>
                            <dd><?= e($event['title']) ?></dd>
                        </div>
                        <div class="event-field">
                            <dt>Type</dt>
                            <dd><span class="badge"><?= e(protection_label(PROTECTION_EVENT_TYPES, $event['event_type'])) ?></span></dd>
                        </div>
                        <div class="event-field">
                            <dt>Lieu</dt>
                            <dd><?= e($event['location'] ?: 'Non renseigné') ?></dd>
                        </div>
                        <div class="event-field">
                            <dt>Début</dt>
                            <dd><?= e($startLabel) ?></dd>
                        </div>
                        <?php if ($endLabel !== ''): ?>
                            <div class="event-field">
                                <dt>Fin</dt>
                                <dd><?= e($endLabel) ?></dd>
                            </div>
                        <?php endif; ?>
                        <div class="event-field event-field-wide">
                            <dt>Description</dt>
                            <dd>
                                <?php if ($eventDescription !== ''): ?>
                                    <div class="markdown-content"><?= render_markdown($eventDescription) ?></div>
                                <?php else: ?>
                                    <span class="muted">Aucune description.</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div class="event-field event-field-wide">
                            <dt>Réponse ou décision</dt>
                            <dd>
                                <?php if ($event['response_summary']): ?>
                                    <?= nl2br(e($event['response_summary'])) ?>
                                <?php else: ?>
                                    <span class="muted">Non renseignée.</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div class="event-field">
                            <dt>Réponse portée par</dt>
                            <dd><?= e($event['response_owner'] ?: 'Non renseigné') ?></dd>
                        </div>
                    </dl>
                    <p class="meta">Saisi par <?= e($event['display_name'] ?: $event['username']) ?> · visibilité <?= e(visibility_label($event['visibility'], $event['group_name'] ?? null)) ?></p>
                    <?php if (event_user_can_manage($event, $user)): ?>
                        <div class="form-actions">
                            <a class="button-primary" href="<?= e(url('events.php?edit=' . (int)$event['id'] . '#title')) ?>">Modifier</a>
                            <form method="post" action="" onsubmit="return confirm('Supprimer cet événement ?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= e((string)$event['id']) ?>">
                                <button class="button-danger" type="submit">Supprimer</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endif; ?>
        </article>

        <article class="card events-form-card">
            <h2><?= $editEvent ? 'Modifier l’évènement' : 'Nouvel évènement' ?></h2>
            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $editEvent ? 'update' : 'create' ?>">
                <input type="hidden" name="id" value="<?= e((string)($form['id'] ?? 0)) ?>">
                <label for="title">Titre</label>
                <input id="title" name="title" required value="<?= e((string)($form['title'] ?? post_value('title'))) ?>">
                <div class="form-grid event-form-grid">
                    <div><label for="event_type">Type</label><select id="event_type" name="event_type"><?php foreach (PROTECTION_EVENT_TYPES as $key => $label): ?><option value="<?= e($key) ?>" <?= (($form['event_type'] ?? post_value('event_type', 'Échéance')) === $key) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                    <div><label for="location">Lieu</label><input id="location" name="location" value="<?= e((string)($form['location'] ?? post_value('location'))) ?>"></div>
                    <div><label for="starts_at_date">Date de début</label><input id="starts_at_date" name="starts_at_date" type="date" required value="<?= e($startDateValue) ?>"></div>
                    <div><label for="starts_at_time">Heure de début</label><input id="starts_at_time" name="starts_at_time" type="time" required value="<?= e($startTimeValue) ?>"></div>
                    <div><label for="ends_at_date">Date de fin</label><input id="ends_at_date" name="ends_at_date" type="date" value="<?= e($endDateValue) ?>"></div>
                    <div><label for="ends_at_time">Heure de fin</label><input id="ends_at_time" name="ends_at_time" type="time" value="<?= e($endTimeValue) ?>"></div>
                    <div><label for="visibility">Visibilité</label><select id="visibility" name="visibility"><option value="public" <?= (($form['visibility'] ?? post_value('visibility')) === 'public') ? 'selected' : '' ?>>Public</option><option value="private" <?= (($form['visibility'] ?? post_value('visibility')) === 'private') ? 'selected' : '' ?>>Privé</option><option value="members" <?= (($form['visibility'] ?? post_value('visibility', 'members')) === 'members') ? 'selected' : '' ?>>Membres</option><option value="group" <?= (($form['visibility'] ?? post_value('visibility')) === 'group') ? 'selected' : '' ?>>Groupe</option></select></div>
                    <div data-group-visibility-field><label for="group_id">Groupe</label><select id="group_id" name="group_id"><option value="">Choisir un groupe</option><?php foreach ($groupOptions as $group): ?><option value="<?= e((string)$group['id']) ?>" <?= (int)($form['group_id'] ?? post_value('group_id', '0')) === (int)$group['id'] ? 'selected' : '' ?>><?= e($group['name']) ?></option><?php endforeach; ?></select></div>
                </div>
                <label for="description">Description</label>
                <textarea id="description" name="description"><?= e((string)($form['description'] ?? post_value('description'))) ?></textarea>
                <label for="response_owner">Réponse portée par</label>
                <input id="response_owner" name="response_owner" placeholder="Équipe, syndicat, CA, élu..." value="<?= e((string)($form['response_owner'] ?? post_value('response_owner'))) ?>">
                <label for="response_summary">Réponse ou décision</label>
                <textarea id="response_summary" name="response_summary"><?= e((string)($form['response_summary'] ?? post_value('response_summary'))) ?></textarea>
                <div class="form-actions"><button class="button-primary" type="submit"><?= $editEvent ? 'Enregistrer' : 'Ajouter' ?></button><?php if ($editEvent): ?><a class="button-secondary" href="<?= e(url('events.php')) ?>">Annuler</a><?php endif; ?></div>
            </form>
        </article>
    </section>
</main>
<?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
