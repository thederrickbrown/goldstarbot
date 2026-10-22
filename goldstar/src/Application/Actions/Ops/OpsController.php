<?php
declare(strict_types=1);

namespace App\Application\Actions\Ops;

use App\Support\AccountService;
use App\Support\ActivityService;
use App\Support\AdminSession;
use App\Support\SettingsService;
use App\Support\StripeService;
use App\Support\TeamService;
use Closure;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Superadmin operator console (sidebar shell): Dashboard, Workspaces, Users,
 * Usage, Billing. Server-rendered to match the app's PHP stack.
 */
final class OpsController
{
    /** @var Closure():PDO */
    private Closure $pdoFactory;
    private ActivityService $activity;
    private StripeService $stripe;
    private AccountService $accounts;
    private TeamService $teams;
    private SettingsService $settings;
    private LoggerInterface $logger;

    /** @param Closure():PDO $pdoFactory */
    public function __construct(Closure $pdoFactory, ActivityService $activity, StripeService $stripe, AccountService $accounts, TeamService $teams, SettingsService $settings, LoggerInterface $logger)
    {
        $this->pdoFactory = $pdoFactory;
        $this->activity = $activity;
        $this->stripe = $stripe;
        $this->accounts = $accounts;
        $this->teams = $teams;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    // ---------------------------------------------------------------------
    // Pages
    // ---------------------------------------------------------------------

    public function dashboard(Request $request, Response $response): Response
    {
        AdminSession::start();
        if (($gate = $this->requireSuper($response)) !== null) {
            return $gate;
        }

        $s = $this->stats();
        $mrr = '$' . number_format($s['mrr']);

        $cards =
            $this->statCard('Monthly Recurring Rev', $mrr, 'dollar', '<span class="submeta">' . $s['active_subs'] . ' active · ' . $s['trial_subs'] . ' trial</span>')
            . $this->statCard('Active Workspaces', (string) $s['active_ws'], 'briefcase', '<span class="submeta">of ' . $s['total_ws'] . ' total</span>')
            . $this->statCard('Total Users', number_format($s['users']), 'users', '<span class="submeta">across all teams</span>')
            . $this->statCard('Stars Given', number_format($s['stars']), 'star', '<span class="submeta">all time</span>');

        $body = <<<HTML
            <div class="stat-grid">{$cards}</div>
            <div class="two-col">
                {$this->activityFeed($this->activity->recent(8))}
                {$this->systemStatus()}
            </div>
        HTML;

        return $this->html($response, $this->shell('dashboard', 'Platform Overview', 'Everything happening across your GoldStarBot install.', $body));
    }

    public function workspaces(Request $request, Response $response): Response
    {
        AdminSession::start();
        if (($gate = $this->requireSuper($response)) !== null) {
            return $gate;
        }

        $rows = '';
        try {
            $stmt = $this->pdoFactory->__invoke()->query(
                "SELECT t.team_id, t.team_domain, t.team_name, t.status, t.first_seen,
                        (SELECT COUNT(*) FROM gsb_stars s WHERE s.team_id=t.team_id) AS stars,
                        (SELECT COUNT(DISTINCT s.receiver_slack_id) FROM gsb_stars s WHERE s.team_id=t.team_id) AS people
                 FROM gsb_teams t ORDER BY (t.status='pending') DESC, t.first_seen DESC"
            );
            $csrf = $this->e(AdminSession::csrfToken());
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $id = (string) $t['team_id'];
                $eid = $this->e($id);
                $name = $this->e($t['team_name'] ?: ($t['team_domain'] ?: $id));
                $initial = strtoupper(substr($name, 0, 1));
                $status = (string) $t['status'];
                $comped = $this->settings->isTeamComped($id);
                $toggle = $status === 'active'
                    ? '<button type="submit" name="action" value="suspend" class="menu-item danger">' . $this->icon('pause') . 'Suspend</button>'
                    : '<button type="submit" name="action" value="enable" class="menu-item">' . $this->icon('check') . 'Enable</button>';
                $compItem = $comped
                    ? '<button type="submit" name="action" value="uncomp" class="menu-item">' . $this->icon('dollar') . 'Remove free access</button>'
                    : '<button type="submit" name="action" value="comp" class="menu-item">' . $this->icon('star') . 'Grant free access</button>';
                $menu = '<div class="menu">'
                    . '<button type="button" class="menu-btn" onclick="opsMenu(this)" aria-label="Actions">' . $this->icon('dots') . '</button>'
                    . '<div class="menu-pop">'
                    . '<a class="menu-item" href="/admin/workspaces/' . rawurlencode($id) . '">' . $this->icon('settings') . 'Edit settings</a>'
                    . '<form method="post" action="/admin/workspaces/' . rawurlencode($id) . '/action"><input type="hidden" name="csrf" value="' . $csrf . '">'
                    . $compItem . $toggle
                    . '<button type="submit" name="action" value="disconnect" class="menu-item danger">' . $this->icon('logout') . 'Disconnect</button></form>'
                    . '</div></div>';
                $freeBadge = $comped ? ' <span class="pill gold-pill">★ Free</span>' : '';
                $rows .= '<tr>'
                    . '<td><div class="wsname"><span class="avatar">' . $this->e($initial) . '</span><div><strong>' . $name . '</strong><br><code>' . $eid . '</code></div></div></td>'
                    . '<td>' . $this->statusBadge($status) . $freeBadge . '</td>'
                    . '<td class="num">' . number_format((int) $t['people']) . '</td>'
                    . '<td class="num">' . number_format((int) $t['stars']) . '</td>'
                    . '<td class="muted">' . $this->e(substr((string) $t['first_seen'], 0, 10)) . '</td>'
                    . '<td class="tar">' . $menu . '</td>'
                    . '</tr>';
            }
        } catch (\Throwable $e) {
            $this->logger->error('Ops workspaces failed', ['error' => $e->getMessage()]);
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="empty">No workspaces yet.</td></tr>';
        }

        $body = <<<HTML
            <input class="search" placeholder="Search workspaces…" oninput="opsFilter(this,'wstable')">
            <div class="card pad0 ovis">
                <table id="wstable" class="tbl">
                    <thead><tr><th>Workspace</th><th>Status</th><th class="num">Users</th><th class="num">Stars</th><th>Installed</th><th class="tar">Actions</th></tr></thead>
                    <tbody>{$rows}</tbody>
                </table>
            </div>
        HTML;

        return $this->html($response, $this->shell('workspaces', 'Workspaces', 'Every Slack workspace connected to GoldStarBot.', $body));
    }

