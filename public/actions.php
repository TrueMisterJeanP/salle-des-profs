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
$editAction = null;
$groupOptions = user_group_options((int)$user['id']);
$allowedVisibility = array_keys(content_visibility_options(true));
$riskNotice = 'Les actions de rupture, d’occupation, de blocage ou de désobéissance doivent être évaluées avec un cadre syndical ou juridique avant toute décision collective.';

function action_datetime_from_parts(string $date, string $time): string
{
    $date = trim($date);
    $time = trim($time);

    return $date !== '' && $time !== '' ? $date . ' ' . $time : '';
}

if ($editId > 0) {
    $editAction = db_fetch_one(
        "SELECT * FROM protection_action_plans WHERE id = :id AND is_active = 1 LIMIT 1",
        ['id' => $editId]
    );
    if ($editAction && !protection_user_can_manage($editAction, $user)) {
        set_flash('error', 'Vous ne pouvez pas modifier cette action.');
        redirect(url('actions.php'));
    }
}

if (is_post()) {
    require_csrf();
    $action = post_value('action', 'create');
    $id = (int)post_value('id', '0');
    $plannedDate = post_value('planned_at_date');
    $plannedTime = post_value('planned_at_time');

    if ($action === 'delete' && $id > 0) {
        $existingAction = db_fetch_one("SELECT * FROM protection_action_plans WHERE id = :id AND is_active = 1 LIMIT 1", ['id' => $id]);
        if (!$existingAction || !protection_user_can_manage($existingAction, $user)) {
            $errors[] = 'Action introuvable ou non modifiable.';
        } else {
            db_query(
                "UPDATE protection_action_plans SET is_active = 0, updated_at = :updated_at WHERE id = :id",
                ['updated_at' => now(), 'id' => $id]
            );
            set_flash('success', 'Action supprimée.');
            redirect(url('actions.php'));
        }
    }

    $data = [
        'title' => post_value('title'),
        'category' => post_value('category', 'administrative'),
        'status' => post_value('status', 'idea'),
        'objective' => post_value('objective'),
        'steps' => post_value('steps'),
        'legal_frame' => post_value('legal_frame'),
        'risks' => post_value('risks'),
        'owner' => post_value('owner'),
        'planned_at' => action_datetime_from_parts($plannedDate, $plannedTime),
        'visibility' => post_value('visibility', 'members'),
        'group_id' => null,
    ];
    $groupId = (int)post_value('group_id', '0');
    if ($data['title'] === '') {
        $errors[] = 'Le titre est obligatoire.';
    }
    if ($data['objective'] === '') {
        $errors[] = 'L’objectif est obligatoire.';
    }
    if (!isset(PROTECTION_ACTION_CATEGORIES[$data['category']]) || !isset(PROTECTION_ACTION_STATUSES[$data['status']])) {
        $errors[] = 'Catégorie ou statut invalide.';
    }
    if (($plannedDate === '') !== ($plannedTime === '')) {
        $errors[] = 'La date et l’heure de l’action doivent être renseignées ensemble.';
    } elseif ($data['planned_at'] !== '' && strtotime($data['planned_at']) === false) {
        $errors[] = 'La date ou l’heure de l’action est invalide.';
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
            $existingAction = db_fetch_one("SELECT * FROM protection_action_plans WHERE id = :id AND is_active = 1 LIMIT 1", ['id' => $id]);
            if (!$existingAction || !protection_user_can_manage($existingAction, $user)) {
                $errors[] = 'Action introuvable ou non modifiable.';
            } else {
                db_query(
                    "UPDATE protection_action_plans
                     SET title = :title, category = :category, status = :status, objective = :objective,
                         steps = :steps, legal_frame = :legal_frame, risks = :risks, owner = :owner,
                         planned_at = :planned_at, visibility = :visibility, group_id = :group_id,
                         updated_at = :updated_at
                     WHERE id = :id",
                    $data + ['updated_at' => now(), 'id' => $id]
                );
                set_flash('success', 'Action mise à jour.');
                redirect(url('actions.php'));
            }
        } else {
            db_insert(
                "INSERT INTO protection_action_plans
                 (title, category, status, objective, steps, legal_frame, risks, owner,
                  planned_at, visibility, group_id, created_by, created_at)
                 VALUES (:title, :category, :status, :objective, :steps, :legal_frame,
                  :risks, :owner, :planned_at, :visibility, :group_id, :created_by, :created_at)",
                $data + ['created_by' => (int)$user['id'], 'created_at' => now()]
            );
            set_flash('success', 'Action ajoutée au suivi collectif.');
            redirect(url('actions.php'));
        }
    }

    if (!$errors) {
        redirect(url('actions.php'));
    }
}

