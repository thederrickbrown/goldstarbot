<?php
declare(strict_types=1);

namespace App\Application\Actions\Admin;

use App\Support\AccountService;
use App\Support\ActivityService;
use App\Support\AdminSession;
use App\Support\Mailer;
use App\Support\SettingsService;
use App\Support\StripeService;
use App\Support\TeamService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * The GoldStarBot admin at /admin — approve/block/add Slack workspaces.
 */
final class AdminController
{
    /** The Slack app manifest shown on the setup page. */
    private const MANIFEST = <<<'YAML'
display_information:
  name: GoldStar
  description: Give teammates gold stars for great work.
  background_color: "#0ea5e9"
features:
  bot_user:
    display_name: GoldStarBot
    always_online: true
  slash_commands:
    - command: /gs
      url: https://goldstarbot.com/star
      description: Give a teammate a gold star
      usage_hint: "@teammate why they earned it"
      should_escape: true
oauth_config:
  scopes:
    bot:
      - commands
      - chat:write
      - chat:write.public
settings:
  org_deploy_enabled: false
  socket_mode_enabled: false
  token_rotation_enabled: false
YAML;

    private TeamService $teams;
    private SettingsService $settings;
    private AccountService $accounts;
    private ActivityService $activity;
    private Mailer $mailer;
    private StripeService $stripe;
    private LoggerInterface $logger;
    private string $adminUser;
    private string $adminPasswordHash;

    /** @param array<string,mixed> $adminConfig */
    public function __construct(TeamService $teams, SettingsService $settings, AccountService $accounts, ActivityService $activity, Mailer $mailer, StripeService $stripe, LoggerInterface $logger, array $adminConfig)
    {
        $this->teams = $teams;
        $this->settings = $settings;
        $this->accounts = $accounts;
        $this->activity = $activity;
        $this->mailer = $mailer;
        $this->stripe = $stripe;
        $this->logger = $logger;
        $this->adminUser = (string) ($adminConfig['username'] ?? 'admin');
        $this->adminPasswordHash = (string) ($adminConfig['password_hash'] ?? '');
    }

    // ---- Auth ------------------------------------------------------------

    /** Where a signed-in user lands: superadmin console vs customer dashboard. */
    private function homeFor(): string
    {
        return AdminSession::isSuper() ? '/admin' : '/dashboard';
    }

    public function loginForm(Request $request, Response $response): Response
    {
        AdminSession::start();
        if (AdminSession::isAuthed()) {
            return $response->withHeader('Location', $this->homeFor())->withStatus(302);
        }
        return $this->html($response, $this->renderLogin(null));
    }

