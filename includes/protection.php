<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

const PROTECTION_EVENT_TYPES = [
    'deadline' => 'Échéance',
    'meeting' => 'Réunion / Instance',
    'union' => 'Action syndicale',
    'incident' => 'Manifestation',
    'institution' => 'Institution',
];
    
const PROTECTION_INCIDENT_TYPES = [
    'agression' => 'Agression',
    'accident' => 'Accident',
    'menace' => 'Menace / Intimidation',
    'pression' => 'Pression administrative',
    'harcelement' => 'Harcèlement',
    'autre' => 'Autre',
];

const PROTECTION_INCIDENT_SOURCES = [
    'eleve' => 'Élève',
    'parent' => 'Parent',
    'etablissement' => 'Établissement',
    'rectorat' => 'Rectorat',
    'autre' => 'Autre',
];

const PROTECTION_SEVERITIES = [
    'low' => 'Faible',
    'medium' => 'Significatif',
    'high' => 'Grave',
    'critical' => 'Critique',
];

const PROTECTION_STATUSES = [
    'open' => 'À traiter',
    'reported' => 'Signalé',
    'answered' => 'Réponse reçue',
    'closed' => 'Clos',
];

const PROTECTION_RESOURCE_TYPES = [
    'law' => 'Texte officiel',
    'service' => 'Service EN',
    'procedure' => 'Procédure',
    'template' => 'Modèle',
    'contact' => 'Contact utile',
];

const PROTECTION_ACTION_CATEGORIES = [
    'administrative' => 'Voie hiérarchique et administrative',
    'union' => 'Mobilisation syndicale et collective',
    'media' => 'Pression médiatique',
    'strike' => 'Grève et débrayage',
    'work_to_rule' => 'Zèle administratif',
    'boycott' => 'Boycott de réforme',
    'civil_disobedience' => 'Désobéissance civile à évaluer',
    'service_disruption' => 'Rupture de service à risque',
    'symbolic' => 'Action symbolique encadrée',
];

const PROTECTION_ACTION_STATUSES = [
    'idea' => 'Idée',
    'draft' => 'Préparation',
    'validated' => 'Validée',
    'active' => 'En cours',
    'done' => 'Terminée',
    'abandoned' => 'Abandonnée',
];

