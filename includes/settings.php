<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function setting_value(string $key, string $default = ''): string
{
    if (!isset($GLOBALS['app_settings_cache']) || !is_array($GLOBALS['app_settings_cache'])) {
        $GLOBALS['app_settings_cache'] = [];
    }

    if (array_key_exists($key, $GLOBALS['app_settings_cache'])) {
        $cached = $GLOBALS['app_settings_cache'][$key];

        return ($cached['found'] ?? false) ? (string)$cached['value'] : $default;
    }

    try {
        $setting = db_fetch_one(
            "SELECT setting_value
             FROM settings
             WHERE setting_key = :key
             LIMIT 1",
            ['key' => $key]
        );

        if ($setting === null || !array_key_exists('setting_value', $setting)) {
            $GLOBALS['app_settings_cache'][$key] = [
                'found' => false,
                'value' => '',
            ];

            return $default;
        }

        $GLOBALS['app_settings_cache'][$key] = [
            'found' => true,
            'value' => (string)$setting['setting_value'],
        ];

        return (string)$setting['setting_value'];
    } catch (Throwable $e) {
        $GLOBALS['app_settings_cache'][$key] = [
            'found' => false,
            'value' => '',
        ];

        return $default;
    }
}

function save_setting_value(string $key, string $value): void
{
    db_save_setting_value($key, $value);

    if (!isset($GLOBALS['app_settings_cache']) || !is_array($GLOBALS['app_settings_cache'])) {
        $GLOBALS['app_settings_cache'] = [];
    }

    $GLOBALS['app_settings_cache'][$key] = [
        'found' => true,
        'value' => $value,
    ];
}

function invitation_validity_days(): int
{
    $days = (int) setting_value('invitation_validity_days', '7');

    return $days > 0 ? $days : 7;
}

function invitation_expires_at(): string
{
    return setting_value('invitation_expires_at', '');
}

function invitation_code_is_active(): bool
{
    $hash = setting_value('invitation_code_hash', '');
    $expiresAt = invitation_expires_at();

    return $hash !== '' && $expiresAt !== '' && $expiresAt >= now();
}

function invitation_code_is_valid(string $code): bool
{
    $code = trim($code);

    if ($code === '' || !invitation_code_is_active()) {
        return false;
    }

    return password_verify($code, setting_value('invitation_code_hash', ''));
}

function invitation_code_is_required(): bool
{
    return invitation_code_is_active();
}

function save_invitation_code(string $code, int $validityDays): void
{
    $validityDays = max(1, $validityDays);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $validityDays . ' days'));

    save_setting_value('invitation_code_display', $code);
    save_setting_value('invitation_code_hash', password_hash($code, PASSWORD_DEFAULT));
    save_setting_value('invitation_validity_days', (string)$validityDays);
    save_setting_value('invitation_expires_at', $expiresAt);
}

function disable_invitation_code(): void
{
    save_setting_value('invitation_code_display', '');
    save_setting_value('invitation_code_hash', '');
    save_setting_value('invitation_expires_at', '');
}

function site_name(): string
{
    return setting_value('site_name', APP_NAME);
}

function site_icon(): string
{
    return setting_value('site_icon', '');
}

function site_logo_path(): string
{
    return setting_value('site_logo_path', '');
}

function upload_storage_path(): string
{
    $path = trim(setting_value('upload_storage_path', ''));

    return rtrim($path !== '' ? $path : UPLOAD_PATH, '/\\');
}

function path_is_allowed_by_open_basedir(string $path): bool
{
    $openBasedir = trim((string)ini_get('open_basedir'));

    if ($openBasedir === '') {
        return true;
    }

    $path = rtrim(str_replace('\\', '/', $path), '/');

    foreach (explode(PATH_SEPARATOR, $openBasedir) as $allowedPath) {
        $allowedPath = trim($allowedPath);

        if ($allowedPath === '') {
            continue;
        }

        $allowedPath = rtrim(str_replace('\\', '/', $allowedPath), '/');

        if ($path === $allowedPath || str_starts_with($path . '/', $allowedPath . '/')) {
            return true;
        }
    }

    return false;
}

function upload_subdir_path(string $subdir): string
{
    $subdir = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $subdir), DIRECTORY_SEPARATOR);

    return upload_storage_path() . ($subdir !== '' ? DIRECTORY_SEPARATOR . $subdir : '');
}

function upload_logical_path(string $subdir, string $filename): string
{
    $subdir = trim(str_replace('\\', '/', $subdir), '/');

    return 'uploads/' . ($subdir !== '' ? $subdir . '/' : '') . $filename;
}

function upload_absolute_path_from_logical(string $path): string
{
    $normalized = str_replace('\\', '/', trim($path));

    if ($normalized === 'uploads') {
        return upload_storage_path();
    }

    if (!str_starts_with($normalized, 'uploads/')) {
        return '';
    }

    $relative = substr($normalized, strlen('uploads/'));
    $relative = str_replace('/', DIRECTORY_SEPARATOR, $relative);

    return upload_storage_path() . DIRECTORY_SEPARATOR . $relative;
}