    public function login(Request $request, Response $response): Response
    {
        AdminSession::start();
        $data = (array) $request->getParsedBody();

        if (!AdminSession::checkCsrf($data['csrf'] ?? null)) {
            return $this->html($response, $this->renderLogin('Session expired — please try again.'), 400);
        }

        $email = strtolower(trim((string) ($data['username'] ?? '')));
        $pass  = (string) ($data['password'] ?? '');

        // Bootstrap: make sure the configured superadmin exists (idempotent),
        // so the operator can always sign in even before the migration seed ran.
        try {
            $this->accounts->ensureSuperadmin($this->adminUser, $this->adminPasswordHash);
        } catch (\Throwable $e) {
            $this->logger->error('ensureSuperadmin failed', ['error' => $e->getMessage()]);
        }

        $acc = null;
        try {
            $acc = $this->accounts->findByEmail($email);
        } catch (\Throwable $e) {
            $this->logger->error('login lookup failed', ['error' => $e->getMessage()]);
        }

        $ok = $acc !== null
            && ($acc['status'] ?? '') === AccountService::ST_ACTIVE
            && $this->accounts->verify($acc, $pass);

        if (!$ok) {
            $this->logger->warning('Admin login failed', ['email' => $email]);
            $this->activity->log(
                ActivityService::T_LOGIN_FAIL,
                'Failed sign-in attempt' . ActivityService::clientContext(),
                null,
                $email !== '' ? $email : null
            );
            return $this->html($response, $this->renderLogin('Invalid email or password.'), 401);
        }

        AdminSession::login($acc);
        $this->activity->log(
            ActivityService::T_LOGIN,
            'Signed in' . ActivityService::clientContext(),
            !empty($acc['team_id']) ? (string) $acc['team_id'] : null,
            (string) $acc['email']
        );
        return $response->withHeader('Location', $this->homeFor())->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        AdminSession::start();
        $email = AdminSession::email();
        if ($email !== '') {
            $this->activity->log(ActivityService::T_LOGOUT, 'Signed out' . ActivityService::clientContext(), null, $email);
        }
        AdminSession::logout();
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    // ---- Dashboard -------------------------------------------------------

    public function dashboard(Request $request, Response $response): Response
    {
        AdminSession::start();
        // Customers get the clean /dashboard URL, not /admin.
        if (!AdminSession::isSuper()) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        $flash = null;
        $params = $request->getQueryParams();
        if (isset($params['ok'])) {
            $flash = (string) $params['ok'];
        }
        return $this->html($response, $this->renderDashboard($this->teams->all(), $flash));
    }

    public function customerDashboard(Request $request, Response $response): Response
    {
        AdminSession::start();
        // Superadmin has the full console at /admin.
        if (AdminSession::isSuper()) {
            return $response->withHeader('Location', '/admin')->withStatus(302);
        }
        $flash = null;
        $params = $request->getQueryParams();
        if (isset($params['ok'])) {
            $flash = (string) $params['ok'];
        }

        // Pull the live subscription state from Stripe (source of truth) and
        // cache the status back to the account.
        $subInfo = null;
        $uid = (int) ($_SESSION['gsb_uid'] ?? 0);
        $acc = $uid > 0 ? $this->accounts->findById($uid) : null;
        if ($acc !== null && !empty($acc['stripe_subscription_id'])) {
            $subInfo = $this->stripe->subscriptionInfo((string) $acc['stripe_subscription_id']);
            if ($subInfo !== null) {
                try {
                    $this->accounts->setSubStatus((string) $acc['stripe_subscription_id'], (string) $subInfo['status']);
                } catch (\Throwable $e) {
                    $this->logger->error('dashboard sub-status update failed', ['error' => $e->getMessage()]);
                }
            }
        }

        return $this->html($response, $this->renderCustomerDashboard($flash, $subInfo));
    }

    public function teams(Request $request, Response $response): Response
    {
        AdminSession::start();
        if (!AdminSession::isSuper()) {
            return $response->withHeader('Location', '/admin')->withStatus(302);
        }
        $data = (array) $request->getParsedBody();

        if (!AdminSession::checkCsrf($data['csrf'] ?? null)) {
            return $response->withHeader('Location', '/admin')->withStatus(302);
        }

        $action = (string) ($data['action'] ?? '');
        $teamId = trim((string) ($data['team_id'] ?? ''));
        $domain = trim((string) ($data['team_domain'] ?? ''));

        $msg = 'Done.';
        if ($teamId === '') {
            $msg = 'A team ID is required.';
        } else {
            switch ($action) {
                case 'approve':
                    $this->teams->setStatus($teamId, TeamService::STATUS_ACTIVE);
                    $msg = "Enabled {$teamId}.";
                    break;
                case 'block':
                    $this->teams->setStatus($teamId, TeamService::STATUS_BLOCKED);
                    $msg = "Disabled {$teamId}.";
                    break;
                case 'pending':
                    $this->teams->setStatus($teamId, TeamService::STATUS_PENDING);
                    $msg = "Set {$teamId} back to pending.";
                    break;
                case 'add':
                    $this->teams->add($teamId, $domain, TeamService::STATUS_ACTIVE);
                    $msg = "Added and enabled {$teamId}.";
                    break;
                default:
                    $msg = 'Unknown action.';
            }
        }

        if ($teamId !== '' && in_array($action, ['approve', 'block', 'pending', 'add'], true)) {
            $this->activity->log(ActivityService::T_WORKSPACE, $msg, $teamId, AdminSession::email());
        }

        return $response->withHeader('Location', '/admin?ok=' . rawurlencode($msg))->withStatus(302);
    }

    // ---- Activity --------------------------------------------------------

    public function activity(Request $request, Response $response): Response
    {
        AdminSession::start();
        if (!AdminSession::isSuper()) {
            return $response->withHeader('Location', '/admin')->withStatus(302);
        }
        return $this->html($response, $this->renderActivity($this->activity->recent(150)));
    }

    // ---- Setup guide -----------------------------------------------------

    public function setup(Request $request, Response $response): Response
    {
        AdminSession::start();
        return $this->html($response, $this->renderSetup());
    }

    // ---- Password setup (post-checkout) ----------------------------------

    public function setPasswordForm(Request $request, Response $response): Response
    {
        AdminSession::start();
        $token = (string) ($request->getQueryParams()['token'] ?? '');
        $acc = $token !== '' ? $this->accounts->findByToken($token) : null;
        if ($acc === null) {
            return $this->html($response, $this->renderSetPassword(null, null, 'This link is invalid or has already been used — try logging in instead.'));
        }
        return $this->html($response, $this->renderSetPassword($acc, $token, null));
    }

    public function setPassword(Request $request, Response $response): Response
    {
        AdminSession::start();
        $data = (array) $request->getParsedBody();
        $token = (string) ($data['token'] ?? '');
        $acc = $token !== '' ? $this->accounts->findByToken($token) : null;

        if ($acc === null || !AdminSession::checkCsrf($data['csrf'] ?? null)) {
            return $this->html($response, $this->renderSetPassword(null, null, 'This link is invalid or has expired.'), 400);
        }

        $pw  = (string) ($data['password'] ?? '');
        $pw2 = (string) ($data['password2'] ?? '');
        if (strlen($pw) < 8) {
            return $this->html($response, $this->renderSetPassword($acc, $token, 'Password must be at least 8 characters.'), 422);
        }
        if ($pw !== $pw2) {
            return $this->html($response, $this->renderSetPassword($acc, $token, 'Those passwords don’t match.'), 422);
        }

        $wasNewAccount = ($acc['status'] ?? '') === AccountService::ST_PENDING;

        $activated = $this->accounts->setPasswordByToken($token, $pw);
        if ($activated === null) {
            return $this->html($response, $this->renderSetPassword(null, null, 'Something went wrong — please try again.'), 400);
        }

        $ctx = ActivityService::clientContext();
        if ($wasNewAccount) {
            // Welcome only on first activation (not on a password reset).
            $this->sendWelcomeEmail((string) $activated['email']);
            $this->activity->log(ActivityService::T_ACCOUNT, 'Account activated' . $ctx, !empty($activated['team_id']) ? (string) $activated['team_id'] : null, (string) $activated['email']);
        } else {
            $this->activity->log(ActivityService::T_PASSWORD, 'Password changed' . $ctx, null, (string) $activated['email']);
        }

        AdminSession::login($activated);
        return $response->withHeader('Location', $this->homeFor())->withStatus(302);
    }

    private function sendWelcomeEmail(string $email): void
    {
        $body = "Welcome to GoldStarBot! 🎉\n\n"
            . "Your account is ready and your 7-day free trial has started.\n\n"
            . "Next, connect GoldStarBot to your Slack workspace from your dashboard:\n"
            . "https://goldstarbot.com/admin\n\n"
            . "Then give your first star:  /gs @teammate great work\n\n"
            . "— GoldStarBot";
        $this->mailer->send($email, 'Welcome to GoldStarBot 🎉', $body);
    }

    // ---- Settings --------------------------------------------------------

    public function settings(Request $request, Response $response): Response
    {
        AdminSession::start();
        if (!AdminSession::isSuper()) {
            return $response->withHeader('Location', '/admin')->withStatus(302);
        }
        $flash = null;
        $params = $request->getQueryParams();
        if (isset($params['ok'])) {
            $flash = (string) $params['ok'];
        }
        return $this->html($response, $this->renderSettings($flash));
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        AdminSession::start();
        if (!AdminSession::isSuper()) {
            return $response->withHeader('Location', '/admin')->withStatus(302);
        }
        $data = (array) $request->getParsedBody();

        if (!AdminSession::checkCsrf($data['csrf'] ?? null)) {
            return $response->withHeader('Location', '/admin/settings')->withStatus(302);
        }

        $kv = [];

        // Secrets: only overwrite when a new value is typed (blank = keep current).
        $signing = trim((string) ($data['signing_secret'] ?? ''));
        if ($signing !== '') {
            $kv['signing_secret'] = $signing;
        }
        $bot = trim((string) ($data['bot_token'] ?? ''));
        if ($bot !== '') {
            $kv['bot_token'] = $bot;
        }

        // Non-secret prefs: always saved.
        $target = (string) ($data['post_target'] ?? 'in_channel');
        if (!in_array($target, ['in_channel', 'channel', 'both'], true)) {
            $target = 'in_channel';
        }
        $kv['post_target'] = $target;
        $kv['recognition_channel'] = trim((string) ($data['recognition_channel'] ?? ''));

        try {
            $this->settings->save($kv);
            $msg = 'Settings saved.';
        } catch (\Throwable $e) {
            $this->logger->error('Admin: save settings failed', ['error' => $e->getMessage()]);
            $msg = 'Could not save settings — check the logs.';
        }

        return $response->withHeader('Location', '/admin/settings?ok=' . rawurlencode($msg))->withStatus(302);
    }

    // ---- Rendering -------------------------------------------------------

    private function renderLogin(?string $error): string
    {
        $csrf = $this->e(AdminSession::csrfToken());
        $err = $error === null ? '' : '<p class="err">' . $this->e($error) . '</p>';
        $head = $this->authHeader();
        return $this->page('Sign in', <<<HTML
            <div class="card narrow login-card">
                <img class="loginlogo" src="/assets/goldstarbot-icon.svg" alt="GoldStar">
                <h2>Sign in</h2>
                {$err}
                <form method="post" action="/login">
                    <input type="hidden" name="csrf" value="{$csrf}">
                    <label>Email<input name="username" type="email" autocomplete="username" autofocus></label>
                    <label>Password<input type="password" name="password" autocomplete="current-password"></label>
                    <button type="submit">Sign in</button>
                </form>
                <p class="muted small" style="margin-top:14px"><a href="/forgot">Forgot your password?</a></p>
                <p class="muted small">New here? <a href="/checkout?plan=team">Start your free trial</a></p>
            </div>
        HTML, $head);
    }

    // ---- Forgot / reset password -----------------------------------------

    public function forgotForm(Request $request, Response $response): Response
    {
        AdminSession::start();
        return $this->html($response, $this->renderForgot(null, false));
    }

    public function forgot(Request $request, Response $response): Response
    {
        AdminSession::start();
        $data = (array) $request->getParsedBody();
        if (!AdminSession::checkCsrf($data['csrf'] ?? null)) {
            return $this->html($response, $this->renderForgot('Session expired — please try again.', false), 400);
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        try {
            $token = $this->accounts->startPasswordReset($email);
            if ($token !== null) {
                $this->sendResetEmail($email, $token);
            }
            $this->activity->log(ActivityService::T_PASSWORD, 'Requested a password reset' . ActivityService::clientContext(), null, $email !== '' ? $email : null);
        } catch (\Throwable $e) {
            $this->logger->error('forgot password failed', ['error' => $e->getMessage()]);
        }

        // Always neutral — don't reveal whether the email exists.
        return $this->html($response, $this->renderForgot(null, true));
    }

    private function sendResetEmail(string $email, string $token): void
    {
        $link = 'https://goldstarbot.com/set-password?token=' . rawurlencode($token);
        $body = "Hi,\n\nWe got a request to reset your GoldStarBot password. Use this link to set a new one:\n\n{$link}\n\nIf you didn't request this, you can safely ignore this email.\n\n— GoldStarBot";
        $this->mailer->send($email, 'Reset your GoldStarBot password', $body);
        // Fallback so the operator can recover the link if email delivery fails.
        $this->logger->info('Password reset link issued', ['email' => $email, 'link' => $link]);
    }

    private function renderForgot(?string $error, bool $sent): string
    {
        $csrf = $this->e(AdminSession::csrfToken());
        $head = $this->authHeader();
        if ($sent) {
            return $this->page('Reset password', <<<HTML
                <div class="card narrow login-card">
                    <img class="loginlogo" src="/assets/goldstarbot-icon.svg" alt="GoldStar">
                    <h2>Check your email</h2>
                    <p class="muted">If an account exists for that email, we've sent a link to reset your password. It may take a minute to arrive.</p>
                    <p class="muted small"><a href="/login">Back to sign in</a></p>
                </div>
            HTML, $head);
        }
        $err = $error === null ? '' : '<p class="err">' . $this->e($error) . '</p>';
        return $this->page('Reset password', <<<HTML
            <div class="card narrow login-card">
                <img class="loginlogo" src="/assets/goldstarbot-icon.svg" alt="GoldStar">
                <h2>Reset your password</h2>
                <p class="muted small">Enter your email and we'll send you a reset link.</p>
                {$err}
                <form method="post" action="/forgot">
                    <input type="hidden" name="csrf" value="{$csrf}">
                    <label style="text-align:left">Email<input name="email" type="email" autocomplete="username" autofocus></label>
                    <button type="submit">Send reset link</button>
                </form>
                <p class="muted small" style="margin-top:14px"><a href="/login">Back to sign in</a></p>
            </div>
        HTML, $head);
    }

    /**
     * @param array<int,array{team_id:string,team_domain:?string,status:string,first_seen:string,stars:int,receivers:int}> $teams
     */
    private function renderDashboard(array $teams, ?string $flash): string
    {
        $csrf = $this->e(AdminSession::csrfToken());
        $flashHtml = $flash === null ? '' : '<div class="flash">' . $this->e($flash) . '</div>';

        $rows = '';
        if ($teams === []) {
            $rows = '<tr><td colspan="6" class="muted">No workspaces yet. They appear here the first time someone runs <code>/gs</code>.</td></tr>';
        }
        foreach ($teams as $t) {
            $id = $this->e($t['team_id']);
            $domain = $this->e($t['team_domain'] ?? '—');
            $badge = $this->statusBadge($t['status']);
            $seen = $this->e(substr($t['first_seen'], 0, 16));
            $actions = $this->rowActions($t['status'], $id, $csrf);
            $rows .= "<tr>
                <td><span class=\"badge {$t['status']}\">{$badge}</span></td>
                <td><strong>{$domain}</strong><br><code>{$id}</code></td>
                <td class=\"num\">{$t['stars']}</td>
                <td class=\"num\">{$t['receivers']}</td>
                <td class=\"muted\">{$seen}</td>
                <td class=\"actions\">{$actions}</td>
            </tr>";
        }

        $header = $this->header('workspaces');
        return $this->page('Workspaces', <<<HTML
            {$flashHtml}
            <div class="card">
                <h2>Workspaces</h2>
                <p class="muted">Workspaces appear here automatically when a plan goes active, or as <em>pending</em> the first time they run <code>/gs</code>. Only <strong>enabled</strong> workspaces can give stars — use the buttons to override.</p>
                <table>
                    <thead><tr><th>Status</th><th>Workspace</th><th class="num">Stars</th><th class="num">People</th><th>First seen</th><th></th></tr></thead>
                    <tbody>{$rows}</tbody>
                </table>
            </div>
        HTML, $header);
    }

    private function rowActions(string $status, string $teamIdHtml, string $csrf): string
    {
        $btn = function (string $action, string $label, string $cls) use ($teamIdHtml, $csrf): string {
            return "<form method=\"post\" action=\"/admin/teams\">
                <input type=\"hidden\" name=\"csrf\" value=\"{$csrf}\">
                <input type=\"hidden\" name=\"action\" value=\"{$action}\">
                <input type=\"hidden\" name=\"team_id\" value=\"{$teamIdHtml}\">
                <button class=\"{$cls}\" type=\"submit\">{$label}</button>
            </form>";
        };

        $out = '';
        if ($status !== TeamService::STATUS_ACTIVE) {
            $out .= $btn('approve', 'Enable', 'approve');
        }
        if ($status !== TeamService::STATUS_BLOCKED) {
            $out .= $btn('block', 'Disable', 'block');
        }
        return $out;
    }

    private function statusBadge(string $status): string
    {
        switch ($status) {
            case TeamService::STATUS_ACTIVE:  return '● enabled';
            case TeamService::STATUS_BLOCKED: return '● disabled';
            default:                          return '● pending';
        }
    }

    private function header(string $active): string
    {
        $authed = AdminSession::isAuthed();
        $on = static function (string $key) use ($active): string {
            return $active === $key ? ' class="on"' : '';
        };

        if (!$authed) {
            $nav = '<a href="/setup"' . $on('setup') . '>Setup guide</a>'
                 . '<a class="loginlink" href="/login">Log in</a>';
        } elseif (AdminSession::isSuper()) {
            $nav = '<a href="/admin"' . $on('workspaces') . '>Workspaces</a>'
                 . '<a href="/admin/activity"' . $on('activity') . '>Activity</a>'
                 . '<a href="/admin/settings"' . $on('settings') . '>Settings</a>'
                 . '<a href="/setup"' . $on('setup') . '>Setup guide</a>'
                 . '<span class="who">' . $this->e(AdminSession::email()) . ' · <b>Superadmin</b></span>'
                 . '<a class="logout" href="/admin/logout">Log out</a>';
        } else {
            $nav = '<a href="/dashboard"' . $on('workspaces') . '>My workspace</a>'
                 . '<a href="/setup"' . $on('setup') . '>Setup guide</a>'
                 . '<span class="who">' . $this->e(AdminSession::email()) . '</span>'
                 . '<a class="logout" href="/admin/logout">Log out</a>';
        }

        $home = $authed ? $this->homeFor() : '/';
        return <<<HTML
            <header class="sitebar"><div class="in">
                <a class="brand" href="{$home}"><img src="/assets/goldstarbot-icon-transparent.svg" alt=""><b>goldstar<span class="g">bot</span></b></a>
                <nav>{$nav}</nav>
            </div></header>
        HTML;
    }

    private function renderSetPassword(?array $acc, ?string $token, ?string $error): string
    {
        $csrf = $this->e(AdminSession::csrfToken());
        $err = $error === null ? '' : '<p class="err">' . $this->e($error) . '</p>';
        $head = $this->authHeader();

        if ($acc === null) {
            $inner = $err . '<p class="muted">This password link is no longer valid.</p><a class="loginlink" href="/login">Go to sign in</a>';
            return $this->page('Set your password', '<div class="card narrow login-card"><img class="loginlogo" src="/assets/goldstarbot-icon.svg" alt=""><h2>Set your password</h2>' . $inner . '</div>', $head);
        }

        $isReset = ($acc['status'] ?? '') === AccountService::ST_ACTIVE;
        $email = $this->e((string) $acc['email']);
        $tok = $this->e((string) $token);
        $title = $isReset ? 'Set a new password' : 'Set your password';
        $sub   = $isReset ? 'Choose a new password for your account.' : 'Create a password to manage your workspace.';
        $btn   = $isReset ? 'Save new password' : 'Create account &amp; sign in';

        return $this->page($title, <<<HTML
            <div class="card narrow login-card">
                <img class="loginlogo" src="/assets/goldstarbot-icon.svg" alt="">
                <h2>{$title}</h2>
                <p class="muted small">{$sub}</p>
                {$err}
                <form method="post" action="/set-password">
                    <input type="hidden" name="csrf" value="{$csrf}">
                    <input type="hidden" name="token" value="{$tok}">
                    <label style="text-align:left">Email<input value="{$email}" disabled></label>
                    <label style="text-align:left">Password<input type="password" name="password" autocomplete="new-password" placeholder="At least 8 characters" autofocus></label>
                    <label style="text-align:left">Confirm password<input type="password" name="password2" autocomplete="new-password"></label>
                    <button type="submit">{$btn}</button>
                </form>
            </div>
        HTML, $head);
    }

    private function authHeader(): string
    {
        return <<<HTML
            <header class="sitebar"><div class="in">
                <a class="brand" href="/"><img src="/assets/goldstarbot-icon-transparent.svg" alt=""><b>goldstar<span class="g">bot</span></b></a>
            </div></header>
        HTML;
    }

    /**
     * @param array<int,array{created_at:string,type:string,team_id:?string,actor:?string,message:string}> $events
     */
    private function renderActivity(array $events): string
    {
        $header = $this->header('activity');
        $rows = '';
        if ($events === []) {
            $rows = '<tr><td colspan="4" class="muted">No activity yet. Stars, workspace changes, trials and logins will show up here.</td></tr>';
        }
        foreach ($events as $e) {
            $when  = $this->e(substr($e['created_at'], 0, 16));
            $type  = $this->e($e['type']);
            $team  = $e['team_id'] !== null ? '<code>' . $this->e($e['team_id']) . '</code>' : '<span class="muted">—</span>';
            $actor = $e['actor'] !== null ? ' <span class="muted">· ' . $this->e($e['actor']) . '</span>' : '';
            $msg   = $this->e($e['message']);
            $rows .= "<tr><td class=\"muted\" style=\"white-space:nowrap\">{$when}</td><td><span class=\"tag t-{$type}\">{$type}</span></td><td>{$msg}{$actor}</td><td>{$team}</td></tr>";
        }
        return $this->page('Activity', <<<HTML
            <div class="card">
                <h2>Activity</h2>
                <p class="muted">Everything happening across all workspaces — newest first (last 150 events).</p>
                <table>
                    <thead><tr><th>When</th><th>Type</th><th>Event</th><th>Workspace</th></tr></thead>
                    <tbody>{$rows}</tbody>
                </table>
            </div>
        HTML, $header);
    }

    /** @param array{status:string,cancel_at_period_end:bool,current_period_end:int,trial_end:int}|null $subInfo */
    private function renderCustomerDashboard(?string $flash, ?array $subInfo = null): string
    {
        $header = $this->header('workspaces');
        $flashHtml = $flash === null ? '' : '<div class="flash">' . $this->e($flash) . '</div>';
        $email = $this->e(AdminSession::email());
        $statusHtml = $this->billingStatusLine($subInfo);

        $teamId = AdminSession::teamId();
        if ($teamId === null) {
            $body = <<<HTML
                <div class="card">
                    <h2>Connect your Slack workspace</h2>
                    <p class="muted">Your account is ready, {$email}. One click installs GoldStarBot into Slack — no manual setup needed.</p>
                    <a class="addslack" href="/slack/oauth/start"><span>＋</span> Add to Slack</a>
                    <p class="muted small" style="margin-top:14px">Prefer manual setup? <a href="/setup">Use the setup guide</a> instead.</p>
                </div>
            HTML;
        } else {
            $rows = array_values(array_filter($this->teams->all(), static function (array $t) use ($teamId): bool {
                return $t['team_id'] === $teamId;
            }));
            $t = $rows[0] ?? null;
            $status = $t ? $this->statusBadge($t['status']) : '● pending';
            $stars = $t ? (int) $t['stars'] : 0;
            $body = <<<HTML
                <div class="card">
                    <h2>Your workspace</h2>
                    <p><span class="badge">{$status}</span> &nbsp; <code>{$this->e($teamId)}</code></p>
                    <p class="muted">{$stars} stars given so far.</p>
                </div>
            HTML;
        }

        $body .= <<<HTML
            <div class="card">
                <h2>Billing</h2>
                {$statusHtml}
                <p class="muted">Team plan — 7-day free trial, then <strong>\$10/mo</strong> for up to 5 active users, then \$2 per active user. Update your card, view invoices, or cancel anytime.</p>
                <a class="loginlink" href="/billing">Manage billing &amp; subscription</a>
            </div>
        HTML;

        return $this->page('My workspace', $flashHtml . $body, $header);
    }

    /** @param array{status:string,cancel_at_period_end:bool,current_period_end:int,trial_end:int}|null $subInfo */
    private function billingStatusLine(?array $subInfo): string
    {
        if ($subInfo === null) {
            return '';
        }
        $status = (string) $subInfo['status'];
        $end = $subInfo['current_period_end'] > 0 ? date('M j, Y', $subInfo['current_period_end']) : '';

        if ($subInfo['cancel_at_period_end']) {
            return '<p class="substat warn">⚠️ Your subscription is set to <strong>cancel on ' . $end . '</strong>. You keep access until then — reactivate anytime from Manage billing.</p>';
        }
        if ($status === 'trialing') {
            $te = $subInfo['trial_end'] > 0 ? date('M j, Y', $subInfo['trial_end']) : $end;
            return '<p class="substat ok">✅ <strong>Free trial</strong> — ends ' . $te . ', then billing begins.</p>';
        }
        if ($status === 'active') {
            return '<p class="substat ok">✅ <strong>Active</strong> — renews ' . $end . '.</p>';
        }
        if (in_array($status, ['canceled', 'incomplete_expired', 'unpaid'], true)) {
            return '<p class="substat bad">❌ <strong>Canceled</strong> — your subscription is no longer active.</p>';
        }
        if ($status === 'past_due') {
            return '<p class="substat warn">⚠️ <strong>Payment past due</strong> — update your card to keep access.</p>';
        }
        return '<p class="substat">Status: ' . $this->e($status) . '</p>';
    }

    private function renderSettings(?string $flash): string
    {
        $header = $this->header('settings');
        $csrf = $this->e(AdminSession::csrfToken());
        $flashHtml = $flash === null ? '' : '<div class="flash">' . $this->e($flash) . '</div>';

        $signingSet = $this->settings->isSet('signing_secret');
        $botSet = $this->settings->isSet('bot_token');
        $target = (string) $this->settings->get('post_target', 'in_channel');
        $channel = $this->e((string) $this->settings->get('recognition_channel', ''));

        $signingPlaceholder = $signingSet ? '•••••••• (saved — leave blank to keep)' : 'Paste the Slack Signing Secret';
        $botPlaceholder = $botSet ? '•••••••• (saved — leave blank to keep)' : 'xoxb-… (optional)';

        $sel = function (string $v) use ($target): string {
            return $v === $target ? ' selected' : '';
        };

        return $this->page('Settings', <<<HTML
            {$flashHtml}
            <div class="card">
                <h2>Slack credentials & delivery</h2>
                <p class="muted">Paste values from your Slack app. Secrets are write-only here — once saved they show as ••••; leave a field blank to keep the current value.</p>
                <form method="post" action="/admin/settings">
                    <input type="hidden" name="csrf" value="{$csrf}">

                    <label>Signing Secret <span class="req">required</span>
                        <input type="password" name="signing_secret" autocomplete="off" placeholder="{$signingPlaceholder}">
                    </label>
                    <p class="hint">Slack app → <strong>Basic Information → App Credentials → Signing Secret</strong>.</p>

                    <label>Where should stars be posted?
                        <select name="post_target">
                            <option value="in_channel"{$sel('in_channel')}>In the channel where the command was run</option>
                            <option value="channel"{$sel('channel')}>A single central #starbot channel</option>
                            <option value="both"{$sel('both')}>Both</option>
                        </select>
                    </label>

                    <label>Recognition channel ID <span class="muted">(for central / both)</span>
                        <input name="recognition_channel" value="{$channel}" placeholder="C0123ABCD">
                    </label>
                    <p class="hint">In Slack, open the channel → <strong>View channel details</strong> → copy the ID at the bottom. Invite the bot to that channel.</p>

                    <label>Bot User OAuth Token <span class="muted">(only for central / both)</span>
                        <input type="password" name="bot_token" autocomplete="off" placeholder="{$botPlaceholder}">
                    </label>
                    <p class="hint">Slack app → <strong>OAuth &amp; Permissions → Bot User OAuth Token</strong> (<code>xoxb-…</code>).</p>

                    <button type="submit">Save settings</button>
                </form>
            </div>
        HTML, $header);
    }

    private function renderSetup(): string
    {
        $header = $this->header('setup');
        $manifest = $this->e(self::MANIFEST);

        return $this->page('Slack setup guide', <<<HTML
            <div class="card">
                <h2>Connect GoldStarBot to Slack</h2>
                <p class="muted">A one-time setup. Takes about 3 minutes. You'll need to be a Slack workspace admin.</p>
            </div>

            <div class="card">
                <div class="step"><span class="num">1</span><div>
                    <h3>Open the Slack app builder</h3>
                    <p>Go to <a href="https://api.slack.com/apps" target="_blank" rel="noopener">api.slack.com/apps</a> and click the green <strong>Create New App</strong> button.</p>
                    <img class="shot" src="/assets/setup-1-your-apps.png" alt="Slack API — Your Apps page with the Create New App button">
                </div></div>

                <div class="step"><span class="num">2</span><div>
                    <h3>Choose “From a manifest”</h3>
                    <p>Slack asks how you want to configure the app — pick <strong>From a manifest</strong>:</p>
                    <img class="shot" src="/assets/setup-2-create-an-app.png" alt="Create an app dialog — From a manifest vs From scratch">
                    <div class="choice">
                        <div class="opt pick">
                            <strong>From a manifest</strong> ✅ pick this
                            <p class="muted">Adds the slash command, URL, escaping, and scopes in one paste — no clicking through menus.</p>
                        </div>
                        <div class="opt">
                            <strong>From scratch</strong>
                            <p class="muted">Manual configuration. More steps, easier to miss the “escape” setting. Skip it.</p>
                        </div>
                    </div>
                    <p>Then select the workspace you want GoldStar in and continue.</p>
                </div></div>

                <div class="step"><span class="num">3</span><div>
                    <h3>Paste this manifest</h3>
                    <p>Replace everything in the manifest box with this, then click <strong>Next → Create</strong>:</p>
                    <div class="codewrap">
                        <button type="button" class="copy" onclick="navigator.clipboard.writeText(document.getElementById('mf').textContent).then(()=>{this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500)})">Copy</button>
                        <pre id="mf">{$manifest}</pre>
                    </div>
                    <p class="muted">This sets the Request URL to <code>https://goldstarbot.com/star</code> and turns on <em>“Escape channels, users &amp; links”</em> — required so the bot can read who you mentioned.</p>
                </div></div>

                <div class="step"><span class="num">4</span><div>
                    <h3>Install to your workspace</h3>
                    <p>On the app’s <strong>Install App</strong> page, click <strong>Install to Workspace</strong> and approve.</p>
                </div></div>

                <div class="step"><span class="num">5</span><div>
                    <h3>Copy your Signing Secret</h3>
                    <p>Go to <strong>Basic Information → App Credentials</strong>, click <strong>Show</strong> on the <strong>Signing Secret</strong>, and copy it. This is what lets the bot trust that requests really come from Slack.</p>
                    <img class="shot" src="/assets/setup-5-signing-secret.png" alt="Slack Basic Information — Signing Secret with Show/Regenerate">
                    <p class="muted">Optional — for a single central #starbot channel instead of posting in-place: also copy the <strong>Bot User OAuth Token</strong> (<code>xoxb-…</code>) from <strong>OAuth &amp; Permissions</strong>.</p>
                    <img class="shot" src="/assets/setup-6-bot-token.png" alt="Slack OAuth &amp; Permissions — Bot User OAuth Token">
                </div></div>

                <div class="step"><span class="num">6</span><div>
                    <h3>Approve your workspace here</h3>
                    <p>In Slack, run <code>/gs</code> once. Your workspace then appears on the <a href="/admin">Workspaces</a> tab as <span class="badge pending">● pending</span> — click <strong>Enable</strong> and you're live.</p>
                </div></div>

                <div class="step"><span class="num">7</span><div>
                    <h3>Give your first star ⭐</h3>
                    <p><code>/gs @teammate great work on the launch</code></p>
                </div></div>
            </div>
        HTML, $header);
    }

    private function page(string $title, string $body, string $topHeader = ''): string
    {
        $t = $this->e($title);
        return <<<HTML
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$t} · GoldStarBot</title>
        <link rel="icon" type="image/svg+xml" href="/assets/goldstarbot-icon.svg">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
          :root{--navy:#1F2937;--gold:#F6B31A;--gold-dk:#b57e07;--green:#22A85A;--green-dk:#1B8E4A;--card:#ffffff;--line:#cfeede;--txt:#1F2937;--muted:#64748b}
          *{box-sizing:border-box}
          body{margin:0;background:linear-gradient(180deg,#d8f5e3 0%,#eaf7ee 300px);color:var(--txt);font:15px/1.5 "Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;min-height:100vh}
          .wrap{max-width:920px;margin:0 auto;padding:24px 20px}
          /* full-width top bar matching the landing page (no nav) */
          header.sitebar{position:sticky;top:0;z-index:10;background:rgba(255,255,255,.9);backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
          header.sitebar .in{max-width:1080px;margin:0 auto;padding:0 20px;min-height:64px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
          header.sitebar .brand{display:flex;align-items:center;gap:9px;text-decoration:none;color:var(--navy)}
          header.sitebar .brand img{height:30px;width:auto}
          header.sitebar .brand b{font:700 24px/1 "Courier Prime",ui-monospace,monospace;letter-spacing:-.02em;position:relative;top:2px}
          header.sitebar nav{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
          header.sitebar nav a{color:var(--muted);text-decoration:none;font-size:14px;font-weight:600}
          header.sitebar nav a:hover{color:var(--navy)}
          header.sitebar nav a.on{color:var(--navy);border-bottom:2px solid var(--gold)}
          a{color:var(--green-dk)}
          h1{font-size:20px;margin:0}h2{font-size:17px;margin:0 0 8px}h3{font-size:15px;margin:0 0 4px}
          .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px}
          .brand{display:flex;align-items:center;gap:9px;text-decoration:none;color:var(--navy)}
          .brand img{height:28px;width:auto}
          .brand span{font:700 23px/1 "Courier Prime",ui-monospace,monospace;letter-spacing:-.02em;position:relative;top:2px}
          nav{display:flex;gap:16px;align-items:center}
          nav a{color:var(--muted);text-decoration:none;font-size:14px;font-weight:600}
          nav a:hover{color:var(--navy)}nav a.on{color:var(--navy);border-bottom:2px solid var(--gold)}
          .logout{color:var(--muted)!important;font-weight:400!important}
          .loginlink{background:var(--navy);color:#fff!important;border-radius:8px;padding:8px 14px}
          .addslack{display:inline-flex;align-items:center;gap:8px;background:#4A154B;color:#fff!important;text-decoration:none;font-weight:700;border-radius:10px;padding:12px 18px;font-size:15px}
          .addslack:hover{background:#611f64}.addslack span{font-size:19px;line-height:1}
          .who{color:var(--muted);font-size:13px}.who b{color:var(--green-dk)}
          .substat{font-size:14px;font-weight:600;margin:0 0 10px}
          .substat.ok{color:var(--green-dk)}.substat.warn{color:var(--gold-dk)}.substat.bad{color:#c0335a}
          .tag{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;border-radius:5px;padding:2px 7px;background:#eef4f0;color:var(--muted)}
          .tag.t-star{background:#fff6e2;color:var(--gold-dk)}
          .tag.t-achievement{background:#f0e9fb;color:#6b3fb0}
          .tag.t-workspace{background:#e9f2ff;color:#2f5fb0}
          .tag.t-trial,.tag.t-account{background:#e2f7ec;color:var(--green-dk)}
          .tag.t-login{background:#eef4f0;color:var(--muted)}
          .tag.t-blocked{background:#fde8ee;color:#c0335a}
          .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:22px;margin-bottom:16px;box-shadow:0 1px 3px rgba(31,41,55,.06)}
          .card.narrow{max-width:360px;margin:40px auto 0}
          .login-card{text-align:center}.login-card h2{margin:0 0 14px}.login-card label{text-align:left}
          .brand .g{color:var(--green)}
          .loginlogo{width:80px;height:80px;margin:0 auto 8px;display:block;border-radius:18px}
          label{display:block;margin:12px 0;font-size:13px;color:var(--muted)}
          input,select{display:block;width:100%;margin-top:6px;padding:10px;background:#f6fcf8;border:1px solid var(--line);border-radius:8px;color:var(--txt);font-size:14px}
          input:focus,select:focus{outline:none;border-color:var(--green);box-shadow:0 0 0 3px rgba(34,168,90,.15)}
          .hint{font-size:12px;color:var(--muted);margin:4px 0 4px}
          .req{color:#c0335a;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
          button{cursor:pointer;border:0;border-radius:8px;padding:9px 14px;font-size:14px;font-weight:600;background:var(--navy);color:#fff}
          button:hover{background:#111827}
          button.block{background:#fde8ee;color:#c0335a}button.block:hover{background:#f9d4de}
          button.approve{background:#e2f7ec;color:var(--green-dk)}button.approve:hover{background:#cdeedd}
          table{width:100%;border-collapse:collapse;margin-top:8px}
          th,td{text-align:left;padding:10px 8px;border-bottom:1px solid var(--line);vertical-align:top}
          th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
          td.num,th.num{text-align:right}
          code{background:#e9f7ef;padding:1px 6px;border-radius:5px;font-size:12px;color:var(--green-dk)}
          .muted{color:var(--muted)}.err{color:#c0335a;font-size:14px}
          .flash{background:#e2f7ec;color:var(--green-dk);padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:14px;border:1px solid #bfe8d1}
          .badge{font-size:12px;font-weight:600;white-space:nowrap}
          .badge.active{color:var(--green-dk)}.badge.blocked{color:#c0335a}.badge.pending{color:var(--gold-dk)}
          .actions{display:flex;gap:6px;flex-wrap:wrap}.actions form{margin:0}
          .actions button{padding:6px 10px;font-size:13px}
          .addform{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.addform input{width:auto;flex:1;min-width:160px;margin-top:0}
          .step{display:flex;gap:14px;padding:16px 0;border-top:1px solid var(--line)}
          .step:first-child{border-top:0;padding-top:4px}
          .step .num{flex:none;width:28px;height:28px;border-radius:50%;background:var(--navy);color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center;font-size:14px}
          .step p{margin:6px 0}
          .shot{display:block;max-width:100%;width:560px;border:1px solid var(--line);border-radius:10px;margin:12px 0 4px;box-shadow:0 4px 16px rgba(31,41,55,.09)}
          .choice{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0}
          .opt{flex:1;min-width:220px;border:1px solid var(--line);border-radius:10px;padding:12px;background:#f6fcf8}
          .opt.pick{border-color:var(--green);background:#e9f7ef}
          .opt p{margin:6px 0 0;font-size:13px}
          .codewrap{position:relative;margin:8px 0}
          .codewrap pre{margin:0;background:#1F2937;color:#eaf6ef;padding:16px;border-radius:10px;overflow-x:auto;font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace}
          .copy{position:absolute;top:10px;right:10px;padding:5px 10px;font-size:12px;background:rgba(255,255,255,.16)}
          .copy:hover{background:rgba(255,255,255,.3)}
        </style></head>
        <body>{$topHeader}<div class="wrap">{$body}</div></body></html>
        HTML;
    }

    private function html(Response $response, string $body, int $status = 200): Response
    {
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus($status);
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
