<?php

// Run against a disposable local database: NOTES_LIBRARY_TEST_MYSQL_PORT=33679 php tests/notes_library.php

declare(strict_types=1);

$dbPort = getenv('NOTES_LIBRARY_TEST_MYSQL_PORT');
if (!$dbPort || !ctype_digit($dbPort) || (int) $dbPort === 3306) {
    $name = ['name' => 'other-owner'];
    fixture_mcp($tailnet, $other, 'create_mockup', $name + ['title' => 'Other owner']);
    fixture_mcp($tailnet, $other, 'put_mockup_files', $name + ['files' => [
        ['path' => 'private.txt', 'text' => 'private owner bytes'],
        ['path' => 'shared.txt', 'text' => 'shared bytes'],
    ]]);
    fixture_mcp($tailnet, $other, 'commit_mockup', $name + ['message' => 'Owner deletion fixture']);
    $call('create_mockup', ['name' => 'survives', 'title' => 'Survives']);
    $call('put_mockup_files', ['name' => 'survives', 'files' => [['path' => 'shared.txt', 'text' => 'shared bytes']]]);
    $otherSession = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO sessions (user_id, token_hash, expires_at) VALUES (2, ?, NOW() + INTERVAL 1 HOUR)')
        ->execute([hash('sha256', $otherSession)]);
    [$status] = fixture_http($tailnet, '/account/delete', ["Cookie: an_session={$otherSession}"],
        http_build_query(['_csrf' => hash_hmac('sha256', 'csrf', $otherSession)]));
    check($status === 302 || $status === 303, 'Account deletion completes.');
    check(fixture_http($tailnet, '/m/other-owner/@1/')[0] === 404, 'Deleting the owner removes the mockup.');
    $privateHash = hash('sha256', 'private owner bytes');
    $sharedHash = hash('sha256', 'shared bytes');
    check(!is_file("{$root}/assets/mockups/" . substr($privateHash, 0, 2) . "/{$privateHash}"), 'Deleting the owner purges unique blobs.');
    check(is_file("{$root}/assets/mockups/" . substr($sharedHash, 0, 2) . "/{$sharedHash}"), 'Deleting the owner preserves another owner’s references.');

    echo "notes_library: skipped (set NOTES_LIBRARY_TEST_MYSQL_PORT to a disposable local MySQL port)\n";
    exit;
}

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function fixture_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr(strrchr($address, ':'), 1);
}

function fixture_http(int $port, string $path, array $headers = [], array|string|null $request = null): array
{
    $curl = curl_init("http://127.0.0.1:{$port}{$path}");
    $responseHeaders = [];
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower($name)] = trim($value);
            }
            return strlen($line);
        },
    ]);
    if ($request !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, is_array($request) ? json_encode($request, JSON_THROW_ON_ERROR) : $request);
    }
    $body = curl_exec($curl);
    check($body !== false, "Request failed for {$path}.");
    return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $responseHeaders, $body];
}

