<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/posts.php';
require_once __DIR__ . '/../includes/protection.php';
require_once __DIR__ . '/../includes/federation.php';

require_login();
protection_ensure_schema();
posts_ensure_attachments_table();

$user = current_user();
$recentPosts = [];
$recentArticles = [];
$recentEvents = [];
$recentIncidents = [];
$recentActions = [];
$recentResources = [];
$federatedItems = [];
$federationError = '';
$federationEmptyMessage = 'Aucun événement, incident ou plan d’action public reçu. Le site suivi doit exporter au moins un élément de protection en visibilité Public.';
$showFederationPanel = false;
$postsPage = 1;
$articlesPage = 1;
$dashboardPanelPageSize = 10;
$dashboardPanelFetchLimit = 100;
$dashboardFederationFetchLimit = 0;
$federationPage = current_page('federation_page');
$federationTotalPages = 1;
$federationTotalItems = 0;
$perPage = $dashboardPanelFetchLimit;
$postsPerPage = $dashboardPanelFetchLimit;
$postsOffset = 0;
$articlesOffset = 0;
$postsTotalPages = 1;
$articlesTotalPages = 1;

function dashboard_federation_date_label(string $date): string
{
    $date = trim($date);

    if ($date === '') {
        return '';
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return str_replace('T', ' ', $date);
    }

    return date('d/m/Y à H:i', $timestamp);
}

function dashboard_federation_meta_label(string $meta, string $type): string
{
    if ($type !== 'incident') {
        return $meta;
    }

    return str_replace(' · ', ' / ', $meta);
}

function dashboard_federation_item_url(string $url): string
{
    $url = trim($url);

    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    $scheme = parse_url($url, PHP_URL_SCHEME);

    return in_array($scheme, ['http', 'https'], true) ? $url : '';
}

function dashboard_federation_page_url(int $page): string
{
    $params = $_GET;
    $params['federation_page'] = $page;

    return url('dashboard.php?' . http_build_query($params) . '#dashboard-federation');
}

function dashboard_render_federation_pagination(int $page, int $totalPages): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $html = '<nav class="dashboard-panel-pager pagination" aria-label="Pages de la fédération">';
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);

    if ($start > 1) {
        $html .= '<a class="button-secondary dashboard-panel-page" href="' . e(dashboard_federation_page_url(1)) . '">1</a>';
        if ($start > 2) {
            $html .= '<span class="pagination-ellipsis">…</span>';
        }
    }

    for ($pageNumber = $start; $pageNumber <= $end; $pageNumber++) {
        if ($pageNumber === $page) {
            $html .= '<span class="button-primary pagination-current dashboard-panel-page" aria-current="page">' . e((string)$pageNumber) . '</span>';
        } else {
            $html .= '<a class="button-secondary dashboard-panel-page" href="' . e(dashboard_federation_page_url($pageNumber)) . '">' . e((string)$pageNumber) . '</a>';
        }
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<span class="pagination-ellipsis">…</span>';
        }
        $html .= '<a class="button-secondary dashboard-panel-page" href="' . e(dashboard_federation_page_url($totalPages)) . '">' . e((string)$totalPages) . '</a>';
    }

    $html .= '</nav>';

    return $html;
}

if (is_post()) {
    require_csrf();

    $action = post_value('action');
    $postId = (int)post_value('post_id', '0');
    $redirectUrl = url('dashboard.php' . ($postId > 0 ? '#post-' . $postId : ''));

    if ($action === 'poll_vote') {
        try {
            post_poll_submit_vote($postId, (int)post_value('option_id', '0'), (int)$user['id'], 'members');
            set_flash('success', 'Vote enregistré.');
        } catch (Throwable $e) {
            set_flash('error', 'Erreur : ' . $e->getMessage());
        }

        redirect($redirectUrl);
    }

    if ($action === 'post_comment') {
        try {
            post_comment_create($postId, (int)$user['id'], post_value('comment_content'));
            set_flash('success', 'Commentaire ajouté.');
        } catch (Throwable $e) {
            set_flash('error', 'Erreur : ' . $e->getMessage());
        }

        redirect($redirectUrl);
    }

    if (in_array($action, ['pin', 'unpin'], true)) {
        if (($user['role'] ?? '') !== 'admin') {
            set_flash('error', 'Action réservée aux administrateurs.');
            redirect($redirectUrl);
        }

        try {
            if ($action === 'pin') {
                post_pin($postId);
                set_flash('success', 'Publication épinglée.');
            } else {
                post_unpin($postId);
                set_flash('success', 'Publication désépinglée.');
            }
        } catch (Throwable $e) {
            set_flash('error', 'Erreur : ' . $e->getMessage());
        }

        redirect($redirectUrl);
    }
}

