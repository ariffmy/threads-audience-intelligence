<div class="page-heading"><div><span class="eyebrow">INSTALLATION HEALTH</span><h1>System status</h1><p>Live checks for the current application foundation.</p></div><button class="btn btn-outline-primary" type="button" id="refresh-status">Refresh status <span aria-hidden="true">↻</span></button></div>
<p id="status-feedback" class="status-feedback" aria-live="polite">Checked <?= $e($statusData['checked_at']) ?></p>
<section class="panel mb-4" aria-labelledby="queue-heading">
    <h2 class="panel-title" id="queue-heading">Job queue</h2>
    <?php $queue = $statusData['queue']; ?>
    <?php if ($queue === null): ?><p>Apply and verify migrations to view queue status.</p><?php else: ?>
    <div class="row g-3 mb-3">
        <?php foreach (['ready' => 'Pending', 'running' => 'Running', 'succeeded' => 'Completed', 'failed' => 'Failed', 'retry_wait' => 'Retry queue', 'cancelled' => 'Cancelled'] as $state => $label): ?>
        <div class="col-6 col-md-4 col-xl-2"><div class="status-card"><h3 class="h6"><?= $e($label) ?></h3><p class="fs-4 mb-0" data-queue-count="<?= $e($state) ?>"><?= $e((string) $queue['counts'][$state]) ?></p></div></div>
        <?php endforeach; ?>
    </div>
    <dl class="detail-list">
        <dt>Last successful worker (UTC)</dt><dd id="queue-last-success"><?= $e($queue['last_success_at'] ?? 'No successful run recorded') ?></dd>
        <dt>Processed jobs in retained runs</dt><dd id="queue-processed"><?= $e((string) $queue['processed_jobs']) ?></dd>
        <dt>Worker failures / unfinished runs</dt><dd id="queue-worker-errors"><?= $e($queue['worker_errors'] . ' / ' . $queue['unfinished_runs']) ?></dd>
        <dt>Longest retained run</dt><dd id="queue-duration"><?= $queue['longest_ms'] === null ? 'No measurement' : $e($queue['longest_ms'] . ' ms') ?></dd>
        <dt>Peak retained worker memory</dt><dd id="queue-memory"><?= $queue['peak_bytes'] === null ? 'No measurement' : $e(number_format($queue['peak_bytes'] / 1048576, 1) . ' MiB') ?></dd>
    </dl>
    <h3 class="h6">Recent job errors (UTC)</h3>
    <ul id="queue-recent-errors"><?php foreach ($queue['recent_errors'] as $error): ?><li><?= $e($error['safe_error_code'] . ' · ' . $error['finished_at']) ?></li><?php endforeach; ?><?php if ($queue['recent_errors'] === []): ?><li>No retained job errors</li><?php endif; ?></ul>
    <p class="panel-footnote">Counts cover this organization's retained jobs. Completed jobs may be maintenance or synthetic demonstrations. A successful worker run can include failed jobs. No external API handlers are enabled.</p>
    <?php endif; ?>
</section>
<div class="row g-3 mb-4" id="system-checks"><?php foreach ($statusData['checks'] as $check): ?><div class="col-md-6"><div class="status-card"><div class="d-flex align-items-center justify-content-between mb-2"><h2><?= $e($check['name']) ?></h2><span class="badge <?= $check['ok'] ? 'healthy-badge' : 'attention-badge' ?>"><?= $check['ok'] ? 'Ready' : 'Review' ?></span></div><p><?= $e($check['detail']) ?></p></div></div><?php endforeach; ?></div>
<div class="row g-4"><section class="col-lg-7"><div class="panel"><h2 class="panel-title">Runtime & extensions</h2><dl class="detail-list"><dt>Application</dt><dd><?= $e($statusData['release']) ?></dd><dt>PHP</dt><dd><?= $e($statusData['php_version']) ?></dd><dt>Database target</dt><dd><?= $e($statusData['target_database']) ?></dd><dt>Environment</dt><dd><?= $e($statusData['environment']) ?></dd></dl><div class="extension-list"><?php foreach ($statusData['extensions'] as $extension => $loaded): ?><span class="badge <?= $loaded ? 'healthy-badge' : 'attention-badge' ?>"><?= $e($extension) ?> · <?= $loaded ? 'loaded' : 'missing' ?></span><?php endforeach; ?></div></div></section><section class="col-lg-5"><div class="panel"><h2 class="panel-title">Schema migrations</h2><ul class="migration-list"><?php foreach ($statusData['migrations'] as $migration): ?><li><span><?= $e($migration['version']) ?></span><span class="migration-state"><?= $e($migration['status']) ?></span></li><?php endforeach; ?></ul><p class="panel-footnote">Migrations run from the command line only.</p></div></section></div>