function protection_ensure_schema(): void
{
    if (!db_column_exists('users', 'discipline')) {
        db_query("ALTER TABLE users ADD COLUMN discipline TEXT");
    }

    if (!db_column_exists('users', 'admin_visible_password')) {
        db_query("ALTER TABLE users ADD COLUMN admin_visible_password TEXT");
    }

    foreach ([
        'protection_events',
        'protection_incidents',
        'protection_resources',
        'protection_union_boards',
        'protection_action_plans',
    ] as $table) {
        if (db_fetch_one(db_is_mysql()
            ? "SHOW TABLES LIKE '$table'"
            : "SELECT name FROM sqlite_master WHERE type='table' AND name='$table'"
        ) && !db_column_exists($table, 'is_active')) {
            db_query("ALTER TABLE $table ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1");
        }
    }

    if (db_fetch_one(db_is_mysql()
        ? "SHOW TABLES LIKE 'protection_resources'"
        : "SELECT name FROM sqlite_master WHERE type='table' AND name='protection_resources'"
    ) && !db_column_exists('protection_resources', 'attachment_id')) {
        db_query("ALTER TABLE protection_resources ADD COLUMN attachment_id INTEGER");
    }

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS protection_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            event_type TEXT NOT NULL DEFAULT 'incident',
            starts_at TEXT NOT NULL,
            ends_at TEXT,
            location TEXT,
            description TEXT,
            response_owner TEXT,
            response_summary TEXT,
            visibility TEXT NOT NULL DEFAULT 'members',
            group_id INTEGER,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
        )"
    );

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS protection_incidents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            occurred_at TEXT NOT NULL,
            incident_type TEXT NOT NULL DEFAULT 'autre',
            source_type TEXT NOT NULL DEFAULT 'autre',
            severity TEXT NOT NULL DEFAULT 'medium',
            status TEXT NOT NULL DEFAULT 'open',
            location TEXT,
            description TEXT NOT NULL,
            immediate_actions TEXT,
            institutional_response TEXT,
            institutional_response_at TEXT,
            follow_up TEXT,
            visibility TEXT NOT NULL DEFAULT 'members',
            group_id INTEGER,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
        )"
    );

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS protection_resources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            resource_type TEXT NOT NULL DEFAULT 'law',
            organization TEXT,
            url TEXT,
            attachment_id INTEGER,
            contact_info TEXT,
            description TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            FOREIGN KEY(attachment_id) REFERENCES attachments(id) ON DELETE SET NULL,
            FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
        )"
    );

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS protection_union_boards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            union_name TEXT NOT NULL,
            local_section TEXT,
            contact_name TEXT,
            email TEXT,
            phone TEXT,
            office_hours TEXT,
            location TEXT,
            announcement TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
        )"
    );

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS protection_union_board_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            board_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(board_id, user_id),
            FOREIGN KEY(board_id) REFERENCES protection_union_boards(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )"
    );

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS protection_action_plans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT 'administrative',
            status TEXT NOT NULL DEFAULT 'idea',
            objective TEXT NOT NULL,
            steps TEXT,
            legal_frame TEXT,
            risks TEXT,
            owner TEXT,
            planned_at TEXT,
            visibility TEXT NOT NULL DEFAULT 'members',
            group_id INTEGER,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
        )"
    );

    ensure_content_group_columns([
        'protection_events',
        'protection_incidents',
        'protection_action_plans',
    ]);
    db_query("CREATE INDEX IF NOT EXISTS idx_protection_events_starts_at ON protection_events(starts_at)");
    db_query("CREATE INDEX IF NOT EXISTS idx_protection_incidents_occurred_at ON protection_incidents(occurred_at)");
    db_query("CREATE INDEX IF NOT EXISTS idx_protection_incidents_status ON protection_incidents(status)");
    db_query("CREATE INDEX IF NOT EXISTS idx_protection_resources_type ON protection_resources(resource_type)");
    db_query("CREATE INDEX IF NOT EXISTS idx_protection_action_plans_status ON protection_action_plans(status)");
}

function protection_label(array $labels, string $key): string
{
    return $labels[$key] ?? $key;
}

function protection_user_can_manage(array $record, array $user): bool
{
    return ($user['role'] ?? '') === 'admin' || (int)($record['created_by'] ?? 0) === (int)($user['id'] ?? 0);
}

function protection_visibility_where(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return $prefix . "visibility IN ('public', 'members')";
}

function protection_fetch_stats(): array
{
    return [
        'events' => (int)(db_fetch_one("SELECT COUNT(*) AS total FROM protection_events WHERE is_active = 1")['total'] ?? 0),
        'incidents' => (int)(db_fetch_one("SELECT COUNT(*) AS total FROM protection_incidents WHERE is_active = 1")['total'] ?? 0),
        'open_incidents' => (int)(db_fetch_one("SELECT COUNT(*) AS total FROM protection_incidents WHERE is_active = 1 AND status IN ('open', 'reported')")['total'] ?? 0),
        'resources' => (int)(db_fetch_one("SELECT COUNT(*) AS total FROM protection_resources WHERE is_active = 1")['total'] ?? 0),
        'unions' => (int)(db_fetch_one("SELECT COUNT(*) AS total FROM protection_union_boards WHERE is_active = 1")['total'] ?? 0),
        'actions' => (int)(db_fetch_one("SELECT COUNT(*) AS total FROM protection_action_plans WHERE is_active = 1 AND status NOT IN ('done', 'abandoned')")['total'] ?? 0),
    ];
}
    