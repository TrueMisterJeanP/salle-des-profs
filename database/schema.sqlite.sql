PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    display_name TEXT,
    discipline TEXT,
    bio TEXT,
    avatar TEXT,
    admin_visible_password TEXT,
    role TEXT NOT NULL DEFAULT 'user',
    is_active INTEGER NOT NULL DEFAULT 1,
    charter_accepted_at TEXT,
    email_verified_at TEXT,
    email_activation_token_hash TEXT,
    email_activation_expires_at TEXT,
    password_setup_token_hash TEXT,
    password_setup_expires_at TEXT,
    used_storage_bytes INTEGER NOT NULL DEFAULT 0,
    quota_storage_bytes INTEGER,
    last_seen_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

CREATE TABLE IF NOT EXISTS auth_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    action TEXT NOT NULL,
    identifier_hash TEXT NOT NULL,
    identifier_value TEXT,
    ip_hash TEXT NOT NULL,
    ip_address TEXT,
    password_fingerprint TEXT,
    success INTEGER NOT NULL DEFAULT 0,
    rate_limit_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_auth_attempts_lookup
    ON auth_attempts(action, identifier_hash, ip_hash, success, created_at);

CREATE INDEX IF NOT EXISTS idx_auth_attempts_created_at
    ON auth_attempts(created_at);

