<?php
declare(strict_types=1);

namespace App\Application\Actions;

use App\Support\AccountService;
use App\Support\ActivityService;
use App\Support\StripeService;
use Closure;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * 7-day free-trial checkout page (/checkout).
 *
 * Captures a trial lead (workspace + contact) into gsb_leads. Card fields are
 * present for UX fidelity but are NOT transmitted to or stored on the server —
 * real billing belongs in a PCI-compliant processor (e.g. Stripe Elements).
 */
final class CheckoutController
{
    private const TRIAL_DAYS = 7;

    /** @var array<string,array{label:string,after:string,features:array<int,string>}> */
    private const PLANS = [
        'team' => [
            'label' => 'Team',
            'after' => '$19/mo — unlimited users',
            'features' => [
                'Full GoldStarBot access for 7 days',
                'Flat $19/mo for your whole team — unlimited users',
                'No per-seat fees, ever',
                'Central #starbot channel',
                'Tallies, leaderboard &amp; all 6 achievements',
                'Priority email support',
                'Card required — free for 7 days, then billed',
            ],
        ],
    ];

    /** @var Closure():PDO */
    private Closure $pdoFactory;
    private AccountService $accounts;
    private ActivityService $activity;
    private StripeService $stripe;
    private LoggerInterface $logger;

    /** @param Closure():PDO $pdoFactory */
    public function __construct(Closure $pdoFactory, AccountService $accounts, ActivityService $activity, StripeService $stripe, LoggerInterface $logger)
    {
        $this->pdoFactory = $pdoFactory;
        $this->accounts = $accounts;
        $this->activity = $activity;
        $this->stripe = $stripe;
        $this->logger = $logger;
    }

    public function show(Request $request, Response $response): Response
    {
        $plan = $this->resolvePlan($request->getQueryParams()['plan'] ?? 'team');
        return $this->html($response, $this->renderCheckout($plan, [], []));
    }

    public function submit(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $plan = $this->resolvePlan($data['plan'] ?? 'team');

        $old = [
            'workspace'  => trim((string) ($data['workspace'] ?? '')),
            'first_name' => trim((string) ($data['first_name'] ?? '')),
            'last_name'  => trim((string) ($data['last_name'] ?? '')),
            'email'      => trim((string) ($data['email'] ?? '')),
            'slack_url'  => trim((string) ($data['slack_url'] ?? '')),
        ];

        $errors = [];
        if ($old['workspace'] === '')  { $errors[] = 'Company / workspace name is required.'; }
        if ($old['first_name'] === '') { $errors[] = 'First name is required.'; }
        if ($old['last_name'] === '')  { $errors[] = 'Last name is required.'; }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'A valid email is required.'; }
        if (empty($data['accept_terms']))   { $errors[] = 'Please accept the Terms & Conditions.'; }
        if (empty($data['accept_privacy'])) { $errors[] = 'Please accept the Privacy Policy.'; }

        if ($errors !== []) {
            return $this->html($response, $this->renderCheckout($plan, $errors, $old), 422);
        }

        try {
            $this->saveLead($plan, $old);
        } catch (\Throwable $e) {
            $this->logger->error('Checkout: saveLead failed', ['error' => $e->getMessage()]);
        }

        // Preferred path: hosted Stripe Checkout — real card, 7-day trial, then auto-charge.
        if ($this->stripe->isConfigured()) {
            $meta = [
                'plan'       => $plan,
                'workspace'  => $old['workspace'],
                'first_name' => $old['first_name'],
                'last_name'  => $old['last_name'],
                'slack_url'  => $old['slack_url'],
            ];
            $session = $this->stripe->createCheckoutSession(
                $plan,
                $old['email'],
                $meta,
                'https://goldstarbot.com/checkout/success?session_id={CHECKOUT_SESSION_ID}',
                'https://goldstarbot.com/checkout?plan=' . rawurlencode($plan) . '&canceled=1'
            );
            if ($session !== null && $session->url) {
                return $response->withHeader('Location', $session->url)->withStatus(303);
            }
            $this->logger->error('Checkout: Stripe session creation returned null; using fallback');
        }

