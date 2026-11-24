<?php

declare(strict_types=1);

/** A note's URL: on the public host when the note is public (FEATURE_VISIBILITY + PUBLIC_URL), else on the app. */
function note_url(string $slug, ?string $visibility = null): string
{
    if ($visibility === Visibility::Public->value && feature(Feature::Visibility) && public_url() !== null) {
        return public_url() . '/n/' . $slug;
    }
    return app_url('/n/' . $slug);
}

/**
 * Columns every note read selects. The optional ones are read only when their feature is on, so an
 * install that never ran the migration keeps working with every flag off (tests/features_off.php).
 */
function note_columns(): string
{
    $cols = 'id, user_id, slug, title, filename, content, size_bytes, views, created_at, updated_at';
    if (feature(Feature::HtmlNotes)) {
        $cols .= ', format';
    }
    if (feature(Feature::Visibility)) {
        $cols .= ', visibility';
    }
    if (feature(Feature::NotesLibrary)) {
        $cols .= ', kind, collection, token_name';
    }
    return $cols;
}

/** The note's format; an html note reads as markdown (escaped) while FEATURE_HTML_NOTES is off. */
function note_format(array $note): NoteFormat
{
    return feature(Feature::HtmlNotes) && ($note['format'] ?? '') === NoteFormat::Html->value ? NoteFormat::Html : NoteFormat::Markdown;
}

function note_visibility(array $note): Visibility
{
    return Visibility::tryFrom((string) ($note['visibility'] ?? '')) ?? Visibility::Tailnet;
}

function slugify(string $title): string
{
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
    return substr($slug, 0, 60) ?: 'note';
}

/**
 * Slug length in base62 characters. SLUG_BYTES (16–32; a positive value below 16 is raised to 16,
 * i.e. 128 bits; 0, empty or not a number keeps the original length) sets the entropy in bytes, rounded up to whole characters (16 bytes: 22 characters, 131
 * bits). Unset, the original 8 characters (about 47 bits). Existing notes keep their slugs. Longer
 * slugs need migration 002, which also makes notes.slug case-sensitive (ascii_bin): under the old
 * case-insensitive collation a lookup matches any letter case, which would cut the entropy.
 */
function slug_length(): int
{
    $bytes = env_int('SLUG_BYTES', 0);
    return $bytes === 0 ? 8 : (int) ceil(min(max($bytes, 16), 32) * 8 / log(62, 2));
}

/** A random base62 slug from the CSPRNG. It never carries the note's title or any other text. */
function generate_slug(): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $slug = '';
    for ($i = 0, $n = slug_length(); $i < $n; $i++) {
        $slug .= $alphabet[random_int(0, 61)];
    }
    return $slug;
}

/** @return array{0: ?array, 1: ?string} [note, error] */
function create_note(int $userId, string $title, string $content, ?string $filename = null, ?NoteFormat $format = null, ?Visibility $visibility = null, array $metadata = []): array
{
    $title = trim($title);
    if ($title === '') {
        return [null, 'title must not be empty'];
    }
    $size = strlen($content);
    $max = (int) env('NOTE_MAX_BYTES', '1048576');
    if ($size > $max) {
        return [null, "content is {$size} bytes; the maximum is {$max} bytes (1 MB)"];
    }
    $fname = ($filename !== null && trim($filename) !== '') ? slugify($filename) : slugify($title);
    $cols = 'user_id, slug, title, filename, content, size_bytes';
    $extra = [];
    if (feature(Feature::HtmlNotes)) {
        $cols .= ', format';
        $extra[] = ($format ?? NoteFormat::Markdown)->value;
    }
    if (feature(Feature::Visibility)) {
        $cols .= ', visibility';
        $extra[] = ($visibility ?? Visibility::Tailnet)->value;
    }
    if (feature(Feature::NotesLibrary)) {
        $cols .= ', collection, token_name';
        array_push($extra, $metadata['collection'] ?? null, $metadata['token_name'] ?? null);
    }
    $stmt = db()->prepare("INSERT INTO notes ({$cols}) VALUES (" . rtrim(str_repeat('?, ', 6 + count($extra)), ', ') . ')');
    // ponytail: retry loop is the cheap fix for the smaller 8-char space; 62^8 ≈ 2e14 keeps collisions near-never.
    for ($try = 0; ; $try++) {
        $slug = generate_slug();
        try {
            $stmt->execute([$userId, $slug, substr($title, 0, 255), $fname, $content, $size, ...$extra]);
            break;
        } catch (PDOException $e) {
            if ($try < 5 && str_contains($e->getMessage(), 'slug')) {
                continue; // duplicate slug — try another
            }
            throw $e;
        }
    }
    return [get_note($slug, $userId), null];
}

