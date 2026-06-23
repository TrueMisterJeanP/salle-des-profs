<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/protection.php';

function federation_token_is_valid(string $token): bool
{
    return preg_match('/^[A-Za-z0-9]{48}$/', $token) === 1;
}

function federation_authorization_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if (preg_match('/^Bearer\s+(.+)$/i', trim((string)$header), $matches) === 1) {
        return trim($matches[1]);
    }

    return trim(get_value('token'));
}

function federation_feed_type_filter(string $type): string
{
    $type = strtolower(trim($type));

    return in_array($type, ['post', 'article', 'event', 'incident', 'action', 'protection'], true) ? $type : '';
}

function federation_item_matches_feed_type(string $itemType, string $feedType): bool
{
    if ($feedType === '') {
        return true;
    }

    if ($feedType === 'protection') {
        return in_array($itemType, ['event', 'incident', 'action'], true);
    }

    return $itemType === $feedType;
}

function federation_local_feed_items(int $limit = 30, string $type = ''): array
{
    $type = federation_feed_type_filter($type);
    $unlimited = $limit <= 0 && $type !== '';
    $limit = $unlimited ? 0 : max(1, min($limit, 400));
    $limitSql = $unlimited ? '' : ' LIMIT :limit';
    $limitParams = $unlimited ? [] : ['limit' => $limit];
    $protectionTypes = ['event', 'incident', 'action'];
    $isProtectionFeed = $type === 'protection';
    $items = [];

    if ($type === '' || $type === 'post') {
        $posts = db_fetch_all(
            "SELECT posts.id, posts.content, posts.created_at, users.username, users.display_name
             FROM posts
             JOIN users ON users.id = posts.author_id
             WHERE posts.is_published = 1
               AND posts.visibility = 'public'
             ORDER BY posts.created_at DESC
            " . $limitSql,
            $limitParams
        );

        foreach ($posts as $post) {
            $summary = excerpt((string)$post['content'], 220);
            $title = excerpt((string)$post['content'], 80);

            $items[] = [
                'type' => 'post',
                'type_label' => 'Annonce',
                'id' => (int)$post['id'],
                'title' => $title !== '' ? $title : 'Annonce publique',
                'date' => (string)$post['created_at'],
                'badge' => '',
                'summary' => $summary,
                'location' => '',
                'meta' => 'Par ' . (string)($post['display_name'] ?: $post['username']),
                'url' => url('public.php') . '#post-' . (int)$post['id'],
            ];
        }
    }

    if ($type === '' || $type === 'article') {
        $articles = db_fetch_all(
            "SELECT articles.id, articles.title, articles.slug, articles.content, articles.excerpt,
                    articles.published_at, articles.created_at, users.username, users.display_name
             FROM articles
             JOIN users ON users.id = articles.author_id
             WHERE articles.status = 'published'
               AND articles.visibility = 'public'
             ORDER BY articles.published_at DESC, articles.created_at DESC
            " . $limitSql,
            $limitParams
        );

        foreach ($articles as $article) {
            $items[] = [
                'type' => 'article',
                'type_label' => 'Article',
                'id' => (int)$article['id'],
                'title' => (string)$article['title'],
                'date' => (string)($article['published_at'] ?: $article['created_at']),
                'badge' => '',
                'summary' => excerpt((string)($article['excerpt'] ?: $article['content']), 220),
                'location' => '',
                'meta' => 'Par ' . (string)($article['display_name'] ?: $article['username']),
                'url' => url('public_article.php?slug=' . rawurlencode((string)$article['slug'])),
            ];
        }
    }

    if ($type === '' || $type === 'event' || $isProtectionFeed) {
        $events = db_fetch_all(
            "SELECT id, title, event_type, starts_at, ends_at, location, response_summary, description, created_at
             FROM protection_events
             WHERE is_active = 1
               AND visibility = 'public'
             ORDER BY starts_at DESC, created_at DESC
            " . $limitSql,
            $limitParams
        );

        foreach ($events as $event) {
            $eventDescription = trim((string)($event['description'] ?? ''));
            $eventResponseSummary = trim((string)($event['response_summary'] ?? ''));

            $items[] = [
                'type' => 'event',
                'type_label' => 'Événement',
                'id' => (int)$event['id'],
                'title' => (string)$event['title'],
                'date' => (string)$event['starts_at'],
                'end_date' => (string)($event['ends_at'] ?? ''),
                'badge' => protection_label(PROTECTION_EVENT_TYPES, (string)$event['event_type']),
                'summary' => $eventDescription !== '' ? excerpt($eventDescription, 220) : '',
                'description' => $eventDescription !== '' ? excerpt($eventDescription, 220) : '',
                'response_summary' => $eventResponseSummary !== '' ? excerpt($eventResponseSummary, 220) : '',
                'location' => (string)($event['location'] ?? ''),
                'url' => url('events.php#event-' . (int)$event['id']),
            ];
        }
    }

    if ($type === '' || $type === 'incident' || $isProtectionFeed) {
        $incidents = db_fetch_all(
            "SELECT id, title, occurred_at, incident_type, source_type, status, institutional_response, description, created_at
             FROM protection_incidents
             WHERE is_active = 1
               AND visibility = 'public'
             ORDER BY occurred_at DESC, created_at DESC
            " . $limitSql,
            $limitParams
        );

        foreach ($incidents as $incident) {
            $incidentDescription = trim((string)($incident['description'] ?? ''));
            $incidentInstitutionalResponse = trim((string)($incident['institutional_response'] ?? ''));

            $items[] = [
                'type' => 'incident',
                'type_label' => 'Incident',
                'id' => (int)$incident['id'],
                'title' => (string)$incident['title'],
                'date' => (string)$incident['occurred_at'],
                'badge' => protection_label(PROTECTION_STATUSES, (string)$incident['status']),
                'summary' => $incidentDescription !== '' ? excerpt($incidentDescription, 220) : '',
                'description' => $incidentDescription !== '' ? excerpt($incidentDescription, 220) : '',
                'response_summary' => $incidentInstitutionalResponse !== '' ? excerpt($incidentInstitutionalResponse, 220) : '',
                'location' => '',
                'meta' => protection_label(PROTECTION_INCIDENT_TYPES, (string)$incident['incident_type'])
                    . ' · ' . protection_label(PROTECTION_INCIDENT_SOURCES, (string)$incident['source_type']),
                'url' => url('incidents.php#incident-' . (int)$incident['id']),
            ];
        }
    }

    if ($type === '' || $type === 'action' || $isProtectionFeed) {
        $actions = db_fetch_all(
            "SELECT id, title, category, status, objective, planned_at, created_at
             FROM protection_action_plans
             WHERE is_active = 1
               AND visibility = 'public'
             ORDER BY COALESCE(planned_at, created_at) DESC, created_at DESC
            " . $limitSql,
            $limitParams
        );

        foreach ($actions as $action) {
            $items[] = [
                'type' => 'action',
                'type_label' => 'Action',
                'id' => (int)$action['id'],
                'title' => (string)$action['title'],
                'date' => (string)($action['planned_at'] ?: $action['created_at']),
                'badge' => protection_label(PROTECTION_ACTION_STATUSES, (string)$action['status']),
                'summary' => excerpt((string)$action['objective'], 220),
                'location' => '',
                'meta' => protection_label(PROTECTION_ACTION_CATEGORIES, (string)$action['category']),
                'url' => url('actions.php#action-' . (int)$action['id']),
            ];
        }
    }

    usort($items, static fn (array $a, array $b): int => strcmp((string)$b['date'], (string)$a['date']));

    if ($isProtectionFeed) {
        $items = array_values(array_filter(
            $items,
            static fn (array $item): bool => in_array((string)($item['type'] ?? ''), $protectionTypes, true)
        ));
    }

    return $unlimited ? $items : array_slice($items, 0, $limit);
}

