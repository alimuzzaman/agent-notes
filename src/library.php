<?php

declare(strict_types=1);

function library_notes(int $userId, array $filters, int $limit, int $offset): array
{
    $liveShare = feature(Feature::ShareLinks)
        ? 'EXISTS (SELECT 1 FROM share_links l WHERE l.note_id = notes.id AND l.revoked_at IS NULL AND l.expires_at > NOW())'
        : '0';
    $sql = "SELECT slug, title, filename, size_bytes, views, created_at, updated_at, format, kind, collection,
                   visibility, token_name, {$liveShare} AS shared FROM notes WHERE user_id = ?";
    $params = [$userId];
    foreach (['kind', 'collection', 'visibility'] as $column) {
        if (array_key_exists($column, $filters)) {
            $sql .= " AND {$column} <=> ?";
            $params[] = $filters[$column];
        }
    }
    if (trim($filters['query'] ?? '') !== '') {
        $sql .= ' AND MATCH (title, content) AGAINST (? IN NATURAL LANGUAGE MODE)';
        $params[] = $filters['query'];
    }
    if (isset($filters['shared'])) {
        $sql .= " AND {$liveShare} = ?";
        $params[] = (int) $filters['shared'];
    }
    $order = match ($filters['sort'] ?? '') {
        'filename' => 'filename, slug',
        'title' => 'title, slug',
        'size' => 'size_bytes DESC, slug',
        default => 'updated_at DESC, id DESC',
    };
    $stmt = db()->prepare($sql . " ORDER BY {$order} LIMIT ? OFFSET ?");
    foreach ([...$params, $limit, $offset] as $i => $value) {
        $stmt->bindValue($i + 1, $value, match (true) {
            is_int($value) => PDO::PARAM_INT,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        });
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

function library_query(string $key): string
{
    return is_string($_GET[$key] ?? null) ? $_GET[$key] : '';
}

function library_headers(): void
{
    header('Cache-Control: private, no-store');
    header('X-Robots-Tag: ' . robots_tag());
    header('Referrer-Policy: no-referrer');
}

function library_index(string $path): never
{
    library_headers();
    $userId = (int) require_login()['id'];
    $page = max(1, min(intdiv(PHP_INT_MAX, 50), (int) library_query('page')));
    $collection = null;
    $query = library_query('query');
    $prefix = '';
    $filters = [];
    if ($path === '/') {
        $tab = match (library_query('tab')) {
            'pages' => 'pages',
            'mockups' => 'mockups',
            default => 'notes',
        };
        $notes = $tab === 'mockups' ? [] : library_notes($userId, ['kind' => $tab === 'pages' ? 'page' : 'note'], 10, 0);
        $mockups = $tab === 'mockups' ? list_mockups($userId, '', '', 10, 0, publishedOnly: true) : [];
        render('library', ['title' => 'Notes', 'tab' => $tab, 'notes' => $notes, 'mockups' => $mockups,
            'prefix' => '', 'query' => '', 'filters' => [], 'collection' => null, 'index' => false, 'page' => 1, 'more' => false]);
    }

    if ($path === '/m') {
        $tab = 'mockups';
        $prefix = library_query('prefix');
        $notes = [];
        $mockups = list_mockups($userId, $query, $prefix, 51, ($page - 1) * 50, publishedOnly: true);
    } else {
        $kind = NoteKind::tryFrom(library_query('kind'));
        $visibility = Visibility::tryFrom(library_query('visibility'));
        $shared = library_query('shared');
        $sort = library_query('sort');
        if (preg_match('#^/n/c/([a-z0-9][a-z0-9-]{0,119})$#D', $path, $m)) {
            $collection = $m[1];
            $sort = 'filename';
        }
        $filters = array_filter([
            'kind' => $kind?->value, 'collection' => $collection ?? library_query('collection'),
            'query' => $query, 'visibility' => $visibility?->value, 'sort' => $sort,
            'shared' => match ($shared) { 'yes' => true, 'no' => false, default => null },
        ], fn($value) => $value !== null && $value !== '');
        $tab = $kind === NoteKind::Page ? 'pages' : 'notes';
        $mockups = [];
        $notes = library_notes($userId, $filters, 51, ($page - 1) * 50);
    }
    $more = count($notes) > 50 || count($mockups) > 50;
    $notes = array_slice($notes, 0, 50);
    $mockups = array_slice($mockups, 0, 50);
    $title = $collection ?? ($tab === 'mockups' ? 'Mockups' : ($tab === 'pages' ? 'Pages' : 'Notes and pages'));
    $index = true;
    render('library', compact('title', 'tab', 'notes', 'mockups', 'query', 'prefix', 'filters', 'collection', 'index', 'page', 'more'));
}

function note_library_nav(array $note, ?array $viewer): array
{
    $nav = [['/n/', 'Notes and pages']];
    if (!$viewer || (int) $viewer['id'] !== (int) $note['user_id'] || $note['collection'] === null) {
        return $nav;
    }
    $nav[] = ['/n/c/' . $note['collection'] . '/', $note['collection']];
    foreach (['<' => 'Previous', '>' => 'Next'] as $operator => $label) {
        $order = $operator === '<' ? 'DESC' : 'ASC';
        $stmt = db()->prepare("SELECT slug, title FROM notes WHERE user_id = ? AND collection = ?
                               AND (filename, slug) {$operator} (?, ?) ORDER BY filename {$order}, slug {$order} LIMIT 1");
        $stmt->execute([$note['user_id'], $note['collection'], $note['filename'], $note['slug']]);
        if ($neighbor = $stmt->fetch()) {
            $nav[] = ['/n/' . $neighbor['slug'], $label . ' · ' . $neighbor['title']];
        }
    }
    return $nav;
}

function mockup_thumbnail(array $mockup): ?string
{
    $stmt = db()->prepare("SELECT f.path FROM mockup_files f JOIN mockup_versions v ON v.id = f.version_id
                           WHERE v.mockup_id = ? AND v.number = ? AND f.mime_type = 'image/png'
                           ORDER BY f.path = 'thumb.png' DESC, f.path LIMIT 1");
    $stmt->execute([$mockup['id'], $mockup['current_version']]);
    $path = $stmt->fetchColumn();
    return $path === false ? null : mockup_url($mockup['name'], (int) $mockup['current_version']) . '@files/' . mockup_file_url($path);
}

function mockup_render(string $rawPath, bool $public): never
{
    library_headers();
    if (!preg_match('#^/m/([a-z0-9][a-z0-9-]{0,119})(/.*)?$#D', $rawPath, $m)) {
        public_not_found();
    }
    $name = $m[1];
    $rest = ltrim($m[2] ?? '', '/');
    $number = null;
    if (preg_match('#^@([1-9][0-9]{0,9})(?:/|$)(.*)$#D', $rest, $parts)) {
        $number = (int) $parts[1];
        $rest = $parts[2];
        if ($number > 4294967295) {
            public_not_found();
        }
    }
    $share = null;
    if ($public) {
        if ($number === null || !str_starts_with($rawPath, mockup_url($name, $number))
            || !($share = mockup_share_grants($name, $number))) {
            public_not_found();
        }
    }
    $mockup = get_mockup($name);
    if (!$mockup || ($share && (int) $mockup['id'] !== (int) $share['mockup_id'])) {
        public_not_found();
    }
    $title = $mockup['title'];
    $nav = $public ? [] : [
        ['/m/', 'Mockups'],
        [mockup_url($name) . '@history', 'Version history'],
    ];
    if ($number === null && $rest === '@history' && !$public) {
        $versions = mockup_history($mockup);
        echo view('library_preview', compact('title', 'nav', 'versions', 'name'));
        exit;
    }
    $version = mockup_version($mockup, $number);
    if (!$version || ($share && (int) $version['id'] !== (int) $share['mockup_version_id'])) {
        public_not_found();
    }
    $root = mockup_url($name, (int) $version['number']);
    $nav[] = [$root . '@screens', 'Screens and files'];

    $raw = str_starts_with($rest, '@files/');
    $filePath = rawurldecode($raw ? substr($rest, strlen('@files/')) : $rest);
    $isRoot = $filePath === '';
    if ($isRoot || str_ends_with($filePath, '/')) {
        $filePath .= 'index.html';
    }
    $file = valid_mockup_path($filePath) ? (mockup_files($version, $filePath)[0] ?? null) : null;
    if (!$raw && ($rest === '@screens' || ($isRoot && !$file))) {
        $files = mockup_files($version);
        echo view('library_preview', compact('title', 'nav', 'files', 'root'));
        exit;
    }
    if (!$file) {
        public_not_found();
    }
    if (!$raw && str_starts_with($file['mime_type'], 'text/html')) {
        if ($share) {
            mockup_share_audit((int) $share['id'], (int) $version['id'], 'viewed');
        }
        $frame = $root . '@files/' . mockup_file_url($file['path']);
        echo view('library_preview', compact('title', 'nav', 'frame'));
        exit;
    }
    $blob = mockup_blob_path($file['content_hash']);
    $stream = @fopen($blob, 'rb');
    if ($stream === false) {
        public_not_found();
    }
    foreach (html_note_headers() as $header) {
        header($header);
    }
    library_headers();
    header('Content-Type: ' . $file['mime_type']);
    header('Content-Length: ' . $file['size_bytes']);
    if ($file['mime_type'] === 'application/octet-stream') {
        header('Content-Disposition: attachment');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
        fpassthru($stream);
    }
    fclose($stream);
    exit;
}
