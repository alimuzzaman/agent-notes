<?php

declare(strict_types=1);

const MOCKUP_FILE_BYTES = 5 * 1024 * 1024;
const MOCKUP_VERSION_BYTES = 25 * 1024 * 1024;
const MOCKUP_FILE_LIMIT = 1000;

function valid_mockup_path(string $path): bool
{
    if ($path === '' || strlen($path) > 512 || preg_match('~[\x00-\x1f\x7f\\\\%?#:]~', $path) || !preg_match('//u', $path)) {
        return false;
    }
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '@') || strlen($part) > 255) {
            return false;
        }
    }
    return true;
}

function mockup_mime(string $path): string
{
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'html', 'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js', 'mjs' => 'text/javascript; charset=utf-8',
        'json', 'map' => 'application/json',
        'txt' => 'text/plain; charset=utf-8',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        default => 'application/octet-stream',
    };
}

function mockup_blob_path(string $hash): string
{
    return asset_root() . '/mockups/' . substr($hash, 0, 2) . '/' . $hash;
}

function write_mockup_blob(string $hash, string $bytes): void
{
    $path = mockup_blob_path($hash);
    if (is_file($path)) {
        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create mockup asset directory');
    }
    $temp = tempnam($dir, 'upload-');
    if ($temp === false) {
        throw new RuntimeException('Could not allocate mockup asset file');
    }
    try {
        if (file_put_contents($temp, $bytes) !== strlen($bytes) || !rename($temp, $path)) {
            throw new RuntimeException('Could not store mockup asset');
        }
    } finally {
        if (is_file($temp)) {
            unlink($temp);
        }
    }
}

// Hold the same lock while adding references and removing unused blobs.
function lock_mockup_blobs(): string
{
    $name = 'notes-mockup-blobs:' . md5(asset_root());
    $stmt = db()->prepare('SELECT GET_LOCK(?, 10)');
    $stmt->execute([$name]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new DomainException('The asset store is busy. Retry the upload.');
    }
    return $name;
}