$actions = db_fetch_all(
    "SELECT a.*, u.username, u.display_name, g.name AS group_name
     FROM protection_action_plans a
     JOIN users u ON u.id = a.created_by
     LEFT JOIN groups g ON g.id = a.group_id
     WHERE a.is_active = 1
     ORDER BY CASE a.status WHEN 'active' THEN 0 WHEN 'validated' THEN 1 WHEN 'draft' THEN 2 ELSE 3 END,
              a.planned_at IS NULL ASC, a.planned_at ASC, a.created_at DESC"
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
$actionMonths = [];
$actionsByMonth = [];
foreach ($actions as $actionItem) {
    $actionTimestamp = $actionItem['planned_at'] ? strtotime((string)$actionItem['planned_at']) : false;
    if ($actionTimestamp === false) {
        $actionTimestamp = strtotime((string)$actionItem['created_at']);
    }
    if ($actionTimestamp === false) {
        continue;
    }
    $monthKey = date('Y-m', $actionTimestamp);
    if (!isset($actionMonths[$monthKey])) {
        $actionMonths[$monthKey] = ucfirst($monthNames[(int)date('n', $actionTimestamp)] ?? date('F', $actionTimestamp)) . ' ' . date('Y', $actionTimestamp);
    }
    $actionsByMonth[$monthKey][] = $actionItem;
}
$currentActionMonth = date('Y-m');
$currentActionMonthTimestamp = strtotime($currentActionMonth . '-01');
if (!isset($actionMonths[$currentActionMonth]) && $currentActionMonthTimestamp !== false) {
    $actionMonths[$currentActionMonth] = ucfirst($monthNames[(int)date('n', $currentActionMonthTimestamp)] ?? date('F', $currentActionMonthTimestamp)) . ' ' . date('Y', $currentActionMonthTimestamp);
    $actionsByMonth[$currentActionMonth] = [];
}
ksort($actionMonths);
ksort($actionsByMonth);
$defaultActionMonth = isset($actionMonths[$currentActionMonth]) ? $currentActionMonth : ($actionMonths ? (string)array_key_last($actionMonths) : '');
$selectedActionMonth = get_value('action_month', $defaultActionMonth);
if (!isset($actionMonths[$selectedActionMonth])) {
    $selectedActionMonth = $defaultActionMonth;
}
$actionMonthKeys = array_keys($actionMonths);
$selectedActionMonthIndex = array_search($selectedActionMonth, $actionMonthKeys, true);
$previousActionMonth = $selectedActionMonthIndex !== false && $selectedActionMonthIndex > 0 ? $actionMonthKeys[$selectedActionMonthIndex - 1] : null;
$nextActionMonth = $selectedActionMonthIndex !== false && $selectedActionMonthIndex < count($actionMonthKeys) - 1 ? $actionMonthKeys[$selectedActionMonthIndex + 1] : null;
$selectedMonthActions = $selectedActionMonth !== '' ? ($actionsByMonth[$selectedActionMonth] ?? []) : [];
$selectedActionPage = max(1, (int)get_value('action_page', '1'));
$selectedActionCount = count($selectedMonthActions);
if ($selectedActionCount > 0 && $selectedActionPage > $selectedActionCount) {
    $selectedActionPage = $selectedActionCount;
}
$selectedAction = $selectedActionCount > 0 ? $selectedMonthActions[$selectedActionPage - 1] : null;
$form = $editAction ?: [];
$formPlannedValue = (string)($form['planned_at'] ?? '');
$plannedDateValue = is_post() ? post_value('planned_at_date') : substr($formPlannedValue, 0, 10);
$plannedTimeValue = is_post() ? post_value('planned_at_time') : substr($formPlannedValue, 11, 5);
$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Actions collectives — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body>
<?php require __DIR__ . '/../templates/header.php'; ?>
<main class="container page">
    <?php foreach ($flashes as $flash): ?><div class="<?= e(flash_class($flash['type'])) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?>
    <?php if ($errors): ?><div class="flash flash-error"><strong>Erreur :</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <section class="card"><h1>Gestion des actions collectives</h1><p class="muted">Planifiez les démarches institutionnelles, syndicales, médiatiques ou les rapports de force légalement encadrés.</p></section>
    <section class="flash flash-warning"><?= e($riskNotice) ?></section>
    <section class="card" id="action-directory">
        <h2>Actions suivies</h2>
        <?php if (!$actions): ?><p class="muted">Aucune action suivie.</p><?php else: ?>
            <div class="incident-directory-layout action-directory-layout">
                <nav class="incident-month-nav action-month-nav" aria-label="Mois des actions">
                    <div class="month-stepper">
                        <?php if ($previousActionMonth !== null): ?>
                            <a class="button-secondary month-stepper-control" href="<?= e(url('actions.php?action_month=' . urlencode($previousActionMonth) . '#action-directory')) ?>" aria-label="Mois précédent">&lt;</a>
                        <?php else: ?>
                            <span class="button-secondary month-stepper-control month-stepper-disabled" aria-hidden="true">&lt;</span>
                        <?php endif; ?>
                        <strong><?= e($actionMonths[$selectedActionMonth] ?? '') ?></strong>
                        <?php if ($nextActionMonth !== null): ?>
                            <a class="button-secondary month-stepper-control" href="<?= e(url('actions.php?action_month=' . urlencode($nextActionMonth) . '#action-directory')) ?>" aria-label="Mois suivant">&gt;</a>
                        <?php else: ?>
                            <span class="button-secondary month-stepper-control month-stepper-disabled" aria-hidden="true">&gt;</span>
                        <?php endif; ?>
                    </div>
                    <?php foreach ($actionMonths as $monthKey => $monthLabel): ?>
                        <?php
                            $isCurrentMonth = $monthKey === $selectedActionMonth;
                            $monthActionCount = count($actionsByMonth[$monthKey] ?? []);
                        ?>
                        <a class="<?= $isCurrentMonth ? 'active' : '' ?>" href="<?= e(url('actions.php?action_month=' . urlencode($monthKey) . '#action-directory')) ?>" <?= $isCurrentMonth ? 'aria-current="page"' : '' ?>>
                            <span><?= e($monthLabel) ?></span>
                            <small><?= e((string)$monthActionCount) ?> action<?= $monthActionCount > 1 ? 's' : '' ?></small>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="incident-month-detail action-month-detail">
                    <?php if ($selectedAction === null): ?>
                        <p class="muted">Aucune action pour ce mois.</p>
                    <?php else: ?>
                        <?php $action = $selectedAction; ?>
                        <article class="record-card" id="action-<?= e((string)$action['id']) ?>">
                            <dl class="event-field-grid action-field-grid">
                                <div class="event-field event-field-title">
                                    <dt>Titre</dt>
                                    <dd><?= e($action['title']) ?></dd>
                                </div>
                                <div class="event-field">
                                    <dt>Catégorie</dt>
                                    <dd><span class="badge"><?= e(protection_label(PROTECTION_ACTION_CATEGORIES, $action['category'])) ?></span></dd>
                                </div>
                                <div class="event-field">
                                    <dt>Statut</dt>
                                    <dd><span class="badge"><?= e(protection_label(PROTECTION_ACTION_STATUSES, $action['status'])) ?></span></dd>
                                </div>
                                <div class="event-field">
                                    <dt>Référent</dt>
                                    <dd><?= e($action['owner'] ?: 'À désigner') ?></dd>
                                </div>
                                <div class="event-field">
                                  <dt>Date</dt>
                                  <dd><?= e($action['planned_at'] ?: 'Non renseignée') ?></dd>
                                </div>
                                <div class="event-field event-field-wide">
                                    <dt>Objectif</dt>
                                    <dd>
                                        <?php if ($action['objective']): ?>
                                            <div class="markdown-content"><?= render_markdown($action['objective']) ?></div>
                                        <?php else: ?>
                                            <span class="muted">Non renseigné.</span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div class="event-field event-field-wide">
                                    <dt>Étapes de travail</dt>
                                    <dd>
                                        <?php if ($action['steps']): ?>
                                            <div class="markdown-content"><?= render_markdown($action['steps']) ?></div>
                                        <?php else: ?>
                                            <span class="muted">Non renseignées.</span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div class="event-field event-field-wide">
                                    <dt>Cadre juridique ou syndical</dt>
                                    <dd>
                                        <?php if ($action['legal_frame']): ?>
                                            <div class="markdown-content"><?= render_markdown($action['legal_frame']) ?></div>
                                        <?php else: ?>
                                            <span class="muted">Non renseigné.</span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div class="event-field event-field-wide">
                                    <dt>Risques, points de vigilance, validations nécessaires</dt>
                                    <dd>
                                        <?php if ($action['risks']): ?>
                                            <div class="markdown-content"><?= render_markdown($action['risks']) ?></div>
                                        <?php else: ?>
                                            <span class="muted">Non renseignés.</span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                              
                            </dl>
                            <?php if ($selectedActionCount > 1): ?>
                                <nav class="pagination incident-fact-pagination action-fact-pagination" aria-label="Actions de <?= e($actionMonths[$selectedActionMonth]) ?>">
                                    <?php for ($pageNumber = 1; $pageNumber <= $selectedActionCount; $pageNumber++): ?>
                                        <?php if ($pageNumber === $selectedActionPage): ?>
                                            <span class="button-primary pagination-current" aria-current="page"><?= e((string)$pageNumber) ?></span>
                                        <?php else: ?>
                                            <a class="button-secondary" href="<?= e(url('actions.php?action_month=' . urlencode($selectedActionMonth) . '&action_page=' . $pageNumber . '#action-directory')) ?>"><?= e((string)$pageNumber) ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </nav>
                            <?php endif; ?>
                            <p class="meta">Saisi par <?= e($action['display_name'] ?: $action['username']) ?> · visibilité <?= e(visibility_label($action['visibility'], $action['group_name'] ?? null)) ?></p>
                            <?php if (protection_user_can_manage($action, $user)): ?>
                                <div class="form-actions">
                                    <a class="button-primary" href="<?= e(url('actions.php?edit=' . (int)$action['id'] . '#title')) ?>">Modifier</a>
                                    <form method="post" action="" onsubmit="return confirm('Supprimer cette action ?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= e((string)$action['id']) ?>">
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
        <h2><?= $editAction ? 'Modifier l’action' : 'Ajouter une action' ?></h2>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editAction ? 'update' : 'create' ?>">
            <input type="hidden" name="id" value="<?= e((string)($form['id'] ?? 0)) ?>">
            <div class="event-field-grid action-form-grid flat-form-grid">
                <div class="event-form-field event-field-title">
                    <label for="title">Titre</label>
                    <input id="title" name="title" required value="<?= e((string)($form['title'] ?? post_value('title'))) ?>">
                </div>
                <div class="event-form-field">
                    <label for="category">Catégorie</label>
                    <select id="category" name="category"><?php foreach (PROTECTION_ACTION_CATEGORIES as $key => $label): ?><option value="<?= e($key) ?>" <?= (($form['category'] ?? post_value('category', 'administrative')) === $key) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                </div>
                <div class="event-form-field">
                    <label for="status">Statut</label>
                    <select id="status" name="status"><?php foreach (PROTECTION_ACTION_STATUSES as $key => $label): ?><option value="<?= e($key) ?>" <?= (($form['status'] ?? post_value('status', 'idea')) === $key) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                </div>
                <div class="event-form-field event-field-wide">
                    <label for="owner">Référent</label>
                    <input id="owner" name="owner" value="<?= e((string)($form['owner'] ?? post_value('owner'))) ?>">
                </div>
                <div class="event-form-field event-field-wide">
                    <label for="objective">Objectif</label>
                    <textarea id="objective" name="objective" required><?= e((string)($form['objective'] ?? post_value('objective'))) ?></textarea>
                </div>
                <div class="event-form-field event-field-wide">
                    <label for="steps">Étapes de travail</label>
                    <textarea id="steps" name="steps" placeholder="Ex. rédaction d’une note, validation collective, saisine d’une instance..."><?= e((string)($form['steps'] ?? post_value('steps'))) ?></textarea>
                </div>
                <div class="event-form-field event-field-wide">
                    <label for="legal_frame">Cadre juridique ou syndical</label>
                    <textarea id="legal_frame" name="legal_frame"><?= e((string)($form['legal_frame'] ?? post_value('legal_frame'))) ?></textarea>
                </div>
                <div class="event-form-field event-field-wide">
                    <label for="risks">Risques, points de vigilance, validations nécessaires</label>
                    <textarea id="risks" name="risks"><?= e((string)($form['risks'] ?? post_value('risks'))) ?></textarea>
                </div>
                <div class="event-form-field">
                    <label for="planned_at_date">Date</label>
                    <input id="planned_at_date" name="planned_at_date" type="date" value="<?= e($plannedDateValue) ?>">
                </div>
                <div class="event-form-field">
                    <label for="planned_at_time">Heure</label>
                    <input id="planned_at_time" name="planned_at_time" type="time" value="<?= e($plannedTimeValue) ?>">
                </div>
                <div class="event-form-field">
                    <label for="visibility">Visibilité</label>
                    <select id="visibility" name="visibility"><option value="public" <?= (($form['visibility'] ?? post_value('visibility')) === 'public') ? 'selected' : '' ?>>Public</option><option value="private" <?= (($form['visibility'] ?? post_value('visibility')) === 'private') ? 'selected' : '' ?>>Privé</option><option value="members" <?= (($form['visibility'] ?? post_value('visibility', 'members')) === 'members') ? 'selected' : '' ?>>Membres</option><option value="group" <?= (($form['visibility'] ?? post_value('visibility')) === 'group') ? 'selected' : '' ?>>Groupe</option></select>
                </div>
                <div class="event-form-field" data-group-visibility-field>
                    <label for="group_id">Groupe</label>
                    <select id="group_id" name="group_id"><option value="">Choisir un groupe</option><?php foreach ($groupOptions as $group): ?><option value="<?= e((string)$group['id']) ?>" <?= (int)($form['group_id'] ?? post_value('group_id', '0')) === (int)$group['id'] ? 'selected' : '' ?>><?= e($group['name']) ?></option><?php endforeach; ?></select>
                </div>
            </div>
            <div class="form-actions"><button class="button-primary" type="submit"><?= $editAction ? 'Enregistrer' : 'Ajouter l’action' ?></button><?php if ($editAction): ?><a class="button-secondary" href="<?= e(url('actions.php')) ?>">Annuler</a><?php endif; ?></div>
        </form>
    </section>
</main>
<?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