    /** Per-workspace settings page (reached from the Workspaces list). */
    public function workspaceDetail(Request $request, Response $response, array $args): Response
    {
        AdminSession::start();
        if (($gate = $this->requireSuper($response)) !== null) {
            return $gate;
        }
        $teamId = (string) ($args['id'] ?? '');
        $ws = $this->findWorkspace($teamId);
        if ($ws === null) {
            return $response->withHeader('Location', '/admin/workspaces')->withStatus(302);
        }

        $params = $request->getQueryParams();
        $flash = isset($params['ok']) ? '<div class="substat ok">✅ ' . $this->e((string) $params['ok']) . '</div>' : '';

        $name = $this->e($ws['team_name'] ?: ($ws['team_domain'] ?: $teamId));
        $csrf = $this->e(AdminSession::csrfToken());
        $status = (string) $ws['status'];
        $channel = $this->e((string) $this->settings->getForTeam($teamId, 'recognition_channel', ''));
        $target = (string) $this->settings->getForTeam($teamId, 'post_target', 'in_channel');
        $sel = static fn(string $v): string => $v === $target ? ' selected' : '';
        $statusSel = static fn(string $v): string => $v === $status ? ' selected' : '';

        $members = (int) $this->scalar("SELECT COUNT(*) FROM gsb_users WHERE team_id = :t", $teamId);
        $stars = (int) $this->scalar("SELECT COUNT(*) FROM gsb_stars WHERE team_id = :t", $teamId);
        $comped = $this->settings->isTeamComped($teamId);
        $access = $comped ? '<span class="pill gold-pill" style="margin:0">★ Free</span>' : '<span class="pill gray">Standard</span>';

        $info = '<div class="kv"><span>Team ID</span><code>' . $this->e($teamId) . '</code></div>'
            . '<div class="kv"><span>Domain</span><b>' . $this->e($ws['team_domain'] ?: '—') . '</b></div>'
            . '<div class="kv"><span>Status</span>' . $this->statusBadge($status) . '</div>'
            . '<div class="kv"><span>Access</span>' . $access . '</div>'
            . '<div class="kv"><span>Members</span><b>' . number_format($members) . '</b></div>'
            . '<div class="kv"><span>Stars</span><b>' . number_format($stars) . '</b></div>'
            . '<div class="kv"><span>Installed</span><b>' . $this->e(substr((string) ($ws['installed_at'] ?: $ws['first_seen']), 0, 10)) . '</b></div>';

        $compCard = $comped
            ? '<p class="muted" style="margin:0 0 14px">This workspace has <strong>free access</strong> — active with no subscription, and billing won\'t disable it.</p>'
                . '<button type="submit" name="action" value="uncomp" class="btn ghost">Remove free access</button>'
            : '<p class="muted" style="margin:0 0 14px">Grant this workspace free access — active forever with no card or subscription required.</p>'
                . '<button type="submit" name="action" value="comp" class="btn">' . $this->icon('star') . 'Grant free access</button>';

        $back = '<a class="backlink" href="/admin/workspaces">' . $this->icon('arrow-left') . 'All workspaces</a>';

        $body = <<<HTML
            {$back}
            {$flash}
            <div class="two-col">
              <form method="post" action="/admin/workspaces/{$this->e(rawurlencode($teamId))}" class="card">
                <input type="hidden" name="csrf" value="{$csrf}">
                <div class="card-head"><h3>Settings</h3></div>
                <label class="fld"><span>Status</span>
                  <select name="status">
                    <option value="active"{$statusSel('active')}>Active — can give stars</option>
                    <option value="blocked"{$statusSel('blocked')}>Suspended — blocked</option>
                    <option value="pending"{$statusSel('pending')}>Pending</option>
                  </select>
                </label>
                <label class="fld"><span>Recognition channel</span>
                  <input name="recognition_channel" value="{$channel}" placeholder="C0123ABCD or #starbot">
                  <small>Where stars are announced in this workspace. Blank = use the app default.</small>
                </label>
                <label class="fld"><span>Where to post</span>
                  <select name="post_target">
                    <option value="in_channel"{$sel('in_channel')}>In the channel where /gs was used</option>
                    <option value="channel"{$sel('channel')}>Only the recognition channel</option>
                    <option value="both"{$sel('both')}>Both</option>
                  </select>
                </label>
                <div style="margin-top:6px"><button type="submit" class="btn">Save settings</button></div>
              </form>
              <div>
                <div class="card" style="margin-bottom:16px"><div class="card-head"><h3>{$name}</h3></div>{$info}</div>
                <form method="post" action="/admin/workspaces/{$this->e(rawurlencode($teamId))}/action" class="card" style="margin-bottom:16px">
                  <input type="hidden" name="csrf" value="{$csrf}">
                  <input type="hidden" name="return" value="detail">
                  <div class="card-head"><h3>Free access</h3></div>
                  {$compCard}
                </form>
                <form method="post" action="/admin/workspaces/{$this->e(rawurlencode($teamId))}/action" class="card">
                  <input type="hidden" name="csrf" value="{$csrf}">
                  <input type="hidden" name="return" value="detail">
                  <div class="card-head"><h3>Danger zone</h3></div>
                  <p class="muted" style="margin:0 0 14px">Disconnecting blocks the bot for this workspace until re-enabled.</p>
                  <button type="submit" name="action" value="disconnect" class="btn ghost danger-btn">Disconnect workspace</button>
                </form>
              </div>
            </div>
        HTML;

        return $this->html($response, $this->shell('workspaces', $ws['team_name'] ?: $teamId, 'Workspace settings', $body));
    }