function release_mockup_blobs(string $lock, array $hashes): void
{
    try {
        if ($hashes) {
            $stmt = db()->prepare('SELECT EXISTS (SELECT 1 FROM mockup_files WHERE content_hash = ?)
                                          OR EXISTS (SELECT 1 FROM mockup_draft_files WHERE content_hash = ?)');
            foreach (array_unique($hashes) as $hash) {
                $stmt->execute([$hash, $hash]);
                $path = mockup_blob_path($hash);
                if (!(int) $stmt->fetchColumn() && is_file($path) && !@unlink($path)) {
                    error_log('Could not remove an unused mockup asset.');
                }
            }
        }
    } finally {
        db()->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
}

function get_mockup(string $name, ?int $userId = null, bool $lock = false): ?array
{
    $sql = 'SELECT * FROM mockups WHERE name = ?';
    $params = [$name];
    if ($userId !== null) {
        $sql .= ' AND user_id = ?';
        $params[] = $userId;
    }
    $stmt = db()->prepare($sql . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function create_mockup(int $userId, string $name, string $title): array
{
    try {
        db()->prepare('INSERT INTO mockups (user_id, name, title) VALUES (?, ?, ?)')
            ->execute([$userId, $name, $title]);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
            throw $e;
        }
        throw new DomainException('That mockup name is already in use.');
    }
    return get_mockup($name, $userId);
}

function list_mockups(int $userId, string $query, string $prefix, int $limit, int $offset, bool $publishedOnly = false): array
{
    $stmt = db()->prepare(
        'SELECT m.*, v.token_name FROM mockups m
         LEFT JOIN mockup_versions v ON v.mockup_id = m.id AND v.number = m.current_version
         WHERE m.user_id = ? AND (LOCATE(?, m.title) > 0 OR LOCATE(?, m.name) > 0)
         AND LEFT(m.name, CHAR_LENGTH(?)) = ?' . ($publishedOnly ? ' AND m.current_version IS NOT NULL' : '') . '
         ORDER BY m.updated_at DESC, m.id DESC LIMIT ? OFFSET ?'
    );
    foreach ([$userId, $query, $query, $prefix, $prefix, $limit, $offset] as $i => $value) {
        $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

function mockup_version(array $mockup, ?int $number): ?array
{
    $stmt = db()->prepare('SELECT * FROM mockup_versions WHERE mockup_id = ? AND number = ?');
    $stmt->execute([$mockup['id'], $number ?? $mockup['current_version']]);
    return $stmt->fetch() ?: null;
}

function mockup_history(array $mockup): array
{
    $stmt = db()->prepare('SELECT number, message, token_name, created_at FROM mockup_versions WHERE mockup_id = ? AND number <= ? ORDER BY number DESC');
    $stmt->execute([$mockup['id'], $mockup['current_version'] ?? 0]);
    return $stmt->fetchAll();
}

function mockup_files(array $version, ?string $path = null): array
{
    $sql = 'SELECT path, mime_type, content_hash, size_bytes FROM mockup_files WHERE version_id = ?';
    $stmt = db()->prepare($sql . ($path === null ? ' ORDER BY path' : ' AND path = ?'));
    $stmt->execute($path === null ? [$version['id']] : [$version['id'], $path]);
    return $stmt->fetchAll();
}

function parse_mockup_uploads(array $uploads): array
{
    if (!array_is_list($uploads) || count($uploads) > MOCKUP_FILE_LIMIT) {
        throw new DomainException('files must be a list of at most 1,000 entries.');
    }
    $files = [];
    foreach ($uploads as $upload) {
        $path = $upload['path'] ?? null;
        if (!is_string($path) || !valid_mockup_path($path) || isset($files[$path])) {
            throw new DomainException('Each file needs a distinct relative path.');
        }
        $text = $upload['text'] ?? null;
        $base64 = $upload['base64'] ?? null;
        if (is_string($text) && !array_key_exists('base64', $upload)) {
            $bytes = $text;
        } elseif (is_string($base64) && !array_key_exists('text', $upload)
            && strlen($base64) <= 4 * intdiv(MOCKUP_FILE_BYTES + 2, 3)) {
            $bytes = base64_decode($base64, true);
        } else {
            $bytes = false;
        }
        if ($bytes === false || strlen($bytes) > MOCKUP_FILE_BYTES) {
            throw new DomainException('Each file needs text or valid base64 and must fit in 5 MiB.');
        }
        $files[$path] = [
            'path' => $path, 'mime_type' => mockup_mime($path), 'content_hash' => hash('sha256', $bytes),
            'size_bytes' => strlen($bytes), 'bytes' => $bytes,
        ];
    }
    return $files;
}

function put_mockup_files(int $userId, string $name, string $draft, array $uploads, bool $reset): array
{
    $files = parse_mockup_uploads($uploads);
    $pdo = db();
    $lock = lock_mockup_blobs();
    $cleanup = [];
    try {
        $pdo->beginTransaction();
        $mockup = get_mockup($name, $userId, lock: true);
        if (!$mockup) {
            throw new DomainException('Mockup not found.');
        }
        $params = [$mockup['id'], $draft];
        $stmt = $pdo->prepare('SELECT path, size_bytes, content_hash FROM mockup_draft_files WHERE mockup_id = ? AND draft = ?');
        $stmt->execute($params);
        $previous = $stmt->fetchAll();
        if ($reset) {
            $pdo->prepare('DELETE FROM mockup_draft_files WHERE mockup_id = ? AND draft = ?')->execute($params);
        }
        foreach ($previous as $file) {
            if ($reset || isset($files[$file['path']])) {
                $cleanup[] = $file['content_hash'];
            }
        }
        $sizes = array_replace($reset ? [] : array_column($previous, 'size_bytes', 'path'), array_column($files, 'size_bytes', 'path'));
        if (count($sizes) > MOCKUP_FILE_LIMIT || array_sum($sizes) > MOCKUP_VERSION_BYTES) {
            throw new DomainException('A version can have up to 1,000 files and 25 MiB.');
        }
        foreach ($sizes as $path => $size) {
            $parent = dirname((string) $path);
            while ($parent !== '.') {
                if (isset($sizes[$parent])) {
                    throw new DomainException('A path cannot be both a file and a directory.');
                }
                $parent = dirname($parent);
            }
        }
        $stmt = $pdo->prepare('REPLACE INTO mockup_draft_files (mockup_id, draft, path, mime_type, content_hash, size_bytes)
                               VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($files as $file) {
            $cleanup[] = $file['content_hash'];
            write_mockup_blob($file['content_hash'], $file['bytes']);
            $stmt->execute([...$params, $file['path'], $file['mime_type'], $file['content_hash'], $file['size_bytes']]);
        }
        $pdo->commit();
        return ['name' => $name, 'files' => count($sizes), 'size_bytes' => array_sum($sizes)];
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        release_mockup_blobs($lock, $cleanup);
    }
}

function mockup_blob_hashes(int $id, bool $byOwner = false): array
{
    $column = $byOwner ? 'm.user_id' : 'm.id';
    $stmt = db()->prepare("SELECT f.content_hash FROM mockup_files f JOIN mockup_versions v ON v.id = f.version_id
                          JOIN mockups m ON m.id = v.mockup_id WHERE {$column} = ?
                          UNION SELECT d.content_hash FROM mockup_draft_files d JOIN mockups m ON m.id = d.mockup_id
                          WHERE {$column} = ?");
    $stmt->execute([$id, $id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function delete_mockup(int $userId, string $name): bool
{
    $pdo = db();
    $lock = lock_mockup_blobs();
    $cleanup = [];
    try {
        $pdo->beginTransaction();
        $mockup = get_mockup($name, $userId, lock: true);
        if (!$mockup) {
            return false;
        }
        $cleanup = mockup_blob_hashes((int) $mockup['id']);
        $pdo->prepare('DELETE FROM mockups WHERE id = ?')->execute([$mockup['id']]);
        $pdo->commit();
        return true;
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        release_mockup_blobs($lock, $cleanup);
    }
}

function commit_mockup(int $userId, string $name, string $draft, string $message, ?string $tokenName): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $mockup = get_mockup($name, $userId, lock: true);
        if (!$mockup) {
            throw new DomainException('Mockup not found.');
        }
        $number = (int) $mockup['current_version'] + 1;
        $pdo->prepare('INSERT INTO mockup_versions (mockup_id, number, message, token_name) VALUES (?, ?, ?, ?)')
            ->execute([$mockup['id'], $number, $message, $tokenName]);
        $versionId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO mockup_files (version_id, path, mime_type, content_hash, size_bytes)
                               SELECT ?, path, mime_type, content_hash, size_bytes FROM mockup_draft_files WHERE mockup_id = ? AND draft = ?');
        $stmt->execute([$versionId, $mockup['id'], $draft]);
        if ($stmt->rowCount() === 0) {
            throw new DomainException('Put files before committing a version.');
        }
        $pdo->prepare('UPDATE mockups SET current_version = ? WHERE id = ?')->execute([$number, $mockup['id']]);
        $pdo->prepare('DELETE FROM mockup_draft_files WHERE mockup_id = ? AND draft = ?')->execute([$mockup['id'], $draft]);
        $pdo->commit();
        return ['name' => $name, 'version' => $number, 'url' => mockup_url($name), 'version_url' => mockup_url($name, $number)];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function mockup_url(string $name, ?int $number = null): string
{
    return '/m/' . $name . '/' . ($number === null ? '' : '@' . $number . '/');
}

function mockup_file_url(string $path): string
{
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}
