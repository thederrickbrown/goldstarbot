<?php
declare(strict_types=1);

namespace App\Application\Actions;

use App\Support\AccountService;
use App\Support\ActivityService;
use App\Support\AdminSession;
use App\Support\TeamService;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * "Add to Slack" OAuth v2 install flow.
 *
 *   /slack/oauth/start    (logged-in customer) -> redirect to Slack authorize
 *   /slack/oauth/callback (Slack)              -> exchange code, store the
 *       workspace + bot token, link it to the account, auto-enable if the
 *       subscription is active/trialing.
 */
final class SlackOAuthAction
{
    private const REDIRECT_URI = 'https://goldstarbot.com/slack/oauth/callback';
    private const SCOPES = 'commands,chat:write,chat:write.public,users:read';

    /** @var array<string,mixed> */
    private array $slack;
    private TeamService $teams;
    private AccountService $accounts;
    private ActivityService $activity;
    private LoggerInterface $logger;
    private Client $http;

    /** @param array<string,mixed> $slackConfig */
    public function __construct(
        array $slackConfig,
        TeamService $teams,
        AccountService $accounts,
        ActivityService $activity,
        LoggerInterface $logger
    ) {
        $this->slack = $slackConfig;
        $this->teams = $teams;
        $this->accounts = $accounts;
        $this->activity = $activity;
        $this->logger = $logger;
        $this->http = new Client(['timeout' => 8.0]);
    }

    public function start(Request $request, Response $response): Response
    {
        AdminSession::start();
        if (!AdminSession::isAuthed()) {
            return $response->withHeader('Location', '/admin/login')->withStatus(302);
        }

        $clientId = (string) ($this->slack['client_id'] ?? '');
        if ($clientId === '') {
            $this->logger->error('SlackOAuth: client_id not configured');
            return $response->withHeader('Location', '/admin?ok=' . rawurlencode('Slack connect isn’t configured yet.'))->withStatus(302);
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;

        $url = 'https://slack.com/oauth/v2/authorize?' . http_build_query([
            'client_id'    => $clientId,
            'scope'        => self::SCOPES,
            'redirect_uri' => self::REDIRECT_URI,
            'state'        => $state,
        ]);

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function callback(Request $request, Response $response): Response
    {
        AdminSession::start();
        $q = $request->getQueryParams();

        if (!empty($q['error'])) {
            return $this->back($response, 'Slack install was canceled.');
        }

        $code  = (string) ($q['code'] ?? '');
        $state = (string) ($q['state'] ?? '');
        $expected = (string) ($_SESSION['oauth_state'] ?? '');
        unset($_SESSION['oauth_state']);

        if ($code === '' || $state === '' || !hash_equals($expected, $state)) {
            return $this->back($response, 'Slack install failed (bad state). Please try again.');
        }

        $data = $this->exchangeCode($code);
        if ($data === null || empty($data['ok'])) {
            $this->logger->error('SlackOAuth: token exchange failed', ['response' => $data]);
            return $this->back($response, 'Couldn’t complete the Slack install. Please try again.');
        }

        $teamId    = (string) ($data['team']['id'] ?? '');
        $teamName  = (string) ($data['team']['name'] ?? '');
        $botToken  = (string) ($data['access_token'] ?? '');
        $botUserId = (string) ($data['bot_user_id'] ?? '');

        if ($teamId === '' || $botToken === '') {
            return $this->back($response, 'Slack didn’t return the expected install details.');
        }

        $this->teams->install($teamId, $teamName, $botToken, $botUserId);

        $accId = (int) ($_SESSION['gsb_uid'] ?? 0);
        if ($accId > 0) {
            $this->accounts->linkTeam($accId, $teamId);
            $_SESSION['gsb_team'] = $teamId;

            $acc = $this->accounts->findById($accId);
            $subStatus = $acc !== null ? (string) ($acc['sub_status'] ?? '') : '';
            if (in_array($subStatus, ['trialing', 'active'], true)) {
                $this->teams->setStatus($teamId, TeamService::STATUS_ACTIVE);
            }
        }

        $this->activity->log(
            ActivityService::T_WORKSPACE,
            'Connected to Slack: ' . ($teamName !== '' ? $teamName : $teamId),
            $teamId,
            AdminSession::email()
        );

        return $this->back($response, 'Connected ' . ($teamName !== '' ? $teamName : 'your workspace') . '! 🎉');
    }

    // ---------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function exchangeCode(string $code): ?array
    {
        try {
            $res = $this->http->post('https://slack.com/api/oauth.v2.access', [
                'form_params' => [
                    'client_id'     => (string) ($this->slack['client_id'] ?? ''),
                    'client_secret' => (string) ($this->slack['client_secret'] ?? ''),
                    'code'          => $code,
                    'redirect_uri'  => self::REDIRECT_URI,
                ],
            ]);
            $decoded = json_decode((string) $res->getBody(), true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            $this->logger->error('SlackOAuth: exchange exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function back(Response $response, string $msg): Response
    {
        return $response->withHeader('Location', '/admin?ok=' . rawurlencode($msg))->withStatus(302);
    }
}
