<?php
declare(strict_types=1);

namespace App\Application\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public Terms of Service and Privacy Policy pages, styled to match the
 * marketing landing page. Required for Slack app distribution and for taking
 * payments.
 */
final class LegalController
{
    private const UPDATED = 'July 18, 2026';
    private const CONTACT = 'support@goldstarbot.com';

    public function terms(Request $request, Response $response): Response
    {
        $c = $this->e(self::CONTACT);
        $body = <<<HTML
        <h1>Terms of Service</h1>
        <p class="upd">Last updated: {$this->e(self::UPDATED)}</p>

        <p>These Terms of Service ("Terms") govern your access to and use of GoldStarBot
        ("GoldStarBot", "we", "us", or "our"), a peer-recognition application for Slack
        available at goldstarbot.com and as an installable Slack app (the "Service"). By
        installing, accessing, or using the Service, you agree to these Terms. If you are
        agreeing on behalf of an organization, you represent that you are authorized to bind
        that organization.</p>

        <h2>1. The Service</h2>
        <p>GoldStarBot lets members of a Slack workspace recognize one another by giving
        "stars" with a slash command, tracks tallies and achievements, and provides an
        administrative dashboard. Features may change over time as we improve the Service.</p>

        <h2>2. Accounts &amp; eligibility</h2>
        <p>To administer the Service you create an account with a valid email address and are
        responsible for keeping your credentials secure and for all activity under your
        account. You must be at least 16 years old and able to form a binding contract. You
        must have authority to install the Service into your Slack workspace.</p>

        <h2>3. Free trial, subscriptions &amp; billing</h2>
        <ul>
          <li><strong>Free trial.</strong> New subscriptions include a 7-day free trial. You
          provide a payment method up front and are not charged until the trial ends unless
          you cancel first.</li>
          <li><strong>Pricing.</strong> GoldStarBot is a flat $19 per month for unlimited
          users, billed monthly in U.S. dollars. There are no per-seat charges.</li>
          <li><strong>Payments.</strong> Payments are processed by Stripe, Inc. We do not store
          your full card details. By subscribing you also agree to Stripe's terms.</li>
          <li><strong>Cancellation.</strong> You may cancel anytime from the billing portal.
          Cancellation takes effect at the end of the current period; fees already incurred are
          non-refundable except where required by law.</li>
          <li><strong>Changes.</strong> We may change pricing on reasonable notice; changes
          apply to the next billing period.</li>
        </ul>

        <h2>4. Acceptable use</h2>
        <p>You agree not to misuse the Service, including: using it to harass, demean, or
        target individuals; uploading unlawful content in star messages; attempting to breach
        security or access data that isn't yours; reverse-engineering the Service; or
        reselling it without our permission. You are responsible for your users' compliance
        with these Terms.</p>

        <h2>5. Your data &amp; Slack</h2>
        <p>When you install GoldStarBot, you authorize us to access limited Slack workspace
        data needed to run the Service (such as workspace and user identifiers, display names,
        and the channel and text of recognition messages). Our handling of that data is
        described in our <a href="/privacy">Privacy Policy</a>. You retain ownership of your
        data; you grant us the rights needed to operate and provide the Service.</p>

        <h2>6. Intellectual property</h2>
        <p>The Service, including its software, branding, and content we provide, is owned by
        us and protected by law. These Terms grant you a limited, non-exclusive,
        non-transferable right to use the Service while your subscription is active.</p>

        <h2>7. Disclaimers</h2>
        <p>The Service is provided "as is" and "as available" without warranties of any kind,
        whether express or implied, including merchantability, fitness for a particular
        purpose, and non-infringement. We do not warrant that the Service will be uninterrupted
        or error-free.</p>

        <h2>8. Limitation of liability</h2>
        <p>To the fullest extent permitted by law, we will not be liable for any indirect,
        incidental, special, consequential, or punitive damages, or for lost profits or data.
        Our total liability for any claim relating to the Service will not exceed the amount
        you paid us in the 12 months before the event giving rise to the claim.</p>

        <h2>9. Termination</h2>
        <p>You may stop using the Service and cancel at any time. We may suspend or terminate
        access if you violate these Terms or to comply with law. On termination, your right to
        use the Service ends; sections that by their nature should survive will survive.</p>

        <h2>10. Changes to these Terms</h2>
        <p>We may update these Terms from time to time. If we make material changes, we will
        update the date above and, where appropriate, notify you. Continued use after changes
        means you accept the updated Terms.</p>

        <h2>11. Governing law</h2>
        <p>These Terms are governed by the laws of the United States and the state in which the
        operator of the Service is established, without regard to conflict-of-laws rules.</p>

        <h2>12. Contact</h2>
        <p>Questions about these Terms? Email <a href="mailto:{$c}">{$c}</a>.</p>
        HTML;

        return $this->page($response, 'Terms of Service', $body);
    }