function upload_realpath_from_logical(string $path): string|false
{
    $absolutePath = upload_absolute_path_from_logical($path);

    return $absolutePath !== '' && path_is_allowed_by_open_basedir($absolutePath) ? realpath($absolutePath) : false;
}

function upload_storage_realpath(): string|false
{
    $path = upload_storage_path();

    return path_is_allowed_by_open_basedir($path) ? realpath($path) : false;
}

function site_logo_url(): string
{
    $path = site_logo_path();

    if ($path === '') {
        return '';
    }

    $absolutePath = upload_absolute_path_from_logical($path);
    $version = is_file($absolutePath) ? (string)filemtime($absolutePath) : '0';

    return url('site_logo.php?v=' . rawurlencode($version));
}

function site_meta_title(): string
{
    return setting_value('site_meta_title', site_name());
}

function site_meta_description(): string
{
    return setting_value(
        'site_meta_description',
        'Plateforme de protection, soutien et coordination des enseignants.'
    );
}

function site_meta_keywords(): string
{
    return setting_value('site_meta_keywords', '');
}

function site_meta_author(): string
{
    return setting_value('site_meta_author', site_name());
}

function site_meta_robots(): string
{
    $value = setting_value('site_meta_robots', 'index,follow');

    return in_array($value, ['index,follow', 'index,nofollow', 'noindex,follow', 'noindex,nofollow'], true)
        ? $value
        : 'index,follow';
}

function site_canonical_base_url(): string
{
    $url = trim(setting_value('site_canonical_url', ''));

    return rtrim($url !== '' ? $url : BASE_URL, '/');
}

function site_og_image_url(): string
{
    $custom = trim(setting_value('site_og_image_url', ''));

    if ($custom !== '') {
        return $custom;
    }

    return site_logo_url();
}

function site_google_verification(): string
{
    return setting_value('google_site_verification', '');
}

function site_bing_verification(): string
{
    return setting_value('bing_site_verification', '');
}

function seo_current_url(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');

    if ($uri === '') {
        return site_canonical_base_url();
    }

    $path = parse_url($uri, PHP_URL_PATH);
    $query = parse_url($uri, PHP_URL_QUERY);
    $publicPrefix = parse_url(BASE_URL, PHP_URL_PATH);
    $publicPrefix = is_string($publicPrefix) ? rtrim($publicPrefix, '/') : '';

    if ($publicPrefix !== '' && is_string($path) && str_starts_with($path, $publicPrefix . '/')) {
        $path = substr($path, strlen($publicPrefix) + 1);
    } elseif ($publicPrefix !== '' && $path === $publicPrefix) {
        $path = '';
    } else {
        $path = ltrim((string)$path, '/');
    }

    $url = site_canonical_base_url() . ($path !== '' ? '/' . ltrim($path, '/') : '');

    return $query ? $url . '?' . $query : $url;
}

function seo_description(string $description): string
{
    $description = strip_tags($description);
    $description = preg_replace('/\[[^\]]+\]\(([^)]+)\)/u', '$1', $description) ?? $description;
    $description = preg_replace('/[`*_>#~]+/u', '', $description) ?? $description;
    $description = preg_replace('/\s+/u', ' ', $description) ?? $description;
    $description = trim($description);

    return mb_substr($description, 0, 300, 'UTF-8');
}

function render_seo_meta(array $overrides = []): void
{
    $title = (string)($overrides['title'] ?? site_meta_title());
    $description = seo_description((string)($overrides['description'] ?? site_meta_description()));
    $keywords = trim((string)($overrides['keywords'] ?? site_meta_keywords()));
    $author = trim((string)($overrides['author'] ?? site_meta_author()));
    $robots = (string)($overrides['robots'] ?? site_meta_robots());
    $canonical = trim((string)($overrides['canonical'] ?? seo_current_url()));
    $image = trim((string)($overrides['image'] ?? site_og_image_url()));
    $type = (string)($overrides['type'] ?? 'website');
    $siteName = site_name();

    echo '    <meta name="description" content="' . e($description) . '">' . PHP_EOL;

    if ($keywords !== '') {
        echo '    <meta name="keywords" content="' . e($keywords) . '">' . PHP_EOL;
    }

    if ($author !== '') {
        echo '    <meta name="author" content="' . e($author) . '">' . PHP_EOL;
    }

    echo '    <meta name="robots" content="' . e($robots) . '">' . PHP_EOL;
    echo '    <link rel="canonical" href="' . e($canonical) . '">' . PHP_EOL;
    echo '    <meta property="og:site_name" content="' . e($siteName) . '">' . PHP_EOL;
    echo '    <meta property="og:type" content="' . e($type) . '">' . PHP_EOL;
    echo '    <meta property="og:title" content="' . e($title) . '">' . PHP_EOL;
    echo '    <meta property="og:description" content="' . e($description) . '">' . PHP_EOL;
    echo '    <meta property="og:url" content="' . e($canonical) . '">' . PHP_EOL;

    if ($image !== '') {
        echo '    <meta property="og:image" content="' . e($image) . '">' . PHP_EOL;
        echo '    <meta name="twitter:image" content="' . e($image) . '">' . PHP_EOL;
    }

    echo '    <meta name="twitter:card" content="' . ($image !== '' ? 'summary_large_image' : 'summary') . '">' . PHP_EOL;
    echo '    <meta name="twitter:title" content="' . e($title) . '">' . PHP_EOL;
    echo '    <meta name="twitter:description" content="' . e($description) . '">' . PHP_EOL;

    if (site_google_verification() !== '') {
        echo '    <meta name="google-site-verification" content="' . e(site_google_verification()) . '">' . PHP_EOL;
    }

    if (site_bing_verification() !== '') {
        echo '    <meta name="msvalidate.01" content="' . e(site_bing_verification()) . '">' . PHP_EOL;
    }

    $schema = $overrides['schema'] ?? null;
    if (!is_array($schema)) {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $siteName,
            'url' => site_canonical_base_url(),
        ];

        if ($image !== '') {
            $schema['logo'] = $image;
        }
    }

    echo '    <script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . PHP_EOL;
}

