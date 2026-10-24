<main class="login-shell">
    <section class="login-story" aria-label="About this application">
        <a class="brand" href="/login"><span class="brand-mark">t<span>i</span></span><span>THREADS<br><small>AUDIENCE INTELLIGENCE</small></span></a>
        <div class="login-story-body"><span class="eyebrow light">YOUR AUDIENCE. IN CONTEXT.</span><h1>Better understanding<br>starts with<br><em>the conversation.</em></h1><p>A private workspace for the discussions that matter to you.</p><div class="story-rule"></div><span class="story-caption">Evidence first. Insight follows.</span></div>
        <div class="story-footer"><span class="phase-light">FOUNDATION · PHASE 01</span><span>Personal application</span></div>
    </section>
    <section class="login-form-panel">
        <div class="login-card">
            <span class="eyebrow">ADMINISTRATOR ACCESS</span>
            <h2>Welcome back.</h2><p class="text-secondary mb-4">Sign in to your personal intelligence workspace.</p>
            <?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><?= $e($error) ?></div><?php endif; ?>
            <form action="/login" method="post">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <div class="mb-3"><label class="form-label" for="email">Email address</label><input class="form-control form-control-lg" id="email" name="email" type="email" autocomplete="username" maxlength="254" required placeholder="Your administrator email" spellcheck="false" autofocus></div>
                <div class="mb-4"><label class="form-label" for="password">Password</label><input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" maxlength="72" required></div>
                <button class="btn btn-primary btn-lg w-100" type="submit">Sign in <span aria-hidden="true" class="ms-2">→</span></button>
            </form>
            <div class="login-access-note"><span aria-hidden="true">▣</span><p>Private access only. Your administrator account is created through the secure command line.</p></div>
            <p class="login-footnote">No public registration · No external integrations active</p>
        </div>
    </section>
</main>