function get_note(string $slug, ?int $userId = null, array $filters = []): ?array
{
    // ponytail: explicit columns so the pdf_cache blob never rides along on page views
    $sql = 'SELECT ' . note_columns() . ' FROM notes WHERE slug = ?';
    $params = [$slug];
    if ($userId !== null) {
        $sql .= ' AND user_id = ?';
        $params[] = $userId;
    }
    if (feature(Feature::NotesLibrary)) {
        foreach (['kind', 'collection'] as $key) {
            if (array_key_exists($key, $filters)) {
                $sql .= " AND {$key} <=> ?";
                $params[] = $filters[$key];
            }
        }
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

/** @return array{0: ?array, 1: ?string} [note, error] */
function update_note(int $userId, string $slug, ?string $title, ?string $content, ?string $filename = null, ?NoteFormat $format = null, ?Visibility $visibility = null, array $metadata = []): array
{
    $note = get_note($slug, $userId);
    if (!$note) {
        return [null, "note '{$slug}' not found"];
    }
    $format = feature(Feature::HtmlNotes) ? $format : null;
    $visibility = feature(Feature::Visibility) ? $visibility : null;
    $fields = [];
    if (feature(Feature::NotesLibrary) && array_key_exists('collection', $metadata)) {
        $fields['collection'] = $metadata['collection'];
    }
    if ($title === null && $content === null && $filename === null && $format === null && $visibility === null && !$fields) {
        return [null, 'provide title, content, and/or filename to update'];
    }
    if ($content !== null) {
        $size = strlen($content);
        $max = (int) env('NOTE_MAX_BYTES', '1048576');
        if ($size > $max) {
            return [null, "content is {$size} bytes; the maximum is {$max} bytes (1 MB)"];
        }
        $fields += ['content' => $content, 'size_bytes' => $size];
    }
    if ($title !== null && trim($title) !== '') {
        $fields['title'] = substr(trim($title), 0, 255);
    }
    if ($filename !== null && trim($filename) !== '') {
        $fields['filename'] = slugify($filename);
    }
    if ($format !== null) {
        $fields['format'] = $format->value;
    }
    if ($visibility !== null) {
        $fields['visibility'] = $visibility->value;
    }
    if ($fields) {
        if (feature(Feature::NotesLibrary) && isset($metadata['token_name'])) {
            $fields['token_name'] = $metadata['token_name'];
        }
        $set = implode(', ', array_map(fn($column) => $column . ' = ?', array_keys($fields)));
        db()->prepare("UPDATE notes SET {$set}, pdf_cache = NULL WHERE id = ?")->execute([...array_values($fields), $note['id']]);
    }
    $updated = get_note($slug, $userId);
    return $updated ? [$updated, null] : [null, "note '{$slug}' not found"];
}

function list_notes(int $userId, int $limit = 50, int $offset = 0): array
{
    $limit = max(1, min($limit, 200));
    $offset = max(0, $offset);
    $extra = (feature(Feature::HtmlNotes) ? ', format' : '') . (feature(Feature::Visibility) ? ', visibility' : '');
    $stmt = db()->prepare(
        "SELECT slug, title, filename, size_bytes, views, created_at, updated_at{$extra} FROM notes
         WHERE user_id = ? ORDER BY updated_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function delete_note(int $userId, string $slug, array $filters = []): bool
{
    $where = 'slug = ? AND user_id = ?';
    $params = [$slug, $userId];
    foreach (['kind', 'collection'] as $key) {
        if (array_key_exists($key, $filters)) {
            $where .= " AND {$key} <=> ?";
            $params[] = $filters[$key];
        }
    }
    return delete_notes($where, $params) > 0;
}

/**
 * Every note deletion goes through here, so a note's asset directory always goes with it. Share links
 * cascade in the database. The rows are locked and deleted in one transaction, the same row lock
 * store_asset() takes, so no upload can land after the purge. The files go after the commit: if
 * removing them fails, they are unreachable (no note, no route) rather than a note pointing at
 * missing files.
 */
function delete_notes(string $where, array $params): int
{
    return count(delete_locked($where, $params));
}

/** Deletes a user, their notes and mockups, and any files no longer in use. */
function delete_user(int $userId): void
{
    $mockupStorage = feature(Feature::NotesLibrary)
        || (is_dir(asset_root() . '/mockups') && db()->query("SHOW TABLES LIKE 'mockups'")->fetchColumn());
    if (!$mockupStorage) {
        delete_locked('user_id = ?', [$userId], $userId);
        return;
    }
    $lock = lock_mockup_blobs();
    $cleanup = [];
    try {
        $cleanup = mockup_blob_hashes($userId, byOwner: true);
        delete_locked('user_id = ?', [$userId], $userId);
    } finally {
        release_mockup_blobs($lock, $cleanup);
    }
}

/**
 * With $userId, the user row is locked first, which blocks new notes for them (the notes foreign-key
 * check waits on it), and deleted with their notes.
 * @return list<int> the deleted note ids
 */
function delete_locked(string $where, array $params, ?int $userId = null): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($userId !== null) {
            $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
        }
        $stmt = $pdo->prepare("SELECT id FROM notes WHERE {$where} FOR UPDATE");
        $stmt->execute($params);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($ids) {
            $pdo->exec('DELETE FROM notes WHERE id IN (' . implode(',', $ids) . ')');
        }
        if ($userId !== null) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    foreach ($ids as $id) {
        purge_note_assets($id);
    }
    return $ids;
}