        // Fallback (Stripe not configured / hiccup): create the account directly.
        try {
            $res = $this->accounts->startCustomer($old['email'], $old['workspace']);
            $this->activity->log(ActivityService::T_TRIAL, sprintf('Started a %s trial for “%s”', $plan, $old['workspace']), null, $old['email']);
            if ($res['needs_password']) {
                $token = (string) ($res['account']['setup_token'] ?? '');
                return $response->withHeader('Location', '/set-password?token=' . rawurlencode($token))->withStatus(302);
            }
            return $response->withHeader('Location', '/admin/login')->withStatus(302);
        } catch (\Throwable $e) {
            $this->logger->error('Checkout: account fallback failed', ['error' => $e->getMessage()]);
            return $this->html($response, $this->renderSuccess($old['first_name'], $old['email']));
        }
    }

    /**
     * Stripe redirects here after a successful checkout. We confirm the session,
     * create the customer account, link the Stripe subscription, and send them
     * on to set a password.
     */
    public function success(Request $request, Response $response): Response
    {
        $sessionId = (string) ($request->getQueryParams()['session_id'] ?? '');
        $session = $this->stripe->retrieveSession($sessionId);

        if ($session === null) {
            return $this->html($response, $this->renderSuccess('', ''));
        }

        $email     = (string) ($session->customer_email ?? ($session->customer_details->email ?? ''));
        $meta      = (array) ($session->metadata ?? []);
        $workspace = (string) ($meta['workspace'] ?? '');
        $first     = (string) ($meta['first_name'] ?? '');
        $plan      = (string) ($meta['plan'] ?? 'team');

        $customerId = is_string($session->customer) ? $session->customer : (string) ($session->customer->id ?? '');
        $subId      = is_string($session->subscription) ? $session->subscription : (string) ($session->subscription->id ?? '');
        $subStatus  = is_object($session->subscription) ? (string) ($session->subscription->status ?? 'trialing') : 'trialing';

        try {
            $res = $this->accounts->startCustomer($email, $workspace);
            $acc = $res['account'];
            if (!empty($acc['id'])) {
                $this->accounts->attachStripe((int) $acc['id'], $customerId, $subId, $subStatus);
            }
            $this->activity->log(ActivityService::T_TRIAL, sprintf('Started a %s trial for “%s”', $plan, $workspace), null, $email);

            if ($res['needs_password']) {
                $token = (string) ($acc['setup_token'] ?? '');
                return $response->withHeader('Location', '/set-password?token=' . rawurlencode($token))->withStatus(302);
            }
            return $response->withHeader('Location', '/admin/login')->withStatus(302);
        } catch (\Throwable $e) {
            $this->logger->error('Checkout success: account failed', ['error' => $e->getMessage()]);
            return $this->html($response, $this->renderSuccess($first, $email));
        }
    }

    // ---------------------------------------------------------------------

    private function resolvePlan(string $plan): string
    {
        $plan = strtolower($plan);
        return isset(self::PLANS[$plan]) ? $plan : 'team';
    }

    /** @param array<string,string> $d */
    private function saveLead(string $plan, array $d): void
    {
        $stmt = $this->pdoFactory->__invoke()->prepare(
            'INSERT INTO gsb_leads (plan, workspace, first_name, last_name, email, slack_url)
             VALUES (:p, :w, :f, :l, :e, :s)'
        );
        $stmt->execute([
            ':p' => $plan,
            ':w' => $d['workspace'],
            ':f' => $d['first_name'],
            ':l' => $d['last_name'],
            ':e' => $d['email'],
            ':s' => $d['slack_url'] !== '' ? $d['slack_url'] : null,
        ]);
    }

    // ---------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------

    /**
     * @param array<int,string> $errors
     * @param array<string,string> $old
     */
    private function renderCheckout(string $plan, array $errors, array $old): string
    {
        $p = self::PLANS[$plan];
        $planLabel = $p['label'];
        $after = $p['after'];
        $afterDisplay = $plan === 'team'
            ? '<span style="text-decoration:line-through;opacity:.45;font-weight:400;margin-right:6px">$29</span>$19/mo'
            : $after;
        $days = self::TRIAL_DAYS;

        $features = '';
        foreach ($p['features'] as $f) {
            $features .= '<li>' . $f . '</li>';
        }

        $errHtml = '';
        if ($errors !== []) {
            $items = '';
            foreach ($errors as $e) {
                $items .= '<li>' . $this->e($e) . '</li>';
            }
            $errHtml = '<div class="errbox"><b>Please fix the following:</b><ul>' . $items . '</ul></div>';
        }

        $v = function (string $k) use ($old): string {
            return isset($old[$k]) ? $this->e($old[$k]) : '';
        };
        $planEsc = $this->e($plan);

        $body = <<<HTML
        <a class="back" href="/#pricing">← Back to pricing</a>
        <div class="grid">
          <div class="left">
            <span class="badge">{$days}-Day Free Trial</span>
            <h1>Get started with GoldStarBot</h1>
            <p class="lede">Start your free trial and give your team recognition today.</p>

            <div class="card summary">
              <div class="srow head">
                <div><div class="k">Selected Plan</div><div class="muted">Free trial</div></div>
                <div class="tar"><div class="plan">{$planLabel}</div><div class="green">{$days} days free</div></div>
              </div>
              <div class="srow"><span>Trial Period</span><b>{$days} days free</b></div>
              <div class="srow"><span>After Trial</span><b>{$afterDisplay}</b></div>
              <div class="srow total"><span>Total due today</span><b>\$0.00</b></div>
              <div class="muted small">Free for {$days} days. Then {$after} — cancel anytime before the trial ends and you won't be charged.</div>
            </div>

            <div class="trust">
              <span>🔒 256-bit encryption</span><span>✓ No setup fees</span><span>⏱ Cancel anytime</span>
            </div>

            <div class="card incl">
              <h3>✨ What's included</h3>
              <ul class="checks">{$features}</ul>
            </div>

            <div class="card secure">
              <div class="shield">🛡️</div>
              <div><b>Secure &amp; private</b><p class="muted small">You won't be charged today. After your {$days}-day trial, billing starts automatically at {$after}. Cancel anytime before then — no charge. Card details are processed securely by Stripe and never stored on our servers.</p></div>
            </div>
          </div>

          <div class="right">
            <form class="card form" method="post" action="/checkout" id="coForm">
              <input type="hidden" name="plan" value="{$planEsc}">
              {$errHtml}

              <div class="sec"><span class="ic">🔒</span><div><h2>Your details</h2><div class="muted small">Tell us about your team</div></div></div>

              <label>Company / workspace name *
                <input name="workspace" value="{$v('workspace')}" placeholder="Acme Inc." required>
              </label>
              <div class="two">
                <label>First name *<input name="first_name" value="{$v('first_name')}" placeholder="First name" required></label>
                <label>Last name *<input name="last_name" value="{$v('last_name')}" placeholder="Last name" required></label>
              </div>
              <div class="two">
                <label>Email *<input type="email" name="email" value="{$v('email')}" placeholder="you@company.com" required></label>
                <label>Slack workspace URL<input name="slack_url" value="{$v('slack_url')}" placeholder="yourteam.slack.com"></label>
              </div>

              <div class="sec"><span class="ic">💳</span><div><h2>Payment <span class="pill">No charge for {$days} days</span></h2><div class="muted small">You'll enter your card securely on the next step</div></div></div>
              <div class="reassure" style="margin:2px 0 16px"><b>🔒 Secured by Stripe</b><p class="muted small" style="margin:4px 0 0">Continue to Stripe's secure checkout to start your trial. No charge today — your card just begins the {$days}-day free trial.</p></div>

              <label class="check"><input type="checkbox" name="accept_terms" value="1"><span><b>I accept the Terms &amp; Conditions</b><br><span class="muted small">Including subscription terms and cancellation policy.</span></span></label>
              <label class="check"><input type="checkbox" name="accept_privacy" value="1"><span><b>I accept the Privacy Policy</b><br><span class="muted small">Your data is protected and never sold to third parties.</span></span></label>

              <div class="reassure">
                <b>💳 What to expect</b>
                <ul class="checks">
                  <li>Full access for {$days} days, free</li>
                  <li>No charge today — your {$days} days are free</li>
                  <li>After the trial, billing starts automatically at {$after}</li>
                  <li>Cancel anytime before the trial ends — no charge</li>
                </ul>
              </div>

              <button class="cta" type="submit">Continue to secure checkout →</button>
            </form>
          </div>
        </div>
        HTML;

        return $this->page('Start your free trial', $body, true);
    }

    private function renderSuccess(string $firstName, string $email): string
    {
        $name = $this->e($firstName !== '' ? $firstName : 'there');
        $mail = $this->e($email);
        $body = <<<HTML
        <div class="card success">
          <div class="big">🎉</div>
          <h1>You're all set, {$name}!</h1>
          <p class="lede">Your {$this->trialDays()}-day free trial is reserved. We've noted <b>{$mail}</b> — check your inbox for next steps.</p>
          <div class="reassure" style="text-align:left;max-width:440px;margin:18px auto">
            <b>Next: connect GoldStarBot to Slack</b>
            <ul class="checks">
              <li>Open the Slack setup guide and install the app</li>
              <li>Approve your workspace in the admin</li>
              <li>Give your first star: <code>/gs @teammate great work</code></li>
            </ul>
          </div>
          <div class="ctarow">
            <a class="cta" href="/setup">Open the setup guide</a>
            <a class="ghost" href="/">Back home</a>
          </div>
        </div>
        HTML;
        return $this->page("You're all set", $body, false);
    }

    private function trialDays(): int
    {
        return self::TRIAL_DAYS;
    }

    private function page(string $title, string $body, bool $wide): string
    {
        $t = $this->e($title);
        $wrapClass = $wide ? 'wrap wide' : 'wrap';
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
          :root{--navy:#1F2937;--gold:#F6B31A;--green:#22A85A;--green-dk:#1B8E4A;--mint:#EAF7EE;--line:#cfeede;--muted:#64748b;--field:#f6fcf8}
          *{box-sizing:border-box}
          body{margin:0;background:#f4faf6;color:var(--navy);font:15px/1.55 "Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
          a{color:var(--green-dk)}
          .topbar{background:#fff;border-bottom:1px solid var(--line)}
          .topbar .in{max-width:1080px;margin:0 auto;padding:14px 20px;display:flex;align-items:center;justify-content:space-between}
          .brand{display:flex;align-items:center;gap:9px;text-decoration:none;color:var(--navy)}
          .brand img{height:28px;width:auto}.brand b{font:700 23px/1 "Courier Prime",ui-monospace,monospace;letter-spacing:-.02em;position:relative;top:2px}.brand .g{color:var(--green)}
          .topbar .lg{color:var(--muted);text-decoration:none;font-weight:600;font-size:14px}
          .wrap{max-width:560px;margin:0 auto;padding:28px 20px 60px}
          .wrap.wide{max-width:1080px}
          .back{display:inline-block;color:var(--muted);text-decoration:none;font-weight:600;margin-bottom:14px}
          .grid{display:grid;grid-template-columns:1fr 1fr;gap:28px;align-items:start}
          h1{font-size:30px;letter-spacing:-.02em;margin:8px 0 6px}
          h2{font-size:18px;margin:0}h3{margin:0 0 10px;font-size:16px}
          .lede{color:var(--muted);font-size:16px;margin:0 0 20px}
          .badge{display:inline-block;background:var(--mint);color:var(--green-dk);font-weight:700;font-size:13px;border-radius:999px;padding:5px 12px}
          .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px;margin-bottom:16px;box-shadow:0 1px 3px rgba(31,41,55,.05)}
          .muted{color:var(--muted)}.small{font-size:13px}.green{color:var(--green-dk)}.tar{text-align:right}
          .summary .srow{display:flex;justify-content:space-between;align-items:flex-start;padding:9px 0;border-bottom:1px solid #eef4f0}
          .summary .srow.head{padding-top:0}.summary .k{font-weight:700;font-size:16px}
          .summary .plan{font-weight:800;font-size:20px;color:var(--green-dk)}
          .summary .total{border-bottom:0;font-size:18px;font-weight:800;padding-top:12px}
          .trust{display:flex;gap:16px;flex-wrap:wrap;justify-content:center;color:var(--muted);font-size:13px;margin:2px 0 18px}
          .checks{list-style:none;padding:0;margin:0}
          .checks li{padding:6px 0 6px 26px;position:relative;font-size:14px}
          .checks li:before{content:"✓";position:absolute;left:0;color:var(--green);font-weight:800}
          .incl{background:linear-gradient(180deg,#f0faf4,#fff)}
          .secure{display:flex;gap:14px;align-items:flex-start}.secure .shield{font-size:22px}
          .secure p{margin:4px 0 0}
          .sec{display:flex;gap:12px;align-items:center;margin:6px 0 14px}
          .sec .ic{font-size:20px}
          .pill{background:var(--mint);color:var(--green-dk);font-size:11px;font-weight:700;border-radius:999px;padding:3px 9px;margin-left:6px;vertical-align:middle}
          label{display:block;font-size:13px;font-weight:600;color:#34506e;margin:0 0 12px}
          input{display:block;width:100%;margin-top:6px;padding:11px 12px;background:var(--field);border:1px solid var(--line);border-radius:9px;font-size:15px;color:var(--navy);font-weight:400}
          input:focus{outline:none;border-color:var(--green);box-shadow:0 0 0 3px rgba(34,168,90,.15)}
          .two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
          label.check{display:flex;gap:10px;align-items:flex-start;font-weight:400;border:1px solid var(--line);border-radius:10px;padding:12px;background:#fff}
          label.check input{width:auto;margin-top:3px}
          .reassure{background:#f0faf4;border:1px solid #cdebd8;border-radius:12px;padding:14px 16px;margin:6px 0 18px}
          .reassure b{display:block;margin-bottom:6px}
          .cta{display:block;width:100%;text-align:center;text-decoration:none;cursor:pointer;border:0;background:var(--green);color:#fff;font-weight:800;font-size:16px;border-radius:12px;padding:15px;box-shadow:0 8px 20px rgba(34,168,90,.25)}
          .cta:hover{background:var(--green-dk)}
          .errbox{background:#fdecef;border:1px solid #f5c2cd;color:#b3253f;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:14px}
          .errbox ul{margin:6px 0 0;padding-left:18px}
          .success{max-width:560px;margin:24px auto;text-align:center}
          .success .big{font-size:52px}
          .ctarow{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:8px}
          .ctarow .cta{width:auto;display:inline-block}
          .ghost{display:inline-block;text-decoration:none;font-weight:700;border-radius:12px;padding:15px 20px;border:1px solid var(--line);color:var(--navy)}
          code{background:#e9f7ef;color:var(--green-dk);border-radius:5px;padding:1px 6px;font-size:13px}
          @media(max-width:820px){.grid{grid-template-columns:1fr}}
        </style></head>
        <body>
          <div class="topbar"><div class="in">
            <a class="brand" href="/"><img src="/assets/goldstarbot-icon-transparent.svg" alt=""><b>goldstar<span class="g">bot</span></b></a>
            <a class="lg" href="/login">Log in</a>
          </div></div>
          <div class="{$wrapClass}">{$body}</div>
        </body></html>
        HTML;
    }

    private function html(Response $response, string $body, int $status = 200): Response
    {
        $response->getBody()->write($body);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withStatus($status);
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
