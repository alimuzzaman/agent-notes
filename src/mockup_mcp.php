<?php

declare(strict_types=1);

function mcp_mockup_tools(): array
{
    $str = ['type' => 'string'];
    $name = ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{0,119}$'];
    $version = ['type' => 'integer', 'minimum' => 1, 'maximum' => 4294967295];
    $draft = ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$', 'description' => 'Use the same draft ID for every call in one upload. Omit for the token’s default draft.'];
    $file = [
        'type' => 'object',
        'properties' => ['path' => $str, 'text' => $str, 'base64' => $str],
        'required' => ['path'],
        'oneOf' => [['required' => ['text']], ['required' => ['base64']]],
    ];
    $tools = [
        'create_mockup' => ['Reserve a mockup name and title. Put files and commit to publish it.', ['name' => $name, 'title' => $str], ['name', 'title']],
        'put_mockup_files' => ['Stage a batch of files for a complete replacement version. A repeated path replaces the staged file. Use reset on the first batch to start over.',
            ['name' => $name, 'draft' => $draft, 'files' => ['type' => 'array', 'maxItems' => MOCKUP_FILE_LIMIT, 'items' => $file], 'reset' => ['type' => 'boolean']], ['name', 'files']],
        'commit_mockup' => ['Publish the staged files as the next version and clear the draft.', ['name' => $name, 'draft' => $draft, 'message' => $str], ['name', 'message']],
        'get_mockup' => ['Get metadata, version history and the file manifest. Pass path to get one file as base64.', ['name' => $name, 'version' => $version, 'path' => $str], ['name']],
        'list_mockups' => ['List your mockups, newest first.', [
            'query' => $str, 'prefix' => $str, 'limit' => ['type' => 'integer'], 'offset' => ['type' => 'integer'],
        ], []],
        'delete_mockup' => ['Delete a mockup and revoke access to all its versions.', ['name' => $name], ['name']],
    ];
    $result = [];
    foreach ($tools as $tool => [$description, $properties, $required]) {
        $result[] = ['name' => $tool, 'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => $properties, 'required' => $required]];
    }
    return $result;
}

function mcp_mockup_name(array $args): string
{
    $name = $args['name'] ?? null;
    if (!is_string($name) || !valid_library_name($name)) {
        throw new DomainException('name must be 1–120 lowercase letters, digits or hyphens and start with a letter or digit.');
    }
    return $name;
}

function mcp_mockup_version(array $args): ?int
{
    if (!array_key_exists('version', $args)) {
        return null;
    }
    if (!is_int($args['version']) || $args['version'] < 1 || $args['version'] > 4294967295) {
        throw new DomainException('version must be a positive version number.');
    }
    return $args['version'];
}

function mcp_mockup_draft(array $args, ?int $tokenId): string
{
    $draft = $args['draft'] ?? 'default';
    if (!is_string($draft) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $draft)) {
        throw new DomainException('draft must be 1–64 letters, digits, underscores or hyphens.');
    }
    return hash('sha256', ($tokenId ?? 'web') . ':' . $draft);
}

function mockup_summary(array $mockup): array
{
    $name = $mockup['name'];
    return [
        'name' => $name, 'title' => $mockup['title'],
        'current_version' => isset($mockup['current_version']) ? (int) $mockup['current_version'] : null,
        'created_at' => $mockup['created_at'], 'updated_at' => $mockup['updated_at'],
        'token_name' => $mockup['token_name'] ?? null,
        'url' => app_url(mockup_url($name)), 'history_url' => app_url(mockup_url($name) . '@history'),
    ];
}

function mcp_mockup_call(string $tool, array $args, int $userId, ?int $tokenId, ?string $tokenName): array
{
    if ($tool === 'list_mockups') {
        $query = $args['query'] ?? '';
        $prefix = $args['prefix'] ?? '';
        if (!is_string($query) || !is_string($prefix)) {
            throw new DomainException('query and prefix must be strings.');
        }
        $rows = list_mockups($userId, $query, $prefix, max(1, min(200, (int) ($args['limit'] ?? 50))), max(0, (int) ($args['offset'] ?? 0)));
        return ['count' => count($rows), 'mockups' => array_map('mockup_summary', $rows)];
    }
    $name = mcp_mockup_name($args);
    switch ($tool) {
        case 'create_mockup':
            $title = $args['title'] ?? null;
            if (!is_string($title) || trim($title) === '' || mb_strlen($title) > 255) {
                throw new DomainException('title must have 1–255 characters.');
            }
            return mockup_summary(create_mockup($userId, $name, trim($title)));

        case 'put_mockup_files':
            if (!is_array($args['files'] ?? null) || !is_bool($args['reset'] ?? false)) {
                throw new DomainException('files must be a list and reset must be a boolean.');
            }
            return put_mockup_files($userId, $name, mcp_mockup_draft($args, $tokenId), $args['files'], $args['reset'] ?? false);

        case 'upload_asset':
            $path = $args['path'] ?? null;
            $result = put_mockup_files($userId, $name, mcp_mockup_draft($args, $tokenId),
                [['path' => $path, 'base64' => $args['data'] ?? null]], false);
            return $result + ['path' => $path, 'mime_type' => mockup_mime($path)];

        case 'commit_mockup':
            $message = $args['message'] ?? null;
            if (!is_string($message) || mb_strlen($message) > 500) {
                throw new DomainException('message must be a string of up to 500 characters.');
            }
            $result = commit_mockup($userId, $name, mcp_mockup_draft($args, $tokenId), $message, $tokenName);
            $result['url'] = app_url($result['url']);
            $result['version_url'] = app_url($result['version_url']);
            return $result;

        case 'get_mockup':
            $mockup = get_mockup($name, $userId);
            if (!$mockup) {
                throw new DomainException('Mockup not found.');
            }
            $number = mcp_mockup_version($args);
            $path = $args['path'] ?? null;
            if ($path !== null && (!is_string($path) || !valid_mockup_path($path))) {
                throw new DomainException('path must be a relative file path.');
            }
            $version = mockup_version($mockup, $number);
            if (!$version && ($number !== null || $path !== null)) {
                throw new DomainException('Mockup version not found.');
            }
            $files = $version ? mockup_files($version, $path) : [];
            if ($path !== null) {
                if (!$files) {
                    throw new DomainException('File not found in this version.');
                }
                $bytes = file_get_contents(mockup_blob_path($files[0]['content_hash']));
                if ($bytes === false) {
                    throw new RuntimeException('Mockup asset is missing.');
                }
                return ['name' => $name, 'version' => (int) $version['number']] + $files[0] + ['base64' => base64_encode($bytes)];
            }
            $history = mockup_history($mockup);
            $mockup['token_name'] = $history[0]['token_name'] ?? null;
            return mockup_summary($mockup) + [
                'version' => $version ? (int) $version['number'] : null,
                'version_url' => $version ? app_url(mockup_url($name, (int) $version['number'])) : null,
                'versions' => $history, 'files' => $files,
            ];

        case 'delete_mockup':
            if (!delete_mockup($userId, $name)) {
                throw new DomainException('Mockup not found.');
            }
            return ['name' => $name, 'deleted' => true];
    }
    throw new LogicException('Unknown mockup tool.');
}
