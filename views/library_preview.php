<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<style>
:root{color-scheme:light dark}body{margin:0;font:16px system-ui,sans-serif;display:flex;flex-direction:column;min-height:100dvh}
nav{padding:.65rem 1rem;display:flex;flex-wrap:wrap;gap:.4rem 1.2rem;border-bottom:1px solid GrayText}
main{padding:1rem;overflow-wrap:anywhere}iframe{border:0;flex:1;width:100%}
</style>
</head>
<body>
<nav aria-label="Publishing navigation">
    <strong><?= e($title) ?></strong>
    <?php foreach ($nav as [$href, $label]): ?><a href="<?= e($href) ?>"><?= e($label) ?></a><?php endforeach; ?>
</nav>
<?php if (isset($frame)): ?>
    <iframe title="<?= e($title) ?>" src="<?= e($frame) ?>" sandbox="<?= e(HTML_NOTE_SANDBOX) ?>"></iframe>
<?php elseif (isset($files)): ?>
    <main><h1>Screens and files</h1><ul>
    <?php foreach ($files as $file): ?>
        <li><a href="<?= e($root . mockup_file_url($file['path'])) ?>"><?= e($file['path']) ?></a> · <?= e(fmt_bytes((int) $file['size_bytes'])) ?></li>
    <?php endforeach; ?>
    </ul></main>
<?php else: ?>
    <main><h1>Version history</h1>
    <?php if (!$versions): ?><p>No versions published.</p><?php endif; ?>
    <ol>
    <?php foreach ($versions as $version): ?>
        <li>
            <a href="<?= e(mockup_url($name, (int) $version['number'])) ?>">Version <?= (int) $version['number'] ?></a>
            · <?= e(fmt_dt($version['created_at'])) ?>
            <?php if ($version['token_name'] !== null): ?> · <?= e($version['token_name']) ?><?php endif; ?>
            <?php if ($version['message'] !== ''): ?> · <?= e($version['message']) ?><?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ol></main>
<?php endif; ?>
</body>
</html>
