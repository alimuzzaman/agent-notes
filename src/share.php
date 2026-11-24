<?php

declare(strict_types=1);

// Share links (FEATURE_SHARE_LINKS, needs FEATURE_VISIBILITY): an expiring, revocable read link to one
// tailnet note, opened on the public host by someone with no tailnet and no account.
//
// Flow: GET /s/<token> checks the link, sets a cookie holding the token (HttpOnly, host-only, Path=/n/<slug>)
// and redirects to the clean /n/<slug>. After that the cookie is checked again on every request, so
// revoking the link or deleting the note ends access at once. Only sha256(token) is stored.

const SHARE_COOKIE = 'an_share';

/** @return array{0: string, 1: string} [token, sha256 hash] — 256 random bits */
function new_share_token(): array
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    return [$token, hash('sha256', $token)];
}

/** @return array{0: ?int, 1: ?string} [hours, error] */
function share_ttl_hours(?int $requested): array
{
    $max = env_int('SHARE_LINK_TTL_MAX_HOURS', 720);
    $hours = $requested ?? min(env_int('SHARE_LINK_TTL_DEFAULT_HOURS', 168), $max);
    if ($hours < 1 || $hours > $max) {
        return [null, "ttl_hours must be between 1 and {$max}"];
    }
    return [$hours, null];
}

function share_cookie_options(string $slug, int $linkExpiresAt): array
{
    return share_cookie_path_options('/n/' . $slug, $linkExpiresAt);
}

function share_cookie_path_options(string $path, int $linkExpiresAt): array
{
    $secure = str_starts_with((string) public_url(), 'https://');
    return [
        'expires' => min($linkExpiresAt, time() + env_int('SHARE_COOKIE_TTL_HOURS', 12) * 3600),
        'path' => $path,
        'secure' => $secure,
        'httponly' => true,
        // None, not Lax: an HTML note runs with an opaque origin (CSP sandbox), so its own <img> requests
        // count as cross-site and a Lax cookie would not reach its assets. Exposure stays small: the
        // cookie is HttpOnly, only sent under this one note's path, and every response under that path
        // is read-only and unreadable cross-origin (no CORS headers anywhere in the app).
        'samesite' => $secure ? 'None' : 'Lax',
    ];
}

function share_url(string $token): string
{
    return (public_url() ?? app_url()) . '/s/' . $token;
}

