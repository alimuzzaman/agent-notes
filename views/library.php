<section class="mx-auto max-w-6xl px-4 py-8">
    <h1 class="mb-4 text-3xl font-bold"><?= e($title) ?></h1>
    <nav class="mb-6 flex flex-wrap gap-4 border-b border-slate-300 pb-3 dark:border-slate-700" aria-label="Library">
        <?php foreach (['notes' => 'Notes', 'pages' => 'Pages', 'mockups' => 'Mockups'] as $key => $label):
            $url = !$index ? '/?tab=' . $key : ($key === 'mockups' ? '/m/' : '/n/?kind=' . ($key === 'notes' ? 'note' : 'page'));
        ?>
            <a class="<?= $tab === $key ? 'font-bold underline' : 'hover:underline' ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?> href="<?= e($url) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($index): ?>
        <form class="mb-6 flex flex-wrap items-end gap-4" method="get">
            <label>Search <input class="block rounded border p-1 dark:bg-slate-900" name="query" value="<?= e($query) ?>"></label>
            <?php if ($tab === 'mockups'): ?>
                <label>Name prefix <input class="block rounded border p-1 dark:bg-slate-900" name="prefix" value="<?= e($prefix) ?>"></label>
            <?php else: ?>
                <?php if ($collection === null): ?>
                    <label>Collection <input class="block rounded border p-1 dark:bg-slate-900" name="collection" value="<?= e($filters['collection'] ?? '') ?>"></label>
                <?php endif; ?>
                <?php
                    $selects = [
                        'kind' => ['Kind', ['' => 'All', 'note' => 'Note', 'page' => 'Page']],
                        'visibility' => ['Visibility', ['' => 'All', 'tailnet' => 'Tailnet only', 'public' => 'Public']],
                    ];
                    if (feature(Feature::ShareLinks)) {
                        $selects['shared'] = ['Share link', ['' => 'All', 'yes' => 'Live', 'no' => 'None']];
                    }
                    if ($collection === null) {
                        $selects['sort'] = ['Sort', ['' => 'Newest', 'title' => 'Title', 'size' => 'Size']];
                    }
                    foreach ($selects as $field => [$label, $options]):
                        $selected = $filters[$field] ?? '';
                        if ($field === 'shared' && is_bool($selected)) {
                            $selected = $selected ? 'yes' : 'no';
                        }
                ?>
                    <label><?= e($label) ?>
                        <select class="block rounded border p-1 dark:bg-slate-900" name="<?= e($field) ?>">
                            <?php foreach ($options as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $value === $selected ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
            <button class="rounded bg-indigo-600 px-4 py-1.5 font-semibold text-white">Apply</button>
        </form>
    <?php endif; ?>

    <?php if ($tab === 'mockups'): ?>
        <?php if (!$mockups): ?><p>No mockups found.</p><?php endif; ?>
        <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($mockups as $mockup): ?>
            <li class="overflow-hidden rounded-lg border border-slate-300 dark:border-slate-700">
                <?php if ($thumb = mockup_thumbnail($mockup)): ?>
                    <img class="aspect-video w-full object-cover" loading="lazy" src="<?= e($thumb) ?>" alt="">
                <?php endif; ?>
                <div class="space-y-1 p-4">
                    <a class="font-semibold underline" href="<?= e(mockup_url($mockup['name'])) ?>"><?= e($mockup['title']) ?></a>
                    <p><?= e($mockup['name']) ?> · v<?= (int) $mockup['current_version'] ?></p>
                    <p><?= e(fmt_dt($mockup['updated_at'])) ?> · <?= e($mockup['token_name'] ?? 'Unrecorded token') ?></p>
                    <a class="underline" href="<?= e(mockup_url($mockup['name']) . '@history') ?>">Version history</a>
                </div>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php elseif ($notes): ?>
        <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr class="border-b border-slate-300 dark:border-slate-700">
                <?php foreach (['Title', 'Kind', 'Collection', 'Updated', 'Size', 'Visibility', 'Share link', 'Token'] as $label): ?>
                    <th scope="col" class="px-3 py-2"><?= e($label) ?></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($notes as $note): ?>
                <tr class="border-b border-slate-200 dark:border-slate-800">
                    <td class="px-3 py-2"><a class="font-semibold underline" href="/n/<?= e($note['slug']) ?>"><?= e($note['title']) ?></a></td>
                    <td class="px-3 py-2"><?= $note['kind'] === 'page' ? 'Page' : 'Note' ?></td>
                    <td class="px-3 py-2"><?php if ($note['collection'] !== null): ?>
                        <a class="underline" href="/n/c/<?= e($note['collection']) ?>/"><?= e($note['collection']) ?></a>
                    <?php else: ?>None<?php endif; ?></td>
                    <td class="px-3 py-2"><?= e(fmt_dt($note['updated_at'])) ?></td>
                    <td class="px-3 py-2"><?= e(fmt_bytes((int) $note['size_bytes'])) ?></td>
                    <td class="px-3 py-2"><?= $note['visibility'] === 'public' ? 'Public' : 'Tailnet only' ?></td>
                    <td class="px-3 py-2"><?= $note['shared'] ? 'Live' : 'None' ?></td>
                    <td class="px-3 py-2"><?= e($note['token_name'] ?? 'Unrecorded') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p>No <?= $tab === 'pages' ? 'pages' : 'notes' ?> found.</p>
    <?php endif; ?>

    <?php if (!$index): ?>
        <a class="mt-5 inline-block font-semibold underline" href="<?= $tab === 'mockups' ? '/m/' : '/n/?kind=' . ($tab === 'pages' ? 'page' : 'note') ?>">Browse all <?= e($tab) ?></a>
    <?php elseif ($page > 1 || $more): ?>
        <nav class="mt-5 flex gap-4" aria-label="Pagination">
            <?php if ($page > 1): ?><a class="underline" href="?<?= e(http_build_query(['page' => $page - 1] + $_GET)) ?>">Previous page</a><?php endif; ?>
            <?php if ($more): ?><a class="underline" href="?<?= e(http_build_query(['page' => $page + 1] + $_GET)) ?>">Next page</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
