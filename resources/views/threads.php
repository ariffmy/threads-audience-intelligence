<div class="page-heading"><div><p class="eyebrow">LOCAL INTEGRATION</p><h1>Threads account</h1><p class="page-description">Connection and import operations for one authorized account.</p></div><span class="badge text-bg-warning">FIXTURE / DEMO</span></div>
<div class="alert alert-warning" role="note"><strong>Synthetic data only.</strong> This page uses a local fake provider. No Meta account is connected and no live network transport is available. Provider permissions and G1–G6 remain unverified.</div>
<?php if (!$enabled): ?>
    <div class="alert alert-secondary">Fixture actions are disabled. A local operator must configure the private encryption keyring and independent deletion ledger before enabling the demo.</div>
<?php endif; ?>
<section class="card p-4 mb-4" aria-labelledby="connection-heading">
    <h2 class="h5" id="connection-heading">Connection health</h2>
    <dl class="row mb-0">
        <dt class="col-sm-4">Connection</dt><dd class="col-sm-8"><?= $e($account['status'] ?? 'Not connected') ?> · fixture</dd>
        <dt class="col-sm-4">Provider identity</dt><dd class="col-sm-8"><?= $e($account['remote_user_id'] ?? 'Unavailable') ?></dd>
        <dt class="col-sm-4">Display handle</dt><dd class="col-sm-8"><?= $e($account['username'] ?? 'Unavailable') ?> <small>(mutable)</small></dd>
        <dt class="col-sm-4">Token expiry (UTC)</dt><dd class="col-sm-8"><?= $e($account['token_expires_at'] ?? 'Unavailable') ?></dd>
        <dt class="col-sm-4">Token health</dt><dd class="col-sm-8"><?= $account !== null && $account['token_valid'] ? 'Unexpired fixture token' : 'Expired or unavailable' ?></dd>
        <dt class="col-sm-4">Permission expiry (UTC)</dt><dd class="col-sm-8"><?= $e($account['permission_expires_at'] ?? 'Unavailable') ?></dd>
        <dt class="col-sm-4">Last exhausted pass (UTC)</dt><dd class="col-sm-8"><?= $e($account['last_sync_at'] ?? 'No successful synchronization') ?></dd>
        <dt class="col-sm-4">Safe error</dt><dd class="col-sm-8"><?= $e($account['safe_error_code'] ?? 'None recorded') ?></dd>
    </dl>
    <?php if ($grants): ?><p class="mt-3 mb-1"><strong>Fixture grants</strong> (these are simulated, not Meta approvals):</p><ul><?php foreach ($grants as $grant): ?><li><?= $e($grant['permission']) ?> · expires <?= $e($grant['expires_at']) ?> UTC</li><?php endforeach; ?></ul><?php else: ?><p class="mt-3">No verified fixture grants.</p><?php endif; ?>
    <div class="d-flex flex-wrap gap-2 mt-3">
        <?php if ($enabled && ($account === null || $account['blocked_at'] === null)): ?><form method="post" action="/threads/connect"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button type="submit" class="btn btn-primary"><?= $account === null ? 'Connect synthetic account' : 'Reauthorize same fixture identity' ?></button></form><?php endif; ?>
        <?php if ($enabled && $account !== null && $account['blocked_at'] === null): ?>
            <?php foreach (['refresh' => 'Queue token refresh', 'rotate' => 'Rotate encryption key', 'disconnect' => 'Disconnect and purge fixture'] as $action => $label): ?><form method="post" action="/threads/<?= $e($action) ?>"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="account" value="<?= $e($account['id']) ?>"><button type="submit" class="btn <?= $action === 'disconnect' ? 'btn-outline-danger' : 'btn-outline-secondary' ?>"><?= $e($label) ?></button></form><?php endforeach; ?>
        <?php endif; ?>
    </div>
    <p class="small text-secondary mt-3 mb-0">Activation and imports advance through the finite CLI worker. Refresh requires a valid long token at least 24 hours old. Disconnect permanently blocks this fixture scope and queues bounded primary cleanup.</p>