function share_audit(?int $linkId, int $noteId, string $event, ?int $actorId = null): void
{
    db()->prepare('INSERT INTO share_link_events (share_link_id, note_id, event, actor_user_id, client) VALUES (?, ?, ?, ?, ?)')
        ->execute([$linkId, $noteId, $event, $actorId, substr(ua_label($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 60)]);
}

/** @return array{0: ?array, 1: ?string} [link with its one-time 'url', error] */
function create_share_link(int $userId, string $slug, ?int $ttlHours, string $via): array
{
    if (public_url() === null) {
        return [null, 'share links need PUBLIC_URL (the public host) to be configured'];
    }
    $note = get_note($slug, $userId);
    if (!$note) {
        return [null, "note '{$slug}' not found"];
    }
    if (note_visibility($note) === Visibility::Public) {
        return [null, 'this note is already public; share its url instead'];
    }
    [$hours, $error] = share_ttl_hours($ttlHours);
    if ($error) {
        return [null, $error];
    }
    [$token, $hash] = new_share_token();
    db()->prepare('INSERT INTO share_links (note_id, token_hash, created_by, created_via, expires_at) VALUES (?, ?, ?, ?, NOW() + INTERVAL ? HOUR)')
        ->execute([$note['id'], $hash, $userId, $via, $hours]);
    $id = (int) db()->lastInsertId();
    share_audit($id, (int) $note['id'], 'created', $userId);
    $link = share_links_for($userId, null, $id)[0];
    $link['url'] = share_url($token); // the only time the token leaves the server
    return [$link, null];
}

/** The Owner's links, newest first, optionally for one note or one link id. Never includes tokens. */
function share_links_for(int $userId, ?string $slug = null, ?int $id = null, bool $mockups = false): array
{
    $target = $mockups ? 'm.name, m.title, v.number AS version' : 'n.slug, n.title';
    $join = $mockups
        ? 'JOIN mockup_versions v ON v.id = l.mockup_version_id JOIN mockups m ON m.id = v.mockup_id WHERE m.user_id = ?'
        : 'JOIN notes n ON n.id = l.note_id WHERE n.user_id = ?';
    $sql = "SELECT l.id, {$target}, l.created_via, l.created_at, l.expires_at, l.revoked_at, l.last_used_at, l.use_count,
                   CASE WHEN l.revoked_at IS NOT NULL THEN 'revoked' WHEN l.expires_at <= NOW() THEN 'expired' ELSE 'active' END AS status
            FROM share_links l {$join}";
    $params = [$userId];
    if ($slug !== null) {
        $sql .= $mockups ? ' AND m.name = ?' : ' AND n.slug = ?';
        $params[] = $slug;
    }
    if ($id !== null) {
        $sql .= ' AND l.id = ?';
        $params[] = $id;
    }
    // Every active link, always: a link that still grants access must stay findable to revoke.
    // Only the history of revoked and expired ones is capped.
    $active = 'l.revoked_at IS NULL AND l.expires_at > NOW()';
    $rows = [];
    foreach (["AND {$active} ORDER BY l.id DESC", "AND NOT ({$active}) ORDER BY l.id DESC LIMIT 100"] as $tail) {
        $stmt = db()->prepare("{$sql} {$tail}");
        $stmt->execute($params);
        array_push($rows, ...$stmt->fetchAll());
    }
    return array_map(fn($r) => ['id' => (int) $r['id'], 'use_count' => (int) $r['use_count']] + $r, $rows);
}

function revoke_share_link(int $userId, int $id): bool
{
    $stmt = db()->prepare('UPDATE share_links l JOIN notes n ON n.id = l.note_id SET l.revoked_at = NOW()
                           WHERE l.id = ? AND n.user_id = ? AND l.revoked_at IS NULL');
    $stmt->execute([$id, $userId]);
    if ($stmt->rowCount() === 0) {
        return feature(Feature::NotesLibrary) && revoke_mockup_share_link($userId, $id);
    }
    $note = db()->prepare('SELECT note_id FROM share_links WHERE id = ?');
    $note->execute([$id]);
    share_audit($id, (int) $note->fetchColumn(), 'revoked', $userId);
    return true;
}

/** The live (unrevoked, unexpired) link a token belongs to, with its note's slug; null otherwise. */
function share_link_by_token(string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
        return null;
    }
    $hash = hash('sha256', $token);
    $mockups = feature(Feature::NotesLibrary);
    $target = $mockups ? ', l.mockup_version_id, m.id AS mockup_id, m.name, v.number' : '';
    $join = $mockups
        ? 'LEFT JOIN notes n ON n.id = l.note_id LEFT JOIN mockup_versions v ON v.id = l.mockup_version_id LEFT JOIN mockups m ON m.id = v.mockup_id'
        : 'JOIN notes n ON n.id = l.note_id';
    $stmt = db()->prepare("SELECT l.id, l.note_id, l.token_hash, UNIX_TIMESTAMP(l.expires_at) AS expires_ts, n.slug {$target}
                           FROM share_links l {$join}
                           WHERE l.token_hash = ? AND l.revoked_at IS NULL AND l.expires_at > NOW()");
    $stmt->execute([$hash]);
    $link = $stmt->fetch();
    return ($link && hash_equals($link['token_hash'], $hash)) ? $link : null;
}

/**
 * Token guesses share one per-IP budget per minute: every /s/ request, and every share cookie that
 * fails to check out. A valid cookie costs nothing, so a note with many images never trips it.
 */
function share_guess(bool $count): bool
{
    $key = 'share-ip:' . client_ip();
    $limit = env_int('SHARE_LINK_RATE_PER_MIN', 30);
    if ($count) {
        return rate_limited($key, $limit);
    }
    $stmt = db()->prepare('SELECT count FROM rate_limits WHERE bucket_key = ? AND window_start = ?');
    $stmt->execute([$key, date('Y-m-d H:i:s', intdiv(time(), 60) * 60)]);
    return (int) $stmt->fetchColumn() > $limit;
}

/** GET /s/<token> on the public host: swap the token for a cookie, then go to the clean note URL. */
function share_open(string $token): never
{
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    if (share_guess(count: true)) {
        header('Retry-After: 60');
        text_response('Too many requests. Try again in a minute.', status: 429);
    }
    $link = share_link_by_token($token);
    if (!$link) {
        public_not_found();
    }
    $stmt = db()->prepare('UPDATE share_links SET last_used_at = NOW(), use_count = use_count + 1
                           WHERE id = ? AND revoked_at IS NULL AND expires_at > NOW()');
    $stmt->execute([$link['id']]);
    if (!$stmt->rowCount()) {
        public_not_found();
    }
    if (isset($link['mockup_version_id'])) {
        mockup_share_audit((int) $link['id'], (int) $link['mockup_version_id'], 'opened');
        $path = mockup_url($link['name'], (int) $link['number']);
        setcookie(MOCKUP_SHARE_COOKIE, $token, share_cookie_path_options($path, (int) $link['expires_ts']));
    } else {
        share_audit((int) $link['id'], (int) $link['note_id'], 'opened');
        $path = '/n/' . $link['slug'];
        setcookie(SHARE_COOKIE, $token, share_cookie_options($link['slug'], (int) $link['expires_ts']));
    }
    header('Location: ' . $path, true, 303);
    exit;
}

/** Does this request carry a live share cookie for exactly $note? Checked on every request. */
function share_cookie_grants(array $note): ?array
{
    $token = $_COOKIE[SHARE_COOKIE] ?? '';
    if (!is_string($token) || $token === '' || share_guess(count: false)) {
        return null;
    }
    $link = share_link_by_token($token);
    if (isset($link['note_id']) && (int) $link['note_id'] === (int) $note['id']) {
        return $link;
    }
    share_guess(count: true);
    return null;
}

const MOCKUP_SHARE_COOKIE = 'an_mockup_share';

function mockup_share_audit(int $linkId, int $versionId, string $event, ?int $actorId = null): void
{
    db()->prepare('INSERT INTO share_link_events (share_link_id, mockup_version_id, event, actor_user_id, client)
                   VALUES ((SELECT id FROM share_links WHERE id = ?), ?, ?, ?, ?)')
        ->execute([$linkId, $versionId, $event, $actorId, substr(ua_label($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 60)]);
}

function create_mockup_share_link(int $userId, string $name, ?int $number, ?int $ttlHours): array
{
    if (public_url() === null) {
        return [null, 'share links need PUBLIC_URL (the public host) to be configured'];
    }
    [$hours, $error] = share_ttl_hours($ttlHours);
    if ($error) {
        return [null, $error];
    }
    [$token, $hash] = new_share_token();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $mockup = get_mockup($name, $userId, lock: true);
        $version = $mockup ? mockup_version($mockup, $number) : null;
        if (!$version) {
            $pdo->rollBack();
            return [null, 'Mockup version not found.'];
        }
        $pdo->prepare("INSERT INTO share_links (mockup_version_id, token_hash, created_by, created_via, expires_at)
                       VALUES (?, ?, ?, 'mcp', NOW() + INTERVAL ? HOUR)")
            ->execute([$version['id'], $hash, $userId, $hours]);
        $id = (int) $pdo->lastInsertId();
        mockup_share_audit($id, (int) $version['id'], 'created', $userId);
        $link = share_links_for($userId, null, $id, mockups: true)[0];
        $pdo->commit();
        return [$link + ['url' => share_url($token)], null];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function revoke_mockup_share_link(int $userId, int $id): bool
{
    $stmt = db()->prepare('UPDATE share_links l JOIN mockup_versions v ON v.id = l.mockup_version_id
                           JOIN mockups m ON m.id = v.mockup_id SET l.revoked_at = NOW()
                           WHERE l.id = ? AND m.user_id = ? AND l.revoked_at IS NULL');
    $stmt->execute([$id, $userId]);
    if (!$stmt->rowCount()) {
        return false;
    }
    $stmt = db()->prepare('SELECT mockup_version_id FROM share_links WHERE id = ?');
    $stmt->execute([$id]);
    $versionId = $stmt->fetchColumn();
    if ($versionId) {
        mockup_share_audit($id, (int) $versionId, 'revoked', $userId);
    }
    return true;
}

function mockup_share_grants(string $name, int $number): ?array
{
    $token = $_COOKIE[MOCKUP_SHARE_COOKIE] ?? '';
    if (!is_string($token) || $token === '' || share_guess(count: false)) {
        return null;
    }
    $link = share_link_by_token($token);
    if (isset($link['mockup_version_id']) && $link['name'] === $name && (int) $link['number'] === $number) {
        return $link;
    }
    share_guess(count: true);
    return null;
}
