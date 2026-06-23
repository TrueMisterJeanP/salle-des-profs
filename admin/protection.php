<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/protection.php';

require_admin();
protection_ensure_schema();

$configs = [
    'events' => [
        'title' => 'Calendrier',
        'table' => 'protection_events',
        'date' => 'starts_at',
        'label' => 'title',
        'types' => PROTECTION_EVENT_TYPES,
        'type_field' => 'event_type',
    ],
    'incidents' => [
        'title' => 'Incidents',
        'table' => 'protection_incidents',
        'date' => 'occurred_at',
        'label' => 'title',
        'types' => PROTECTION_INCIDENT_TYPES,
        'type_field' => 'incident_type',
    ],
    'actions' => [
        'title' => 'Actions gérées',
        'table' => 'protection_action_plans',
        'date' => 'created_at',
        'label' => 'title',
        'types' => PROTECTION_ACTION_CATEGORIES,
        'type_field' => 'category',
    ],
    'resources' => [
        'title' => 'Textes et services',
        'table' => 'protection_resources',
        'date' => 'created_at',
        'label' => 'title',
        'types' => PROTECTION_RESOURCE_TYPES,
        'type_field' => 'resource_type',
    ],
    'syndicates' => [
        'title' => 'Panneaux syndicaux',
        'table' => 'protection_union_boards',
        'date' => 'created_at',
        'label' => 'union_name',
        'types' => [],
        'type_field' => '',
    ],
];
    
$type = array_key_exists(get_value('type'), $configs) ? get_value('type') : 'events';
$config = $configs[$type];
$table = $config['table'];
$errors = [];
$editId = (int)get_value('edit', '0');

function admin_protection_selected(string $value, string $current): string
{
    return $value === $current ? 'selected' : '';
}

function admin_protection_visibility_value(): string
{
    $visibility = post_value('visibility', 'members');

    return in_array($visibility, ['public', 'private', 'members'], true) ? $visibility : 'members';
}