    public function privacy(Request $request, Response $response): Response
    {
        $c = $this->e(self::CONTACT);
        $body = <<<HTML
        <h1>Privacy Policy</h1>
        <p class="upd">Last updated: {$this->e(self::UPDATED)}</p>

        <p>This Privacy Policy explains what information GoldStarBot ("we", "us") collects, how
        we use it, and your choices. It applies to goldstarbot.com and the GoldStarBot Slack
        app (the "Service").</p>

        <h2>1. Information we collect</h2>
        <ul>
          <li><strong>Account information</strong> — your email address and a hashed password
          for administrators of the Service.</li>
          <li><strong>Slack workspace data</strong> — workspace (team) ID and name, and for
          people who use GoldStarBot: Slack user IDs, usernames/display names, the recognition
          ("star") messages and reasons they send, and the channel involved.</li>
          <li><strong>Usage &amp; security logs</strong> — an activity log of actions taken in
          the Service (sign-ins, commands used, stars given, billing events, workspace
          changes), including the IP address and browser/device (user-agent) for
          authentication events, used to secure accounts and provide an audit trail.</li>
          <li><strong>Payment information</strong> — processed by Stripe. We receive limited
          billing details (such as subscription status and invoices) but <strong>do not store
          your full card number</strong>.</li>
          <li><strong>Cookies</strong> — a single session cookie to keep you signed in. We do
          not use third-party advertising cookies.</li>
        </ul>

        <h2>2. How we use information</h2>
        <ul>
          <li>To provide and operate the Service (record stars, show leaderboards, dashboards).</li>
          <li>To manage subscriptions, trials, and billing.</li>
          <li>To secure accounts, detect abuse, and maintain an audit trail.</li>
          <li>To send transactional email (welcome, password reset, billing notices).</li>
          <li>To provide support and to comply with legal obligations.</li>
        </ul>
        <p>We do <strong>not</strong> sell your personal information.</p>

        <h2>3. How information is shared</h2>
        <ul>
          <li><strong>Stripe</strong> — to process payments and manage subscriptions.</li>
          <li><strong>Slack</strong> — the Service integrates with Slack to send and receive
          messages on your instruction.</li>
          <li><strong>Hosting/infrastructure</strong> — our hosting provider stores data on our
          behalf.</li>
          <li><strong>Legal</strong> — where required to comply with law or protect rights and
          safety.</li>
        </ul>

        <h2>4. Data retention</h2>
        <p>We keep your information for as long as your account or workspace is active and as
        needed to provide the Service, comply with legal obligations, resolve disputes, and
        enforce agreements. You can request deletion as described below.</p>

        <h2>5. Security</h2>
        <p>We protect your data with measures including encrypted transport (HTTPS), hashed
        passwords, signed and verified requests to and from Slack and Stripe, access controls,
        and audit logging. No method of transmission or storage is 100% secure, but we work to
        protect your information.</p>

        <h2>6. Your rights &amp; choices</h2>
        <p>Depending on your location, you may have rights to access, correct, export, or delete
        your personal information, and to object to or restrict certain processing. You can
        cancel your subscription anytime, remove the app from your Slack workspace, and contact
        us to request account deletion. To exercise any right, email us below.</p>

        <h2>7. Children</h2>
        <p>The Service is not directed to children under 16, and we do not knowingly collect
        their personal information.</p>

        <h2>8. International users</h2>
        <p>We may process and store information in the United States and other countries. By
        using the Service you consent to this transfer, subject to applicable law.</p>

        <h2>9. Changes to this policy</h2>
        <p>We may update this Policy from time to time. We will revise the date above and, for
        material changes, provide appropriate notice.</p>

        <h2>10. Contact</h2>
        <p>Questions or requests? Email <a href="mailto:{$c}">{$c}</a>.</p>
        HTML;

        return $this->page($response, 'Privacy Policy', $body);
    }

