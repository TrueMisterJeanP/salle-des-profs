<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/pagination.php';

require_admin();

activity_log_ensure_table();
auth_ensure_rate_limit_table();

if (is_post()) {
    require_csrf();

    $action = post_value('action');

    if ($action === 'clear_activity') {
        activity_log_disable_current_request();
        $_SESSION['skip_next_activity_log'] = true;
        db_query("DELETE FROM visitor_activity");
        set_flash('success', 'Journal d’activité effacé.');
        redirect(admin_url('activity.php'));
    }

    if ($action === 'clear_auth_attempt_display') {
        db_query(
            "UPDATE auth_attempts
             SET identifier_value = NULL,
                 ip_address = NULL,
                 password_fingerprint = NULL"
        );
        set_flash('success', 'Informations affichables des tentatives de connexion effacées. La protection anti force brute reste active.');
        redirect(admin_url('activity.php'));
    }
}

$search = get_value('q');
$type = get_value('type', 'all');
$params = [];
$whereParts = [];

$allowedTypes = ['all', 'users', 'visitors'];

if (!in_array($type, $allowedTypes, true)) {
    $type = 'all';
}

if ($search !== '') {
    $whereParts[] = "(visitor_activity.path LIKE :q
        OR visitor_activity.full_url LIKE :q
        OR visitor_activity.ip_address LIKE :q
        OR visitor_activity.user_agent LIKE :q
        OR users.username LIKE :q
        OR users.display_name LIKE :q)";
    $params['q'] = '%' . $search . '%';
}

if ($type === 'users') {
    $whereParts[] = "visitor_activity.user_id IS NOT NULL";
} elseif ($type === 'visitors') {
    $whereParts[] = "visitor_activity.user_id IS NULL";
}

$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$page = current_page();
$perPage = 10;
$offset = pagination_offset($page, $perPage);

$totalConnectedUsers = (int)(db_fetch_one(
    "SELECT COUNT(DISTINCT user_id) AS total
     FROM visitor_activity
     WHERE user_id IS NOT NULL"
)['total'] ?? 0);

$totalAnonymousVisitors = (int)(db_fetch_one(
    "SELECT COUNT(DISTINCT visitor_hash) AS total
     FROM visitor_activity
     WHERE user_id IS NULL
       AND visitor_hash IS NOT NULL
       AND visitor_hash != ''"
)['total'] ?? 0);

$totalRows = (int)(db_fetch_one(
    "SELECT COUNT(*) AS total
     FROM visitor_activity
     LEFT JOIN users ON users.id = visitor_activity.user_id
     $where",
    $params
)['total'] ?? 0);

$totalPages = total_pages($totalRows, $perPage);

$activities = db_fetch_all(
    "SELECT visitor_activity.*,
            users.username,
            users.display_name
     FROM visitor_activity
     LEFT JOIN users ON users.id = visitor_activity.user_id
     $where
     ORDER BY visitor_activity.created_at DESC, visitor_activity.id DESC
     LIMIT :limit OFFSET :offset",
    array_merge($params, [
        'limit' => $perPage,
        'offset' => $offset,
    ])
);

$authAttemptsWhere = "action = 'login'
       AND success = 0
       AND (
            identifier_value IS NOT NULL
            OR ip_address IS NOT NULL
            OR password_fingerprint IS NOT NULL
       )";
$authAttemptsPage = current_page('auth_attempts_page');
$authAttemptsPerPage = 10;
$authAttemptsOffset = pagination_offset($authAttemptsPage, $authAttemptsPerPage);
$totalAuthAttempts = (int)(db_fetch_one(
    "SELECT COUNT(*) AS total
     FROM auth_attempts
     WHERE $authAttemptsWhere"
)['total'] ?? 0);
$authAttemptsTotalPages = total_pages($totalAuthAttempts, $authAttemptsPerPage);

