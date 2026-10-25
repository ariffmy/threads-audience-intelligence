<?php

declare(strict_types=1);

use Tai\Bootstrap\QueueComposition;
use Tai\Modules\Queue\Application\WorkerBudget;
use Tai\Modules\Queue\Domain\LostLease;
use Tai\Modules\Threads\Domain\ProviderFailure;
use Tai\Modules\Threads\Domain\ProviderRequest;
use Tai\Modules\Threads\Infrastructure\FixtureTransport;
use Tai\Shared\Domain\UserFacingException;
use Tai\Shared\Http\HttpException;
use Tai\Shared\Infrastructure\Config;
use Tai\Shared\Infrastructure\Database;
use Tai\Shared\Infrastructure\Logger;
use TaiTests\Suite;
use function TaiTests\check;
use function TaiTests\raises;

return static function (Suite $suite, Config $config, PDO $pdo, string $directory, string $configFile, array $settings): void {
    check($config->get('app.env') === 'test' && preg_match('/^tai_test_[a-f0-9]{16}$/D', $config->get('database.name')) === 1);
    $marker = (string) $pdo->query('SELECT marker FROM _tai_test_marker')->fetchColumn(); check(strlen($marker) === 32);
    $keyring = $directory . '/threads-keyring.json';
    file_put_contents($keyring, json_encode(['current' => 'k1', 'keys' => ['k1' => bin2hex(random_bytes(32))]], JSON_THROW_ON_ERROR));
    $settings['threads'] = ['fixture_enabled' => true, 'keyring_path' => $keyring];
    $settings['privacy'] = ['ledger_path' => $directory . '/threads-independent-ledger', 'ledger_key' => bin2hex(random_bytes(32))];
    $threadsFile = $directory . '/threads-config.php';
    file_put_contents($threadsFile, '<?php return ' . var_export($settings, true) . ';');
    putenv('TAI_CONFIG_FILE=' . $threadsFile); $testConfig = Config::load($config->root);
    $new = static function (?FixtureTransport $fake = null) use ($pdo, $testConfig): array {
        $runtime = new QueueComposition($pdo, $testConfig, new Logger($testConfig->storage('logs')), $fake);
        $sql = $runtime->repository;
        $sql->execute('INSERT INTO organizations (name,slug) VALUES (?,?)', ['Threads fixture', 'threads-' . bin2hex(random_bytes(8))]);
        $org = (string) $sql->execute('SELECT LAST_INSERT_ID()')->fetchColumn();
        $sql->execute("INSERT INTO memberships (organization_id,user_id,role,status) VALUES (?,1,'owner','active')", [$org]);
        $session = bin2hex(random_bytes(24));
        $version = (int) $sql->execute('SELECT session_version FROM users WHERE id=1')->fetchColumn();
        $sql->execute('INSERT INTO auth_sessions (user_id,organization_id,session_id_hash,session_version,created_at,last_seen_at,absolute_expires_at)
            VALUES (1,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),TIMESTAMPADD(HOUR,12,UTC_TIMESTAMP(6)))', [$org, hash('sha256', $session, true), $version]);
        $identity = ['organization_id' => $org, 'user_id' => '1', 'auth_session_id' => (string) $sql->execute('SELECT LAST_INSERT_ID()')->fetchColumn()];
        return [$runtime, $identity, $session];
    };
    $publish = static function (QueueComposition $runtime, string $org): array {
        $claim = $runtime->queue->claim($org); check($claim !== null, 'Expected due Threads job.');
        $result = $runtime->threads->handler->prepare($claim, new WorkerBudget(25, 20, 96));
        $runtime->queue->complete($claim, fn () => $runtime->threads->handler->publish($claim, $result));
        return [$claim, $result];
    };
    $connect = static function (array $context) use ($publish): array {
        [$runtime, $identity, $session] = $context; $org = $identity['organization_id'];
        $state = $runtime->threads->accounts->begin($identity, $session);
        $runtime->threads->accounts->callback($identity, $session, 'state=' . $state . '&code=fixture-code', new WorkerBudget(25, 1, 96));
        for ($i = 0; $i < 3; $i++) $publish($runtime, $org);
        $id = (string) $runtime->repository->execute('SELECT id FROM threads_accounts WHERE organization_id=?', [$org])->fetchColumn();
        check($runtime->threads->repository->account($org, $id)['status'] === 'active', 'Fixture activation failed.');
        return [$runtime, $identity, $session, $id];
    };
    $import = static function (array $context, string $mode = 'posts', ?string $root = null) use ($publish): string {
        [$runtime, $identity, , $id] = $context;
        $run = $runtime->threads->collection->start($identity, $id, $mode, '2026-10-01 00:00:00', '2026-10-26 00:00:00', $root);
        $publish($runtime, $identity['organization_id']);
        return $run;
    };
    $sources = static fn (array $c): array => $c[0]->threads->repository->visible($c[1]['organization_id'], $c[3]);
    $rootId = static fn (array $c): string => (string) $c[0]->repository->execute("SELECT id FROM content_items WHERE organization_id=? AND threads_account_id=? AND kind='owned_post' LIMIT 1", [$c[1]['organization_id'], $c[3]])->fetchColumn();
    $runState = static fn (array $c, string $run): array => $c[0]->threads->repository->run($c[1]['organization_id'], $c[3], $run);
    try {
        $suite->test('Threads OAuth: valid state is session/tenant-bound, consumed once and encrypted pending', static function () use ($new, $publish): void {
            [$r, $identity, $session] = $new(); $org = $identity['organization_id'];
            $state = $r->threads->accounts->begin($identity, $session);
            $row = $r->repository->execute('SELECT * FROM threads_oauth_states WHERE organization_id=?', [$org])->fetch();
            check(strlen($row['state_hash']) === 32 && !str_contains(json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE), $state));
            $r->threads->accounts->callback($identity, $session, 'state=' . $state . '&code=fixture-code', new WorkerBudget(25, 1, 96));
            $a = $r->threads->repository->account($org, (string) $row['threads_account_id']); check($a['status'] === 'pending');
            check(!str_contains($r->threads->repository->credential($org, (string) $a['id'])['envelope'], 'fixture-short-token'));
            raises(fn () => $r->threads->accounts->callback($identity, $session, 'state=' . $state . '&code=fixture-code', new WorkerBudget(25, 1, 96)), UserFacingException::class);
            for ($i = 0; $i < 3; $i++) $publish($r, $org);
            check($r->threads->repository->account($org, (string) $a['id'])['status'] === 'active');
        });
        foreach (['missing', 'expired', 'session', 'tenant', 'redirect', 'generation', 'privacy', 'revoked session'] as $mode) {
            $suite->test('Threads OAuth rejects ' . $mode . ' callback context', static function () use ($new, $mode): void {
                [$r, $identity, $session] = $new(); $org = $identity['organization_id'];
                $state = $r->threads->accounts->begin($identity, $session);
                if ($mode === 'expired') $r->repository->execute('UPDATE threads_oauth_states SET expires_at=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE organization_id=?', [$org]);
                if ($mode === 'redirect') $r->repository->execute("UPDATE threads_oauth_states SET redirect_uri='https://evil.example/callback' WHERE organization_id=?", [$org]);
                if ($mode === 'generation') $r->repository->execute('UPDATE threads_accounts SET credential_generation=credential_generation+1 WHERE organization_id=?', [$org]);
                if ($mode === 'privacy') $r->repository->execute('UPDATE threads_accounts SET privacy_epoch=privacy_epoch+1 WHERE organization_id=?', [$org]);
                if ($mode === 'revoked session') $r->repository->execute('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE id=?', [$identity['auth_session_id']]);
                if ($mode === 'session') $session = 'wrong-session';
                if ($mode === 'tenant') { [, $identity] = $new(); }
                $query = $mode === 'missing' ? 'code=fixture-code' : 'state=' . $state . '&code=fixture-code';
                raises(fn () => $r->threads->accounts->callback($identity, $session, $query, new WorkerBudget(25, 1, 96)), $mode === 'revoked session' ? HttpException::class : UserFacingException::class);
                check((int) $r->repository->execute('SELECT COUNT(*) FROM threads_credentials WHERE organization_id=?', [$org])->fetchColumn() === 0);
            });
        }
        $suite->test('Threads reconnect rejects provider identity substitution without replacing identity', static function () use ($new, $connect, $testConfig, $pdo): void {
            $c = $connect($new()); [$r, $identity, $session, $id] = $c; $org = $identity['organization_id'];
            $fake = new FixtureTransport(static fn () => ['status' => 200, 'body' => '{"access_token":"fixture-token","token_type":"bearer","user_id":"900002"}']);
            $other = new QueueComposition($pdo, $testConfig, new Logger($testConfig->storage('logs')), $fake);
            $state = $other->threads->accounts->begin($identity, $session);
            raises(fn () => $other->threads->accounts->callback($identity, $session, 'state=' . $state . '&code=fixture-code', new WorkerBudget(25, 1, 96)), UserFacingException::class);
            $a = $r->threads->repository->account($org, $id);
            check($a['remote_user_id'] === '900001' && $a['status'] === 'reauth_required');
            check((int) $r->repository->execute('SELECT COUNT(*) FROM threads_credentials WHERE organization_id=?', [$org])->fetchColumn() === 0);
        });
        $suite->test('Threads rejects identity/grant validation before activation', static function () use ($new, $publish): void {
            $fake = new FixtureTransport(static function (ProviderRequest $request): array {
                if ($request->operation === 'debug') return ['status' => 200, 'body' => '{"data":{"is_valid":false,"message":"fixture-secret"}}'];
                return (new FixtureTransport())->send($request);
            });
            [$r, $identity, $session] = $new($fake); $org = $identity['organization_id'];
            $state = $r->threads->accounts->begin($identity, $session);
            $r->threads->accounts->callback($identity, $session, 'state=' . $state . '&code=fixture-code', new WorkerBudget(25, 1, 96));
            $publish($r, $org); $publish($r, $org);
            check($r->repository->execute('SELECT status FROM threads_accounts WHERE organization_id=?', [$org])->fetchColumn() === 'reauth_required');
        });
        $suite->test('Threads collection: idempotent owned roots, replies and overlapping conversation', static function () use ($new, $connect, $import, $sources, $rootId): void {
            $c = $connect($new()); $import($c); $import($c); $root = $rootId($c); check(count($sources($c)) === 1);
            $import($c, 'replies', $root); $import($c, 'conversation', $root);
            $items = $sources($c); check(count($items) === 3);
            check($items[2]['body'] === null && $items[2]['username'] === null);
            check((int) $c[0]->repository->execute('SELECT COUNT(*) FROM content_items WHERE organization_id=? AND author_user_id IS NOT NULL', [$c[1]['organization_id']])->fetchColumn() === 0);
        });
        $suite->test('Threads collection: arbitrary root, reply-as-root and cross-tenant references denied', static function () use ($new, $connect, $import, $rootId): void {
            $c = $connect($new()); $import($c); $root = $rootId($c); $import($c, 'replies', $root);
            [$r, $identity, , $id] = $c;
            raises(fn () => $r->threads->collection->start($identity, $id, 'replies', '2026-10-01 00:00:00', '2026-10-26 00:00:00', '999999'), DomainException::class);
            $reply = (string) $r->repository->execute("SELECT id FROM content_items WHERE organization_id=? AND kind='reply' LIMIT 1", [$identity['organization_id']])->fetchColumn();
            raises(fn () => $r->threads->collection->start($identity, $id, 'replies', '2026-10-01 00:00:00', '2026-10-26 00:00:00', $reply), UserFacingException::class);
            $other = $connect($new());
            raises(fn () => $r->threads->collection->start($other[1], $id, 'posts', '2026-10-01 00:00:00', '2026-10-26 00:00:00'), HttpException::class, 404);
            raises(fn () => $r->repository->execute('INSERT INTO threads_credentials (organization_id,threads_account_id,generation,kind,envelope,issued_at,expires_at) VALUES (?,?,1,\'long\',\'{}\',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))', [$other[1]['organization_id'], $id]), PDOException::class);
            raises(fn () => $r->threads->privacy->delete($other[1], $id), HttpException::class, 404);
        });
        $suite->test('Threads source edits version evidence and never extend first collection or expiry', static function () use ($new, $connect, $import, $rootId): void {
            $text = 'First synthetic text';
            $fake = new FixtureTransport(static function (ProviderRequest $q) use (&$text): array {
                if ($q->operation !== 'posts') return (new FixtureTransport())->send($q);
                return ['status' => 200, 'body' => json_encode(['data' => [['id' => '910001', 'owner' => ['id' => '900001'], 'text' => $text, 'timestamp' => '2026-10-25T00:00:00Z']]])];
            });
            $c = $connect($new($fake)); $import($c); [$r,$identity,,$id] = $c; $org = $identity['organization_id']; $root = $rootId($c);
            $before = $r->threads->repository->source($org,$id,$root); $text = 'Edited synthetic text'; $import($c);
            $after = $r->threads->repository->source($org,$id,$root);
            check((int) $after['source_version'] === (int) $before['source_version'] + 1 && $after['body'] === $text);
            check($after['first_collected_at'] === $before['first_collected_at'] && $after['expires_at'] === $before['expires_at']);
        });
        $suite->test('Threads evidence UI escapes provider text and never exposes encrypted credentials', static function () use ($new, $connect, $import, $testConfig): void {
            $fake = new FixtureTransport(static function (ProviderRequest $q): array {
                if ($q->operation !== 'posts') return (new FixtureTransport())->send($q);
                return ['status' => 200, 'body' => json_encode(['data' => [['id' => '910001', 'owner' => ['id' => '900001'], 'text' => '<script>syntheticAttack()</script> 👋', 'username' => '<img src=x>', 'timestamp' => '2026-10-25T00:00:00Z']]])];
            });
            $c = $connect($new($fake)); $import($c); [$r,$identity] = $c;
            $session = new \TaiTests\MemorySession();
            $controller = new \Tai\Modules\Threads\Http\ThreadsController($r->threads, $session, new \Tai\Modules\Identity\Application\Csrf($session), new \Tai\Shared\Http\View($testConfig->root . '/resources/views'), $testConfig);
            $page = $controller->page($identity + ['display_name' => 'Synthetic administrator']);
            check($page->status === 200 && str_contains($page->body, '&lt;script&gt;syntheticAttack()&lt;/script&gt;'));
            check(!str_contains($page->body, '<script>syntheticAttack()') && str_contains($page->body, '&lt;img src=x&gt;'));
            foreach (['fixture-long-token', 'fixture-short-token', 'envelope', 'ciphertext', 'client_secret'] as $secret) check(!str_contains($page->body, $secret));
        });
        $suite->test('Threads collection rejects mismatching owned-list evidence', static function () use ($new, $connect, $import, $sources, $runState): void {
            $fake = new FixtureTransport(static fn (ProviderRequest $q) => $q->operation === 'posts'
                ? ['status' => 200, 'body' => '{"data":[{"id":"123","owner":{"id":"999"},"timestamp":"2026-10-25T00:00:00Z"}]}'] : (new FixtureTransport())->send($q));
            $c = $connect($new($fake)); $run = $import($c);
            check($sources($c) === [] && $runState($c, $run)['status'] === 'partial');
        });
        $suite->test('Threads collection resumes cursor pages without fetching returned URLs and detects loops', static function () use ($new, $connect, $import, $publish, $runState, $sources): void {
            $fake = new FixtureTransport(static function (ProviderRequest $q): array {
                if ($q->operation !== 'posts') return (new FixtureTransport())->send($q);
                check($q->url === 'https://graph.threads.com/v1.0/me/threads');
                return ['status' => 200, 'body' => '{"data":[{"id":"910001","owner":{"id":"900001"},"timestamp":"2026-10-25T00:00:00Z"}],"paging":{"next":"https://evil.example/?access_token=fixture-secret","cursors":{"after":"fixture-cursor"}}}'];
            });
            $c = $connect($new($fake)); $run = $import($c); $state = $runState($c, $run);
            check($state['status'] === 'running' && $state['version'] === 2 && !str_contains($state['cursor_envelope'], 'fixture-cursor'));
            $publish($c[0], $c[1]['organization_id']); $state = $runState($c, $run);
            check($state['status'] === 'partial' && $state['safe_error_code'] === 'cursor_loop' && count($sources($c)) === 1);
        });
        $suite->test('Threads collection discloses local page cap and unknown-time exclusions', static function () use ($new, $connect, $import, $publish, $runState): void {
            $page = 0;
            $fake = new FixtureTransport(static function (ProviderRequest $q) use (&$page): array {
                if ($q->operation !== 'posts') return (new FixtureTransport())->send($q);
                $page++;
                return ['status' => 200, 'body' => json_encode(['data' => [['id' => '910001', 'owner' => ['id' => '900001']]], 'paging' => ['next' => 'ignored', 'cursors' => ['after' => 'cursor-' . $page]]])];
            });
            $c = $connect($new($fake)); $run = $import($c);
            for ($i = 1; $i < 10; $i++) $publish($c[0], $c[1]['organization_id']);
            $state = $runState($c, $run);
            check($state['status'] === 'partial' && (int) $state['pages'] === 10 && (int) $state['unknown_time'] === 10 && $state['safe_error_code'] === 'collection_cap');
        });
        $suite->test('Threads page writes, checkpoint and continuation roll back together', static function () use ($new, $connect, $runState, $sources): void {
            $fake = new FixtureTransport(static fn (ProviderRequest $q) => $q->operation === 'posts'
                ? ['status' => 200, 'body' => '{"data":[{"id":"910001","owner":{"id":"900001"},"timestamp":"2026-10-25T00:00:00Z"}],"paging":{"next":"ignored","cursors":{"after":"cursor"}}}'] : (new FixtureTransport())->send($q));
            $c = $connect($new($fake)); [$r, $identity, , $id] = $c; $org = $identity['organization_id'];
            $run = $r->threads->collection->start($identity, $id, 'posts', '2026-10-01 00:00:00', '2026-10-26 00:00:00');
            $claim = $r->queue->claim($org); $result = $r->threads->handler->prepare($claim, new WorkerBudget(25, 20, 96));
            raises(fn () => $r->queue->complete($claim, static function () use ($r, $claim, $result): void { $r->threads->handler->publish($claim, $result); throw new RuntimeException('synthetic interruption'); }), RuntimeException::class);
            check($sources($c) === [] && (int) $runState($c, $run)['version'] === 1);
            check((int) $r->repository->execute('SELECT COUNT(*) FROM jobs WHERE organization_id=? AND job_type=\'threads.posts\'', [$org])->fetchColumn() === 1);
            $r->queue->complete($claim, fn () => $r->threads->handler->publish($claim, $result));
            check(count($sources($c)) === 1 && (int) $runState($c, $run)['version'] === 2);
        });
        $suite->test('Threads deletion inside the fake request holds no DB transaction and cannot resurrect content', static function () use ($new, $connect, $import, $rootId, $pdo): void {
            $delete = null;
            $fake = new FixtureTransport(static function (ProviderRequest $q) use (&$delete, $pdo): array {
                check(!$pdo->inTransaction(), 'A provider request held an open database transaction.');
                if ($q->operation === 'replies') $delete();
                return (new FixtureTransport())->send($q);
            });
            $c = $connect($new($fake)); $import($c); [$r, $identity, , $id] = $c; $org = $identity['organization_id'];
            $delete = static fn () => $r->threads->privacy->delete($identity, $id);
            $r->threads->collection->start($identity, $id, 'replies', '2026-10-01 00:00:00', '2026-10-26 00:00:00', $rootId($c));
            $claim = $r->queue->claim($org); $result = $r->threads->handler->prepare($claim, new WorkerBudget(25, 1, 96));
            raises(fn () => $r->queue->complete($claim, fn () => $r->threads->handler->publish($claim, $result)), LostLease::class);
            check((int) $r->repository->execute("SELECT COUNT(*) FROM content_items WHERE organization_id=? AND kind='reply'", [$org])->fetchColumn() === 0);
        });
        foreach (['privacy', 'credential', 'grant', 'lease', 'root edit', 'disconnect', 'source deletion'] as $race) {
            $suite->test('Threads late response rejected after ' . $race, static function () use ($new, $connect, $import, $rootId, $sources, $race): void {
                $c = $connect($new()); $import($c); [$r, $identity, , $id] = $c; $org = $identity['organization_id']; $root = $rootId($c);
                $r->threads->collection->start($identity, $id, 'replies', '2026-10-01 00:00:00', '2026-10-26 00:00:00', $root);
                $claim = $r->queue->claim($org); $result = $r->threads->handler->prepare($claim, new WorkerBudget(25, 20, 96));
                if ($race === 'privacy') $r->repository->execute('UPDATE threads_accounts SET privacy_epoch=privacy_epoch+1 WHERE organization_id=?', [$org]);
                if ($race === 'credential') $r->repository->execute('UPDATE threads_accounts SET credential_generation=credential_generation+1 WHERE organization_id=?', [$org]);
                if ($race === 'grant') $r->repository->execute('UPDATE threads_accounts SET grant_revision=grant_revision+1 WHERE organization_id=?', [$org]);
                if ($race === 'lease') $r->repository->execute('UPDATE jobs SET lease_expires_at=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE organization_id=? AND id=?', [$org, $claim->id]);
                if ($race === 'root edit') $r->repository->execute('UPDATE content_items SET source_version=source_version+1 WHERE organization_id=? AND id=?', [$org, $root]);
                if ($race === 'disconnect') $r->threads->privacy->delete($identity, $id);
                if ($race === 'source deletion') $r->threads->privacy->delete($identity, $id, $root);
                raises(fn () => $r->queue->complete($claim, fn () => $r->threads->handler->publish($claim, $result)), LostLease::class);
                check((int) $r->repository->execute("SELECT COUNT(*) FROM content_items WHERE organization_id=? AND kind='reply'", [$org])->fetchColumn() === 0);
                if (in_array($race, ['disconnect', 'source deletion'], true)) check($sources($c) === []);
            });
        }
        $suite->test('Threads request preflight defers without consuming delivery/provider attempts', static function () use ($new, $connect): void {
            $c = $connect($new()); [$r, $identity, , $id] = $c; $org = $identity['organization_id'];
            $r->threads->collection->start($identity, $id, 'posts', '2026-10-01 00:00:00', '2026-10-26 00:00:00');
            $count = (int) $r->repository->execute('SELECT COUNT(*) FROM threads_request_reservations WHERE organization_id=?', [$org])->fetchColumn();
            $r->repository->execute('UPDATE threads_accounts SET next_allowed_at=TIMESTAMPADD(MINUTE,1,UTC_TIMESTAMP(6)) WHERE organization_id=?', [$org]);
            check($r->queue->claim($org) === null);
            check((int) $r->repository->execute("SELECT attempts FROM jobs WHERE organization_id=? AND job_type='threads.posts'", [$org])->fetchColumn() === 0);
            check((int) $r->repository->execute('SELECT COUNT(*) FROM threads_request_reservations WHERE organization_id=?', [$org])->fetchColumn() === $count);
        });
        $suite->test('Threads transient throttle releases reservation and retries through finite queue backoff', static function () use ($new, $connect): void {
            $fake = new FixtureTransport(static fn (ProviderRequest $q) => $q->operation === 'posts' ? ['status' => 429, 'body' => 'sensitive'] : (new FixtureTransport())->send($q));
            $c = $connect($new($fake)); [$r, $identity, , $id] = $c; $org = $identity['organization_id'];
            $r->threads->collection->start($identity, $id, 'posts', '2026-10-01 00:00:00', '2026-10-26 00:00:00');
            $report = $r->worker->run($org, new WorkerBudget(25, 20, 96));
            $job = $r->repository->execute("SELECT * FROM jobs WHERE organization_id=? AND job_type='threads.posts'", [$org])->fetch();
            check($job['status'] === 'retry_wait' && $job['safe_error_code'] === 'provider_throttled' && $job['attempts'] === 1);
            check($r->threads->repository->account($org, $id)['request_key'] === null && $report['processed'] >= 1);
        });
        $suite->test('Threads refresh rejects young/expired token and expired permission independently', static function () use ($new, $connect): void {
            $c = $connect($new()); [$r, $identity, , $id] = $c; $org = $identity['organization_id'];
            raises(fn () => $r->threads->accounts->refresh($identity, $id), UserFacingException::class);
            $r->repository->execute('UPDATE threads_credentials SET issued_at=TIMESTAMPADD(DAY,-2,UTC_TIMESTAMP(6)),expires_at=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE organization_id=?', [$org]);
            raises(fn () => $r->threads->accounts->refresh($identity, $id), UserFacingException::class);
            $r->repository->execute('UPDATE threads_credentials SET expires_at=TIMESTAMPADD(DAY,10,UTC_TIMESTAMP(6)) WHERE organization_id=?', [$org]);
            $r->repository->execute('UPDATE threads_accounts SET permission_expires_at=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE organization_id=?', [$org]);
            raises(fn () => $r->threads->accounts->refresh($identity, $id), UserFacingException::class);
            check($r->threads->repository->visible($org, $id) === []);
        });
        $suite->test('Threads concurrent refresh reservation and stale credential publication are fenced', static function () use ($new, $connect, $testConfig): void {
            $c = $connect($new()); [$r, $identity, , $id] = $c; $org = $identity['organization_id'];
            $r->repository->execute('UPDATE threads_credentials SET issued_at=TIMESTAMPADD(DAY,-2,UTC_TIMESTAMP(6)) WHERE organization_id=?', [$org]);
            $r->threads->accounts->refresh($identity, $id);
            $r->queue->dispatch($org, 'threads.token', ['account' => $id, 'step' => 'refresh'], 'separate-refresh-key', 80);
            $claim = $r->queue->claim($org);
            $pdo2 = (new Database($testConfig))->connection(); $other = new QueueComposition($pdo2, $testConfig, new Logger($testConfig->storage('logs')));
            check($other->queue->claim($org) === null); // The slot is already reserved before preparation starts.
            check((int) $r->repository->execute("SELECT COUNT(*) FROM threads_request_reservations WHERE organization_id=? AND operation='refresh'", [$org])->fetchColumn() === 0);
            $result = $r->threads->handler->prepare($claim, new WorkerBudget(25, 20, 96));
            check($other->queue->claim($org) === null);
            $r->threads->accounts->rotate($identity, $id);
            raises(fn () => $r->queue->complete($claim, fn () => $r->threads->handler->publish($claim, $result)), LostLease::class);
        });
        $suite->test('Threads successful refresh advances generation and revalidates before active', static function () use ($new, $connect, $publish): void {
            $c = $connect($new()); [$r, $identity, , $id] = $c; $org = $identity['organization_id'];
            $old = $r->threads->repository->account($org, $id);
            $r->repository->execute('UPDATE threads_credentials SET issued_at=TIMESTAMPADD(DAY,-2,UTC_TIMESTAMP(6)) WHERE organization_id=?', [$org]);
            $r->threads->accounts->refresh($identity, $id); $publish($r, $org);
            $a = $r->threads->repository->account($org, $id);
            check($a['status'] === 'pending' && (int) $a['credential_generation'] === (int) $old['credential_generation'] + 1);
            $publish($r, $org); $publish($r, $org);
            check($r->threads->repository->active($r->threads->repository->account($org, $id)));
        });
        $suite->test('Threads incremental owned posts uses the exhausted window with bounded overlap', static function () use ($new, $connect, $import, $runState): void {
            $c = $connect($new()); $import($c); [$r, $identity, , $id] = $c;
            $run = $r->threads->collection->incremental($identity, $id, '2026-10-27 00:00:00');
            check($runState($c, $run)['window_start'] === '2026-10-25 23:00:00.000000');
            check($runState($c, $run)['window_end'] === '2026-10-27 00:00:00.000000');
        });
        $suite->test('Threads local fixture revocation blocks reads, credentials and queued work', static function () use ($new, $connect, $import, $sources): void {
            $c = $connect($new()); $import($c); [$r, $identity, , $id] = $c; $org = $identity['organization_id'];
            $r->threads->privacy->revoke($identity, $id);
            check($r->threads->repository->account($org, $id)['status'] === 'revoked' && $sources($c) === []);
            check((int) $r->repository->execute('SELECT COUNT(*) FROM threads_credentials WHERE organization_id=?', [$org])->fetchColumn() === 0);
        });
        foreach (['claim', 'prepared', 'publication'] as $mode) {
            $suite->test('Threads killed process recovers atomic ingestion after ' . $mode, static function () use ($new, $connect, $sources, $runState, $mode, $testConfig, $config, $marker): void {
                $c = $connect($new()); [$r, $identity, , $id] = $c; $org = $identity['organization_id'];
                $run = $r->threads->collection->start($identity, $id, 'posts', '2026-10-01 00:00:00', '2026-10-26 00:00:00');
                $probe = bin2hex(random_bytes(8));
                $process = proc_open([PHP_BINARY, __DIR__ . '/threads-worker.php', $mode, $org, $probe, $marker], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $config->root, null, ['bypass_shell' => true, 'create_no_window' => true]);
                check(is_resource($process));
                $ready = $testConfig->storage('locks') . '/' . $probe . '.threads-ready'; $deadline = hrtime(true) + 4_000_000_000;
                while (!is_file($ready) && hrtime(true) < $deadline && proc_get_status($process)['running']) { usleep(10000); clearstatcache(true, $ready); }
                $reached = is_file($ready);
                proc_terminate($process); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
                check($reached, 'Fault probe did not reach barrier.');
                check($sources($c) === [] && (int) $runState($c, $run)['version'] === 1);
                $r->repository->execute('UPDATE jobs SET lease_expires_at=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE organization_id=? AND status=\'running\'', [$org]);
                $r->repository->execute('UPDATE threads_accounts SET request_until=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE organization_id=?', [$org]);
                check($r->queue->recover($org) === 1);
                $r->repository->execute("UPDATE jobs SET available_at=UTC_TIMESTAMP(6) WHERE organization_id=? AND status='retry_wait'", [$org]);
                $claim = $r->queue->claim($org); check($claim !== null && $claim->generation === 2);
                $result = $r->threads->handler->prepare($claim, new WorkerBudget(25, 1, 96));
                $r->queue->complete($claim, fn () => $r->threads->handler->publish($claim, $result));
                check(count($sources($c)) === 1 && (int) $runState($c, $run)['version'] === 2);
            });
        }
        $suite->test('Threads separate worker processes reserve only one concurrent refresh', static function () use ($new, $connect, $testConfig, $config, $marker): void {
            $c = $connect($new()); [$r, $identity, , $id] = $c; $org = $identity['organization_id'];
            $r->repository->execute('UPDATE threads_credentials SET issued_at=TIMESTAMPADD(DAY,-2,UTC_TIMESTAMP(6)) WHERE organization_id=?', [$org]);
            $r->threads->accounts->refresh($identity, $id);
            $r->queue->dispatch($org, 'threads.token', ['account' => $id, 'step' => 'refresh'], 'competing-refresh-process', 80);
            $probe = bin2hex(random_bytes(8));
            $process = proc_open([PHP_BINARY, __DIR__ . '/threads-worker.php', 'prepared', $org, $probe, $marker], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $config->root, null, ['bypass_shell' => true, 'create_no_window' => true]);
            $ready = $testConfig->storage('locks') . '/' . $probe . '.threads-ready'; $deadline = hrtime(true) + 4_000_000_000;
            try {
                while (!is_file($ready) && hrtime(true) < $deadline && proc_get_status($process)['running']) { usleep(10000); clearstatcache(true, $ready); }
                check(is_file($ready));
                $other = proc_open([PHP_BINARY, __DIR__ . '/threads-worker.php', 'complete', $org, bin2hex(random_bytes(8)), $marker], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $otherPipes, $config->root, null, ['bypass_shell' => true, 'create_no_window' => true]);
                $output = stream_get_contents($otherPipes[1]); $error = stream_get_contents($otherPipes[2]); fclose($otherPipes[1]); fclose($otherPipes[2]);
                check(proc_close($other) === 0 && json_decode($output, true)['claimed'] === false, 'Competing refresh was not deferred: ' . $error);
                touch($testConfig->storage('locks') . '/' . $probe . '.threads-go');
                $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                check(proc_close($process) === 0 && json_decode($output, true)['claimed'] === true, 'First refresh failed: ' . $error); $process = null;
                check((int) $r->repository->execute("SELECT COUNT(*) FROM threads_request_reservations WHERE organization_id=? AND operation='refresh'", [$org])->fetchColumn() === 1);
            } finally { if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); } }
        });
        $suite->test('Threads token key rotation republishes only under the new key and generation', static function () use ($new, $connect, $keyring): void {
            $c = $connect($new()); [$r, $identity, , $id] = $c; $org = $identity['organization_id'];
            $original = file_get_contents($keyring); $ring = json_decode($original, true);
            $ring['keys']['k2'] = bin2hex(random_bytes(32)); $ring['current'] = 'k2'; file_put_contents($keyring, json_encode($ring));
            try {
                $before = $r->threads->repository->credential($org, $id); $r->threads->accounts->rotate($identity, $id);
                $after = $r->threads->repository->credential($org, $id);
                check(json_decode($after['envelope'], true)['key'] === 'k2' && (int) $after['generation'] === (int) $before['generation'] + 1);
                check($after['issued_at'] === $before['issued_at'] && $after['expires_at'] === $before['expires_at']);
            } finally { file_put_contents($keyring, $original); }
        });
        $suite->test('Threads retention and disconnect purge dependencies, retain suppression and isolate tenants', static function () use ($new, $connect, $import, $rootId, $sources): void {
            $c = $connect($new()); $import($c); $import($c, 'conversation', $rootId($c));
            $other = $connect($new()); $import($other);
            [$r, $identity, , $id] = $c; $org = $identity['organization_id'];
            $r->threads->privacy->delete($identity, $id); check($sources($c) === []);
            $r->worker->run($org, new WorkerBudget(25, 20, 96));
            foreach (['content_items', 'threads_credentials', 'threads_account_grants', 'threads_sync_runs', 'threads_sync_checkpoints', 'threads_request_reservations', 'threads_oauth_states'] as $table) {
                check((int) $r->repository->execute('SELECT COUNT(*) FROM ' . $table . ' WHERE organization_id=?', [$org])->fetchColumn() === 0, 'Unpurged: ' . $table);
            }
            check(count($sources($other)) === 1);
            check($r->repository->execute('SELECT status FROM threads_deletions WHERE organization_id=?', [$org])->fetchColumn() === 'primary_complete');
            raises(fn () => $r->threads->accounts->begin($identity, 'new-session'), UserFacingException::class);
        });
        $suite->test('Threads source deletion purges more than 100 children in bounded chunks', static function () use ($new, $connect, $import, $rootId, $sources): void {
            $c = $connect($new()); $import($c); $import($c, 'conversation', $rootId($c));
            [$r, $identity, , $id] = $c; $org = $identity['organization_id']; $root = $rootId($c);
            for ($i = 0; $i < 103; $i++) {
                $r->repository->execute("INSERT INTO content_items (organization_id,threads_account_id,remote_media_id,kind,root_id,content_hash,suppression_hash,provenance_json,expires_at)
                    VALUES (?,?,?,'reply',?,?,?,'{}',TIMESTAMPADD(DAY,1,UTC_TIMESTAMP(6)))", [$org, $id, (string) (930000 + $i), $root, random_bytes(32), bin2hex(random_bytes(32))]);
            }
            $r->threads->privacy->delete($identity, $id, $root); check($sources($c) === []);
            $r->worker->run($org, new WorkerBudget(25, 1, 96));
            check((int) $r->repository->execute('SELECT COUNT(*) FROM content_items WHERE organization_id=?', [$org])->fetchColumn() > 0);
            check($r->repository->execute('SELECT status FROM threads_deletions WHERE organization_id=?', [$org])->fetchColumn() === 'blocked');
            $r->worker->run($org, new WorkerBudget(25, 20, 96));
            check((int) $r->repository->execute('SELECT COUNT(*) FROM content_items WHERE organization_id=?', [$org])->fetchColumn() === 0);
            check($r->repository->execute('SELECT status FROM threads_deletions WHERE organization_id=?', [$org])->fetchColumn() === 'primary_complete');
        });
        $suite->test('Threads independent ledger rejects tampering and extra content fields', static function () use ($new, $connect, $testConfig): void {
            $c = $connect($new()); [$r, $identity, , $id] = $c; $org = $identity['organization_id'];
            $r->threads->privacy->delete($identity, $id);
            $path = $testConfig->get('privacy.ledger_path') . '/threads-deletions.jsonl'; $original = file_get_contents($path);
            try {
                $lines = explode("\n", trim($original)); $record = json_decode($lines[0], true); $record['body'] .= 'tampered'; $lines[0] = json_encode($record);
                file_put_contents($path, implode("\n", $lines) . "\n");
                raises(fn () => $r->threads->privacy->ledger->replay(static fn () => null), RuntimeException::class);
            } finally { file_put_contents($path, $original); }
            $a = $r->threads->repository->account($org, $id);
            raises(fn () => $r->threads->privacy->ledger->appendTarget(['organization_id' => $org, 'request_key' => bin2hex(random_bytes(16)), 'scope_key' => $a['scope_key'], 'source_hash' => str_repeat('0',64), 'target_kind' => 'threads_account', 'body' => 'forbidden']), InvalidArgumentException::class);
        });
        $suite->test('Threads source expiry blocks reads immediately and retention ledger suppresses reimport', static function () use ($new, $connect, $import, $sources): void {
            $c = $connect($new()); $import($c); [$r, $identity, , $id] = $c; $org = $identity['organization_id'];
            $r->repository->execute('UPDATE content_items SET expires_at=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE organization_id=?', [$org]);
            check($sources($c) === []);
            $r->queue->dispatch($org, 'retention.sweep', [], 'test-retention'); $r->worker->run($org, new WorkerBudget(25, 20, 96));
            check((int) $r->repository->execute('SELECT COUNT(*) FROM content_items WHERE organization_id=?', [$org])->fetchColumn() === 0);
            $import($c); check($sources($c) === []);
            check((int) $r->repository->execute('SELECT COUNT(*) FROM threads_deletions WHERE organization_id=?', [$org])->fetchColumn() === 1);
        });
        $suite->test('Threads independent ledger replay blocks restored account/source data and resumes by offset', static function () use ($new, $connect, $import, $sources): void {
            $c = $connect($new()); $import($c); [$r, $identity, , $id] = $c; $org = $identity['organization_id'];
            $r->threads->privacy->delete($identity, $id);
            // Simulate restoration of a pre-deletion database subset while keeping the independent ledger.
            $r->repository->execute('DELETE FROM threads_deletions WHERE organization_id=?', [$org]);
            $r->repository->execute("UPDATE threads_accounts SET status='active',blocked_at=NULL WHERE organization_id=?", [$org]);
            $r->repository->execute('UPDATE content_items SET blocked_at=NULL WHERE organization_id=?', [$org]);
            $offset = 0;
            do { $result = $r->threads->privacy->ledger->replay($r->threads->privacy->apply(...), $offset); $offset = $result['offset']; } while (!$result['done']);
            check($sources($c) === [] && $r->threads->repository->account($org, $id)['blocked_at'] !== null);
            check($r->threads->privacy->ledger->replay($r->threads->privacy->apply(...), $offset)['records'] === 0);
        });
        $suite->test('Threads organization deletion remains suppressed after an absent-account restore', static function () use ($new, $connect): void {
            $c = $connect($new()); [$r, $identity, , $id] = $c; $org = $identity['organization_id'];
            $r->threads->privacy->delete($identity, $id, null, true);
            $r->worker->run($org, new WorkerBudget(25, 20, 96));
            $r->repository->execute('DELETE FROM threads_accounts WHERE organization_id=? AND id=?', [$org,$id]);
            check((int) $r->repository->execute("SELECT COUNT(*) FROM threads_deletions WHERE organization_id=? AND target_kind='threads_organization'", [$org])->fetchColumn() === 1);
            raises(fn () => $r->threads->accounts->begin($identity, 'restored-session'), UserFacingException::class);
            $absent = ['organization_id' => '999999999999999999', 'request_key' => bin2hex(random_bytes(16)), 'target_kind' => 'threads_organization', 'scope_key' => bin2hex(random_bytes(16)), 'source_hash' => str_repeat('0',64)];
            $r->threads->privacy->apply($absent);
            check((int) $r->repository->execute('SELECT COUNT(*) FROM threads_deletions WHERE organization_id=?', [$absent['organization_id']])->fetchColumn() === 1);
        });
        $suite->test('Threads production environment refuses fixture activation even with enabled flag', static function () use ($settings, $directory, $config, $pdo): void {
            $production = $settings; $production['app']['env'] = 'production'; $production['app']['url'] = 'https://localhost';
            $file = $directory . '/threads-production-denial.php'; file_put_contents($file, '<?php return ' . var_export($production, true) . ';');
            putenv('TAI_CONFIG_FILE=' . $file);
            try { $cfg = Config::load($config->root); $r = new QueueComposition($pdo, $cfg, new Logger($cfg->storage('logs')));
                raises(fn () => $r->threads->accounts->assertFixtureEnabled(), UserFacingException::class);
                raises(fn () => $r->threads->client->lifecycle('profile', 'fixture-token', 1000), ProviderFailure::class);
            } finally { putenv('TAI_CONFIG_FILE=' . $directory . '/threads-config.php'); }
        });
    } finally {
        putenv('TAI_CONFIG_FILE=' . $configFile);
    }
};