function federation_feed_payload(int $limit = 30, string $type = ''): array
{
    $type = federation_feed_type_filter($type);

    return [
        'site' => [
            'name' => site_name(),
            'url' => site_canonical_base_url(),
        ],
        'filter' => [
            'type' => $type,
        ],
        'generated_at' => now(),
        'items' => federation_local_feed_items($limit, $type),
    ];
}

function federation_remote_feed_url(): string
{
    $baseUrl = federation_follow_url();

    return $baseUrl !== '' ? $baseUrl . '/federation_feed.php' : '';
}

function federation_configured_follow_slots(): array
{
    $slots = [];

    foreach (federation_follow_slots() as $slot) {
        $url = rtrim(trim((string)$slot['url']), '/');
        $token = trim((string)$slot['token']);

        if ($url === '' || $token === '') {
            continue;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL) || !federation_token_is_valid($token)) {
            continue;
        }

        $slots[] = [
            'index' => (int)$slot['index'],
            'name' => federation_slot_label($slot),
            'url' => $url,
            'token' => $token,
        ];
    }

    return $slots;
}

function federation_remote_feed_url_for_slot(array $slot): string
{
    $baseUrl = rtrim(trim((string)($slot['url'] ?? '')), '/');

    return $baseUrl !== '' ? $baseUrl . '/federation_feed.php' : '';
}

