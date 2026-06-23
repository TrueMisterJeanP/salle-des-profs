<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/contact_messages.php';

require_admin();

$user = current_user();
$flashes = get_flashes();

$stats = [
    'users' => 0,
    'active_users' => 0,
    'groups' => 0,
    'messages' => 0,
    'posts' => 0,
    'articles' => 0,
    'comments' => 0,
    'attachments' => 0,
    'contact_messages' => 0,
    'unreplied_contact_messages' => 0,
];

$recentUsers = [];
$recentPosts = [];
$recentArticles = [];

try {
    $stats['users'] = (int)(db_fetch_one("SELECT COUNT(*) AS total FROM users")['total'] ?? 0);
    $stats['active_users'] = (int)(db_fetch_one("SELECT COUNT(*) AS total FROM users WHERE is_active = 1")['total'] ?? 0);
    $stats['groups'] = (int)(db_fetch_one("SELECT COUNT(*) AS total FROM groups")['total'] ?? 0);
    $stats['messages'] = (int)(db_fetch_one("SELECT COUNT(*) AS total FROM messages WHERE is_deleted = 0")['total'] ?? 0);
    $stats['posts'] = (int)(db_fetch_one("SELECT COUNT(*) AS total FROM posts")['total'] ?? 0);
    $stats['articles'] = (int)(db_fetch_one("SELECT COUNT(*) AS total FROM articles")['total'] ?? 0);
    $stats['comments'] = (int)(db_fetch_one("SELECT COUNT(*) AS total FROM comments WHERE is_deleted = 0")['total'] ?? 0);
    $stats['attachments'] = (int)(db_fetch_one("SELECT COUNT(*) AS total FROM attachments")['total'] ?? 0);
    contact_messages_ensure_table();
    $stats['contact_messages'] = (int)(db_fetch_one("SELECT COUNT(*) AS total FROM contact_messages")['total'] ?? 0);
    $stats['unreplied_contact_messages'] = (int)(db_fetch_one("SELECT COUNT(*) AS total FROM contact_messages WHERE replied_at IS NULL")['total'] ?? 0);

    $recentUsers = db_fetch_all(
        "SELECT id, username, email, display_name, role, is_active, created_at
         FROM users
         ORDER BY created_at DESC
         LIMIT 6"
    );

    $recentPosts = db_fetch_all(
        "SELECT posts.id, posts.content, posts.visibility, posts.is_published, posts.created_at,
                users.username, users.display_name
         FROM posts
         JOIN users ON users.id = posts.author_id
         ORDER BY posts.created_at DESC
         LIMIT 6"
    );

    $recentArticles = db_fetch_all(
        "SELECT articles.id, articles.title, articles.slug, articles.status, articles.visibility, articles.created_at,
                users.username, users.display_name
         FROM articles
         JOIN users ON users.id = articles.author_id
         ORDER BY articles.created_at DESC
         LIMIT 6"
    );
} catch (Throwable $e) {
    set_flash('error', 'Erreur lors du chargement de l’administration : ' . $e->getMessage());
}