    private function page(Response $response, string $title, string $body): Response
    {
        $t = $this->e($title);
        $html = <<<HTML
        <!doctype html><html lang="en"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$t} · GoldStarBot</title>
        <meta name="description" content="{$t} for GoldStarBot — peer recognition for Slack.">
        <link rel="icon" type="image/svg+xml" href="/assets/goldstarbot-icon.svg">
        <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
          :root{--navy:#1F2937;--gold:#F6B31A;--green:#22A85A;--green-dk:#1B8E4A;--line:#e6eef5;--muted:#64748b;--mint:#eef7f1}
          *{box-sizing:border-box}
          body{margin:0;color:var(--navy);background:#fff;font:16px/1.65 "Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
          a{color:var(--green-dk)}
          .wrap{max-width:820px;margin:0 auto;padding:0 22px}
          header.top{border-bottom:1px solid var(--line);padding:16px 0;position:sticky;top:0;background:rgba(255,255,255,.92);backdrop-filter:blur(6px);z-index:10}
          header.top .wrap{max-width:1080px;display:flex;align-items:center;justify-content:space-between}
          .brand{display:flex;align-items:center;gap:9px;text-decoration:none;color:var(--navy)}
          .brand img{height:30px;width:auto}
          .brand b{font:700 24px/1 "Courier Prime",ui-monospace,monospace;letter-spacing:-.02em;position:relative;top:2px}
          .brand b .g{color:var(--green)}
          .navlinks{display:flex;align-items:center;gap:18px;font-weight:600;font-size:14px}
          .navlinks a{text-decoration:none;color:var(--muted)}.navlinks a:hover{color:var(--navy)}
          .btn{display:inline-block;text-decoration:none;font-weight:700;border-radius:10px;padding:11px 18px;font-size:14px;background:var(--gold);color:var(--navy)}
          .btn:hover{background:#e5a512}
          main{padding:40px 0 20px}
          h1{font-size:32px;letter-spacing:-.02em;margin:0 0 4px}
          h2{font-size:20px;margin:30px 0 8px}
          .upd{color:var(--muted);font-size:14px;margin:0 0 24px}
          p,li{color:#334155}
          ul{padding-left:22px}li{margin:6px 0}
          footer.foot{background:var(--navy);color:#cfe3d8;padding:30px 0;text-align:center;font-size:14px;margin-top:40px}
          footer.foot a{color:#cfe3d8}
          footer.foot .brand{justify-content:center;color:#fff;margin-bottom:8px}
          footer.foot .brand b{color:#fff}footer.foot .brand b .g{color:#3FD37E}
          @media(max-width:640px){.navlinks a.hide{display:none}h1{font-size:26px}}
        </style></head><body>
        <header class="top"><div class="wrap">
          <a class="brand" href="/"><img src="/assets/goldstarbot-icon-transparent.svg" alt=""><b>goldstar<span class="g">bot</span></b></a>
          <div class="navlinks">
            <a class="hide" href="/setup">Setup</a>
            <a href="/login">Login</a>
            <a class="btn" href="/checkout?plan=team">Get started</a>
          </div>
        </div></header>
        <main><div class="wrap">{$body}</div></main>
        <footer class="foot"><div class="wrap">
          <a class="brand" href="/"><img src="/assets/goldstarbot-icon-transparent.svg" alt=""><b>goldstar<span class="g">bot</span></b></a><br>
          <a href="/">Home</a> · <a href="/terms">Terms</a> · <a href="/privacy">Privacy</a> · <a href="/login">Log in</a>
          <div style="opacity:.6;margin-top:8px">© goldstarbot.com — peer recognition for Slack</div>
        </div></footer>
        </body></html>
        HTML;

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus(200);
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