function default_about_content(): string
{
    return <<<MARKDOWN
{site_name} est une plateforme de protection, soutien et coordination des enseignants de l’Éducation nationale.

Elle permet de documenter les faits d’établissement, de suivre les réponses institutionnelles, de référencer les textes et services utiles, de discuter en privé ou en groupe, et de publier des messages publics compatibles ActivityPub.

L’objectif est de ne pas perdre les informations importantes dans un flux de discussion, mais de les organiser, les retrouver et les utiliser pour des réponses collectives.
MARKDOWN;
}

function about_content(): string
{
    return setting_value('about_content', str_replace('{site_name}', site_name(), default_about_content()));
}

function default_footer_content(): string
{
    return <<<MARKDOWN
{site_name} — protection, soutien et coordination des enseignants.
MARKDOWN;
}

function footer_content(): string
{
    return setting_value('footer_content', str_replace('{site_name}', site_name(), default_footer_content()));
}

function federation_enabled(): bool
{
    return setting_value('federation_enabled', '0') === '1';
}

function federation_follow_url(): string
{
    return rtrim(trim(setting_value('federation_follow_url', '')), '/');
}

function federation_token(): string
{
    return trim(setting_value('federation_token', ''));
}

function federation_follow_slots(): array
{
    $slots = [
        [
            'index' => 1,
            'name' => trim(setting_value('federation_name', '')),
            'url' => federation_follow_url(),
            'token' => federation_token(),
        ],
    ];

    for ($index = 2; $index <= 4; $index++) {
        $slots[] = [
            'index' => $index,
            'name' => trim(setting_value('federation_name_' . $index, '')),
            'url' => rtrim(trim(setting_value('federation_follow_url_' . $index, '')), '/'),
            'token' => trim(setting_value('federation_token_' . $index, '')),
        ];
    }

    return $slots;
}

function federation_slot_label(array $slot): string
{
    $index = max(1, (int)($slot['index'] ?? 1));
    $name = trim((string)($slot['name'] ?? ''));

    return $name !== '' ? $name : 'Flux #' . $index;
}

function federation_publication_token(): string
{
    return trim(setting_value('federation_publication_token', ''));
}

function ical_enabled(): bool
{
    return setting_value('ical_enabled', '0') === '1';
}

function ical_token(): string
{
    return trim(setting_value('ical_token', ''));
}

function ical_token_is_valid(string $token): bool
{
    return preg_match('/^[A-Za-z0-9]{48}$/', $token) === 1;
}

function ical_feed_url(?string $token = null): string
{
    $token = trim($token ?? ical_token());

    if (!ical_token_is_valid($token)) {
        return '';
    }

    return url('events_ical.php?token=' . rawurlencode($token));
}

function default_usage_charter_content(): string
{
    return <<<MARKDOWN
# Charte d’utilisation

En utilisant {site_name}, vous vous engagez à respecter un usage professionnel, loyal et collectif du service.

## Conditions d’utilisation

- Publier uniquement des informations utiles à la protection, au soutien et à la coordination des enseignants.
- Respecter la confidentialité des échanges, des personnes et des situations.
- Ne pas diffuser de propos injurieux, diffamatoires, discriminatoires, menaçants ou contraires à la loi.
- Ne pas détourner le service à des fins de harcèlement, de pression, de spam ou de désinformation.

## Sanctions en cas d’abus

Tout abus peut entraîner la suppression de contenus, la suspension ou la désactivation du compte, ainsi que le signalement aux autorités compétentes si la situation l’exige.

L’accès au service implique l’acceptation de cette charte.
MARKDOWN;
}

function usage_charter_content(): string
{
    return setting_value('usage_charter_content', str_replace('{site_name}', site_name(), default_usage_charter_content()));
}