function federation_fetch_remote_feed(int $limit = 30, string $type = ''): array
{
    if (!federation_enabled()) {
        return ['items' => [], 'error' => ''];
    }

    $slots = federation_configured_follow_slots();

    if (!$slots) {
        return ['items' => [], 'error' => 'Aucun flux fédéré configuré.'];
    }

    $items = [];
    $errors = [];

    foreach ($slots as $slot) {
        $result = federation_fetch_remote_slot($slot, $limit, $type);

        if ($result['error'] !== '') {
            $errors[] = federation_slot_label($slot) . ' : ' . $result['error'];
            continue;
        }

        $items = array_merge($items, $result['items']);
    }

    usort($items, static fn (array $a, array $b): int => strcmp((string)$b['date'], (string)$a['date']));

    return [
        'items' => $limit <= 0 && federation_feed_type_filter($type) !== '' ? $items : array_slice($items, 0, $limit),
        'error' => $items ? '' : implode(' ', $errors),
    ];
}

function federation_fetch_remote_slot(array $slot, int $limit = 30, string $type = ''): array
{
    $url = federation_remote_feed_url_for_slot($slot);
    $token = trim((string)($slot['token'] ?? ''));
    $type = federation_feed_type_filter($type);

    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['items' => [], 'error' => 'URL de fédération invalide.'];
    }

    if (!federation_token_is_valid($token)) {
        return ['items' => [], 'error' => 'Token du site suivi invalide.'];
    }

    $query = ['limit' => $limit <= 0 && $type !== '' ? 0 : max(1, min($limit, 400))];
    if ($type !== '') {
        $query['type'] = $type;
    }

    $body = federation_http_get($url . '?' . http_build_query($query), $token);

    if ($body === null) {
        return ['items' => [], 'error' => 'Fil fédéré indisponible.'];
    }

    $payload = json_decode($body, true);

    if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
        return ['items' => [], 'error' => 'Réponse de fédération invalide.'];
    }

    $payloadType = federation_feed_type_filter((string)($payload['filter']['type'] ?? ''));
    if ($limit <= 0 && $type !== '' && $payloadType !== $type) {
        $fallbackBody = federation_http_get($url . '?' . http_build_query(['limit' => 400]), $token);
        if ($fallbackBody !== null) {
            $fallbackPayload = json_decode($fallbackBody, true);
            if (is_array($fallbackPayload) && isset($fallbackPayload['items']) && is_array($fallbackPayload['items'])) {
                $payload = $fallbackPayload;
            }
        }
    }

    $sourceName = (string)($payload['site']['name'] ?? 'Salle des profs');
    $sourceUrl = (string)($payload['site']['url'] ?? (string)($slot['url'] ?? ''));
    $items = [];

    foreach ($payload['items'] as $item) {
        if (!is_array($item)) {
            continue;
        }

        $title = trim((string)($item['title'] ?? ''));
        $date = trim((string)($item['date'] ?? ''));
        $endDate = trim((string)($item['end_date'] ?? $item['ends_at'] ?? ''));
        $description = excerpt(trim((string)($item['description'] ?? '')), 220);
        $responseSummary = excerpt(trim((string)($item['response_summary'] ?? '')), 220);

        if ($title === '' || $date === '') {
            continue;
        }

        $itemType = trim((string)($item['type'] ?? ''));

        if (!federation_item_matches_feed_type($itemType, $type)) {
            continue;
        }

        $items[] = [
            'type' => $itemType,
            'type_label' => trim((string)($item['type_label'] ?? 'Info')),
            'title' => $title,
            'date' => $date,
            'end_date' => $endDate,
            'badge' => trim((string)($item['badge'] ?? '')),
            'summary' => excerpt(trim((string)($item['summary'] ?? '')), 220),
            'description' => $description,
            'response_summary' => $responseSummary,
            'location' => trim((string)($item['location'] ?? '')),
            'meta' => trim((string)($item['meta'] ?? '')),
            'url' => trim((string)($item['url'] ?? '')),
            'source_name' => $sourceName,
            'source_url' => $sourceUrl,
        ];
    }

    usort($items, static fn (array $a, array $b): int => strcmp((string)$b['date'], (string)$a['date']));

    return [
        'items' => $limit <= 0 && $type !== '' ? $items : array_slice($items, 0, $limit),
        'error' => '',
    ];
}

