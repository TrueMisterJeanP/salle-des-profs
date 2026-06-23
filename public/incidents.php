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
$editIncident = null;
$groupOptions = user_group_options((int)$user['id']);
$allowedVisibility = array_keys(content_visibility_options(true));

function incident_datetime_from_parts(string $date, string $time): string
{
    $date = trim($date);
    $time = trim($time);

    return $date !== '' && $time !== '' ? $date . ' ' . $time : '';
}

if ($editId > 0) {
    $editIncident = db_fetch_one(
        "SELECT * FROM protection_incidents WHERE id = :id AND is_active = 1 LIMIT 1",
        ['id' => $editId]
    );
    if ($editIncident && !protection_user_can_manage($editIncident, $user)) {
        set_flash('error', 'Vous ne pouvez pas modifier cet incident.');
        redirect(url('incidents.php'));
    }
}

if (is_post()) {
    require_csrf();
    $action = post_value('action', 'create');
    $id = (int)post_value('id', '0');
    $occurredDate = post_value('occurred_at_date');
    $occurredTime = post_value('occurred_at_time');
    $institutionalResponseDate = post_value('institutional_response_at_date');
    $institutionalResponseTime = post_value('institutional_response_at_time');

    if ($action === 'delete' && $id > 0) {
        $incident = db_fetch_one("SELECT * FROM protection_incidents WHERE id = :id AND is_active = 1 LIMIT 1", ['id' => $id]);
        if (!$incident || !protection_user_can_manage($incident, $user)) {
            $errors[] = 'Incident introuvable ou non modifiable.';
        } else {
            db_query(
                "UPDATE protection_incidents SET is_active = 0, updated_at = :updated_at WHERE id = :id",
                ['updated_at' => now(), 'id' => $id]
            );
            set_flash('success', 'Incident supprimé.');
            redirect(url('incidents.php'));
        }
    }

    $data = [
        'title' => post_value('title'),
        'occurred_at' => incident_datetime_from_parts($occurredDate, $occurredTime) ?: now(),
        'incident_type' => post_value('incident_type', 'autre'),
        'source_type' => post_value('source_type', 'autre'),
        'severity' => post_value('severity', 'medium'),
        'status' => post_value('status', 'open'),
        'location' => post_value('location'),
        'description' => post_value('description'),
        'immediate_actions' => post_value('immediate_actions'),
        'institutional_response' => post_value('institutional_response'),
        'institutional_response_at' => incident_datetime_from_parts($institutionalResponseDate, $institutionalResponseTime),
        'follow_up' => post_value('follow_up'),
        'visibility' => post_value('visibility', 'members'),
        'group_id' => null,
    ];
    $groupId = (int)post_value('group_id', '0');

    if ($data['title'] === '') {
        $errors[] = 'Le titre est obligatoire.';
    }
    if ($data['description'] === '') {
        $errors[] = 'La description est obligatoire.';
    }
    if (($occurredDate === '') !== ($occurredTime === '')) {
        $errors[] = 'La date et l’heure de l’incident doivent être renseignées ensemble.';
    } elseif ($occurredDate !== '' && strtotime($data['occurred_at']) === false) {
        $errors[] = 'La date ou l’heure de l’incident est invalide.';
    }
    if (($institutionalResponseDate === '') !== ($institutionalResponseTime === '')) {
        $errors[] = 'La date et l’heure de réponse institutionnelle doivent être renseignées ensemble.';
    } elseif ($data['institutional_response_at'] !== '' && strtotime($data['institutional_response_at']) === false) {
        $errors[] = 'La date ou l’heure de réponse institutionnelle est invalide.';
    }
    if (!isset(PROTECTION_INCIDENT_TYPES[$data['incident_type']])) {
        $errors[] = 'Type d’incident invalide.';
    }
    if (!isset(PROTECTION_INCIDENT_SOURCES[$data['source_type']])) {
        $errors[] = 'Origine invalide.';
    }
    if (!isset(PROTECTION_SEVERITIES[$data['severity']]) || !isset(PROTECTION_STATUSES[$data['status']])) {
        $errors[] = 'Statut ou gravité invalide.';
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
            $incident = db_fetch_one("SELECT * FROM protection_incidents WHERE id = :id AND is_active = 1 LIMIT 1", ['id' => $id]);
            if (!$incident || !protection_user_can_manage($incident, $user)) {
                $errors[] = 'Incident introuvable ou non modifiable.';
            } else {
                db_query(
                    "UPDATE protection_incidents
                     SET title = :title, occurred_at = :occurred_at, incident_type = :incident_type,
                         source_type = :source_type, severity = :severity, status = :status,
                         location = :location, description = :description,
                         immediate_actions = :immediate_actions,
                         institutional_response = :institutional_response,
                         institutional_response_at = :institutional_response_at,
                         follow_up = :follow_up, visibility = :visibility, group_id = :group_id, updated_at = :updated_at
                     WHERE id = :id",
                    $data + ['updated_at' => now(), 'id' => $id]
                );
                set_flash('success', 'Incident mis à jour.');
                redirect(url('incidents.php'));
            }
        } else {
            db_insert(
                "INSERT INTO protection_incidents
                 (title, occurred_at, incident_type, source_type, severity, status, location,
                  description, immediate_actions, institutional_response, institutional_response_at,
                  follow_up, visibility, group_id, created_by, created_at)
                 VALUES (:title, :occurred_at, :incident_type, :source_type, :severity, :status,
                  :location, :description, :immediate_actions, :institutional_response,
                  :institutional_response_at, :follow_up, :visibility, :group_id, :created_by, :created_at)",
                $data + ['created_by' => (int)$user['id'], 'created_at' => now()]
            );
            set_flash('success', 'Incident ajouté à l’inventaire.');
            redirect(url('incidents.php'));
        }
    }

    if (!$errors) {
        redirect(url('incidents.php'));
    }
}