?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Administration — <?= e(APP_NAME) ?></title>
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
            <h1>Administration</h1>
            <p class="muted">
                Gestion générale de la plateforme.
            </p>

            <div class="form-actions">
                <a class="button-secondary" href="<?= e(url('dashboard.php')) ?>">Retour au site</a>
                <a class="button-secondary" href="<?= e(admin_url('settings.php')) ?>">Configuration</a>
                <a class="button-secondary" href="<?= e(admin_url('database.php')) ?>">Base de données</a>
                <a class="button-secondary" href="<?= e(admin_url('migrations.php')) ?>">Migrations</a>
                <a class="button-secondary" href="<?= e(admin_url('users.php')) ?>">Utilisateurs</a>
                <a class="button-secondary" href="<?= e(admin_url('mail.php')) ?>">Courriels</a>
                <a class="button-secondary" href="<?= e(admin_url('groups.php')) ?>">Groupes</a>
                <a class="button-secondary" href="<?= e(admin_url('activity.php')) ?>">Activité</a>
                <a class="button-secondary" href="<?= e(admin_url('contact_messages.php')) ?>">Messages de contact</a>
                <a class="button-secondary" href="<?= e(admin_url('posts.php')) ?>">Publications</a>
                <a class="button-secondary" href="<?= e(admin_url('articles.php')) ?>">Articles</a>
                <a class="button-secondary" href="<?= e(admin_url('comments.php')) ?>">Commentaires des articles</a>
                <a class="button-secondary" href="<?= e(admin_url('post_comments.php')) ?>">Commentaires des annonces</a>
                <a class="button-secondary" href="<?= e(admin_url('mastodon_publications.php')) ?>">Publications Mastodon</a>
                <a class="button-secondary" href="<?= e(admin_url('protection.php')) ?>">Protection</a>
            </div>
        </section>

        <section class="grid grid-4 dashboard-grid">
            <article class="card dashboard-card">
                <h2>Utilisateurs</h2>
                <p class="stat"><?= e((string)$stats['users']) ?></p>
                <p class="muted"><?= e((string)$stats['active_users']) ?> actifs</p>
            </article>

            <article class="card dashboard-card">
                <h2>Groupes</h2>
                <p class="stat"><?= e((string)$stats['groups']) ?></p>
                <p class="muted">espaces créés</p>
            </article>

            <article class="card dashboard-card">
                <h2>Messages</h2>
                <p class="stat"><?= e((string)$stats['messages']) ?></p>
                <p class="muted">messages conservés</p>
            </article>

            <article class="card dashboard-card">
                <h2>Fichiers</h2>
                <p class="stat"><?= e((string)$stats['attachments']) ?></p>
                <p class="muted">pièces jointes</p>
            </article>

            <article class="card dashboard-card">
                <h2>Contacts</h2>
                <p class="stat"><?= e((string)$stats['contact_messages']) ?></p>
                <p class="muted"><?= e((string)$stats['unreplied_contact_messages']) ?> sans réponse</p>
            </article>
        </section>

        <section class="grid">
            <article class="card">
                <h2>Utilisateurs récents</h2>

                <?php if (!$recentUsers): ?>
                    <p class="muted">Aucun utilisateur.</p>
                <?php else: ?>
                    <div class="table-wrapper admin-recent-users-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Rôle</th>
                                    <th>État</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentUsers as $recentUser): ?>
                                    <tr>
                                        <td>
                                            <?= e($recentUser['display_name'] ?: $recentUser['username']) ?><br>
                                            <span class="meta"><?= e($recentUser['email']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge <?= $recentUser['role'] === 'admin' ? 'badge-admin' : 'badge-user' ?>">
                                                <?= e(user_role_label($recentUser['role'])) ?>
                                            </span>
                                        </td>
                                        <td><?= (int)$recentUser['is_active'] === 1 ? 'Actif' : 'Bloqué' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </article>
        </section>

        <section class="grid grid-2 admin-latest-grid">
            <article class="card">
                <h2>Publications récentes</h2>

                <?php if (!$recentPosts): ?>
                    <p class="muted">Aucune publication.</p>
                <?php else: ?>
                    <?php foreach ($recentPosts as $post): ?>
                        <div class="post">
                            <div class="post-content markdown-content">
                                <?= render_markdown(excerpt($post['content'], 140)) ?>
                            </div>
                            <p class="meta">
                                <?= e($post['display_name'] ?: $post['username']) ?>
                                · <?= e(visibility_label($post['visibility'])) ?>
                                · <?= (int)$post['is_published'] === 1 ? 'publié' : 'masqué' ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </article>

            <article class="card">
                <h2>Articles récents</h2>

                <?php if (!$recentArticles): ?>
                    <p class="muted">Aucun article.</p>
                <?php else: ?>
                    <?php foreach ($recentArticles as $article): ?>
                        <div class="article-preview">
                            <h3>
                                <a href="<?= e(url('article.php?slug=' . urlencode($article['slug']))) ?>">
                                    <?= e($article['title']) ?>
                                </a>
                            </h3>
                            <p class="meta">
                                <?= e($article['display_name'] ?: $article['username']) ?>
                                · <?= e(article_status_label($article['status'])) ?>
                                · <?= e(visibility_label($article['visibility'])) ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </article>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