function federation_cache_ensure_schema(): void
{
    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS federated_feed_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_index INTEGER NOT NULL,
            source_name TEXT NOT NULL,
            source_url TEXT,
            item_type TEXT,
            type_label TEXT,
            title TEXT NOT NULL,
            item_date TEXT NOT NULL,
            end_date TEXT,
            badge TEXT,
            summary TEXT,
            description TEXT,
            response_summary TEXT,
            location TEXT,
            meta TEXT,
            item_url TEXT,
            fetched_at TEXT NOT NULL
        )"
    );

    db_query("CREATE INDEX IF NOT EXISTS idx_federated_feed_items_date ON federated_feed_items(item_date)");
    db_query("CREATE INDEX IF NOT EXISTS idx_federated_feed_items_source ON federated_feed_items(source_index)");

    if (!db_column_exists('federated_feed_items', 'description')) {
        db_query("ALTER TABLE federated_feed_items ADD COLUMN description TEXT");
    }
    if (!db_column_exists('federated_feed_items', 'response_summary')) {
        db_query("ALTER TABLE federated_feed_items ADD COLUMN response_summary TEXT");
    }
    if (!db_column_exists('federated_feed_items', 'end_date')) {
        db_query("ALTER TABLE federated_feed_items ADD COLUMN end_date TEXT");
    }
}

function federation_cache_last_refresh_at(): string
{
    return setting_value('federation_cache_last_refresh_at', '');
}

function federation_cache_last_error(): string
{
    return setting_value('federation_cache_last_error', '');
}

function federation_refresh_cache_if_due(int $limit = 100, int $ttlSeconds = 900): void
{
    if (!federation_enabled()) {
        return;
    }

    federation_cache_ensure_schema();
    $lastRefresh = federation_cache_last_refresh_at();

    if ($lastRefresh !== '' && strtotime($lastRefresh) !== false && time() - (int)strtotime($lastRefresh) < $ttlSeconds) {
        $cacheAge = time() - (int)strtotime($lastRefresh);
        $cachedItems = (int)(db_fetch_one("SELECT COUNT(*) AS total FROM federated_feed_items")['total'] ?? 0);

        if ($cachedItems > 0 || $cacheAge < 60) {
            return;
        }
    }

    federation_refresh_cache($limit);
}

