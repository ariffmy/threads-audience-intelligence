<?php

declare(strict_types=1);

namespace Tai\Modules\Threads\Http;

use Tai\Bootstrap\ThreadsComposition;
use Tai\Modules\Identity\Application\Csrf;
use Tai\Modules\Identity\Domain\SessionStore;
use Tai\Modules\Queue\Application\WorkerBudget;
use Tai\Modules\Queue\Domain\JobPayload;
use Tai\Shared\Domain\UserFacingException;
use Tai\Shared\Http\Request;
use Tai\Shared\Http\Response;
use Tai\Shared\Http\View;
use Tai\Shared\Infrastructure\Config;

final readonly class ThreadsController
{
    public function __construct(private ThreadsComposition $threads, private SessionStore $session, private Csrf $csrf, private View $view, private Config $config)
    {
    }

    public function page(array $identity): Response
    {
        $data = $this->threads->repository->overview($identity);
        return $this->view->render('threads', ['title' => 'Threads account', 'identity' => $identity, 'csrf' => $this->csrf->token(),
            'environment' => $this->config->get('app.env'),
            'enabled' => $this->config->get('app.env') !== 'production' && $this->config->get('threads.fixture_enabled') === true] + $data);
    }

    public function connect(array $identity): Response
    {
        $state = $this->threads->accounts->begin($identity, $this->session->id());
        $sessionId = $this->session->id();
        // Simulate provider consent inside this fixture POST. No browser navigation to Meta or secret query logging.
        $this->session->close();
        $this->threads->accounts->callback($identity, $sessionId, 'state=' . $state . '&code=fixture-code', new WorkerBudget(25, 1, 96));
        return Response::redirect('/threads');
    }

    public function callback(Request $request, array $identity): Response
    {
        $id = $this->session->id();
        $this->session->close();
        try {
            $this->threads->accounts->callback($identity, $id, $request->queryString, new WorkerBudget(25, 1, 96));
        } catch (\Throwable) {
            // All callback outcomes leave the credential-bearing query immediately, without assets or raw errors.
            return Response::redirect('/threads?authorization=failed');
        }
        return Response::redirect('/threads');
    }

    public function mutate(Request $request, array $identity, string $action): Response
    {
        $this->threads->accounts->assertFixtureEnabled();
        $account = $request->string('account', 18);
        JobPayload::identifier($account);
        match ($action) {
            'disconnect' => $this->threads->privacy->delete($identity, $account),
            'deleteSource' => $this->threads->privacy->delete($identity, $account, $request->string('source', 18)),
            'refresh' => $this->threads->accounts->refresh($identity, $account),
            'rotate' => $this->threads->accounts->rotate($identity, $account),
            'import' => $this->threads->collection->start($identity, $account, $request->string('mode', 16),
                $request->string('window_start', 19), $request->string('window_end', 19), ($root = $request->string('root', 18)) === '' ? null : $root),
            'incremental' => $this->threads->collection->incremental($identity, $account, $request->string('window_end', 19)),
            default => throw new UserFacingException('Unsupported Threads action.'),
        };
        return Response::redirect('/threads');
    }
}