$flashes = get_flashes();
$showFederationPanel = federation_enabled();

try {
    posts_ensure_pinning_column();
    ensure_content_group_column('articles');

    $visiblePostsWhere = "posts.is_published = 1
           AND (
                posts.visibility = 'public'
                OR
                posts.visibility = 'members'
                OR posts.author_id = :current_user_id
                OR (
                    posts.visibility = 'group'
                    AND EXISTS (
                        SELECT 1
                        FROM group_members gm
                        WHERE gm.group_id = posts.group_id
                          AND gm.user_id = :current_user_id
                    )
                )
           )";
    $visibleArticlesWhere = "articles.status = 'published'
           AND (
                articles.visibility = 'public'
                OR
                articles.visibility = 'members'
                OR articles.author_id = :current_user_id
                OR (
                    articles.visibility = 'group'
                    AND EXISTS (
                        SELECT 1
                        FROM group_members gm
                        WHERE gm.group_id = articles.group_id
                          AND gm.user_id = :current_user_id
                    )
                )
           )";

    $postsTotalPages = total_pages((int)(db_fetch_one(
        "SELECT COUNT(*) AS total
         FROM posts
         WHERE $visiblePostsWhere",
        ['current_user_id' => $user['id']]
    )['total'] ?? 0), $postsPerPage);

    $articlesTotalPages = total_pages((int)(db_fetch_one(
        "SELECT COUNT(*) AS total
         FROM articles
         WHERE $visibleArticlesWhere",
        ['current_user_id' => $user['id']]
    )['total'] ?? 0), $perPage);

    $recentPosts = db_fetch_all(
        "SELECT posts.*, users.display_name, users.username, users.avatar
         FROM posts
         JOIN users ON users.id = posts.author_id
         WHERE $visiblePostsWhere
         ORDER BY CASE WHEN posts.pinned_at IS NULL THEN 1 ELSE 0 END ASC,
                  posts.pinned_at DESC,
                  posts.created_at DESC
         LIMIT :limit OFFSET :offset",
        [
            'current_user_id' => $user['id'],
            'limit' => $postsPerPage,
            'offset' => $postsOffset,
        ]
    );
    $recentPosts = posts_attach_attachments(posts_attach_comments(posts_attach_polls($recentPosts, (int)$user['id'])));

    $recentArticles = db_fetch_all(
        "SELECT articles.*, users.display_name, users.username, users.avatar
         FROM articles
         JOIN users ON users.id = articles.author_id
         WHERE $visibleArticlesWhere
         ORDER BY articles.published_at DESC, articles.created_at DESC
         LIMIT :limit OFFSET :offset",
        [
            'current_user_id' => $user['id'],
            'limit' => $perPage,
            'offset' => $articlesOffset,
        ]
    );

    $recentEvents = db_fetch_all(
        "SELECT *
         FROM protection_events
         WHERE is_active = 1
         ORDER BY starts_at DESC, created_at DESC
         LIMIT 1"
    );

    $recentIncidents = db_fetch_all(
        "SELECT *
         FROM protection_incidents
         WHERE is_active = 1
         ORDER BY occurred_at DESC, created_at DESC
         LIMIT 1"
    );

    $recentActions = db_fetch_all(
        "SELECT *
         FROM protection_action_plans
         WHERE is_active = 1
         ORDER BY created_at DESC
         LIMIT 1"
    );

    $recentResources = db_fetch_all(
        "SELECT *
         FROM protection_resources
         WHERE is_active = 1
         ORDER BY created_at DESC
         LIMIT 1"
    );

    if ($showFederationPanel) {
        federation_refresh_cache($dashboardFederationFetchLimit, 'protection');
        $federatedItems = array_values(array_filter(
            federation_cached_items($dashboardFederationFetchLimit),
            static fn (array $item): bool => in_array((string)($item['type'] ?? ''), ['event', 'incident', 'action'], true)
        ));
        $federationTotalItems = count($federatedItems);
        $federationTotalPages = total_pages($federationTotalItems, $dashboardPanelPageSize);
        if ($federationPage > $federationTotalPages) {
            $federationPage = $federationTotalPages;
        }
        $federatedItems = array_slice($federatedItems, pagination_offset($federationPage, $dashboardPanelPageSize), $dashboardPanelPageSize);
        $federationError = federation_cache_last_error();
    }
} catch (Throwable $e) {
    set_flash('error', 'Erreur lors du chargement du tableau de bord : ' . $e->getMessage());
}