function federation_refresh_cache(int $limit = 100, string $type = ''): void
{
    federation_cache_ensure_schema();
    $type = federation_feed_type_filter($type);

    $slots = federation_configured_follow_slots();
    $errors = [];

    if (!$slots) {
        db_query("DELETE FROM federated_feed_items");
        save_setting_value('federation_cache_last_error', 'Aucun flux fédéré configuré.');
        save_setting_value('federation_cache_last_refresh_at', now());
        return;
    }

    foreach ($slots as $slot) {
        $result = federation_fetch_remote_slot($slot, $limit, $type);

        if ($result['error'] !== '') {
            $errors[] = federation_slot_label($slot) . ' : ' . $result['error'];
            continue;
        }

        if ($type === 'protection') {
            db_query(
                "DELETE FROM federated_feed_items
                 WHERE source_index = :source_index
                   AND item_type IN ('event', 'incident', 'action')",
                ['source_index' => $slot['index']]
            );
        } elseif ($type !== '') {
            db_query(
                "DELETE FROM federated_feed_items
                 WHERE source_index = :source_index
                   AND item_type = :item_type",
                [
                    'source_index' => $slot['index'],
                    'item_type' => $type,
                ]
            );
        } else {
            db_query("DELETE FROM federated_feed_items WHERE source_index = :source_index", [
                'source_index' => $slot['index'],
            ]);
        }

        foreach ($result['items'] as $item) {
            db_insert(
                "INSERT INTO federated_feed_items
                 (source_index, source_name, source_url, item_type, type_label, title, item_date,
                  end_date, badge, summary, description, response_summary, location, meta, item_url, fetched_at)
                 VALUES
                 (:source_index, :source_name, :source_url, :item_type, :type_label, :title, :item_date,
                  :end_date, :badge, :summary, :description, :response_summary, :location, :meta, :item_url, :fetched_at)",
                [
                    'source_index' => $slot['index'],
                    'source_name' => federation_slot_label($slot),
                    'source_url' => (string)($item['source_url'] ?? $slot['url']),
                    'item_type' => (string)($item['type'] ?? ''),
                    'type_label' => (string)($item['type_label'] ?? 'Info'),
                    'title' => (string)$item['title'],
                    'item_date' => (string)$item['date'],
                    'end_date' => (string)($item['end_date'] ?? ''),
                    'badge' => (string)($item['badge'] ?? ''),
                    'summary' => (string)($item['summary'] ?? ''),
                    'description' => (string)($item['description'] ?? ''),
                    'response_summary' => (string)($item['response_summary'] ?? ''),
                    'location' => (string)($item['location'] ?? ''),
                    'meta' => (string)($item['meta'] ?? ''),
                    'item_url' => (string)($item['url'] ?? ''),
                    'fetched_at' => now(),
                ]
            );
        }
    }

    save_setting_value('federation_cache_last_error', implode(' ', $errors));
    save_setting_value('federation_cache_last_refresh_at', now());
}

function federation_cached_items(int $limit = 100): array
{
    federation_cache_ensure_schema();

    $limitSql = $limit <= 0 ? '' : 'LIMIT :limit';
    $params = $limit <= 0 ? [] : ['limit' => max(1, min($limit, 400))];

    $rows = db_fetch_all(
        "SELECT *
         FROM federated_feed_items
         ORDER BY item_date DESC, fetched_at DESC, id DESC
         " . $limitSql,
        $params
    );

    return array_map(static fn (array $row): array => [
        'type' => (string)($row['item_type'] ?? ''),
        'type_label' => (string)($row['type_label'] ?? 'Info'),
        'title' => (string)$row['title'],
        'date' => (string)$row['item_date'],
        'end_date' => (string)($row['end_date'] ?? ''),
        'badge' => (string)($row['badge'] ?? ''),
        'summary' => (string)($row['summary'] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'response_summary' => (string)($row['response_summary'] ?? ''),
        'location' => (string)($row['location'] ?? ''),
        'meta' => (string)($row['meta'] ?? ''),
        'url' => (string)($row['item_url'] ?? ''),
        'source_name' => (string)($row['source_name'] ?? 'Salle des profs'),
        'source_url' => (string)($row['source_url'] ?? ''),
    ], $rows);
}

function federation_http_get(string $url, string $token): ?string
{
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];

    if (function_exists('curl_init')) {
        $handle = curl_init($url);

        if ($handle !== false) {
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 2,
            ]);

            $body = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            return is_string($body) && $status >= 200 && $status < 300 ? $body : null;
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 4,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);

    if (!is_string($body)) {
        return null;
    }

    $statusLine = $http_response_header[0] ?? '';

    return preg_match('/\s2\d\d\s/', $statusLine) === 1 ? $body : null;
}