$incidents = db_fetch_all(
    "SELECT i.*, u.username, u.display_name, g.name AS group_name
     FROM protection_incidents i
     JOIN users u ON u.id = i.created_by
     LEFT JOIN groups g ON g.id = i.group_id
     WHERE i.is_active = 1
     ORDER BY i.occurred_at DESC, i.created_at DESC"
);
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
$incidentMonths = [];
$incidentsByMonth = [];
foreach ($incidents as $incident) {
    $incidentTimestamp = strtotime((string)$incident['occurred_at']);
    if ($incidentTimestamp === false) {
        $incidentTimestamp = strtotime((string)$incident['created_at']);
    }
    if ($incidentTimestamp === false) {
        continue;
    }
    $monthKey = date('Y-m', $incidentTimestamp);
    if (!isset($incidentMonths[$monthKey])) {
        $incidentMonths[$monthKey] = ucfirst($monthNames[(int)date('n', $incidentTimestamp)] ?? date('F', $incidentTimestamp)) . ' ' . date('Y', $incidentTimestamp);
    }
    $incidentsByMonth[$monthKey][] = $incident;
}
$currentIncidentMonth = date('Y-m');
$currentIncidentMonthTimestamp = strtotime($currentIncidentMonth . '-01');
if (!isset($incidentMonths[$currentIncidentMonth]) && $currentIncidentMonthTimestamp !== false) {
    $incidentMonths[$currentIncidentMonth] = ucfirst($monthNames[(int)date('n', $currentIncidentMonthTimestamp)] ?? date('F', $currentIncidentMonthTimestamp)) . ' ' . date('Y', $currentIncidentMonthTimestamp);
    $incidentsByMonth[$currentIncidentMonth] = [];
}
ksort($incidentMonths);
ksort($incidentsByMonth);
$defaultIncidentMonth = isset($incidentMonths[$currentIncidentMonth]) ? $currentIncidentMonth : ($incidentMonths ? (string)array_key_last($incidentMonths) : '');
$selectedIncidentMonth = get_value('incident_month', $defaultIncidentMonth);
if (!isset($incidentMonths[$selectedIncidentMonth])) {
    $selectedIncidentMonth = $defaultIncidentMonth;
}
$incidentMonthKeys = array_keys($incidentMonths);
$selectedIncidentMonthIndex = array_search($selectedIncidentMonth, $incidentMonthKeys, true);
$previousIncidentMonth = $selectedIncidentMonthIndex !== false && $selectedIncidentMonthIndex > 0 ? $incidentMonthKeys[$selectedIncidentMonthIndex - 1] : null;
$nextIncidentMonth = $selectedIncidentMonthIndex !== false && $selectedIncidentMonthIndex < count($incidentMonthKeys) - 1 ? $incidentMonthKeys[$selectedIncidentMonthIndex + 1] : null;
$selectedMonthIncidents = $selectedIncidentMonth !== '' ? ($incidentsByMonth[$selectedIncidentMonth] ?? []) : [];
$selectedFactPage = max(1, (int)get_value('fact', '1'));
$selectedFactCount = count($selectedMonthIncidents);
if ($selectedFactCount > 0 && $selectedFactPage > $selectedFactCount) {
    $selectedFactPage = $selectedFactCount;
}
$selectedIncident = $selectedFactCount > 0 ? $selectedMonthIncidents[$selectedFactPage - 1] : null;
$form = $editIncident ?: [];
$formOccurredValue = (string)($form['occurred_at'] ?? '');
$formInstitutionalResponseValue = (string)($form['institutional_response_at'] ?? '');
$occurredDateValue = is_post() ? post_value('occurred_at_date') : substr($formOccurredValue, 0, 10);
$occurredTimeValue = is_post() ? post_value('occurred_at_time') : substr($formOccurredValue, 11, 5);
$institutionalResponseDateValue = is_post() ? post_value('institutional_response_at_date') : substr($formInstitutionalResponseValue, 0, 10);
$institutionalResponseTimeValue = is_post() ? post_value('institutional_response_at_time') : substr($formInstitutionalResponseValue, 11, 5);
$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Inventaire des incidents — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body>
<?php require __DIR__ . '/../templates/header.php'; ?>
<main class="container page">
    <?php foreach ($flashes as $flash): ?><div class="<?= e(flash_class($flash['type'])) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?>
    <?php if ($errors): ?><div class="flash flash-error"><strong>Erreur :</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="card">
        <h1>Inventaire des incidents</h1>
        <p class="muted">Documentez les faits, les démarches immédiates et la réponse de l’institution.</p>
    </section>

    <section class="card" id="incident-directory">
        <h2>Faits répertoriés</h2>
        <?php if (!$incidents): ?><p class="muted">Aucun fait enregistré.</p><?php else: ?>
            <div class="incident-directory-layout">
                <nav class="incident-month-nav" aria-label="Mois des faits">
                    <div class="month-stepper">
                        <?php if ($previousIncidentMonth !== null): ?>
                            <a class="button-secondary month-stepper-control" href="<?= e(url('incidents.php?incident_month=' . urlencode($previousIncidentMonth) . '#incident-directory')) ?>" aria-label="Mois précédent">&lt;</a>
                        <?php else: ?>
                            <span class="button-secondary month-stepper-control month-stepper-disabled" aria-hidden="true">&lt;</span>
                        <?php endif; ?>
                        <strong><?= e($incidentMonths[$selectedIncidentMonth] ?? '') ?></strong>
                        <?php if ($nextIncidentMonth !== null): ?>
                            <a class="button-secondary month-stepper-control" href="<?= e(url('incidents.php?incident_month=' . urlencode($nextIncidentMonth) . '#incident-directory')) ?>" aria-label="Mois suivant">&gt;</a>
                        <?php else: ?>
                            <span class="button-secondary month-stepper-control month-stepper-disabled" aria-hidden="true">&gt;</span>
                        <?php endif; ?>
                    </div>
                    <?php foreach ($incidentMonths as $monthKey => $monthLabel): ?>
                        <?php
                            $isCurrentMonth = $monthKey === $selectedIncidentMonth;
                            $monthIncidentCount = count($incidentsByMonth[$monthKey] ?? []);
                        ?>
                        <a class="<?= $isCurrentMonth ? 'active' : '' ?>" href="<?= e(url('incidents.php?incident_month=' . urlencode($monthKey) . '#incident-directory')) ?>" <?= $isCurrentMonth ? 'aria-current="page"' : '' ?>>
                            <span><?= e($monthLabel) ?></span>
                            <small><?= e((string)$monthIncidentCount) ?> fait<?= $monthIncidentCount > 1 ? 's' : '' ?></small>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="incident-month-detail">
                    <?php if ($selectedIncident === null): ?>
                        <p class="muted">Aucun fait pour ce mois.</p>
                    <?php else: ?>
                        <?php
                            $incident = $selectedIncident;
                            $occurredTimestamp = strtotime((string)$incident['occurred_at']);
                            $responseTimestamp = $incident['institutional_response_at'] ? strtotime((string)$incident['institutional_response_at']) : false;
                            $occurredLabel = $occurredTimestamp !== false ? date('d/m/Y à H:i', $occurredTimestamp) : (string)$incident['occurred_at'];
                            $responseDateLabel = $responseTimestamp !== false ? date('d/m/Y à H:i', $responseTimestamp) : 'Non renseignée';
                        ?>
                        <article class="record-card" id="incident-<?= e((string)$incident['id']) ?>">
                            <dl class="event-field-grid incident-field-grid">
                                <div class="event-field event-field-title">
                                    <dt>Titre</dt>
                                    <dd><?= e($incident['title']) ?></dd>
                                </div>
                                <div class="event-field">
                                    <dt>Date et heure</dt>
                                    <dd><?= e($occurredLabel) ?></dd>
                                </div>
                                <div class="event-field">
                                    <dt>Origine</dt>
                                    <dd><?= e(protection_label(PROTECTION_INCIDENT_SOURCES, $incident['source_type'])) ?></dd>
                                </div>
                                <div class="event-field">
                                    <dt>Type</dt>
                                    <dd><span class="badge"><?= e(protection_label(PROTECTION_INCIDENT_TYPES, $incident['incident_type'])) ?></span></dd>
                                </div>
                                <div class="event-field">
                                    <dt>Statut</dt>
                                    <dd><span class="badge"><?= e(protection_label(PROTECTION_STATUSES, $incident['status'])) ?></span></dd>
                                </div>
                                <div class="event-field">
                                    <dt>Lieu</dt>
                                    <dd><?= e($incident['location'] ?: 'Non renseigné') ?></dd>
                                </div>
                                <div class="event-field">
                                    <dt>Gravité</dt>
                                    <dd><span class="badge"><?= e(protection_label(PROTECTION_SEVERITIES, $incident['severity'])) ?></span></dd>
                                </div>
                                <div class="event-field event-field-wide">
                                    <dt>Description factuelle</dt>
                                    <dd>
                                        <?php if ($incident['description']): ?>
                                            <div class="markdown-content"><?= render_markdown($incident['description']) ?></div>
                                        <?php else: ?>
                                            <span class="muted">Aucune description.</span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div class="event-field event-field-wide">
                                    <dt>Démarches immédiates</dt>
                                    <dd>
                                        <?php if ($incident['immediate_actions']): ?>
                                            <div class="markdown-content"><?= render_markdown($incident['immediate_actions']) ?></div>
                                        <?php else: ?>
                                            <span class="muted">Non renseignées.</span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div class="event-field event-field-wide">
                                    <dt>Réponse de l’institution</dt>
                                    <dd>
                                        <?php if ($incident['institutional_response']): ?>
                                            <div class="markdown-content"><?= render_markdown($incident['institutional_response']) ?></div>
                                        <?php else: ?>
                                            <span class="muted">Non renseignée.</span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div class="event-field">
                                    <dt>Date de réponse institutionnelle</dt>
                                    <dd><?= e($responseDateLabel) ?></dd>
                                </div>
                                <div class="event-field event-field-wide">
                                    <dt>Suite à donner</dt>
                                    <dd>
                                        <?php if ($incident['follow_up']): ?>
                                            <div class="markdown-content"><?= render_markdown($incident['follow_up']) ?></div>
                                        <?php else: ?>
                                            <span class="muted">Non renseignée.</span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                            </dl>
                            <?php if ($selectedFactCount > 1): ?>
                                <nav class="pagination incident-fact-pagination" aria-label="Faits de <?= e($incidentMonths[$selectedIncidentMonth]) ?>">
                                    <?php for ($pageNumber = 1; $pageNumber <= $selectedFactCount; $pageNumber++): ?>
                                        <?php if ($pageNumber === $selectedFactPage): ?>
                                            <span class="button-primary pagination-current" aria-current="page"><?= e((string)$pageNumber) ?></span>
                                        <?php else: ?>
                                            <a class="button-secondary" href="<?= e(url('incidents.php?incident_month=' . urlencode($selectedIncidentMonth) . '&fact=' . $pageNumber . '#incident-directory')) ?>"><?= e((string)$pageNumber) ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </nav>
                            <?php endif; ?>
                            <p class="meta">Saisi par <?= e($incident['display_name'] ?: $incident['username']) ?> · visibilité <?= e(visibility_label($incident['visibility'], $incident['group_name'] ?? null)) ?></p>
                            <?php if (protection_user_can_manage($incident, $user)): ?>
                                <div class="form-actions">
                                    <a class="button-primary" href="<?= e(url('incidents.php?edit=' . (int)$incident['id'] . '#title')) ?>">Modifier</a>
                                    <form method="post" action="" onsubmit="return confirm('Supprimer cet incident ?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= e((string)$incident['id']) ?>">
                                        <button class="button-danger" type="submit">Supprimer</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2><?= $editIncident ? 'Modifier l’incident' : 'Nouvel incident' ?></h2>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editIncident ? 'update' : 'create' ?>">
            <input type="hidden" name="id" value="<?= e((string)($form['id'] ?? 0)) ?>">
            <div class="event-field-grid incident-form-grid flat-form-grid">
                <div class="event-form-field event-field-title">
                    <label for="title">Titre</label>
                    <input id="title" name="title" required value="<?= e((string)($form['title'] ?? post_value('title'))) ?>">
                </div>
                <div class="event-form-field">
                    <label for="occurred_at_date">Date de l’incident</label>
                    <input id="occurred_at_date" name="occurred_at_date" type="date" value="<?= e($occurredDateValue) ?>">
                </div>
                <div class="event-form-field">
                    <label for="occurred_at_time">Heure de l’incident</label>
                    <input id="occurred_at_time" name="occurred_at_time" type="time" value="<?= e($occurredTimeValue) ?>">
                </div>
                <div class="event-form-field">
                    <label for="source_type">Origine</label>
                    <select id="source_type" name="source_type"><?php foreach (PROTECTION_INCIDENT_SOURCES as $key => $label): ?><option value="<?= e($key) ?>" <?= (($form['source_type'] ?? post_value('source_type', 'autre')) === $key) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                </div>
                <div class="event-form-field">
                    <label for="incident_type">Type</label>
                    <select id="incident_type" name="incident_type"><?php foreach (PROTECTION_INCIDENT_TYPES as $key => $label): ?><option value="<?= e($key) ?>" <?= (($form['incident_type'] ?? post_value('incident_type', 'autre')) === $key) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                </div>
                <div class="event-form-field">
                    <label for="status">Statut</label>
                    <select id="status" name="status"><?php foreach (PROTECTION_STATUSES as $key => $label): ?><option value="<?= e($key) ?>" <?= (($form['status'] ?? post_value('status', 'open')) === $key) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                </div>
                <div class="event-form-field">
                    <label for="location">Lieu</label>
                    <input id="location" name="location" value="<?= e((string)($form['location'] ?? post_value('location'))) ?>">
                </div>
                <div class="event-form-field">
                    <label for="severity">Gravité</label>
                    <select id="severity" name="severity"><?php foreach (PROTECTION_SEVERITIES as $key => $label): ?><option value="<?= e($key) ?>" <?= (($form['severity'] ?? post_value('severity', 'low')) === $key) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                </div>
                <div class="event-form-field event-field-wide">
                    <label for="description">Description factuelle</label>
                    <textarea id="description" name="description" required><?= e((string)($form['description'] ?? post_value('description'))) ?></textarea>
                </div>
                <div class="event-form-field event-field-wide">
                    <label for="immediate_actions">Démarches immédiates</label>
                    <textarea id="immediate_actions" name="immediate_actions"><?= e((string)($form['immediate_actions'] ?? post_value('immediate_actions'))) ?></textarea>
                </div>
                <div class="event-form-field event-field-wide">
                    <label for="institutional_response">Réponse de l’institution</label>
                    <textarea id="institutional_response" name="institutional_response"><?= e((string)($form['institutional_response'] ?? post_value('institutional_response'))) ?></textarea>
                </div>
                <div class="event-form-field">
                    <label for="institutional_response_at_date">Date de réponse institutionnelle</label>
                    <input id="institutional_response_at_date" name="institutional_response_at_date" type="date" value="<?= e($institutionalResponseDateValue) ?>">
                </div>
                <div class="event-form-field">
                    <label for="institutional_response_at_time">Heure de réponse institutionnelle</label>
                    <input id="institutional_response_at_time" name="institutional_response_at_time" type="time" value="<?= e($institutionalResponseTimeValue) ?>">
                </div>
                <div class="event-form-field event-field-wide">
                    <label for="follow_up">Suite à donner</label>
                    <textarea id="follow_up" name="follow_up"><?= e((string)($form['follow_up'] ?? post_value('follow_up'))) ?></textarea>
                </div>
                <div class="event-form-field">
                    <label for="visibility">Visibilité</label>
                    <select id="visibility" name="visibility"><option value="public" <?= (($form['visibility'] ?? post_value('visibility')) === 'public') ? 'selected' : '' ?>>Public</option><option value="private" <?= (($form['visibility'] ?? '') === 'private') ? 'selected' : '' ?>>Privé</option><option value="members" <?= (($form['visibility'] ?? 'members') === 'members') ? 'selected' : '' ?>>Membres</option><option value="group" <?= (($form['visibility'] ?? '') === 'group') ? 'selected' : '' ?>>Groupe</option></select>
                </div>
                <div class="event-form-field" data-group-visibility-field>
                    <label for="group_id">Groupe</label>
                    <select id="group_id" name="group_id"><option value="">Choisir un groupe</option><?php foreach ($groupOptions as $group): ?><option value="<?= e((string)$group['id']) ?>" <?= (int)($form['group_id'] ?? 0) === (int)$group['id'] ? 'selected' : '' ?>><?= e($group['name']) ?></option><?php endforeach; ?></select>
                </div>
            </div>
            <div class="form-actions"><button class="button-primary" type="submit"><?= $editIncident ? 'Enregistrer' : 'Ajouter' ?></button><?php if ($editIncident): ?><a class="button-secondary" href="<?= e(url('incidents.php')) ?>">Annuler</a><?php endif; ?></div>
        </form>
    </section>
</main>
<?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
