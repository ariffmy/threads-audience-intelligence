<?php

declare(strict_types=1);

namespace Tai\Modules\Operations\Http;

use Tai\Modules\Identity\Application\AuthenticationService;
use Tai\Modules\Identity\Application\Csrf;
use Tai\Modules\Identity\Domain\SessionStore;
use Tai\Modules\Operations\Infrastructure\SystemStatus;
use Tai\Modules\Operations\Infrastructure\OperationsRepository;
use Tai\Shared\Http\HttpException;
use Tai\Shared\Http\Request;
use Tai\Shared\Http\Response;
use Tai\Shared\Http\View;
use Tai\Shared\Infrastructure\Config;

final class AdminController
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly Csrf $csrf,
        private readonly SessionStore $session,
        private readonly View $view,
        private readonly Config $config,
        private readonly SystemStatus $status,
        private readonly OperationsRepository $repository,
    ) {
    }

    public function dashboard(array $identity): Response
    {
        return $this->page('dashboard', 'Dashboard', $identity, ['events' => $this->repository->recentEvents((string) $identity['organization_id'])]);
    }

    public function profile(array $identity): Response
    {
        return $this->page('profile', 'Account & profile', $identity);
    }

    public function saveProfile(Request $request, array $identity): Response
    {
        $this->auth->updateProfile($identity, $request->string('display_name', 640));
        $this->session->put('_flash', 'Your profile has been updated.');
        return Response::redirect('/profile');
    }

    public function password(Request $request, array $identity): Response
    {
        $new = $request->string('new_password', 72);
        if (!hash_equals($new, $request->string('confirm_password', 72))) {
            throw new HttpException(422, 'The new password confirmation does not match.');
        }
        $this->auth->changePassword($identity, $request->string('current_password', 72), $new);
        return Response::redirect('/login');
    }

    public function settings(array $identity): Response
    {
        return $this->page('settings', 'Application settings', $identity, ['timezones' => \DateTimeZone::listIdentifiers()]);
    }

    public function saveSettings(Request $request, array $identity): Response
    {
        $this->auth->updateTimezone($identity, $request->string('timezone', 64));
        $this->session->put('_flash', 'Display timezone saved.');
        return Response::redirect('/settings');
    }

    public function system(array $identity): Response
    {
        return $this->page('status', 'System status', $identity, ['statusData' => $this->status->inspect((string) $identity['organization_id'])]);
    }

    public function systemJson(array $identity): Response
    {
        return Response::json($this->status->inspect((string) $identity['organization_id']));
    }

    private function page(string $template, string $title, array $identity, array $data = []): Response
    {
        $flash = $this->session->get('_flash');
        $this->session->remove('_flash');
        return $this->view->render($template, $data + [
            'title' => $title, 'identity' => $identity, 'csrf' => $this->csrf->token(),
            'flash' => is_string($flash) ? $flash : null,
            'environment' => $this->config->get('app.env'),
            'canonicalUrl' => $this->config->get('app.url'),
            'idleMinutes' => intdiv($this->config->get('session.idle_seconds'), 60),
            'absoluteHours' => intdiv($this->config->get('session.absolute_seconds'), 3600),
            'auditDays' => $this->config->get('security.audit_days'),
            'logDays' => $this->config->get('security.log_days'),
        ]);
    }
}