if (is_post()) {
    require_csrf();

    $action = post_value('action');
    $id = (int)post_value('id', '0');
    $postType = array_key_exists(post_value('type'), $GLOBALS['configs']) ? post_value('type') : 'events';
    $postConfig = $GLOBALS['configs'][$postType];
    $postTable = $postConfig['table'];

    try {
        if ($id <= 0) {
            $errors[] = 'Élément invalide.';
        } elseif ($action === 'delete') {
            db_query("DELETE FROM $postTable WHERE id = :id", ['id' => $id]);
            set_flash('success', 'Élément supprimé.');
            redirect(admin_url('protection.php?type=' . $postType));
        } elseif ($action === 'disable' || $action === 'enable') {
            db_query(
                "UPDATE $postTable SET is_active = :is_active, updated_at = :updated_at WHERE id = :id",
                [
                    'is_active' => $action === 'enable' ? 1 : 0,
                    'updated_at' => now(),
                    'id' => $id,
                ]
            );
            set_flash('success', $action === 'enable' ? 'Élément réactivé.' : 'Élément désactivé.');
            redirect(admin_url('protection.php?type=' . $postType));
        } elseif ($action === 'update') {
            if ($postType === 'events') {
                $eventType = post_value('event_type', 'incident');
                if (!isset(PROTECTION_EVENT_TYPES[$eventType])) {
                    $errors[] = 'Type d’événement invalide.';
                }
                db_query(
                    "UPDATE protection_events
                     SET title = :title, event_type = :event_type, starts_at = :starts_at,
                         ends_at = :ends_at, location = :location, description = :description,
                         response_owner = :response_owner, response_summary = :response_summary,
                         visibility = :visibility, is_active = :is_active, updated_at = :updated_at
                     WHERE id = :id",
                    [
                        'title' => post_value('title'),
                        'event_type' => $eventType,
                        'starts_at' => str_replace('T', ' ', post_value('starts_at')),
                        'ends_at' => str_replace('T', ' ', post_value('ends_at')),
                        'location' => post_value('location'),
                        'description' => post_value('description'),
                        'response_owner' => post_value('response_owner'),
                        'response_summary' => post_value('response_summary'),
                        'visibility' => admin_protection_visibility_value(),
                        'is_active' => isset($_POST['is_active']) ? 1 : 0,
                        'updated_at' => now(),
                        'id' => $id,
                    ]
                );
            } elseif ($postType === 'incidents') {
                db_query(
                    "UPDATE protection_incidents
                     SET title = :title, occurred_at = :occurred_at, incident_type = :incident_type,
                         source_type = :source_type, severity = :severity, status = :status,
                         location = :location, description = :description,
                         immediate_actions = :immediate_actions, institutional_response = :institutional_response,
                         institutional_response_at = :institutional_response_at, follow_up = :follow_up,
                         visibility = :visibility, is_active = :is_active, updated_at = :updated_at
                     WHERE id = :id",
                    [
                        'title' => post_value('title'),
                        'occurred_at' => str_replace('T', ' ', post_value('occurred_at')),
                        'incident_type' => array_key_exists(post_value('incident_type'), PROTECTION_INCIDENT_TYPES) ? post_value('incident_type') : 'autre',
                        'source_type' => array_key_exists(post_value('source_type'), PROTECTION_INCIDENT_SOURCES) ? post_value('source_type') : 'autre',
                        'severity' => array_key_exists(post_value('severity'), PROTECTION_SEVERITIES) ? post_value('severity') : 'medium',
                        'status' => array_key_exists(post_value('status'), PROTECTION_STATUSES) ? post_value('status') : 'open',
                        'location' => post_value('location'),
                        'description' => post_value('description'),
                        'immediate_actions' => post_value('immediate_actions'),
                        'institutional_response' => post_value('institutional_response'),
                        'institutional_response_at' => post_value('institutional_response_at'),
                        'follow_up' => post_value('follow_up'),
                        'visibility' => admin_protection_visibility_value(),
                        'is_active' => isset($_POST['is_active']) ? 1 : 0,
                        'updated_at' => now(),
                        'id' => $id,
                    ]
                );
            } elseif ($postType === 'resources') {
                db_query(
                    "UPDATE protection_resources
                     SET title = :title, resource_type = :resource_type, organization = :organization,
                         url = :url, contact_info = :contact_info, description = :description,
                         is_active = :is_active, updated_at = :updated_at
                     WHERE id = :id",
                    [
                        'title' => post_value('title'),
                        'resource_type' => array_key_exists(post_value('resource_type'), PROTECTION_RESOURCE_TYPES) ? post_value('resource_type') : 'law',
                        'organization' => post_value('organization'),
                        'url' => post_value('url'),
                        'contact_info' => post_value('contact_info'),
                        'description' => post_value('description'),
                        'is_active' => isset($_POST['is_active']) ? 1 : 0,
                        'updated_at' => now(),
                        'id' => $id,
                    ]
                );
            } elseif ($postType === 'syndicates') {
                db_query(
                    "UPDATE protection_union_boards
                     SET union_name = :union_name, local_section = :local_section, contact_name = :contact_name,
                         email = :email, phone = :phone, office_hours = :office_hours, location = :location,
                         announcement = :announcement, is_active = :is_active, updated_at = :updated_at
                     WHERE id = :id",
                    [
                        'union_name' => post_value('union_name'),
                        'local_section' => post_value('local_section'),
                        'contact_name' => post_value('contact_name'),
                        'email' => post_value('email'),
                        'phone' => post_value('phone'),
                        'office_hours' => post_value('office_hours'),
                        'location' => post_value('location'),
                        'announcement' => post_value('announcement'),
                        'is_active' => isset($_POST['is_active']) ? 1 : 0,
                        'updated_at' => now(),
                        'id' => $id,
                    ]
                );
            } elseif ($postType === 'actions') {
                db_query(
                    "UPDATE protection_action_plans
                     SET title = :title, category = :category, status = :status, objective = :objective,
                         steps = :steps, legal_frame = :legal_frame, risks = :risks, owner = :owner,
                         planned_at = :planned_at, visibility = :visibility, is_active = :is_active,
                         updated_at = :updated_at
                     WHERE id = :id",
                    [
                        'title' => post_value('title'),
                        'category' => array_key_exists(post_value('category'), PROTECTION_ACTION_CATEGORIES) ? post_value('category') : 'administrative',
                        'status' => array_key_exists(post_value('status'), PROTECTION_ACTION_STATUSES) ? post_value('status') : 'idea',
                        'objective' => post_value('objective'),
                        'steps' => post_value('steps'),
                        'legal_frame' => post_value('legal_frame'),
                        'risks' => post_value('risks'),
                        'owner' => post_value('owner'),
                        'planned_at' => post_value('planned_at'),
                        'visibility' => admin_protection_visibility_value(),
                        'is_active' => isset($_POST['is_active']) ? 1 : 0,
                        'updated_at' => now(),
                        'id' => $id,
                    ]
                );
            }

            if (!$errors) {
                set_flash('success', 'Élément mis à jour.');
                redirect(admin_url('protection.php?type=' . $postType));
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Erreur : ' . $e->getMessage();
    }
}

$rows = db_fetch_all(
    "SELECT *
     FROM $table
     ORDER BY is_active DESC, {$config['date']} DESC, id DESC
     LIMIT 200"
);

$editRow = $editId > 0
    ? db_fetch_one("SELECT * FROM $table WHERE id = :id LIMIT 1", ['id' => $editId])
    : null;
$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Administration protection — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/../public/assets/app.css')) ?>">
</head>
<body>
<?php require __DIR__ . '/../templates/header.php'; ?>
<main class="container page">
    <?php foreach ($flashes as $flash): ?><div class="<?= e(flash_class($flash['type'])) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?>
    <?php if ($errors): ?><div class="flash flash-error"><strong>Erreur :</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="card">
        <h1>Administration protection</h1>
        <p class="muted">Lire, modifier, désactiver et supprimer les registres opérationnels du site.</p>
        <nav class="form-actions" aria-label="Sections protection">
            <?php foreach ($configs as $key => $item): ?>
                <a class="button-secondary" href="<?= e(admin_url('protection.php?type=' . $key)) ?>" <?= $key === $type ? 'aria-current="page"' : '' ?>><?= e($item['title']) ?></a>
            <?php endforeach; ?>
            <a class="button-secondary" href="<?= e(admin_url('index.php')) ?>">Retour admin</a>
        </nav>
    </section>

    <?php if ($editRow): ?>
        <section class="card" id="edit">
            <h2>Modifier : <?= e((string)$editRow[$config['label']]) ?></h2>
            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="type" value="<?= e($type) ?>">
                <input type="hidden" name="id" value="<?= e((string)$editRow['id']) ?>">

                <?php if ($type === 'events'): ?>
                    <label>Titre</label><input name="title" value="<?= e($editRow['title']) ?>" required>
                    <div class="form-grid">
                        <div><label>Type</label><select name="event_type"><?php foreach (PROTECTION_EVENT_TYPES as $key => $label): ?><option value="<?= e($key) ?>" <?= admin_protection_selected($key, $editRow['event_type']) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                        <div><label>Début</label><input type="datetime-local" name="starts_at" value="<?= e(str_replace(' ', 'T', substr($editRow['starts_at'], 0, 16))) ?>"></div>
                        <div><label>Fin</label><input type="datetime-local" name="ends_at" value="<?= e(str_replace(' ', 'T', substr((string)$editRow['ends_at'], 0, 16))) ?>"></div>
                        <div><label>Visibilité</label><select name="visibility"><option value="public" <?= admin_protection_selected('public', $editRow['visibility']) ?>>Public</option><option value="private" <?= admin_protection_selected('private', $editRow['visibility']) ?>>Privé</option><option value="members" <?= admin_protection_selected('members', $editRow['visibility']) ?>>Membres</option></select></div>
                    </div>
                    <label>Lieu</label><input name="location" value="<?= e($editRow['location']) ?>">
                    <label>Description</label><textarea name="description"><?= e($editRow['description']) ?></textarea>
                    <label>Réponse portée par</label><input name="response_owner" value="<?= e($editRow['response_owner']) ?>">
                    <label>Réponse</label><textarea name="response_summary"><?= e($editRow['response_summary']) ?></textarea>
                <?php elseif ($type === 'incidents'): ?>
                    <label>Titre</label><input name="title" value="<?= e($editRow['title']) ?>" required>
                    <div class="form-grid">
                        <div><label>Date</label><input type="datetime-local" name="occurred_at" value="<?= e(str_replace(' ', 'T', substr($editRow['occurred_at'], 0, 16))) ?>"></div>
                        <div><label>Type</label><select name="incident_type"><?php foreach (PROTECTION_INCIDENT_TYPES as $key => $label): ?><option value="<?= e($key) ?>" <?= admin_protection_selected($key, $editRow['incident_type']) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                        <div><label>Origine</label><select name="source_type"><?php foreach (PROTECTION_INCIDENT_SOURCES as $key => $label): ?><option value="<?= e($key) ?>" <?= admin_protection_selected($key, $editRow['source_type']) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                        <div><label>Gravité</label><select name="severity"><?php foreach (PROTECTION_SEVERITIES as $key => $label): ?><option value="<?= e($key) ?>" <?= admin_protection_selected($key, $editRow['severity']) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                        <div><label>Statut</label><select name="status"><?php foreach (PROTECTION_STATUSES as $key => $label): ?><option value="<?= e($key) ?>" <?= admin_protection_selected($key, $editRow['status']) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                        <div><label>Visibilité</label><select name="visibility"><option value="public" <?= admin_protection_selected('public', $editRow['visibility']) ?>>Public</option><option value="private" <?= admin_protection_selected('private', $editRow['visibility']) ?>>Privé</option><option value="members" <?= admin_protection_selected('members', $editRow['visibility']) ?>>Membres</option></select></div>
                    </div>
                    <label>Lieu</label><input name="location" value="<?= e($editRow['location']) ?>">
                    <label>Description</label><textarea name="description"><?= e($editRow['description']) ?></textarea>
                    <label>Démarches immédiates</label><textarea name="immediate_actions"><?= e($editRow['immediate_actions']) ?></textarea>
                    <label>Réponse institutionnelle</label><textarea name="institutional_response"><?= e($editRow['institutional_response']) ?></textarea>
                    <label>Date de réponse</label><input type="date" name="institutional_response_at" value="<?= e(substr((string)$editRow['institutional_response_at'], 0, 10)) ?>">
                    <label>Suite à donner</label><textarea name="follow_up"><?= e($editRow['follow_up']) ?></textarea>
                <?php elseif ($type === 'resources'): ?>
                    <label>Titre</label><input name="title" value="<?= e($editRow['title']) ?>" required>
                    <div class="form-grid">
                        <div><label>Type</label><select name="resource_type"><?php foreach (PROTECTION_RESOURCE_TYPES as $key => $label): ?><option value="<?= e($key) ?>" <?= admin_protection_selected($key, $editRow['resource_type']) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                        <div><label>Service / organisme</label><input name="organization" value="<?= e($editRow['organization']) ?>"></div>
                    </div>
                    <label>URL</label><input name="url" value="<?= e($editRow['url']) ?>">
                    <label>Contact</label><textarea name="contact_info"><?= e($editRow['contact_info']) ?></textarea>
                    <label>Description</label><textarea name="description"><?= e($editRow['description']) ?></textarea>
                <?php elseif ($type === 'syndicates'): ?>
                    <label>Syndicat</label><input name="union_name" value="<?= e($editRow['union_name']) ?>" required>
                    <div class="form-grid">
                        <div><label>Section</label><input name="local_section" value="<?= e($editRow['local_section']) ?>"></div>
                        <div><label>Contact</label><input name="contact_name" value="<?= e($editRow['contact_name']) ?>"></div>
                        <div><label>Courriel</label><input name="email" value="<?= e($editRow['email']) ?>"></div>
                        <div><label>Téléphone</label><input name="phone" value="<?= e($editRow['phone']) ?>"></div>
                    </div>
                    <label>Permanences</label><textarea name="office_hours"><?= e($editRow['office_hours']) ?></textarea>
                    <label>Lieu</label><input name="location" value="<?= e($editRow['location']) ?>">
                    <label>Annonce</label><textarea name="announcement"><?= e($editRow['announcement']) ?></textarea>
                <?php elseif ($type === 'actions'): ?>
                    <label>Titre</label><input name="title" value="<?= e($editRow['title']) ?>" required>
                    <div class="form-grid">
                        <div><label>Catégorie</label><select name="category"><?php foreach (PROTECTION_ACTION_CATEGORIES as $key => $label): ?><option value="<?= e($key) ?>" <?= admin_protection_selected($key, $editRow['category']) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                        <div><label>Statut</label><select name="status"><?php foreach (PROTECTION_ACTION_STATUSES as $key => $label): ?><option value="<?= e($key) ?>" <?= admin_protection_selected($key, $editRow['status']) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                        <div><label>Référent</label><input name="owner" value="<?= e($editRow['owner']) ?>"></div>
                        <div><label>Date cible</label><input type="date" name="planned_at" value="<?= e(substr((string)$editRow['planned_at'], 0, 10)) ?>"></div>
                    </div>
                    <label>Objectif</label><textarea name="objective"><?= e($editRow['objective']) ?></textarea>
                    <label>Étapes</label><textarea name="steps"><?= e($editRow['steps']) ?></textarea>
                    <label>Cadre</label><textarea name="legal_frame"><?= e($editRow['legal_frame']) ?></textarea>
                    <label>Risques</label><textarea name="risks"><?= e($editRow['risks']) ?></textarea>
                    <label>Visibilité</label><select name="visibility"><option value="public" <?= admin_protection_selected('public', $editRow['visibility']) ?>>Public</option><option value="private" <?= admin_protection_selected('private', $editRow['visibility']) ?>>Privé</option><option value="members" <?= admin_protection_selected('members', $editRow['visibility']) ?>>Membres</option></select>
                <?php endif; ?>

                <label class="checkbox-label"><input type="checkbox" name="is_active" value="1" <?= (int)$editRow['is_active'] === 1 ? 'checked' : '' ?>> Actif</label>
                <div class="form-actions">
                    <button class="button-primary" type="submit">Enregistrer</button>
                    <a class="button-secondary" href="<?= e(admin_url('protection.php?type=' . $type)) ?>">Annuler</a>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2><?= e($config['title']) ?></h2>
        <?php if (!$rows): ?><p class="muted">Aucun élément.</p><?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Élément</th><th>Type</th><th>Date</th><th>État</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $rowType = $config['type_field'] !== '' ? (string)$row[$config['type_field']] : '';
                            $typeLabel = $rowType !== '' ? protection_label($config['types'], $rowType) : 'Panneau syndical';
                        ?>
                        <tr>
                            <td><strong><?= e((string)$row[$config['label']]) ?></strong><br><span class="meta">#<?= e((string)$row['id']) ?></span></td>
                            <td><?= e($typeLabel) ?></td>
                            <td><?= e((string)$row[$config['date']]) ?></td>
                            <td><?= (int)$row['is_active'] === 1 ? '<span class="badge badge-active">Actif</span>' : '<span class="badge badge-disabled">Désactivé</span>' ?></td>
                            <td>
                                <div class="admin-actions">
                                    <a class="button-secondary" href="<?= e(admin_url('protection.php?type=' . $type . '&edit=' . (int)$row['id'] . '#edit')) ?>">Lire / modifier</a>
                                    <form method="post" action="">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="type" value="<?= e($type) ?>">
                                        <input type="hidden" name="id" value="<?= e((string)$row['id']) ?>">
                                        <input type="hidden" name="action" value="<?= (int)$row['is_active'] === 1 ? 'disable' : 'enable' ?>">
                                        <button class="button-secondary" type="submit"><?= (int)$row['is_active'] === 1 ? 'Désactiver' : 'Réactiver' ?></button>
                                    </form>
                                    <form method="post" action="" onsubmit="return confirm('Supprimer définitivement cet élément ?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="type" value="<?= e($type) ?>">
                                        <input type="hidden" name="id" value="<?= e((string)$row['id']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button class="button-danger" type="submit">Supprimer</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