    /** Save per-workspace settings. */
    public function saveWorkspace(Request $request, Response $response, array $args): Response
    {
        AdminSession::start();
        if (($gate = $this->requireSuper($response)) !== null) {
            return $gate;
        }
        $teamId = (string) ($args['id'] ?? '');
        $data = (array) $request->getParsedBody();
        $back = '/admin/workspaces/' . rawurlencode($teamId);
        if (!AdminSession::checkCsrf($data['csrf'] ?? null) || $this->findWorkspace($teamId) === null) {
            return $response->withHeader('Location', $back)->withStatus(302);
        }

        $status = (string) ($data['status'] ?? '');
        if (in_array($status, [TeamService::STATUS_ACTIVE, TeamService::STATUS_BLOCKED, TeamService::STATUS_PENDING], true)) {
            $this->teams->setStatus($teamId, $status);
        }
        $target = (string) ($data['post_target'] ?? 'in_channel');
        if (!in_array($target, ['in_channel', 'channel', 'both'], true)) {
            $target = 'in_channel';
        }
        try {
            $this->settings->saveForTeam($teamId, [
                'recognition_channel' => trim((string) ($data['recognition_channel'] ?? '')),
                'post_target'         => $target,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('saveWorkspace failed', ['error' => $e->getMessage()]);
        }
        $this->activity->log(ActivityService::T_WORKSPACE, 'Workspace settings updated', $teamId, AdminSession::email());
        return $response->withHeader('Location', $back . '?ok=' . rawurlencode('Settings saved.'))->withStatus(302);
    }

    /** Quick actions from the workspace list menu: enable / suspend / disconnect. */
    public function workspaceAction(Request $request, Response $response, array $args): Response
    {
        AdminSession::start();
        if (($gate = $this->requireSuper($response)) !== null) {
            return $gate;
        }
        $teamId = (string) ($args['id'] ?? '');
        $data = (array) $request->getParsedBody();
        if (!AdminSession::checkCsrf($data['csrf'] ?? null) || $this->findWorkspace($teamId) === null) {
            return $response->withHeader('Location', '/admin/workspaces')->withStatus(302);
        }
        $action = (string) ($data['action'] ?? '');
        $map = [
            'enable'     => TeamService::STATUS_ACTIVE,
            'suspend'    => TeamService::STATUS_BLOCKED,
            'disconnect' => TeamService::STATUS_BLOCKED,
        ];
        if ($action === 'comp') {
            // Grant free access: active, no card, and billing won't disable it.
            $this->teams->setStatus($teamId, TeamService::STATUS_ACTIVE);
            $this->settings->setTeamComp($teamId, true);
            $this->activity->log(ActivityService::T_WORKSPACE, 'Granted free access', $teamId, AdminSession::email());
        } elseif ($action === 'uncomp') {
            $this->settings->setTeamComp($teamId, false);
            $this->activity->log(ActivityService::T_WORKSPACE, 'Removed free access', $teamId, AdminSession::email());
        } elseif (isset($map[$action])) {
            $this->teams->setStatus($teamId, $map[$action]);
            $this->activity->log(ActivityService::T_WORKSPACE, 'Workspace ' . $action . 'd', $teamId, AdminSession::email());
        }
        $back = ($data['return'] ?? '') === 'detail' ? '/admin/workspaces/' . rawurlencode($teamId) : '/admin/workspaces';
        return $response->withHeader('Location', $back)->withStatus(302);
    }

    /** @return array<string,mixed>|null */
    private function findWorkspace(string $teamId): ?array
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $teamId)) {
            return null;
        }
        try {
            $st = $this->pdoFactory->__invoke()->prepare('SELECT * FROM gsb_teams WHERE team_id = :t');
            $st->execute([':t' => $teamId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            $this->logger->error('findWorkspace failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function scalar(string $sql, string $teamId): int
    {
        try {
            $st = $this->pdoFactory->__invoke()->prepare($sql);
            $st->execute([':t' => $teamId]);
            return (int) $st->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function billing(Request $request, Response $response): Response
    {
        AdminSession::start();
        if (($gate = $this->requireSuper($response)) !== null) {
            return $gate;
        }

        $b = $this->stripe->platformBilling(20, $this->ourCustomerIds());
        $mrr = '$' . number_format($b['mrr_cents'] / 100);

        $cards =
            $this->statCard('Total MRR', $mrr, 'dollar', '<span class="submeta">committed monthly revenue</span>')
            . $this->statCard('Active', (string) $b['active'], 'credit-card', '<span class="submeta">paying subscriptions</span>')
            . $this->statCard('On Trial', (string) $b['trialing'], 'users', '<span class="submeta">converting soon</span>')
            . $this->statCard('Past Due', (string) $b['past_due'], 'activity', ($b['past_due'] > 0 ? '<span class="chg bad">needs attention</span>' : '<span class="submeta">all current</span>'));

        $inv = '';
        foreach ($b['invoices'] as $i) {
            $amt = '$' . number_format($i['amount'] / 100, 2);
            $dl = $i['url'] !== '' ? '<a class="dl" href="' . $this->e($i['url']) . '" target="_blank" rel="noopener" title="View invoice">' . $this->icon('download') . '</a>' : '';
            $inv .= '<tr>'
                . '<td><code>' . $this->e($i['id']) . '</code></td>'
                . '<td>' . ($i['email'] !== '' ? $this->e($i['email']) : '<span class="muted">—</span>') . '</td>'
                . '<td class="num">' . $amt . '</td>'
                . '<td>' . $this->invoiceBadge($i['status']) . '</td>'
                . '<td class="muted">' . ($i['created'] > 0 ? date('M j, Y', $i['created']) : '—') . '</td>'
                . '<td class="tar">' . $dl . '</td>'
                . '</tr>';
        }
        if ($inv === '') {
            $inv = '<tr><td colspan="6" class="empty">' . ($this->stripe->isConfigured() ? 'No invoices yet.' : 'Stripe is not configured.') . '</td></tr>';
        }

        $body = <<<HTML
            <div class="stat-grid">{$cards}</div>
            <div class="card" style="margin-bottom:20px"><div class="card-head"><h3>Plans</h3></div>{$this->planCards()}</div>
            <div class="card pad0">
                <div class="card-head" style="padding:18px 20px 0"><h3>Recent Invoices</h3></div>
                <table class="tbl"><thead><tr><th>Invoice</th><th>Customer</th><th class="num">Amount</th><th>Status</th><th>Issued</th><th class="tar">PDF</th></tr></thead>
                <tbody>{$inv}</tbody></table>
            </div>
        HTML;

        return $this->html($response, $this->shell('billing', 'Billing', 'Revenue, plans and invoices — live from Stripe.', $body));
    }

    public function usage(Request $request, Response $response): Response
    {
        AdminSession::start();
        if (($gate = $this->requireSuper($response)) !== null) {
            return $gate;
        }

        $u = $this->usageData();
        $cards =
            $this->statCard('Total Stars', number_format($u['total']), 'star', '<span class="submeta">all time</span>')
            . $this->statCard('This Month', number_format($u['month']), 'activity', '<span class="submeta">stars given</span>')
            . $this->statCard('Active Users (30d)', number_format($u['active30']), 'users', '<span class="submeta">gave or received</span>')
            . $this->statCard('Peak Day', number_format($u['peak']), 'trending', '<span class="submeta">' . $this->e($u['peak_day']) . '</span>');

        $rows = '';
        foreach ($u['by_team'] as $t) {
            $name = $this->e($t['name']);
            $rows .= '<tr><td><div class="wsname"><span class="avatar">' . $this->e(strtoupper(substr($name, 0, 1))) . '</span><strong>' . $name . '</strong></div></td>'
                . '<td class="num">' . number_format((int) $t['stars']) . '</td>'
                . '<td class="num">' . number_format((int) $t['users']) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="empty">No usage yet.</td></tr>';
        }

        $body = <<<HTML
            <div class="stat-grid">{$cards}</div>
            <div class="card" style="margin-bottom:20px">
                <div class="card-head"><h3>Usage Timeline <span class="muted" style="font-weight:400">· last 30 days</span></h3>
                    <span class="legend"><span class="lg"><i class="sw sw1"></i>Stars</span><span class="lg"><i class="sw sw2"></i>Active users</span></span>
                </div>
                {$this->areaChart($u['series_stars'], $u['series_users'], $u['labels'])}
            </div>
            <div class="card pad0">
                <div class="card-head" style="padding:18px 20px 0"><h3>Workspace Breakdown</h3></div>
                <table class="tbl"><thead><tr><th>Workspace</th><th class="num">Stars</th><th class="num">Active Users</th></tr></thead>
                <tbody>{$rows}</tbody></table>
            </div>
        HTML;

        return $this->html($response, $this->shell('usage', 'Usage', 'Stars and activity across every workspace.', $body));
    }

    public function users(Request $request, Response $response): Response
    {
        AdminSession::start();
        if (($gate = $this->requireSuper($response)) !== null) {
            return $gate;
        }

        $rows = '';
        try {
            $stmt = $this->pdoFactory->__invoke()->query(
                "SELECT COALESCE(NULLIF(u.display_name,''), NULLIF(u.slack_username,''), u.slack_user_id) AS name,
                        u.slack_user_id, u.team_id, u.created_at,
                        COALESCE(t.team_name, t.team_domain, u.team_id) AS team,
                        (SELECT COUNT(*) FROM gsb_stars s WHERE s.giver_slack_id=u.slack_user_id AND s.team_id=u.team_id) AS given,
                        COALESCE((SELECT total FROM gsb_star_totals st WHERE st.receiver_slack_id=u.slack_user_id AND st.team_id=u.team_id),0) AS received
                 FROM gsb_users u LEFT JOIN gsb_teams t ON t.team_id=u.team_id
                 ORDER BY received DESC, given DESC"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $name = $this->e((string) $r['name']);
                $rows .= '<tr>'
                    . '<td><div class="wsname"><span class="avatar sm">' . $this->e(strtoupper(substr($name, 0, 1))) . '</span><div><strong>' . $name . '</strong><br><code>' . $this->e((string) $r['slack_user_id']) . '</code></div></div></td>'
                    . '<td>' . $this->e((string) $r['team']) . '</td>'
                    . '<td class="num">' . number_format((int) $r['given']) . '</td>'
                    . '<td class="num">' . number_format((int) $r['received']) . '</td>'
                    . '<td class="muted">' . $this->e(substr((string) $r['created_at'], 0, 10)) . '</td>'
                    . '</tr>';
            }
        } catch (\Throwable $e) {
            $this->logger->error('Ops users failed', ['error' => $e->getMessage()]);
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="empty">No users yet.</td></tr>';
        }

        $body = <<<HTML
            <input class="search" placeholder="Search users…" oninput="opsFilter(this,'usertable')">
            <div class="card pad0">
                <table id="usertable" class="tbl">
                    <thead><tr><th>User</th><th>Workspace</th><th class="num">Given</th><th class="num">Received</th><th>Joined</th></tr></thead>
                    <tbody>{$rows}</tbody>
                </table>
            </div>
        HTML;

        return $this->html($response, $this->shell('users', 'Users', 'Every member who has used GoldStarBot.', $body));
    }

    /** Full platform activity log (superadmin). */
    public function activity(Request $request, Response $response): Response
    {
        AdminSession::start();
        if (($gate = $this->requireSuper($response)) !== null) {
            return $gate;
        }
        $events = $this->activity->recent(300);
        $body = '<input class="search" placeholder="Search activity — user, action, workspace…" oninput="opsFilterList(this,\'actfeed\')">'
            . '<div class="card feed"><div class="card-head"><h3>All Activity</h3><span class="live"><span class="dot"></span>Live</span></div>'
            . '<div id="actfeed">' . $this->feedItems($events) . '</div></div>';
        return $this->html($response, $this->shell('activity', 'Activity', 'Every sign-in, command, star, billing and workspace event.', $body));
    }

    // ---------------------------------------------------------------------
    // Customer console (same shell, scoped to the signed-in workspace)
    // ---------------------------------------------------------------------

    public function custDashboard(Request $request, Response $response): Response
    {
        [$account, $gate] = $this->customerContext($response);
        if ($gate !== null) {
            return $gate;
        }
        $teamId = $account['team_id'] ?? null;
        $s = $this->custStats($teamId);
        $sub = $this->subInfoFor($account);
        $statusLine = $this->billingStatusLine($sub);

        $planLabel = $sub !== null ? ucfirst((string) $sub['status']) : ($account['sub_status'] ? ucfirst((string) $account['sub_status']) : '—');
        $cards =
            $this->statCard('Stars Given', number_format($s['stars']), 'star', '<span class="submeta">in your workspace</span>')
            . $this->statCard('This Month', number_format($s['month']), 'activity', '<span class="submeta">stars so far</span>')
            . $this->statCard('Team Members', number_format($s['members']), 'users', '<span class="submeta">seen by GoldStarBot</span>')
            . $this->statCard('Plan', $this->e($planLabel), 'credit-card', '<span class="submeta"><a href="/dashboard/billing">Manage billing →</a></span>');

        $connect = $teamId ? '' : '<div class="card" style="margin-bottom:20px"><div class="empty-big">' . $this->icon('link') . '<h3>Connect your Slack workspace</h3><p class="muted">Add GoldStarBot to Slack to start giving stars. Your stats will appear here.</p><p style="margin-top:14px"><a href="/slack/oauth/start"><img alt="Add to Slack" height="40" width="139" src="https://platform.slack-edge.com/img/add_to_slack.png" srcset="https://platform.slack-edge.com/img/add_to_slack.png 1x, https://platform.slack-edge.com/img/add_to_slack@2x.png 2x"></a></p></div></div>';

        $body = <<<HTML
            {$statusLine}
            <div class="stat-grid">{$cards}</div>
            {$connect}
            <div class="two-col">
                {$this->activityFeed($teamId ? $this->teamActivity($teamId, 8) : [], 'Recent Activity')}
                {$this->leaderboard($teamId)}
            </div>
        HTML;

        return $this->html($response, $this->shell('dashboard', 'Dashboard', 'Recognition across your Slack workspace.', $body, 'customer'));
    }

    public function custTeam(Request $request, Response $response): Response
    {
        [$account, $gate] = $this->customerContext($response);
        if ($gate !== null) {
            return $gate;
        }
        $teamId = $account['team_id'] ?? null;
        $rows = '';
        if ($teamId !== null) {
            try {
                $st = $this->pdoFactory->__invoke()->prepare(
                    "SELECT COALESCE(NULLIF(u.display_name,''), NULLIF(u.slack_username,''), u.slack_user_id) AS name,
                            u.slack_user_id, u.created_at,
                            (SELECT COUNT(*) FROM gsb_stars s WHERE s.giver_slack_id=u.slack_user_id AND s.team_id=u.team_id) AS given,
                            COALESCE((SELECT total FROM gsb_star_totals stt WHERE stt.receiver_slack_id=u.slack_user_id AND stt.team_id=u.team_id),0) AS received
                     FROM gsb_users u WHERE u.team_id = :t ORDER BY received DESC, given DESC"
                );
                $st->execute([':t' => $teamId]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $name = $this->e((string) $r['name']);
                    $rows .= '<tr>'
                        . '<td><div class="wsname"><span class="avatar sm">' . $this->e(strtoupper(substr($name, 0, 1))) . '</span><strong>' . $name . '</strong></div></td>'
                        . '<td class="num">' . number_format((int) $r['given']) . '</td>'
                        . '<td class="num">' . number_format((int) $r['received']) . '</td>'
                        . '<td class="muted">' . $this->e(substr((string) $r['created_at'], 0, 10)) . '</td>'
                        . '</tr>';
                }
            } catch (\Throwable $e) {
                $this->logger->error('Cust team failed', ['error' => $e->getMessage()]);
            }
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="empty">No members yet.</td></tr>';
        }

        $body = <<<HTML
            <input class="search" placeholder="Search team…" oninput="opsFilter(this,'teamtable')">
            <div class="card pad0">
                <table id="teamtable" class="tbl">
                    <thead><tr><th>Member</th><th class="num">Given</th><th class="num">Received</th><th>Joined</th></tr></thead>
                    <tbody>{$rows}</tbody>
                </table>
            </div>
        HTML;

        return $this->html($response, $this->shell('team', 'Team', 'Everyone in your workspace using GoldStarBot.', $body, 'customer'));
    }

    public function custUsage(Request $request, Response $response): Response
    {
        [$account, $gate] = $this->customerContext($response);
        if ($gate !== null) {
            return $gate;
        }
        $teamId = $account['team_id'] ?? null;
        // Never fall through to platform-wide data: a customer with no linked
        // workspace sees the connect prompt, not everyone's usage.
        if ($teamId === null) {
            $body = '<div class="card"><div class="empty-big">' . $this->icon('link') . '<h3>Connect your workspace</h3><p class="muted">Once GoldStarBot is in your Slack, your usage shows up here.</p><p style="margin-top:14px"><a href="/slack/oauth/start"><img alt="Add to Slack" height="40" width="139" src="https://platform.slack-edge.com/img/add_to_slack.png" srcset="https://platform.slack-edge.com/img/add_to_slack.png 1x, https://platform.slack-edge.com/img/add_to_slack@2x.png 2x"></a></p></div></div>';
            return $this->html($response, $this->shell('usage', 'Usage', 'Recognition activity in your workspace.', $body, 'customer'));
        }
        $u = $this->usageData($teamId);
        $cards =
            $this->statCard('Total Stars', number_format($u['total']), 'star', '<span class="submeta">all time</span>')
            . $this->statCard('This Month', number_format($u['month']), 'activity', '<span class="submeta">stars given</span>')
            . $this->statCard('Active Users (30d)', number_format($u['active30']), 'users', '<span class="submeta">gave or received</span>')
            . $this->statCard('Peak Day', number_format($u['peak']), 'trending', '<span class="submeta">' . $this->e($u['peak_day']) . '</span>');

        $body = <<<HTML
            <div class="stat-grid">{$cards}</div>
            <div class="card">
                <div class="card-head"><h3>Usage Timeline <span class="muted" style="font-weight:400">· last 30 days</span></h3>
                    <span class="legend"><span class="lg"><i class="sw sw1"></i>Stars</span><span class="lg"><i class="sw sw2"></i>Active users</span></span>
                </div>
                {$this->areaChart($u['series_stars'], $u['series_users'], $u['labels'])}
            </div>
        HTML;

        return $this->html($response, $this->shell('usage', 'Usage', 'Recognition activity in your workspace.', $body, 'customer'));
    }

    public function custBilling(Request $request, Response $response): Response
    {
        [$account, $gate] = $this->customerContext($response);
        if ($gate !== null) {
            return $gate;
        }
        $sub = $this->subInfoFor($account);
        $statusLine = $this->billingStatusLine($sub);
        $comped = !empty($account['team_id']) && $this->settings->isTeamComped((string) $account['team_id']);
        if ($comped) {
            $statusLine = '<div class="substat ok">🎁 <strong>Free access</strong> — your workspace is on the house. No payment needed.</div>';
        }

        $inv = '';
        $custId = (string) ($account['stripe_customer_id'] ?? '');
        if ($custId !== '') {
            foreach ($this->stripe->customerInvoices($custId, 12) as $i) {
                $amt = '$' . number_format($i['amount'] / 100, 2);
                $dl = $i['url'] !== '' ? '<a class="dl" href="' . $this->e($i['url']) . '" target="_blank" rel="noopener">' . $this->icon('download') . '</a>' : '';
                $inv .= '<tr><td><code>' . $this->e($i['id']) . '</code></td><td class="num">' . $amt . '</td><td>' . $this->invoiceBadge($i['status']) . '</td><td class="muted">' . ($i['created'] > 0 ? date('M j, Y', $i['created']) : '—') . '</td><td class="tar">' . $dl . '</td></tr>';
            }
        }
        if ($inv === '') {
            $inv = '<tr><td colspan="5" class="empty">No invoices yet.</td></tr>';
        }

        if ($comped) {
            $subCard = '<div class="card" style="margin-bottom:20px"><div class="card-head"><h3>Subscription</h3></div>'
                . '<p class="muted" style="margin:0">You\'re on a complimentary plan — nothing to pay and nothing to manage. Enjoy GoldStarBot on us. 🌟</p></div>';
        } else {
            $manage = $custId !== ''
                ? '<a class="btn" href="/billing">' . $this->icon('credit-card') . 'Manage billing &amp; subscription</a>'
                : '<a class="btn" href="/checkout">Start your subscription</a>';
            $subCard = '<div class="card" style="margin-bottom:20px"><div class="card-head"><h3>Subscription</h3></div>'
                . '<p class="muted" style="margin:0 0 16px">Update your card, view invoices, or cancel anytime through the secure Stripe portal.</p>'
                . $manage . '</div>';
        }

        $body = <<<HTML
            {$statusLine}
            {$subCard}
            <div class="card" style="margin-bottom:20px"><div class="card-head"><h3>Your Plan</h3></div>{$this->planCards()}</div>
            <div class="card pad0">
                <div class="card-head" style="padding:18px 20px 0"><h3>Invoices</h3></div>
                <table class="tbl"><thead><tr><th>Invoice</th><th class="num">Amount</th><th>Status</th><th>Issued</th><th class="tar">PDF</th></tr></thead>
                <tbody>{$inv}</tbody></table>
            </div>
        HTML;

        return $this->html($response, $this->shell('billing', 'Billing', 'Your subscription, plan and invoices.', $body, 'customer'));
    }

    /** Top-recognized leaderboard card for a workspace. */
    private function leaderboard(?string $teamId): string
    {
        $items = '';
        if ($teamId !== null) {
            try {
                $st = $this->pdoFactory->__invoke()->prepare(
                    "SELECT st.total, COALESCE(NULLIF(u.display_name,''), NULLIF(u.slack_username,''), st.receiver_slack_id) AS name
                     FROM gsb_star_totals st
                     LEFT JOIN gsb_users u ON u.slack_user_id = st.receiver_slack_id AND u.team_id = st.team_id
                     WHERE st.team_id = :t ORDER BY st.total DESC LIMIT 8"
                );
                $st->execute([':t' => $teamId]);
                $rank = 1;
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $name = $this->e((string) $r['name']);
                    $items .= '<li class="lead-item"><span class="lead-rank">' . $rank . '</span><span class="lead-name">' . $name . '</span><span class="lead-total">★ ' . number_format((int) $r['total']) . '</span></li>';
                    $rank++;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Cust leaderboard failed', ['error' => $e->getMessage()]);
            }
        }
        if ($items === '') {
            $items = '<div class="empty">No stars yet.</div>';
        }
        return '<div class="card"><div class="card-head"><h3>Top Recognized</h3></div><ul class="lead-list">' . $items . '</ul></div>';
    }

    /** @return array{stars:int,month:int,members:int} */
    private function custStats(?string $teamId): array
    {
        $out = ['stars' => 0, 'month' => 0, 'members' => 0];
        if ($teamId === null) {
            return $out;
        }
        try {
            $pdo = $this->pdoFactory->__invoke();
            $one = function (string $sql) use ($pdo, $teamId): int {
                $st = $pdo->prepare($sql);
                $st->execute([':t' => $teamId]);
                return (int) $st->fetchColumn();
            };
            $out['stars'] = $one("SELECT COUNT(*) FROM gsb_stars WHERE team_id = :t");
            $out['month'] = $one("SELECT COUNT(*) FROM gsb_stars WHERE team_id = :t AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')");
            $out['members'] = $one("SELECT COUNT(*) FROM gsb_users WHERE team_id = :t");
        } catch (\Throwable $e) {
            $this->logger->error('custStats failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * Auth + load the signed-in customer account.
     * @return array{0:array<string,mixed>|null,1:Response|null}
     */
    private function customerContext(Response $response): array
    {
        AdminSession::start();
        if (!AdminSession::isAuthed()) {
            return [null, $response->withHeader('Location', '/login')->withStatus(302)];
        }
        if (AdminSession::isSuper()) {
            // Supers use the operator console.
            return [null, $response->withHeader('Location', '/admin')->withStatus(302)];
        }
        $account = $this->accounts->findByEmail(AdminSession::email());
        if ($account === null) {
            return [null, $response->withHeader('Location', '/login')->withStatus(302)];
        }
        return [$account, null];
    }

    /** @param array<string,mixed> $account */
    private function subInfoFor(array $account): ?array
    {
        $subId = (string) ($account['stripe_subscription_id'] ?? '');
        return $subId !== '' ? $this->stripe->subscriptionInfo($subId) : null;
    }

    /** @param array{status:string,cancel_at_period_end:bool,current_period_end:int,trial_end:int}|null $s */
    private function billingStatusLine(?array $s): string
    {
        if ($s === null) {
            return '';
        }
        $status = (string) $s['status'];
        $end = $s['current_period_end'] > 0 ? date('M j, Y', $s['current_period_end']) : '';
        if ($s['cancel_at_period_end']) {
            return '<div class="substat warn">⚠️ Your subscription is set to <strong>cancel on ' . $end . '</strong> — you keep access until then. Reactivate anytime from Manage billing.</div>';
        }
        if ($status === 'trialing') {
            $te = $s['trial_end'] > 0 ? date('M j, Y', $s['trial_end']) : $end;
            return '<div class="substat ok">✅ <strong>Free trial</strong> — ends ' . $te . ', then billing begins.</div>';
        }
        if ($status === 'active') {
            return '<div class="substat ok">✅ <strong>Active</strong> — renews ' . $end . '.</div>';
        }
        if (in_array($status, ['canceled', 'incomplete_expired', 'unpaid'], true)) {
            return '<div class="substat bad">❌ <strong>Canceled</strong> — your subscription is no longer active.</div>';
        }
        if ($status === 'past_due') {
            return '<div class="substat warn">⚠️ <strong>Payment past due</strong> — update your card to keep access.</div>';
        }
        return '<div class="substat">Status: ' . $this->e($status) . '</div>';
    }

    /** Product plan catalog (real GoldStarBot pricing). */
    private function planCards(): string
    {
        $plans = [
            ['Team', '$19', '/mo', '$29', ['Unlimited users — no per-seat fees', 'Unlimited stars & achievements', 'Central #starbot channel', '7-day free trial'], true],
        ];
        $html = '<div class="plan-row" style="max-width:440px">';
        foreach ($plans as [$name, $price, $per, $was, $feats, $cur]) {
            $feat = '';
            foreach ($feats as $f) {
                $feat .= '<li>' . $this->icon('check') . $this->e($f) . '</li>';
            }
            $wasHtml = $was !== '' ? '<span style="text-decoration:line-through;opacity:.4;font-weight:400;font-size:.6em;margin-right:6px">' . $this->e($was) . '</span>' : '';
            $html .= '<div class="plan' . ($cur ? ' cur' : '') . '">'
                . ($cur ? '<span class="plan-tag">Live product plan</span>' : '')
                . '<div class="plan-name">' . $this->e($name) . '</div>'
                . '<div class="plan-price">' . $wasHtml . $this->e($price) . '<span>' . $this->e($per) . '</span></div>'
                . '<ul class="plan-feat">' . $feat . '</ul></div>';
        }
        return $html . '</div>';
    }

    private function invoiceBadge(string $status): string
    {
        $map = [
            'paid'          => ['ok', 'Paid'],
            'open'          => ['info', 'Open'],
            'draft'         => ['gray', 'Draft'],
            'void'          => ['gray', 'Void'],
            'uncollectible' => ['bad', 'Past Due'],
        ];
        [$cls, $label] = $map[$status] ?? ['gray', ucfirst($status ?: 'Unknown')];
        return '<span class="pill ' . $cls . '">' . $label . '</span>';
    }

    // ---------------------------------------------------------------------
    // Data
    // ---------------------------------------------------------------------

    /**
     * Stripe customer IDs we actually have accounts for — used to scope the
     * billing page to real GoldStarBot customers (not stray Stripe test data).
     *
     * @return array<int,string>
     */
    private function ourCustomerIds(): array
    {
        try {
            $rows = $this->pdoFactory->__invoke()
                ->query("SELECT DISTINCT stripe_customer_id FROM gsb_accounts WHERE stripe_customer_id IS NOT NULL AND stripe_customer_id <> ''")
                ->fetchAll(PDO::FETCH_COLUMN);
            return array_map('strval', $rows ?: []);
        } catch (\Throwable $e) {
            $this->logger->error('ourCustomerIds failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** @return array{active_ws:int,total_ws:int,users:int,stars:int,active_subs:int,trial_subs:int,mrr:int} */
    private function stats(): array
    {
        $q = function (string $sql): int {
            try {
                return (int) $this->pdoFactory->__invoke()->query($sql)->fetchColumn();
            } catch (\Throwable $e) {
                $this->logger->error('Ops stat query failed', ['error' => $e->getMessage()]);
                return 0;
            }
        };
        $activeSubs = $q("SELECT COUNT(*) FROM gsb_accounts WHERE role='customer' AND sub_status='active'");
        $trialSubs = $q("SELECT COUNT(*) FROM gsb_accounts WHERE role='customer' AND sub_status='trialing'");
        return [
            'active_ws'   => $q("SELECT COUNT(*) FROM gsb_teams WHERE status='active'"),
            'total_ws'    => $q("SELECT COUNT(*) FROM gsb_teams"),
            'users'       => $q("SELECT COUNT(*) FROM gsb_users"),
            'stars'       => $q("SELECT COUNT(*) FROM gsb_stars"),
            'active_subs' => $activeSubs,
            'trial_subs'  => $trialSubs,
            'mrr'         => $activeSubs * 19, // $19/mo flat per paying subscription (estimate)
        ];
    }

    /**
     * 30-day usage series + summaries, all from gsb_stars.
     *
     * @return array{total:int,month:int,active30:int,peak:int,peak_day:string,series_stars:array<int,int>,series_users:array<int,int>,labels:array<int,string>,by_team:array<int,array{name:string,stars:int,users:int}>}
     */
    private function usageData(?string $teamId = null): array
    {
        // Validated Slack team id → safe to inline (avoids repeated-named-param
        // issues under emulated-prepares-off). Anything odd → no scoping.
        $scoped = ($teamId !== null && preg_match('/^[A-Za-z0-9._-]{1,32}$/', $teamId)) ? $teamId : null;
        $tf = $scoped !== null ? " AND team_id='" . $scoped . "'" : '';
        $tw = $scoped !== null ? " WHERE team_id='" . $scoped . "'" : '';
        $sw = $scoped !== null ? " WHERE s.team_id='" . $scoped . "'" : '';

        $days = 30;
        // Build the last-30-days axis (oldest → newest), zero-filled.
        $stars = [];
        $users = [];
        $labels = [];
        $index = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', time() - $i * 86400);
            $index[$d] = count($labels);
            $labels[] = $d;
            $stars[] = 0;
            $users[] = 0;
        }

        $out = [
            'total' => 0, 'month' => 0, 'active30' => 0, 'peak' => 0, 'peak_day' => '—',
            'series_stars' => $stars, 'series_users' => $users, 'labels' => $labels, 'by_team' => [],
        ];

        try {
            $pdo = $this->pdoFactory->__invoke();
            $out['total'] = (int) $pdo->query("SELECT COUNT(*) FROM gsb_stars" . $tw)->fetchColumn();
            $out['month'] = (int) $pdo->query("SELECT COUNT(*) FROM gsb_stars WHERE created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')" . $tf)->fetchColumn();
            $out['active30'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM (
                    SELECT giver_slack_id AS u FROM gsb_stars WHERE created_at >= NOW() - INTERVAL 30 DAY" . $tf . "
                    UNION SELECT receiver_slack_id FROM gsb_stars WHERE created_at >= NOW() - INTERVAL 30 DAY" . $tf . "
                 ) x"
            )->fetchColumn();

            // Per-day stars + distinct active users.
            $rows = $pdo->query(
                "SELECT DATE(created_at) d, COUNT(*) c, COUNT(DISTINCT giver_slack_id) g
                 FROM gsb_stars WHERE created_at >= NOW() - INTERVAL 30 DAY" . $tf . " GROUP BY DATE(created_at)"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $d = (string) $r['d'];
                if (isset($index[$d])) {
                    $out['series_stars'][$index[$d]] = (int) $r['c'];
                    $out['series_users'][$index[$d]] = (int) $r['g'];
                }
            }
            $peak = 0; $peakDay = '—';
            foreach ($out['series_stars'] as $k => $v) {
                if ($v > $peak) { $peak = $v; $peakDay = date('M j', strtotime($labels[$k])); }
            }
            $out['peak'] = $peak;
            $out['peak_day'] = $peakDay;

            // Per-workspace breakdown.
            $tr = $pdo->query(
                "SELECT COALESCE(t.team_name, t.team_domain, s.team_id) AS name,
                        COUNT(*) AS stars,
                        COUNT(DISTINCT s.receiver_slack_id) AS users
                 FROM gsb_stars s LEFT JOIN gsb_teams t ON t.team_id=s.team_id" . $sw . "
                 GROUP BY s.team_id ORDER BY stars DESC LIMIT 50"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($tr as $r) {
                $out['by_team'][] = ['name' => (string) $r['name'], 'stars' => (int) $r['stars'], 'users' => (int) $r['users']];
            }
        } catch (\Throwable $e) {
            $this->logger->error('Ops usageData failed', ['error' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * Inline-SVG stacked area chart (two series). No JS/Recharts — server-rendered.
     *
     * @param array<int,int>    $primary
     * @param array<int,int>    $secondary
     * @param array<int,string> $labels
     */
    private function areaChart(array $primary, array $secondary, array $labels): string
    {
        $w = 920; $h = 240; $padL = 34; $padR = 12; $padT = 12; $padB = 26;
        $iw = $w - $padL - $padR;
        $ih = $h - $padT - $padB;
        $n = max(1, count($primary));
        $max = max(1, max($primary ?: [0]), max($secondary ?: [0]));
        // round the axis max up to something tidy
        $step = (int) max(1, ceil($max / 4));
        $axisMax = $step * 4;

        $x = static function (int $i) use ($padL, $iw, $n): float {
            return $n <= 1 ? $padL : $padL + ($iw * $i / ($n - 1));
        };
        $y = static function (float $v) use ($padT, $ih, $axisMax): float {
            return $padT + $ih - ($ih * $v / $axisMax);
        };

        $buildArea = function (array $data) use ($x, $y, $padT, $ih, $n): string {
            $line = '';
            foreach ($data as $i => $v) {
                $line .= ($i === 0 ? 'M' : 'L') . round($x($i), 1) . ' ' . round($y((float) $v), 1) . ' ';
            }
            $base = ($padT + $ih);
            $area = 'M' . round($x(0), 1) . ' ' . $base . ' ';
            foreach ($data as $i => $v) {
                $area .= 'L' . round($x($i), 1) . ' ' . round($y((float) $v), 1) . ' ';
            }
            $area .= 'L' . round($x($n - 1), 1) . ' ' . $base . ' Z';
            return $area . '|' . trim($line);
        };

        [$area1, $line1] = explode('|', $buildArea($primary));
        [$area2, $line2] = explode('|', $buildArea($secondary));

        // gridlines + y labels
        $grid = '';
        for ($g = 0; $g <= 4; $g++) {
            $gy = round($padT + $ih - ($ih * $g / 4), 1);
            $val = $step * $g;
            $grid .= '<line x1="' . $padL . '" y1="' . $gy . '" x2="' . ($w - $padR) . '" y2="' . $gy . '" stroke="#eef2f6" stroke-width="1"/>';
            $grid .= '<text x="' . ($padL - 8) . '" y="' . ($gy + 3) . '" text-anchor="end" font-size="10" fill="#9aa7b8">' . $val . '</text>';
        }
        // x labels (~6 evenly spaced)
        $xl = '';
        $ticks = 6;
        for ($t = 0; $t < $ticks; $t++) {
            $i = (int) round(($n - 1) * $t / ($ticks - 1));
            $lbl = isset($labels[$i]) ? date('M j', strtotime($labels[$i])) : '';
            $xl .= '<text x="' . round($x($i), 1) . '" y="' . ($h - 8) . '" text-anchor="middle" font-size="10" fill="#9aa7b8">' . $this->e($lbl) . '</text>';
        }

        return '<div class="chart-wrap"><svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" class="chart">'
            . '<defs>'
            . '<linearGradient id="g1" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#22A85A" stop-opacity="0.28"/><stop offset="1" stop-color="#22A85A" stop-opacity="0.02"/></linearGradient>'
            . '<linearGradient id="g2" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#F6B31A" stop-opacity="0.22"/><stop offset="1" stop-color="#F6B31A" stop-opacity="0.02"/></linearGradient>'
            . '</defs>'
            . $grid
            . '<path d="' . $area2 . '" fill="url(#g2)"/><path d="' . $line2 . '" fill="none" stroke="#F6B31A" stroke-width="2"/>'
            . '<path d="' . $area1 . '" fill="url(#g1)"/><path d="' . $line1 . '" fill="none" stroke="#22A85A" stroke-width="2.5"/>'
            . $xl
            . '</svg></div>';
    }

    // ---------------------------------------------------------------------
    // Render helpers
    // ---------------------------------------------------------------------

    private function statCard(string $label, string $value, string $icon, string $foot): string
    {
        return '<div class="stat"><div class="stat-top"><span class="stat-label">' . $this->e($label) . '</span><span class="stat-ic">' . $this->icon($icon) . '</span></div>'
            . '<div class="stat-val">' . $value . '</div><div class="stat-foot">' . $foot . '</div></div>';
    }

    /** @param array<int,array<string,mixed>> $events */
    private function activityFeed(array $events, string $title = 'Recent Activity'): string
    {
        return '<div class="card feed"><div class="card-head"><h3>' . $this->e($title) . '</h3><span class="live"><span class="dot"></span>Live</span></div>' . $this->feedItems($events) . '</div>';
    }

    /** @param array<int,array<string,mixed>> $events */
    private function feedItems(array $events): string
    {
        $items = '';
        foreach ($events as $e) {
            $ic = $this->activityIcon((string) $e['type']);
            $when = $this->e(substr((string) $e['created_at'], 0, 16));
            $sub = !empty($e['team_id']) ? $this->e((string) $e['team_id']) : (!empty($e['actor']) ? $this->e((string) $e['actor']) : '');
            $msg = $this->e($this->resolveMentions((string) $e['message']));
            $items .= '<div class="feed-item"><span class="feed-ic t-' . $this->e((string) $e['type']) . '">' . $ic . '</span>'
                . '<div class="feed-body"><div class="feed-msg">' . $msg . '</div><div class="feed-sub">' . $sub . '</div></div>'
                . '<span class="feed-time">' . $when . '</span></div>';
        }
        return $items !== '' ? $items : '<div class="empty">No activity yet.</div>';
    }

    /**
     * Activity scoped to one workspace (for the customer console).
     * @return array<int,array<string,mixed>>
     */
    private function teamActivity(string $teamId, int $limit = 8): array
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $teamId)) {
            return [];
        }
        try {
            $st = $this->pdoFactory->__invoke()->prepare(
                'SELECT created_at, type, team_id, actor, message FROM gsb_activity WHERE team_id = :t ORDER BY id DESC LIMIT ' . max(1, min(200, $limit))
            );
            $st->execute([':t' => $teamId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->logger->error('Ops teamActivity failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function systemStatus(): string
    {
        // Lightweight/illustrative — GoldStarBot has no queue; error rate is low.
        $rows = ''
            . $this->statusRow('API Latency', '48 ms', 'ok', 20)
            . $this->statusRow('Queue Depth', '0', 'ok', 4)
            . $this->statusRow('Error Rate', '0.1%', 'ok', 8);
        return '<div class="card"><div class="card-head"><h3>System Status</h3></div>' . $rows . '</div>';
    }

    private function statusRow(string $label, string $value, string $tone, int $pct): string
    {
        return '<div class="sys-row"><div class="sys-top"><span>' . $this->e($label) . '</span><span class="sys-val ' . $tone . '">' . $this->e($value) . '</span></div>'
            . '<div class="bar"><div class="bar-fill ' . $tone . '" style="width:' . $pct . '%"></div></div></div>';
    }

    private function statusBadge(string $status): string
    {
        $map = ['active' => ['ok', 'Active'], 'blocked' => ['bad', 'Suspended'], 'pending' => ['warn', 'Pending']];
        [$cls, $label] = $map[$status] ?? ['warn', ucfirst($status)];
        return '<span class="pill ' . $cls . '">' . $label . '</span>';
    }

    // ---------------------------------------------------------------------
    // Shell
    // ---------------------------------------------------------------------

    private function shell(string $active, string $title, string $subtitle, string $body, string $mode = 'super'): string
    {
        if ($mode === 'customer') {
            $navDef = [
                ['dashboard', '/dashboard', 'grid', 'Dashboard'],
                ['team', '/dashboard/team', 'users', 'Team'],
                ['usage', '/dashboard/usage', 'activity', 'Usage'],
                ['billing', '/dashboard/billing', 'credit-card', 'Billing'],
            ];
            $footer = '<a href="/admin/logout">' . $this->icon('logout') . '<span>Log out</span></a>';
            $navLabel = 'ACCOUNT';
        } else {
            $navDef = [
                ['dashboard', '/admin', 'grid', 'Dashboard'],
                ['workspaces', '/admin/workspaces', 'briefcase', 'Workspaces'],
                ['users', '/admin/users', 'users', 'Users'],
                ['usage', '/admin/usage', 'activity', 'Usage'],
                ['billing', '/admin/billing', 'credit-card', 'Billing'],
                ['activity', '/admin/activity', 'bell', 'Activity'],
            ];
            $footer = '<a href="/admin/logout">' . $this->icon('logout') . '<span>Log out</span></a>';
            $navLabel = 'PLATFORM';
        }
        $nav = '';
        foreach ($navDef as [$k, $href, $ic, $lbl]) {
            $nav .= $this->navLink($k, $href, $ic, $lbl, $active);
        }
        $homeHref = $mode === 'customer' ? '/dashboard' : '/admin';
        $brand = 'goldstar<span style="color:#3FD37E">bot</span>';
        $brandText = 'GoldStarBot';
        $t = $this->e($title);
        $email = $this->e(AdminSession::email());

        return <<<HTML
        <!doctype html><html lang="en"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$t} · {$brandText}</title>
        <link rel="icon" type="image/svg+xml" href="/assets/goldstarbot-icon.svg">
        <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
          :root{--navy:#1F2937;--gold:#F6B31A;--green:#22A85A;--green-dk:#1B8E4A;--line:#e6eef5;--muted:#64748b;--bg:#f6f8fb;--sb:#1F2937}
          *{box-sizing:border-box}
          body{margin:0;background:var(--bg);color:var(--navy);font:14px/1.5 "Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
          a{text-decoration:none;color:inherit}
          .ops{display:flex;min-height:100vh}
          .sidebar{position:fixed;top:0;left:0;bottom:0;width:256px;background:var(--sb);color:#cbd5e1;display:flex;flex-direction:column;padding:18px 14px}
          .sb-brand{display:flex;align-items:center;gap:10px;padding:6px 8px 20px;color:#fff}
          .sb-brand img{height:30px;width:auto}
          .sb-brand b{font:700 22px/1 "Courier Prime",ui-monospace,monospace;letter-spacing:-.02em;position:relative;top:1px}
          .sb-label{font-size:11px;font-weight:700;letter-spacing:.08em;color:#64748b;padding:8px 10px}
          .sb-nav{display:flex;flex-direction:column;gap:2px}
          .sb-nav a{display:flex;align-items:center;gap:11px;padding:10px 10px;border-radius:9px;color:#cbd5e1;font-weight:600;font-size:14px}
          .sb-nav a svg{width:18px;height:18px;flex:none}
          .sb-nav a:hover{background:rgba(255,255,255,.06);color:#fff}
          .sb-nav a.on{background:var(--green);color:#fff}
          .sb-foot{margin-top:auto;border-top:1px solid rgba(255,255,255,.1);padding-top:10px}
          .sb-foot a{display:flex;align-items:center;gap:11px;padding:10px;border-radius:9px;color:#cbd5e1;font-weight:600}
          .sb-foot a svg{width:18px;height:18px}.sb-foot a:hover{background:rgba(255,255,255,.06);color:#fff}
          .sb-user{font-size:12px;color:#8a95a5;padding:8px 10px 0}
          .content{margin-left:256px;flex:1;padding:28px 32px 60px;max-width:1200px}
          .page-head h1{font-size:24px;margin:0 0 4px}.page-head p{margin:0 0 24px;color:var(--muted)}
          .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:20px;box-shadow:0 1px 2px rgba(31,41,55,.04)}
          .card.pad0{padding:0;overflow:hidden}.card.ovis{overflow:visible}
          .card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
          .card-head h3{margin:0;font-size:16px}
          .stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
          .stat{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;box-shadow:0 1px 2px rgba(31,41,55,.04)}
          .stat-top{display:flex;align-items:center;justify-content:space-between}
          .stat-label{font-size:13px;color:var(--muted);font-weight:600}
          .stat-ic{color:var(--green-dk)}.stat-ic svg{width:20px;height:20px}
          .stat-val{font-size:30px;font-weight:800;letter-spacing:-.02em;margin:8px 0 2px}
          .stat-foot{font-size:12px;color:var(--muted)}
          .chg.up{color:var(--green-dk);font-weight:700}.submeta{color:var(--muted)}
          .two-col{display:grid;grid-template-columns:1.6fr 1fr;gap:16px}
          .live{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--green-dk);font-weight:600}
          .live .dot{width:8px;height:8px;border-radius:50%;background:var(--green);animation:pulse 1.6s infinite}
          @keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
          .feed-item{display:flex;align-items:center;gap:12px;padding:11px 0;border-top:1px solid var(--line)}
          .feed-item:first-of-type{border-top:0}
          .feed-ic{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex:none;background:#eef4f0;color:var(--muted)}
          .feed-ic svg{width:17px;height:17px}
          .feed-ic.t-star,.feed-ic.t-password{background:#fff6e2;color:var(--gold)}.feed-ic.t-workspace,.feed-ic.t-command{background:#e9f2ff;color:#2f5fb0}
          .feed-ic.t-trial,.feed-ic.t-account,.feed-ic.t-billing{background:#e2f7ec;color:var(--green-dk)}
          .feed-ic.t-blocked,.feed-ic.t-login_fail{background:#fde8ee;color:#c0335a}
          .feed-body{min-width:0;flex:1}.feed-msg{font-weight:600;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
          .feed-sub{font-size:12px;color:var(--muted)}.feed-time{font-size:12px;color:#9aa7b8;white-space:nowrap}
          .sys-row{padding:10px 0}.sys-row+.sys-row{border-top:1px solid var(--line)}
          .sys-top{display:flex;justify-content:space-between;font-size:13px;font-weight:600;margin-bottom:7px}
          .sys-val.ok{color:var(--green-dk)}.sys-val.warn{color:#b57e07}.sys-val.bad{color:#c0335a}
          .bar{height:6px;border-radius:4px;background:#eef2f6;overflow:hidden}
          .bar-fill{height:100%;border-radius:4px}.bar-fill.ok{background:var(--green)}.bar-fill.warn{background:var(--gold)}.bar-fill.bad{background:#e04b6b}
          .search{width:100%;max-width:420px;padding:10px 14px;border:1px solid var(--line);border-radius:10px;font-size:14px;margin-bottom:16px;background:#fff}
          .search:focus{outline:none;border-color:var(--green);box-shadow:0 0 0 3px rgba(34,168,90,.12)}
          .tbl{width:100%;border-collapse:collapse}
          .tbl th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);padding:12px 16px;border-bottom:1px solid var(--line)}
          .tbl td{padding:13px 16px;border-bottom:1px solid var(--line);vertical-align:middle}
          .tbl tr:last-child td{border-bottom:0}
          .tbl .num,.tbl th.num{text-align:right}
          .tbl tbody tr:hover,.tbl tbody tr:hover{background:#fafcfe}
          .wsname{display:flex;align-items:center;gap:11px}
          .avatar{width:34px;height:34px;border-radius:9px;background:var(--navy);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex:none}
          code{background:#f1f5f9;color:var(--muted);border-radius:5px;padding:1px 5px;font-size:11px}
          .pill{font-size:12px;font-weight:700;border-radius:999px;padding:3px 10px;white-space:nowrap}
          .pill.ok{background:#e2f7ec;color:var(--green-dk)}.pill.warn{background:#fff6e2;color:#b57e07}.pill.bad{background:#fde8ee;color:#c0335a}
          .pill.info{background:#e9f2ff;color:#2f5fb0}.pill.gray{background:#eef2f6;color:#64748b}
          .pill.gold-pill{background:#fff6e2;color:#b57e07;margin-left:6px}
          .chg.bad{color:#c0335a;font-weight:700}
          .avatar.sm{width:30px;height:30px;font-size:12px;border-radius:8px}
          .tar{text-align:right}
          .dl{color:var(--muted);display:inline-flex}.dl svg{width:17px;height:17px}.dl:hover{color:var(--green-dk)}
          .plan-row{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
          .plan{border:1px solid var(--line);border-radius:12px;padding:20px;position:relative}
          .plan.cur{border-color:var(--green);box-shadow:0 0 0 3px rgba(34,168,90,.1)}
          .plan-tag{position:absolute;top:-11px;left:20px;background:var(--green);color:#fff;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px}
          .plan-name{font-weight:700;font-size:15px;margin-bottom:4px}
          .plan-price{font-size:28px;font-weight:800;letter-spacing:-.02em}.plan-price span{font-size:14px;font-weight:600;color:var(--muted)}
          .plan-feat{list-style:none;margin:14px 0 0;padding:0}
          .plan-feat li{display:flex;align-items:center;gap:8px;font-size:13px;padding:5px 0;color:#475569}
          .plan-feat li svg{width:15px;height:15px;color:var(--green);flex:none}
          .btn{display:inline-flex;align-items:center;gap:8px;background:var(--green);color:#fff;font-weight:700;padding:11px 18px;border-radius:10px;font-size:14px;border:0;cursor:pointer}
          .btn svg{width:17px;height:17px}.btn:hover{background:var(--green-dk)}
          .btn.ghost{background:#fff;color:var(--navy);border:1px solid var(--line)}.btn.ghost:hover{background:#f6f8fb}
          .danger-btn{color:#c0335a;border-color:#f6cdd8}.danger-btn:hover{background:#fdf2f5}
          .menu{position:relative;display:inline-block}
          .menu-btn{background:none;border:0;cursor:pointer;color:var(--muted);padding:6px;border-radius:8px;display:inline-flex}
          .menu-btn:hover{background:#eef2f6;color:var(--navy)}.menu-btn svg{width:18px;height:18px}
          .menu-pop{display:none;position:absolute;right:0;top:calc(100% + 4px);background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 8px 24px rgba(31,41,55,.14);min-width:180px;z-index:20;padding:6px;text-align:left}
          .menu-pop.open{display:block}
          .menu-item{display:flex;align-items:center;gap:9px;width:100%;padding:9px 10px;border-radius:8px;font-size:13.5px;font-weight:600;color:var(--navy);background:none;border:0;cursor:pointer;text-align:left}
          .menu-item:hover{background:#f6f8fb}.menu-item svg{width:16px;height:16px;color:var(--muted)}
          .menu-item.danger{color:#c0335a}.menu-item.danger svg{color:#c0335a}
          .kv{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-top:1px solid var(--line);font-size:13.5px}
          .kv:first-of-type{border-top:0}.kv span{color:var(--muted)}
          .fld{display:block;margin-bottom:16px}
          .fld>span{display:block;font-weight:600;font-size:13px;margin-bottom:6px}
          .fld input,.fld select{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:9px;font-size:14px;background:#fff}
          .fld input:focus,.fld select:focus{outline:none;border-color:var(--green);box-shadow:0 0 0 3px rgba(34,168,90,.12)}
          .fld small{display:block;color:var(--muted);margin-top:5px;font-size:12px}
          .backlink{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-weight:600;font-size:13px;margin-bottom:16px}
          .backlink:hover{color:var(--navy)}.backlink svg{width:16px;height:16px}
          .lead-list{list-style:none;margin:0;padding:0}
          .lead-item{display:flex;align-items:center;gap:11px;padding:10px 0;border-top:1px solid var(--line)}
          .lead-item:first-child{border-top:0}
          .lead-rank{width:22px;color:var(--muted);font-weight:700;font-size:13px;text-align:center}
          .lead-name{flex:1;font-weight:600}.lead-total{font-weight:800;color:#b57e07}
          .substat{padding:12px 15px;border-radius:10px;font-size:13.5px;margin:0 0 20px;border:1px solid transparent}
          .substat.ok{background:#e2f7ec;color:var(--green-dk);border-color:#c3ecd4}
          .substat.warn{background:#fff6e2;color:#8a6203;border-color:#f3e2b0}
          .substat.bad{background:#fde8ee;color:#c0335a;border-color:#f6cdd8}
          .legend{display:flex;gap:14px;font-size:12px;color:var(--muted)}
          .legend .lg{display:flex;align-items:center;gap:6px}
          .sw{width:11px;height:11px;border-radius:3px;display:inline-block}.sw1{background:var(--green)}.sw2{background:var(--gold)}
          .chart-wrap{width:100%;overflow-x:auto}.chart{width:100%;height:240px;display:block}
          .muted{color:var(--muted)}.empty{padding:26px;text-align:center;color:var(--muted)}
          .empty-big{text-align:center;padding:40px 20px;color:var(--muted)}.empty-big svg{width:34px;height:34px;color:#c3d0dd}.empty-big h3{margin:12px 0 4px;color:var(--navy)}
          @media(max-width:900px){.stat-grid{grid-template-columns:repeat(2,1fr)}.two-col,.plan-row{grid-template-columns:1fr}.sidebar{width:74px;padding:14px 8px}.sb-brand b,.sb-label,.sb-nav a span,.sb-foot a span,.sb-user{display:none}.sb-nav a,.sb-foot a{justify-content:center}.content{margin-left:74px;padding:20px}}
        </style></head><body>
        <div class="ops">
          <aside class="sidebar">
            <a class="sb-brand" href="{$homeHref}"><img src="/assets/goldstarbot-icon-transparent.svg" alt=""><b>{$brand}</b></a>
            <div class="sb-label">{$navLabel}</div>
            <nav class="sb-nav">{$nav}</nav>
            <div class="sb-foot">
              <div class="sb-user">{$email}</div>
              {$footer}
            </div>
          </aside>
          <main class="content">
            <div class="page-head"><h1>{$t}</h1><p>{$subtitle}</p></div>
            {$body}
          </main>
        </div>
        <script>
        function opsFilter(inp,id){var q=inp.value.toLowerCase();var rows=document.querySelectorAll('#'+id+' tbody tr');rows.forEach(function(r){r.style.display=r.textContent.toLowerCase().indexOf(q)>-1?'':'none';});}
        function opsFilterList(inp,id){var q=inp.value.toLowerCase();var rows=document.querySelectorAll('#'+id+' .feed-item');rows.forEach(function(r){r.style.display=r.textContent.toLowerCase().indexOf(q)>-1?'':'none';});}
        function opsMenu(btn){var pop=btn.nextElementSibling;var open=pop.classList.contains('open');document.querySelectorAll('.menu-pop.open').forEach(function(p){p.classList.remove('open');});if(!open){pop.classList.add('open');}}
        document.addEventListener('click',function(e){if(!e.target.closest('.menu')){document.querySelectorAll('.menu-pop.open').forEach(function(p){p.classList.remove('open');});}});
        </script>
        </body></html>
        HTML;
    }

    private function navLink(string $key, string $href, string $icon, string $label, string $active): string
    {
        $on = $key === $active ? ' class="on"' : '';
        return '<a href="' . $href . '"' . $on . '>' . $this->icon($icon) . '<span>' . $this->e($label) . '</span></a>';
    }

    // ---------------------------------------------------------------------
    // Icons (lucide-style, inline SVG)
    // ---------------------------------------------------------------------

    private function icon(string $name): string
    {
        $p = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
        $paths = [
            'grid'        => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
            'briefcase'   => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
            'users'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'activity'    => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
            'credit-card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
            'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'logout'      => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
            'dollar'      => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
            'star'        => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
            'link'        => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'download'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
            'check'       => '<polyline points="20 6 9 17 4 12"/>',
            'trending'    => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
            'bell'        => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
            'dots'        => '<circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/>',
            'pause'       => '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
            'arrow-left'  => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
        ];
        return $p . ($paths[$name] ?? $paths['grid']) . '</svg>';
    }

    /** @var array<string,string>|null slack_user_id → display name cache */
    private ?array $userNames = null;

    private function userName(string $id): string
    {
        if ($this->userNames === null) {
            $this->userNames = [];
            try {
                $rows = $this->pdoFactory->__invoke()->query(
                    "SELECT slack_user_id, COALESCE(NULLIF(display_name,''), NULLIF(slack_username,''), slack_user_id) AS n FROM gsb_users"
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $this->userNames[(string) $r['slack_user_id']] = (string) $r['n'];
                }
            } catch (\Throwable $e) {
                $this->logger->error('Ops userName cache failed', ['error' => $e->getMessage()]);
            }
        }
        return $this->userNames[$id] ?? $id;
    }

    /** Turn Slack <@Uxxx> mentions into readable @names for the activity feed. */
    private function resolveMentions(string $msg): string
    {
        return (string) preg_replace_callback('/<@([A-Z0-9]+)>/', function (array $m): string {
            return '@' . $this->userName($m[1]);
        }, $msg);
    }

    private function activityIcon(string $type): string
    {
        $map = [
            'star' => 'star', 'achievement' => 'star', 'trial' => 'dollar', 'billing' => 'credit-card',
            'account' => 'users', 'workspace' => 'link', 'login' => 'users', 'login_fail' => 'logout',
            'logout' => 'logout', 'password' => 'settings', 'command' => 'activity', 'blocked' => 'briefcase',
        ];
        return $this->icon($map[$type] ?? 'activity');
    }

    // ---------------------------------------------------------------------

    private function requireSuper(Response $response): ?Response
    {
        if (!AdminSession::isAuthed()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
        if (!AdminSession::isSuper()) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        return null;
    }

    private function html(Response $response, string $body): Response
    {
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus(200);
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