?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Tableau de bord — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:FILL@1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
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
            <h1>Bienvenue, <?= e($user['display_name'] ?: $user['username']) ?></h1>
            <p class="muted">
                Cet espace est destiné à la <b>protection des enseignants par les enseignants</b> : suivi collectif des faits d’établissement,
                réponses institutionnelles, appuis syndicaux, publications et articles.
            </p>

            <div class="form-actions">
                <a class="button-protection" href="<?= e(url('protection.php')) ?>">Établissement</a>
                <a class="button-syndicate" href="<?= e(url('syndicates.php')) ?>">Syndicats</a>
                <a class="button-secondary" href="<?= e(url('feed.php')) ?>">Mes annonces</a>
                <a class="button-secondary" href="<?= e(url('articles.php')) ?>">Mes articles</a>
                <a class="button-secondary" href="<?= e(url('chat.php')) ?>">Messagerie</a>
            </div>
        </section>

        <section class="dashboard-main-layout <?= $showFederationPanel ? '' : 'dashboard-main-layout-no-federation' ?>">
            <?php if ($showFederationPanel): ?>
                <article class="card dashboard-federation-card" id="dashboard-federation">
                    <div class="section-heading-row">
                        <h2>Fédération</h2>
                    </div>

                    <?php if (!$federatedItems): ?>
                        <p class="muted"><?= e($federationError !== '' ? $federationError : $federationEmptyMessage) ?></p>
                    <?php else: ?>
                        <?php if ($federationError !== ''): ?>
                            <p class="muted"><?= e($federationError) ?></p>
                        <?php endif; ?>
                        <div class="compact-list federation-feed-list">
                            <?php foreach ($federatedItems as $item): ?>
                                <?php
                                    $federationItemType = (string)($item['type'] ?? '');
                                    $federationItemTitle = (string)($item['title'] ?? 'Élément fédéré');
                                    $federationItemUrl = dashboard_federation_item_url((string)($item['url'] ?? ''));
                                    $federationItemSourceName = (string)($item['source_name'] ?? 'Flux fédéré');
                                    $federationItemSummary = (string)($item['summary'] ?? '');
                                    $federationItemDescription = (string)($item['description'] ?? '');
                                    $federationItemResponseSummary = (string)($item['response_summary'] ?? '');
                                    $federationItemLocation = (string)($item['location'] ?? '');
                                    $federationItemBadge = (string)($item['badge'] ?? '');
                                    $federationItemTypeLabel = (string)($item['type_label'] ?? 'Info');
                                    $hasFederationStructuredDetails = in_array($federationItemType, ['event', 'incident'], true)
                                        && ($federationItemDescription !== '' || $federationItemResponseSummary !== '');
                                    $federationDateLabel = dashboard_federation_date_label((string)($item['date'] ?? ''));
                                    $federationEndDateLabel = $federationItemType === 'event'
                                        ? dashboard_federation_date_label((string)($item['end_date'] ?? ''))
                                        : '';
                                    $federationMetaLabel = dashboard_federation_meta_label((string)($item['meta'] ?? ''), $federationItemType);
                                ?>
                                <article>
                                    <div class="federation-item-heading">
                                        <div class="meta federation-item-meta-row">
                                            <span class="federation-date-row">
                                                <span class="federation-date-icon material-symbols-outlined" aria-hidden="true">calendar_month</span>
                                                <span class="federation-date-text"><?= e($federationDateLabel) ?><?php if ($federationEndDateLabel !== ''): ?> - <?= e($federationEndDateLabel) ?><?php endif; ?></span>
                                            </span>
                                        </div>
                                        <span class="meta federation-item-source"><?= e($federationItemSourceName) ?></span>
                                        <strong>
                                            <?= e($federationItemTitle) ?>
                                        </strong>
                                        
                                    </div>
                                    
                                    
                                    <?php if ($hasFederationStructuredDetails): ?>
                                        <?php if ($federationItemDescription !== ''): ?>
                                            <p class="federation-item-summary"><?= nl2br(e($federationItemDescription)) ?></p>
                                        <?php endif; ?>
                                        <?php if ($federationItemResponseSummary !== ''): ?>
                                            <span class="meta"><strong>Réponse :</strong></span>
                                            <p class="federation-item-summary"><?= nl2br(e($federationItemResponseSummary)) ?></p>
                                        <?php endif; ?>
                                    <?php elseif ($federationItemSummary !== ''): ?>
                                        <p class="federation-item-summary"><?= nl2br(e($federationItemSummary)) ?></p>
                                    <?php endif; ?>
                                    
                                    <?php if ($federationMetaLabel !== ''): ?>
                                        <p class="meta"><?= e($federationMetaLabel) ?></p>
                                    <?php endif; ?>
                                    
                                  
                                    <?php if ($federationItemLocation !== ''): ?>
                                        <p class="meta federation-item-location"><?= e($federationItemLocation) ?></p>
                                    <?php endif; ?>
                                    

                                    <div class="federation-item-tags">
                                        <span class="badge"><?= e($federationItemTypeLabel) ?></span>
                                        <?php if ($federationItemBadge !== ''): ?>
                                            <span class="badge badge-muted"><?= e($federationItemBadge) ?></span>
                                        <?php endif; ?>
                                    </div>
                                  
                              
                                  
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <?= dashboard_render_federation_pagination($federationPage, $federationTotalPages) ?>
                    <?php endif; ?>
                </article>
            <?php endif; ?>

            <div class="dashboard-right-column">
        <section class="grid grid-4 dashboard-protection-grid dashboard-protection-row">
            <article class="card">
                <div class="section-heading-row">
                    <h2>Dernier événement</h2>
                </div>

                <?php if (!$recentEvents): ?>
                    <p class="muted">Aucun événement enregistré.</p>
                <?php else: ?>
                    <div class="compact-list">
                        <?php foreach ($recentEvents as $event): ?>
                            <?php
                                $eventDateSource = (string)$event['starts_at'];
                                $eventTimestamp = strtotime($eventDateSource);

                                $eventDateLabel = $eventTimestamp !== false
                                    ? date('d/m/Y H:i', $eventTimestamp)
                                    : str_replace('T', ' ', $eventDateSource);
                            ?>
                            <article>
                                <strong><?= e($event['title']) ?></strong>
                                <p class="meta">
                                    <?= e($eventDateLabel) ?>
                                </p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>

          <article class="card">
            <div class="section-heading-row">
              <h2>Dernier incident</h2>
            </div>
            
            <?php if (!$recentIncidents): ?>
            <p class="muted">Aucun incident enregistré.</p>
            <?php else: ?>
            <div class="compact-list">
              <?php foreach ($recentIncidents as $incident): ?>
              <?php
                $incidentDateSource = (string)$incident['occurred_at'];
                $incidentTimestamp = strtotime($incidentDateSource);
                
                $incidentDateLabel = $incidentTimestamp !== false
                ? date('d/m/Y H:i', $incidentTimestamp)
                : str_replace('T', ' ', $incidentDateSource);
              ?>
              
              <article>
                <strong><?= e($incident['title']) ?></strong>
                
                <p class="meta">
                  <?= e($incidentDateLabel) ?>
                </p>
              </article>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </article>

            <article class="card">
                <div class="section-heading-row">
                    <h2>Dernière action</h2>
                </div>

                <?php if (!$recentActions): ?>
                    <p class="muted">Aucune action enregistrée.</p>
                <?php else: ?>
                    <div class="compact-list">
                        <?php foreach ($recentActions as $action): ?>
                            <?php
                              $actionDateSource = (string)($action['planned_at'] ?: $action['created_at']);
                              $actionTimestamp = strtotime($actionDateSource);
                              $actionDateLabel = $actionTimestamp !== false ? date('d/m/Y', $actionTimestamp) : $actionDateSource;
                              $actionTimeLabel = $actionTimestamp !== false ? date('H:i', $actionTimestamp) : '';
                            ?>
                            <article>
                                <strong><?= e($action['title']) ?></strong>
                                <p class="meta dashboard-action-date-row">
                                    <?= e($actionDateLabel . ($actionTimeLabel !== '' ? ' ' . $actionTimeLabel : '')) ?>
                                </p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>

            <article class="card">
                <div class="section-heading-row">
                    <h2>Dernière ressource</h2>
                </div>

                <?php if (!$recentResources): ?>
                    <p class="muted">Pas de ressource enregistrée.</p>
                <?php else: ?>
                    <div class="compact-list">
                        <?php foreach ($recentResources as $resource): ?>
                            <article>
                                <strong><?= e($resource['title']) ?></strong>
                                <p class="meta">
                                    <?= e($resource['created_at']) ?>
                                </p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>

        </section>

        <section class="grid grid-2 dashboard-latest-grid dashboard-latest-row">
            <article class="card" id="dashboard-latest-posts" data-dashboard-paged-panel data-page-size="<?= e((string)$dashboardPanelPageSize) ?>">
                <h2>Dernières annonces</h2>

                <?php if (!$recentPosts): ?>
                    <p class="muted">Aucune publication pour l’instant.</p>
                <?php else: ?>
                    <div data-dashboard-paged-list>
                    <?php foreach ($recentPosts as $post): ?>
                        <div class="post <?= !empty($post['pinned_at']) ? 'post-pinned' : '' ?>" id="post-<?= e((string)$post['id']) ?>">
                            <div class="post-header">
                                <div>
                                    <strong><?= e($post['display_name'] ?: $post['username']) ?></strong>
                                    <span class="meta">@<?= e($post['username']) ?></span>
                                </div>
                                <span class="post-header-right">
                                    <?php if (!empty($post['pinned_at'])): ?>
                                        <span class="badge badge-pinned">Épinglé</span>
                                    <?php endif; ?>
                                    <span class="post-avatar">
                                        <?php if (!empty($post['avatar'])): ?>
                                            <img src="<?= e(avatar_url((int)$post['author_id'], (string)$post['avatar'])) ?>" alt="">
                                        <?php else: ?>
                                            <?= e(mb_strtoupper(mb_substr($post['display_name'] ?: $post['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                        <?php endif; ?>
                                    </span>
                                </span>
                            </div>
                            <div class="post-content markdown-content">
                                <?= render_markdown($post['content']) ?>
                            </div>
                            <?= render_post_attachments($post) ?>
                            <?= render_post_poll($post) ?>
                            <p class="meta">
                                Publié le <?= e($post['created_at']) ?>
                            </p>
                            <div class="form-actions">
                                <a class="button-secondary" href="<?= e(url('discussion.php?post_id=' . (int)$post['id'])) ?>">
                                    Commenter<?= !empty($post['comments']) ? ' (' . e((string)count($post['comments'])) . ')' : '' ?>
                                </a>
                            </div>
                            <?php if (($user['role'] ?? '') === 'admin'): ?>
                                <?php if (empty($post['pinned_at']) && in_array($post['visibility'], ['members', 'group'], true)): ?>
                                    <div class="form-actions">
                                        <form method="post" action="<?= e(url('dashboard.php#post-' . (int)$post['id'])) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="pin">
                                            <input type="hidden" name="post_id" value="<?= e((string)$post['id']) ?>">
                                            <button type="submit" class="button-pinned">Épingler</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <nav class="dashboard-panel-pager" data-dashboard-paged-pagination aria-label="Pages des dernières publications"></nav>
                <?php endif; ?>
            </article>

            <article class="card" id="dashboard-latest-articles" data-dashboard-paged-panel data-page-size="<?= e((string)$dashboardPanelPageSize) ?>">
                <h2>Derniers articles</h2>

                <?php if (!$recentArticles): ?>
                    <p class="muted">Aucun article publié pour l’instant.</p>
                <?php else: ?>
                    <div data-dashboard-paged-list>
                    <?php foreach ($recentArticles as $article): ?>
                        <div class="article-preview">
                            <div class="article-preview-header">
                                <h3>
                                    <a href="<?= e(url('article.php?slug=' . urlencode($article['slug']))) ?>">
                                        <?= e($article['title']) ?>
                                    </a>
                                </h3>
                                <span class="post-avatar">
                                    <?php if (!empty($article['avatar'])): ?>
                                        <img src="<?= e(avatar_url((int)$article['author_id'], (string)$article['avatar'])) ?>" alt="">
                                    <?php else: ?>
                                        <?= e(mb_strtoupper(mb_substr($article['display_name'] ?: $article['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="article-preview-summary markdown-content">
                                <?= render_markdown($article['excerpt'] ?: excerpt($article['content'], 220)) ?>
                            </div>
                            <p class="meta">
                                Par <?= e($article['display_name'] ?: $article['username']) ?>
                                · <?= e($article['published_at'] ?: $article['created_at']) ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <nav class="dashboard-panel-pager" data-dashboard-paged-pagination aria-label="Pages des derniers articles"></nav>
                <?php endif; ?>
            </article>

        </section>
            </div>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
    <script src="<?= e(url('assets/app.js') . '?v=' . filemtime(__DIR__ . '/assets/app.js')) ?>"></script>
</body>
</html>