function fixture_mcp(int $port, string $token, string $tool, array $args = [], bool $error = false): array
{
    [$status, , $body] = fixture_http($port, '/mcp', ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]]);
    check($status === 200, "{$tool} returned HTTP {$status}: " . substr($body, 0, 250));
    $out = json_decode($body, true, flags: JSON_THROW_ON_ERROR)['result'] ?? [];
    check(($out['isError'] ?? false) === $error && isset($out['content'][0]['text']), "{$tool}: " . substr($body, 0, 250));
    return json_decode($out['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
}

$repo = dirname(__DIR__);
$database = 'an_fixture_' . bin2hex(random_bytes(6));
$root = sys_get_temp_dir() . '/' . $database;
$pdo = new PDO("mysql:host=127.0.0.1;port={$dbPort};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET time_zone = '+00:00'");
$pdo->exec("CREATE DATABASE {$database}");
mkdir($root, 0700);
$servers = [];
try {
    foreach (['public', 'src', 'views'] as $dir) {
        exec('cp -R ' . escapeshellarg("{$repo}/{$dir}") . ' ' . escapeshellarg("{$root}/{$dir}"), $_, $status);
        check($status === 0, "Could not copy {$dir}.");
    }
    symlink("{$repo}/vendor", "{$root}/vendor");
    file_put_contents("{$root}/serve.php", '<?php $_SERVER["AGENT_NOTES_SCOPE"] = getenv("FIXTURE_SCOPE");'
        . ' require __DIR__ . "/public/index.php";');
    $pdo->exec("USE {$database}");
    $pdo->exec(file_get_contents("{$repo}/schema.sql"));
    $pdo->exec("INSERT INTO users (email, email_verified_at) VALUES ('other@example.test', NOW())");
    $token = 'fixture-owner-' . bin2hex(random_bytes(12));
    $second = 'fixture-second-' . bin2hex(random_bytes(12));
    $other = 'fixture-other-' . bin2hex(random_bytes(12));
    foreach ([[$token, 1, 'publisher'], [$second, 1, 'second token'], [$other, 2, 'other user']] as [$value, $owner, $label]) {
        $pdo->prepare('INSERT INTO api_tokens (user_id, name, token_hash, prefix) VALUES (?, ?, ?, ?)')
            ->execute([$owner, $label, hash('sha256', $value), 'fixture']);
    }
    $session = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO sessions (user_id, token_hash, expires_at) VALUES (1, ?, NOW() + INTERVAL 1 HOUR)')
        ->execute([hash('sha256', $session)]);
    $sessionHeader = ["Cookie: an_session={$session}"];

    $tailnet = fixture_port();
    $public = fixture_port();
    foreach (['tailnet' => $tailnet, 'public' => $public] as $scope => $port) {
        $env = [
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => $dbPort, 'DB_DATABASE' => $database, 'DB_USERNAME' => 'root',
            'FEATURE_NOTES_LIBRARY' => 'true', 'FEATURE_VISIBILITY' => 'true', 'FEATURE_HTML_NOTES' => 'true',
            'FEATURE_ASSETS' => 'true', 'FEATURE_SHARE_LINKS' => 'true', 'RATE_LIMIT_PER_MIN' => '300',
            'APP_URL' => "http://127.0.0.1:{$tailnet}", 'PUBLIC_URL' => "https://127.0.0.1:{$public}",
            'ASSET_STORAGE_PATH' => "{$root}/assets", 'FIXTURE_SCOPE' => $scope,
        ];
        $log = "{$root}/{$scope}.log";
        $cmd = [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'display_errors=0', '-S', "127.0.0.1:{$port}", "{$root}/serve.php"];
        $servers[] = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $env);
        for ($try = 0; $try < 50 && !($socket = @fsockopen('127.0.0.1', $port)); $try++) {
            usleep(100000);
        }
        check(is_resource($socket), "Could not start the {$scope} fixture.");
        fclose($socket);
    }

    $call = fn(string $tool, array $args = [], bool $error = false) => fixture_mcp($tailnet, $token, $tool, $args, $error);
    [$status, , $body] = fixture_http($tailnet, '/mcp', ["Authorization: Bearer {$token}"],
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
    check($status === 200 && (json_decode($body, true)['result']['serverInfo']['name'] ?? null) === 'notes', 'MCP server name: ' . substr($body, 0, 500));
    check(fixture_http($tailnet, '/mcp', [], [])[0] === 401, 'MCP must require a bearer token.');
    [$status, , $body] = fixture_http($tailnet, '/mcp', ["Authorization: Bearer {$token}"], ['jsonrpc' => '2.0', 'id' => 1]);
    check($status === 200 && json_decode($body, true)['error']['code'] === -32601, 'A missing method retains the legacy MCP error.');

    $note = $call('create_note', ['title' => 'Searchable report', 'content' => 'quartzite findings', 'collection' => 'bench', 'filename' => '00-first']);
    $page = $call('create_note', ['title' => 'Legacy page', 'content' => '<h1>Page fixture</h1>', 'format' => 'html', 'collection' => 'bench', 'filename' => '01-next']);
    $external = $call('create_note', ['title' => 'Public HTML', 'content' => '<h1>Public fixture</h1>', 'kind' => 'page', 'visibility' => 'public']);
    fixture_mcp($tailnet, $other, 'create_note', ['title' => 'Other owner fixture', 'content' => 'quartzite', 'collection' => 'bench']);
    $call('create_note', ['title' => 'Wrong kind', 'content' => '', 'kind' => 'note', 'format' => 'html'], true);
    check($note['kind'] === 'note' && $page['kind'] === 'page' && $page['format'] === 'html', 'Kind and format mapping.');
    check($page['token_name'] === 'publisher', 'Publishing token recorded.');
    check($call('list_notes', ['query' => 'quartzite', 'kind' => 'note', 'collection' => 'bench'])['count'] === 1, 'Full-text and collection filters.');
    check($call('list_notes', ['collection' => 'bench', 'limit' => 1, 'offset' => 1])['count'] === 1, 'MCP pagination.');
    $call('get_note', ['slug' => $page['slug'], 'kind' => 'note'], true);
    $call('delete_note', ['slug' => $page['slug'], 'collection' => 'other'], true);
    check($call('update_note', ['slug' => $page['slug'], 'title' => 'Updated page'])['kind'] === 'page', 'Legacy update keeps HTML format.');
    check($call('update_note', ['slug' => $external['slug'], 'collection' => null])['collection'] === null, 'Explicit null clears a collection.');

    $notePath = '/n/' . $note['slug'];
    $pagePath = '/n/' . $page['slug'];
    [$status, , $body] = fixture_http($tailnet, $notePath, $sessionHeader);
    check($status === 200 && str_contains($body, 'Next · Updated page'), 'Collection navigation.');
    [$status, , $body] = fixture_http($tailnet, $pagePath, $sessionHeader);
    check($status === 200 && str_contains($body, $pagePath . '/preview') && str_contains($body, 'Previous · Searchable report'), 'HTML page frame and navigation.');
    [$status, $headers, $body] = fixture_http($tailnet, $pagePath . '/preview');
    check($status === 200 && isset($headers['content-security-policy']) && $body === '<h1>Page fixture</h1>', 'HTML page content in sandbox.');
    [$status, $headers, $body] = fixture_http($public, '/n/' . $external['slug']);
    check($status === 200 && isset($headers['content-security-policy']) && $body === '<h1>Public fixture</h1>', 'Public HTML response stays unchanged.');
    $legacyShare = $call('create_share_link', ['slug' => $page['slug']]);
    [$status, $headers] = fixture_http($public, parse_url($legacyShare['url'], PHP_URL_PATH));
    check($status === 303 && $headers['location'] === $pagePath && str_contains($headers['set-cookie'], 'path=' . $pagePath), 'Legacy share cookie path.');
    $pageCookie = ['Cookie: ' . explode(';', $headers['set-cookie'])[0]];
    check(fixture_http($public, $pagePath, $pageCookie)[2] === '<h1>Page fixture</h1>', 'Legacy share serves the original page.');
    check(fixture_http($public, $pagePath . '/preview', $pageCookie)[0] === 404, 'Legacy share cannot reach private preview.');
    [, , $body] = fixture_http($tailnet, '/n/?shared=yes&kind=page', $sessionHeader);
    check(str_contains($body, 'Updated page') && !str_contains($body, 'Public HTML'), 'Index applies live share and kind filters.');
    [, , $body] = fixture_http($tailnet, '/n/c/bench/', $sessionHeader);
    check(strpos($body, 'Searchable report') < strpos($body, 'Updated page'), 'Collection order follows filenames.');
    check(!str_contains($body, 'Other owner fixture'), 'Collection index stays owner-scoped.');

    foreach (['/', '/n/', '/n/c/bench/', '/m/', '/m/design/@history', '/.well-known/mcp/server-card.json', '/mcp'] as $path) {
        check(fixture_http($public, $path, [...$sessionHeader, 'AGENT_NOTES_SCOPE: tailnet'])[0] === 404, "Public route must be closed: {$path}.");
    }
    check(fixture_http($public, '/mcp', ["Authorization: Bearer {$token}"], ['method' => 'tools/list'])[0] === 404, 'Public MCP POST must be closed.');

    $name = ['name' => 'design'];
    $call('create_mockup', $name + ['title' => 'Design fixture']);
    $call('commit_mockup', $name + ['message' => 'empty'], true);
    $stage = fn(array $files, string $draft = 'release', bool $reset = false, bool $error = false) =>
        $call('put_mockup_files', $name + compact('files', 'draft', 'reset'), $error);
    foreach (['../secret.txt', 'x/%2e%2e/y', "a\\b", '@history', 'a//b', '/absolute'] as $path) {
        $stage([['path' => $path, 'text' => 'invalid']], error: true);
    }
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0t8AAAAASUVORK5CYII=';
    $stage([
        ['path' => 'index.html', 'text' => '<a href="screens/details.html">Details</a><link rel="stylesheet" href="css/style.css"><img src="thumb.png">'],
        ['path' => 'css/style.css', 'text' => 'a { color: green; }'],
        ['path' => 'thumb.png', 'base64' => $png],
    ], reset: true);
    $stage([['path' => 'index.html/nested.txt', 'text' => 'conflict']], error: true);
    $stage([['path' => 'screens/details.html', 'text' => '<img src="../thumb.png">']]);
    fixture_mcp($tailnet, $second, 'put_mockup_files', $name + ['draft' => 'release', 'files' => [['path' => 'only.html', 'text' => 'second publisher']]]);
    fixture_mcp($tailnet, $other, 'get_mockup', $name, true);
    fixture_mcp($tailnet, $other, 'delete_mockup', $name, true);
    $first = $call('commit_mockup', $name + ['draft' => 'release', 'message' => 'First version']);
    check($first['version'] === 1, 'First version number.');
    $call('commit_mockup', $name + ['draft' => 'release', 'message' => 'retry'], true);

    $shared = $call('create_share_link', $name);
    check($shared['version'] === 1 && $call('list_share_links', ['kind' => 'mockup'])['count'] === 1, 'Share defaults to current version.');
    check($call('list_share_links')['count'] === 1, 'Default shares list retains note-only shape.');
    $call('revoke_share_link', ['id' => $legacyShare['id']]);
    check(fixture_http($public, $pagePath, $pageCookie)[0] === 404, 'Legacy share revocation still works.');
    fixture_mcp($tailnet, $other, 'revoke_share_link', ['id' => $shared['id']], true);

    $secondVersion = fixture_mcp($tailnet, $second, 'commit_mockup', $name + ['draft' => 'release', 'message' => 'Second version']);
    check($secondVersion['version'] === 2, 'Version increments across tokens.');
    $old = $call('get_mockup', $name + ['version' => 1]);
    $current = $call('get_mockup', $name);
    check($old['version'] === 1 && count($old['files']) === 4 && count($old['versions']) === 2, 'Manifest and history.');
    check($current['version'] === 2 && count($current['files']) === 1 && $current['token_name'] === 'second token', 'Replacement version and isolated draft.');
    check($call('get_mockup', $name + ['version' => 1, 'path' => 'thumb.png'])['base64'] === $png, 'Binary pull.');
    check($call('list_mockups', ['prefix' => 'des', 'query' => 'fixture'])['count'] === 1, 'Mockup listing filters.');
    check(str_contains(fixture_http($tailnet, '/m/design/')[2], 'only.html'), 'Generated list for current version without index.');
    check(str_contains(fixture_http($tailnet, '/m/design/@history')[2], 'First version'), 'Private version history.');
    [, , $body] = fixture_http($tailnet, '/m/design/@1/');
    check(str_contains($body, '/m/design/@1/@files/index.html') && str_contains($body, 'Version history'), 'Private mockup wrapper.');

    [$status, $headers] = fixture_http($public, parse_url($shared['url'], PHP_URL_PATH));
    check($status === 303 && $headers['location'] === '/m/design/@1/'
        && str_contains($headers['set-cookie'], 'path=/m/design/@1/') && str_contains($headers['set-cookie'], 'SameSite=None'), 'Scoped mockup cookie.');
    $shareCookie = ['Cookie: ' . explode(';', $headers['set-cookie'])[0]];
    [$status, $headers, $body] = fixture_http($public, '/m/design/@1/', $shareCookie);
    check($status === 200 && !str_contains($body, 'Version history') && str_contains($body, 'Screens and files'), 'Public mockup wrapper hides private navigation.');
    [$status, $headers, $body] = fixture_http($public, '/m/design/@1/@files/screens/details.html', $shareCookie);
    check($status === 200 && $body === '<img src="../thumb.png">' && str_contains($headers['content-security-policy'], 'sandbox')
        && !str_contains($headers['content-security-policy'], 'allow-same-origin'), 'Shared nested HTML uses the sandbox.');
    [$status, $headers, $body] = fixture_http($public, '/m/design/@1/@files/css/style.css', $shareCookie);
    check($status === 200 && $headers['content-type'] === 'text/css; charset=utf-8' && str_contains($headers['cache-control'], 'no-store'), 'Shared CSS authorizes each request.');
    foreach (['/m/design/', '/m/design/@2/', '/m/design/@history', '/m/other/@1/', '/m/design/@1/@files/%2e%2e/%2e%2e/@2/only.html', $pagePath] as $path) {
        check(fixture_http($public, $path, $shareCookie)[0] === 404, "Mockup share escapes its version at {$path}.");
    }
    check(fixture_http($public, '/m/design/@1/@files/thumb.png')[0] === 404, 'Asset needs authorization.');
    check(fixture_http($public, '/m/design/@1/@files/thumb.png', $shareCookie)[2] === base64_decode($png), 'Shared image bytes.');
    $call('revoke_share_link', ['id' => $shared['id']]);
    check(fixture_http($public, '/m/design/@1/@files/thumb.png', $shareCookie)[0] === 404, 'Revocation takes effect on assets.');
    $expired = $call('create_share_link', $name + ['version' => 1]);
    $pdo->prepare('UPDATE share_links SET expires_at = NOW() WHERE id = ?')->execute([$expired['id']]);
    check(fixture_http($public, parse_url($expired['url'], PHP_URL_PATH))[0] === 404, 'Expired share is closed.');
    $call('delete_mockup', $name);
    check(fixture_http($tailnet, '/m/design/@1/')[0] === 404, 'Deleted versions are no longer reachable.');
    check($call('list_share_links', ['kind' => 'mockup'])['count'] === 0, 'Delete cascades to mockup shares.');

    $call('create_mockup', $name + ['title' => 'Limit fixture']);
    $stage([['path' => 'large.txt', 'text' => str_repeat('x', 5 * 1024 * 1024 + 1)]], error: true);
    $large = str_repeat('x', 5 * 1024 * 1024);
    for ($i = 1; $i <= 5; $i++) {
        $stage([['path' => "file-{$i}.txt", 'text' => $large]]);
    }
    $stage([['path' => 'over.txt', 'text' => 'x']], error: true);
    $hash = hash('sha256', $large);
    $blob = "{$root}/assets/mockups/" . substr($hash, 0, 2) . "/{$hash}";
    check(count(glob($blob)) === 1, 'Identical bytes use one disk object.');
    $stage([['path' => 'copy.txt', 'text' => $large]], draft: 'keep');
    $stage([['path' => 'over.txt', 'text' => 'reset']], reset: true);
    check(is_file($blob), 'Reset preserves blobs used in another draft.');
    $call('commit_mockup', $name + ['draft' => 'release', 'message' => 'After reset']);
    check(count($call('get_mockup', $name)['files']) === 1, 'Reset starts a new draft.');
    $stage([], draft: 'keep', reset: true);
    clearstatcache(true, $blob);
    check(!is_file($blob), 'Reset removes an unused draft blob.');
    $hash = hash('sha256', 'reset');
    $blob = "{$root}/assets/mockups/" . substr($hash, 0, 2) . "/{$hash}";
    check(is_file($blob), 'Committed version keeps its blob.');
    $call('delete_mockup', $name);
    clearstatcache(true, $blob);
    check(!is_file($blob), 'Delete removes blobs without references.');
    echo "notes_library: note and mockup MCP, indexes, sandboxed content, limits and scoped shares passed\n";
} finally {
    foreach ($servers as $server) {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
    }
    $pdo->exec("DROP DATABASE {$database}");
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
