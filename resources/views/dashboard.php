<div class="page-heading"><div><span class="eyebrow">WORKSPACE OVERVIEW</span><h1>Your intelligence workspace.</h1><p>The foundation is in place. Your audience story starts here.</p></div><a class="btn btn-outline-primary" href="/status">View system status <span aria-hidden="true">↗</span></a></div>
<section class="foundation-banner" aria-labelledby="foundation-heading"><div class="foundation-icon" aria-hidden="true">✓</div><div><span class="eyebrow">PHASE 01 · FOUNDATION & AUTHENTICATION</span><h2 id="foundation-heading">A private space, ready for what’s next.</h2><p>Administrator access and workspace controls are available. Threads collection and audience analysis will arrive in later phases.</p></div><span class="badge phase-badge">Foundation</span></section>
<div class="section-heading"><h2>Build your audience intelligence</h2><span>Development roadmap</span></div>
<div class="row g-3 module-grid">
<?php foreach ([
    ['01', 'Threads connections', 'Connect the Threads accounts you own or are authorized to manage.', 'Phase 3', '◎'],
    ['02', 'Discussion collection', 'Import permitted posts and available replies with clear source evidence.', 'Phase 3', '☷'],
    ['03', 'Audience understanding', 'Explore sentiment, discussion stance and recurring topics in collected replies.', 'Phase 4', '◈'],
    ['04', 'Intelligence reports', 'Turn observed discussion into evidence-linked dashboards and reports.', 'Phase 5', '▤'],
] as [$number, $heading, $description, $phase, $icon]): ?>
    <div class="col-md-6"><article class="module-card h-100"><div class="module-top"><span class="module-icon" aria-hidden="true"><?= $e($icon) ?></span><span class="module-number"><?= $e($number) ?></span></div><h3><?= $e($heading) ?></h3><p><?= $e($description) ?></p><div class="module-bottom"><span class="planned-dot"></span><span>Not implemented</span><span class="ms-auto roadmap-phase"><?= $e($phase) ?></span></div></article></div>
<?php endforeach; ?>
</div>
<div class="row g-4 mt-2">
    <section class="col-lg-7"><div class="panel h-100"><div class="section-heading"><h2>Recent administrative activity</h2><span>Audit log</span></div><ul class="activity-list">
        <?php $eventLabels = ['admin.created' => 'Administrator initialized', 'auth.login' => 'Administrator signed in', 'auth.logout' => 'Administrator signed out', 'profile.updated' => 'Profile updated', 'password.changed' => 'Password changed · sessions revoked', 'settings.updated' => 'Application settings updated']; ?>
        <?php foreach ($events as $event): ?><li><span class="activity-dot" aria-hidden="true"></span><span><?= $e($eventLabels[$event['event_type']] ?? 'Administrative event') ?></span><time><?= $e((new DateTimeImmutable($event['occurred_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($identity['display_timezone']))->format('d M, H:i')) ?></time></li><?php endforeach; ?>
        <?php if ($events === []): ?><li>No retained administrative activity.</li><?php endif; ?>
    </ul><p class="panel-footnote">Times shown in <?= $e($identity['display_timezone']) ?>.</p></div></section>
    <section class="col-lg-5"><div class="panel privacy-panel h-100"><span class="eyebrow">DESIGNED AROUND EVIDENCE</span><h2>Clarity before conclusions.</h2><p>Future analytics will be based on accessible, authorized content. Missing data and uncertainty will remain visible.</p><div class="privacy-tags"><span>Private by default</span><span>No fabricated analytics</span></div></div></section>
</div>