</section>
<?php if ($enabled && $account !== null && $account['status'] === 'active' && $account['blocked_at'] === null): ?>
<section class="card p-4 mb-4" aria-labelledby="import-heading">
    <h2 class="h5" id="import-heading">Queue a bounded fixture import</h2>
    <form action="/threads/import" method="post" class="row g-3">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="account" value="<?= $e($account['id']) ?>">
        <div class="col-md-4"><label for="mode" class="form-label">Collection</label><select id="mode" name="mode" class="form-select"><option value="posts">Owned posts</option><option value="replies">Top-level replies</option><option value="conversation">Flattened conversation</option></select></div>
        <div class="col-md-8"><label for="root" class="form-label">Verified root (leave empty for owned posts)</label><select id="root" name="root" class="form-select"><option value="">Owned posts list</option><?php foreach ($items as $item): if ($item['kind'] !== 'owned_post') continue; ?><option value="<?= $e($item['id']) ?>">Fixture media <?= $e($item['remote_media_id']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label for="window_start" class="form-label">Start UTC · inclusive</label><input class="form-control" id="window_start" name="window_start" value="2026-10-01 00:00:00" required pattern="[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}"></div>
        <div class="col-md-6"><label for="window_end" class="form-label">End UTC · exclusive, maximum 30 days</label><input class="form-control" id="window_end" name="window_end" value="2026-10-26 00:00:00" required pattern="[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}"></div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Queue fixture import</button></div>
    </form>
    <form action="/threads/incremental" method="post" class="mt-3"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="account" value="<?= $e($account['id']) ?>"><input type="hidden" name="window_end" value="2026-10-26 00:00:00"><button class="btn btn-outline-primary" type="submit">Queue incremental owned posts</button><p class="small text-secondary mt-2 mb-0">Uses the last exhausted owned-post window with a one-hour overlap, capped at 30 days. Demo end: 26 October 2026 UTC.</p></form>
</section>
<?php endif; ?>
<section class="card p-4 mb-4" aria-labelledby="runs-heading">
    <h2 class="h5" id="runs-heading">Recent import status</h2><p class="small text-secondary">Exhausted means the accessible fixture pass ended within the requested window. It does not establish complete reply visibility. Retained observations may overlap between passes; they are not unique audience counts.</p>
    <?php if (!$runs): ?><p>No retained imports available.</p><?php else: ?><div class="table-responsive"><table class="table"><caption>Latest ten local collection runs</caption><thead><tr><th scope="col">Mode</th><th scope="col">Outcome</th><th scope="col">Pages</th><th scope="col">Seen / retained observations</th><th scope="col">Unknown time</th><th scope="col">Safe error</th></tr></thead><tbody><?php foreach ($runs as $run): ?><tr><td><?= $e($run['mode']) ?></td><td><?= $e($run['status']) ?></td><td><?= $e($run['pages']) ?></td><td><?= $e($run['records_seen']) ?> / <?= $e($run['records_retained']) ?></td><td><?= $e($run['unknown_time']) ?></td><td><?= $e($run['safe_error_code'] ?? 'None') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<section class="card p-4" aria-labelledby="sources-heading"><h2 class="h5" id="sources-heading">Retained fixture evidence</h2><p class="small text-secondary">At most 100 local records. Missing fields remain unavailable; reply media IDs identify content, not authors. No author profiles are created.</p>
    <?php if (!$items): ?><p>No currently accessible fixture evidence.</p><?php endif; ?>
    <?php foreach ($items as $item): ?><article class="border-top py-3"><h3 class="h6"><?= $e($item['kind']) ?> · media <?= $e($item['remote_media_id']) ?></h3><p class="mb-1 text-break"><?= $e($item['body'] ?? 'Text unavailable') ?></p><p class="small text-secondary"><?= $e($item['username'] ?? 'Author handle unavailable') ?> · <?= $e($item['posted_at'] ?? 'Time unavailable') ?> UTC</p><?php if ($enabled): ?><form action="/threads/delete-source" method="post"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="account" value="<?= $e($account['id']) ?>"><input type="hidden" name="source" value="<?= $e($item['id']) ?>"><button type="submit" class="btn btn-sm btn-outline-danger">Delete fixture source</button></form><?php endif; ?></article><?php endforeach; ?>
</section>