CREATE TABLE IF NOT EXISTS contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    contact_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'accepted',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, contact_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(contact_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT,
    created_by INTEGER NOT NULL,
    visibility TEXT NOT NULL DEFAULT 'private',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS group_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role TEXT NOT NULL DEFAULT 'member',
    joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(group_id, user_id),
    FOREIGN KEY(group_id) REFERENCES groups(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sender_id INTEGER NOT NULL,
    receiver_id INTEGER,
    group_id INTEGER,
    content TEXT,
    attachment_id INTEGER,
    message_type TEXT NOT NULL DEFAULT 'text',
    is_read INTEGER NOT NULL DEFAULT 0,
    is_deleted INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(group_id) REFERENCES groups(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    filename TEXT NOT NULL,
    original_name TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    size INTEGER NOT NULL,
    path TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS storage_quota (
    id INTEGER PRIMARY KEY,
    used_bytes INTEGER NOT NULL DEFAULT 0,
    quota_bytes INTEGER
);

CREATE TABLE IF NOT EXISTS article_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    attachment_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(article_id, attachment_id),
    FOREIGN KEY(article_id) REFERENCES articles(id) ON DELETE CASCADE,
    FOREIGN KEY(attachment_id) REFERENCES attachments(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    visibility TEXT NOT NULL DEFAULT 'members',
    group_id INTEGER,
    source_message_id INTEGER,
    is_published INTEGER NOT NULL DEFAULT 1,
    pinned_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY(author_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(source_message_id) REFERENCES messages(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS post_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL,
    attachment_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(post_id, attachment_id),
    FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY(attachment_id) REFERENCES attachments(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS post_polls (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL UNIQUE,
    question TEXT NOT NULL,
    closes_at TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS post_poll_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL,
    option_text TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(poll_id) REFERENCES post_polls(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS post_poll_votes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL,
    option_id INTEGER NOT NULL,
    user_id INTEGER,
    visitor_key TEXT,
    visitor_fingerprint TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(poll_id, user_id),
    UNIQUE(poll_id, visitor_key),
    UNIQUE(poll_id, visitor_fingerprint),
    FOREIGN KEY(poll_id) REFERENCES post_polls(id) ON DELETE CASCADE,
    FOREIGN KEY(option_id) REFERENCES post_poll_options(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    content TEXT NOT NULL,
    excerpt TEXT,
    visibility TEXT NOT NULL DEFAULT 'members',
    group_id INTEGER,
    status TEXT NOT NULL DEFAULT 'draft',
    source_message_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    published_at TEXT,
    FOREIGN KEY(author_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(source_message_id) REFERENCES messages(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    target_type TEXT NOT NULL,
    target_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    is_deleted INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS content_tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tag_id INTEGER NOT NULL,
    target_type TEXT NOT NULL,
    target_id INTEGER NOT NULL,
    UNIQUE(tag_id, target_type, target_id),
    FOREIGN KEY(tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    content TEXT NOT NULL,
    link TEXT,
    is_read INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS web_notification_preferences (
    user_id INTEGER PRIMARY KEY,
    messenger_enabled INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS contact_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    subject TEXT NOT NULL,
    message TEXT NOT NULL,
    ip_address TEXT,
    user_agent TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    replied_at TEXT,
    replied_by INTEGER,
    reply_subject TEXT,
    reply_message TEXT,
    FOREIGN KEY(replied_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS visitor_activity (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    visitor_hash TEXT,
    method TEXT NOT NULL,
    path TEXT NOT NULL,
    query_string TEXT,
    full_url TEXT,
    referer TEXT,
    user_agent TEXT,
    ip_address TEXT,
    http_status INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key TEXT NOT NULL UNIQUE,
    setting_value TEXT
);

CREATE TABLE IF NOT EXISTS migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL UNIQUE,
    executed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS federated_feed_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_index INTEGER NOT NULL,
    source_name TEXT NOT NULL,
    source_url TEXT,
    item_type TEXT,
    type_label TEXT,
    title TEXT NOT NULL,
    item_date TEXT NOT NULL,
    badge TEXT,
    summary TEXT,
    location TEXT,
    meta TEXT,
    item_url TEXT,
    fetched_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS mastodon_publications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    target_type TEXT NOT NULL,
    target_id INTEGER NOT NULL,
    mastodon_url TEXT,
    mastodon_id TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, target_type, target_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS activitypub_actors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL UNIQUE,
    actor_url TEXT NOT NULL UNIQUE,
    inbox_url TEXT NOT NULL,
    outbox_url TEXT NOT NULL,
    followers_url TEXT NOT NULL,
    public_key TEXT NOT NULL,
    private_key TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS activitypub_remote_actors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_url TEXT NOT NULL UNIQUE,
    inbox_url TEXT NOT NULL,
    shared_inbox_url TEXT,
    followers_url TEXT,
    preferred_username TEXT,
    public_key_id TEXT,
    public_key_pem TEXT NOT NULL,
    raw_json TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

CREATE TABLE IF NOT EXISTS activitypub_followers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    remote_actor_id INTEGER NOT NULL,
    follow_id TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'accepted',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(user_id, remote_actor_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(remote_actor_id) REFERENCES activitypub_remote_actors(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS activitypub_inbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    remote_actor_id INTEGER,
    activity_id TEXT NOT NULL UNIQUE,
    activity_type TEXT NOT NULL,
    raw_json TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(remote_actor_id) REFERENCES activitypub_remote_actors(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS activitypub_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    remote_actor_id INTEGER NOT NULL,
    activity_id TEXT NOT NULL,
    inbox_url TEXT NOT NULL,
    activity_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    last_status_code INTEGER,
    last_error TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(remote_actor_id) REFERENCES activitypub_remote_actors(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS activitypub_blocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    remote_actor_id INTEGER NOT NULL,
    block_id TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, remote_actor_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(remote_actor_id) REFERENCES activitypub_remote_actors(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS protection_events (
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
);

CREATE TABLE IF NOT EXISTS protection_incidents (
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
);

CREATE TABLE IF NOT EXISTS protection_resources (
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
);

CREATE TABLE IF NOT EXISTS protection_union_boards (
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
);

CREATE TABLE IF NOT EXISTS protection_union_board_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    board_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(board_id, user_id),
    FOREIGN KEY(board_id) REFERENCES protection_union_boards(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS protection_action_plans (
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
);

CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);

CREATE INDEX IF NOT EXISTS idx_contacts_user_id ON contacts(user_id);
CREATE INDEX IF NOT EXISTS idx_contacts_contact_id ON contacts(contact_id);

CREATE INDEX IF NOT EXISTS idx_group_members_group_id ON group_members(group_id);
CREATE INDEX IF NOT EXISTS idx_group_members_user_id ON group_members(user_id);

CREATE INDEX IF NOT EXISTS idx_messages_sender_id ON messages(sender_id);
CREATE INDEX IF NOT EXISTS idx_messages_receiver_id ON messages(receiver_id);
CREATE INDEX IF NOT EXISTS idx_messages_group_id ON messages(group_id);
CREATE INDEX IF NOT EXISTS idx_messages_created_at ON messages(created_at);
CREATE INDEX IF NOT EXISTS idx_messages_group_active_created
ON messages(group_id, is_deleted, created_at);
CREATE INDEX IF NOT EXISTS idx_messages_private_sender_receiver_active_created
ON messages(sender_id, receiver_id, group_id, is_deleted, created_at);
CREATE INDEX IF NOT EXISTS idx_messages_private_receiver_sender_unread
ON messages(receiver_id, sender_id, group_id, is_read, is_deleted);

CREATE INDEX IF NOT EXISTS idx_posts_author_id ON posts(author_id);
CREATE INDEX IF NOT EXISTS idx_posts_visibility ON posts(visibility);
CREATE INDEX IF NOT EXISTS idx_posts_created_at ON posts(created_at);
CREATE INDEX IF NOT EXISTS idx_posts_published_visibility_created
ON posts(is_published, visibility, created_at);
CREATE INDEX IF NOT EXISTS idx_posts_group_published_created
ON posts(group_id, is_published, created_at);
CREATE INDEX IF NOT EXISTS idx_post_attachments_post
ON post_attachments(post_id);
CREATE INDEX IF NOT EXISTS idx_post_attachments_attachment
ON post_attachments(attachment_id);
CREATE INDEX IF NOT EXISTS idx_post_poll_options_poll_id ON post_poll_options(poll_id);
CREATE INDEX IF NOT EXISTS idx_post_poll_votes_poll_id ON post_poll_votes(poll_id);
CREATE INDEX IF NOT EXISTS idx_post_poll_votes_option_id ON post_poll_votes(option_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_post_poll_votes_fingerprint ON post_poll_votes(poll_id, visitor_fingerprint);

CREATE INDEX IF NOT EXISTS idx_articles_author_id ON articles(author_id);
CREATE INDEX IF NOT EXISTS idx_articles_slug ON articles(slug);
CREATE INDEX IF NOT EXISTS idx_articles_status ON articles(status);
CREATE INDEX IF NOT EXISTS idx_articles_status_visibility_published
ON articles(status, visibility, published_at, created_at);

CREATE INDEX IF NOT EXISTS idx_comments_target ON comments(target_type, target_id);

CREATE INDEX IF NOT EXISTS idx_contact_messages_created_at ON contact_messages(created_at);
CREATE INDEX IF NOT EXISTS idx_contact_messages_replied_at ON contact_messages(replied_at);

CREATE INDEX IF NOT EXISTS idx_article_attachments_article
ON article_attachments(article_id);

CREATE INDEX IF NOT EXISTS idx_article_attachments_attachment
ON article_attachments(attachment_id);

CREATE INDEX IF NOT EXISTS idx_content_tags_target
ON content_tags(target_type, target_id);

CREATE INDEX IF NOT EXISTS idx_content_tags_tag_id
ON content_tags(tag_id);

CREATE INDEX IF NOT EXISTS idx_notifications_user_id ON notifications(user_id);
CREATE INDEX IF NOT EXISTS idx_notifications_is_read ON notifications(is_read);
CREATE INDEX IF NOT EXISTS idx_notifications_user_unread
ON notifications(user_id, is_read);

CREATE INDEX IF NOT EXISTS idx_visitor_activity_created_at
ON visitor_activity(created_at);

CREATE INDEX IF NOT EXISTS idx_visitor_activity_user_id
ON visitor_activity(user_id);

CREATE INDEX IF NOT EXISTS idx_federated_feed_items_date
ON federated_feed_items(item_date);

CREATE INDEX IF NOT EXISTS idx_federated_feed_items_source
ON federated_feed_items(source_index);

CREATE INDEX IF NOT EXISTS idx_mastodon_publications_user_id
ON mastodon_publications(user_id);

CREATE INDEX IF NOT EXISTS idx_mastodon_publications_target
ON mastodon_publications(target_type, target_id);

CREATE INDEX IF NOT EXISTS idx_activitypub_actors_user_id
ON activitypub_actors(user_id);

CREATE INDEX IF NOT EXISTS idx_activitypub_followers_user_id
ON activitypub_followers(user_id);

CREATE INDEX IF NOT EXISTS idx_activitypub_deliveries_status
ON activitypub_deliveries(status);

CREATE INDEX IF NOT EXISTS idx_protection_events_starts_at
ON protection_events(starts_at);

CREATE INDEX IF NOT EXISTS idx_protection_incidents_occurred_at
ON protection_incidents(occurred_at);

CREATE INDEX IF NOT EXISTS idx_protection_incidents_status
ON protection_incidents(status);

CREATE INDEX IF NOT EXISTS idx_protection_resources_type
ON protection_resources(resource_type);

CREATE INDEX IF NOT EXISTS idx_protection_action_plans_status
ON protection_action_plans(status);

INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES
('site_name', 'Salle des profs'),
('site_meta_title', 'Salle des profs'),
('site_meta_description', 'Plateforme de protection, soutien et coordination des enseignants.'),
('site_meta_keywords', ''),
('site_meta_author', 'Salle des profs'),
('site_meta_robots', 'index,follow'),
('site_canonical_url', ''),
('site_og_image_url', ''),
('google_site_verification', ''),
('bing_site_verification', ''),
('registration_enabled', '0'),
('default_visibility', 'members'),
('default_user_storage_quota_bytes', ''),
('federation_enabled', '0'),
('federation_name', ''),
('federation_follow_url', ''),
('federation_token', ''),
('federation_name_2', ''),
('federation_follow_url_2', ''),
('federation_token_2', ''),
('federation_name_3', ''),
('federation_follow_url_3', ''),
('federation_token_3', ''),
('federation_name_4', ''),
('federation_follow_url_4', ''),
('federation_token_4', ''),
('federation_publication_token', ''),
('federation_cache_last_refresh_at', ''),
('federation_cache_last_error', ''),
('ical_enabled', '0'),
('ical_token', ''),
('storage_quota_counters_initialized', '1'),
('storage_quota_innodb_checked', '1'),
('invitation_code_display', ''),
('invitation_code_hash', ''),
('invitation_validity_days', '7'),
('invitation_expires_at', '');