$authAttempts = db_fetch_all(
    "SELECT auth_attempts.*,
            (
                SELECT COUNT(*)
                FROM auth_attempts repeated
                WHERE repeated.password_fingerprint = auth_attempts.password_fingerprint
                  AND repeated.password_fingerprint IS NOT NULL
                  AND repeated.password_fingerprint != ''
            ) AS password_repeat_count
     FROM auth_attempts
     WHERE $authAttemptsWhere
     ORDER BY created_at DESC, id DESC
     LIMIT :limit OFFSET :offset",
    [
        'limit' => $authAttemptsPerPage,
        'offset' => $authAttemptsOffset,
    ]
);

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Activité — Administration — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/../public/assets/app.css')) ?>">
</head>
<body>
    <?php require __DIR__ . '/../templates/header.php'; ?>

    <main class="container page">
        <?php foreach ($flashes as $flash): ?>
            <div class="<?= e(flash_class($flash['type'])) ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endforeach; ?>

        <section class="card">
            <h1>Activité du site</h1>
            <p class="muted">
                Consultez les accès aux pages : heure, utilisateur, IP, URL, référent et navigateur.
            </p>

            <div class="form-actions">
                <a class="button-secondary" href="<?= e(admin_url('index.php')) ?>">Retour admin</a>
            </div>
        </section>

        <section class="grid grid-2 dashboard-grid">
            <article class="card dashboard-card">
                <h2>Utilisateurs identifiés</h2>
                <p class="stat"><?= e((string)$totalConnectedUsers) ?></p>
                <p class="muted">comptes connectés différents</p>
            </article>

            <article class="card dashboard-card">
                <h2>Visiteurs non connectés</h2>
                <p class="stat"><?= e((string)$totalAnonymousVisitors) ?></p>
                <p class="muted">visiteurs publics différents</p>
            </article>
        </section>

        <section class="card">
            <form method="get" action="">
                <label for="q">Rechercher</label>
                <input
                    type="search"
                    id="q"
                    name="q"
                    value="<?= e($search) ?>"
                    placeholder="URL, IP, navigateur, utilisateur..."
                >

                <label for="type">Type d’accès</label>
                <select id="type" name="type">
                    <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>
                        Tous
                    </option>
                    <option value="users" <?= $type === 'users' ? 'selected' : '' ?>>
                        Utilisateurs connectés
                    </option>
                    <option value="visitors" <?= $type === 'visitors' ? 'selected' : '' ?>>
                        Visiteurs non connectés
                    </option>
                </select>

                <div class="form-actions">
                    <button type="submit" class="button-primary">Rechercher</button>
                    <a class="button-secondary" href="<?= e(admin_url('activity.php')) ?>">Réinitialiser</a>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Tentatives de connexion échouées</h2>
            <p class="muted">
                Les mots de passe saisis ne sont pas enregistrés en clair. Une empreinte HMAC non réversible permet seulement de repérer les répétitions.
            </p>

            <form class="activity-clear-form" method="post" action="" onsubmit="return confirm('Effacer les IP et identifiants affichables des tentatives ? La protection anti force brute restera active.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clear_auth_attempt_display">
                <div class="form-actions">
                    <button type="submit" class="button-danger">
                        Effacer les informations affichées
                    </button>
                </div>
            </form>

            <?php if (!$authAttempts): ?>
                <p class="muted">Aucune tentative échouée enregistrée.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Date et heure</th>
                                <th>Adresse IP</th>
                                <th>Nom d’utilisateur ou email</th>
                                <th>Mot de passe</th>
                                <th>Répétitions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($authAttempts as $attempt): ?>
                                <tr>
                                    <td><?= e($attempt['created_at']) ?></td>
                                    <td><?= e($attempt['ip_address'] ?: '[effacé]') ?></td>
                                    <td><?= e($attempt['identifier_value'] ?: '[effacé]') ?></td>
                                    <td>
                                        <?php if (!empty($attempt['password_fingerprint'])): ?>
                                            <code><?= e(substr((string)$attempt['password_fingerprint'], 0, 16)) ?></code>
                                        <?php else: ?>
                                            <span class="meta">Non disponible</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e((string)($attempt['password_repeat_count'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($authAttemptsTotalPages > 1): ?>
                    <?= render_pagination($authAttemptsPage, $authAttemptsTotalPages, 'auth_attempts_page') ?>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Journal</h2>

            <form class="activity-clear-form" method="post" action="" onsubmit="return confirm('Effacer définitivement tout le journal d’activité ?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clear_activity">
                <div class="form-actions">
                    <button type="submit" class="button-danger">
                        Effacer le journal
                    </button>
                </div>
            </form>

            <?php if (!$activities): ?>
                <p class="muted">Aucune activité enregistrée.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Heure</th>
                                <th>Type</th>
                                <th>Identité</th>
                                <th>IP</th>
                                <th>Lien consulté</th>
                                <th>Référent</th>
                                <th>Navigateur</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activities as $activity): ?>
                                <tr>
                                    <td><?= e($activity['created_at']) ?></td>
                                    <td>
                                        <?php if (!empty($activity['user_id'])): ?>
                                            <span class="badge badge-active">Connecté</span>
                                        <?php else: ?>
                                            <span class="badge badge-disabled">Visiteur</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($activity['user_id'])): ?>
                                            <?= e($activity['display_name'] ?: $activity['username'] ?: 'Utilisateur #' . $activity['user_id']) ?><br>
                                            <span class="meta">#<?= e((string)$activity['user_id']) ?></span>
                                        <?php else: ?>
                                            <span class="meta">
                                                Non identifié<br>
                                                <?= e(substr((string)$activity['visitor_hash'], 0, 12)) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($activity['ip_address'] ?: '-') ?></td>
                                    <td>
                                        <strong><?= e($activity['method']) ?></strong>
                                        <?= e($activity['path']) ?>
                                        <?php if (!empty($activity['query_string'])): ?>
                                            <br><span class="meta">?<?= e($activity['query_string']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($activity['referer'])): ?>
                                            <span class="meta"><?= e(excerpt($activity['referer'], 90)) ?></span>
                                        <?php else: ?>
                                            <span class="meta">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="meta"><?= e(excerpt($activity['user_agent'] ?: '-', 120)) ?></span>
                                    </td>
                                    <td><?= e((string)($activity['http_status'] ?: '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <?= render_pagination($page, $totalPages) ?>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
