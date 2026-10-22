<?php
declare(strict_types=1);

namespace App\Application\Actions;

use App\Support\AccountService;
use App\Support\ActivityService;
use App\Support\Mailer;
use App\Support\SettingsService;
use App\Support\StripeService;
use App\Support\TeamService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Stripe\Event;

/**
 * Stripe webhook receiver. Keeps subscription status in sync and — once a
 * workspace is linked (team_id) — auto-enables/disables it based on billing.
 */
final class StripeWebhookAction
{
    private StripeService $stripe;
    private AccountService $accounts;
    private TeamService $teams;
    private ActivityService $activity;
    private Mailer $mailer;
    private SettingsService $settings;
    private LoggerInterface $logger;

    public function __construct(
        StripeService $stripe,
        AccountService $accounts,
        TeamService $teams,
        ActivityService $activity,
        Mailer $mailer,
        SettingsService $settings,
        LoggerInterface $logger
    ) {
        $this->stripe = $stripe;
        $this->accounts = $accounts;
        $this->teams = $teams;
        $this->activity = $activity;
        $this->mailer = $mailer;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $raw = (string) $request->getBody();
        $sig = $request->getHeaderLine('Stripe-Signature');

        $event = $this->stripe->constructEvent($raw, $sig);
        if ($event === null) {
            return $response->withStatus(400);
        }

        try {
            $this->handle($event);
        } catch (\Throwable $e) {
            $this->logger->error('StripeWebhook: handler error', ['type' => $event->type, 'error' => $e->getMessage()]);
        }

        // Always 200 for a verified event so Stripe doesn't retry a handled one.
        return $response->withStatus(200);
    }

    private function handle(Event $event): void
    {
        $obj = $event->data->object;

        switch ($event->type) {
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                $subId  = (string) ($obj->id ?? '');
                $status = $event->type === 'customer.subscription.deleted'
                    ? 'canceled'
                    : (string) ($obj->status ?? '');
                $this->accounts->setSubStatus($subId, $status);
                $this->applyToWorkspace($subId, $status, 'Subscription ' . $status);
                break;

            case 'customer.subscription.trial_will_end':
                $subId = (string) ($obj->id ?? '');
                $email = $this->emailForSub($subId);
                if ($email !== '') {
                    $this->mailer->send(
                        $email,
                        'Your GoldStarBot trial ends soon',
                        "Heads up — your GoldStarBot free trial ends in a few days.\n\n"
                        . "No action needed to keep it: billing then starts automatically at \$10/mo (covers 5 active users), plus \$2 per additional active user that month.\n\n"
                        . "Manage or cancel anytime from your dashboard:\nhttps://goldstarbot.com/admin\n\n— GoldStarBot"
                    );
                }
                $this->activity->log(ActivityService::T_TRIAL, 'Trial ends soon', null, $email !== '' ? $email : null);
                break;

            case 'invoice.payment_failed':
                $subId = (string) ($obj->subscription ?? '');
                $this->accounts->setSubStatus($subId, 'past_due');
                $this->applyToWorkspace($subId, 'past_due', 'Payment failed');
                $email = $this->emailForSub($subId);
                if ($email !== '') {
                    $this->mailer->send(
                        $email,
                        'GoldStarBot payment failed',
                        "We couldn't process your latest GoldStarBot payment, so your workspace has been paused.\n\n"
                        . "Update your card to restore access:\nhttps://goldstarbot.com/admin\n\n— GoldStarBot"
                    );
                }
                break;

            case 'invoice.paid':
                $subId = (string) ($obj->subscription ?? '');
                $this->applyToWorkspace($subId, 'active', 'Invoice paid');
                break;

            default:
                // Unhandled event types are fine — acknowledged with 200.
                break;
        }
    }

    private function emailForSub(string $subId): string
    {
        if ($subId === '') {
            return '';
        }
        $acc = $this->accounts->findBySubscription($subId);
        return $acc !== null ? (string) ($acc['email'] ?? '') : '';
    }

    /**
     * Enable the workspace while billing is healthy (trialing/active), disable it
     * otherwise. No-op until the account is linked to a Slack team_id (via OAuth).
     */
    private function applyToWorkspace(string $subId, string $status, string $note): void
    {
        if ($subId === '') {
            return;
        }
        $acc = $this->accounts->findBySubscription($subId);
        $teamId = $acc !== null && !empty($acc['team_id']) ? (string) $acc['team_id'] : null;
        $email = $acc !== null ? (string) ($acc['email'] ?? '') : '';

        $healthy = in_array($status, ['trialing', 'active'], true);

        // Comped workspaces have free access — billing never disables them.
        if ($teamId !== null && !$this->settings->isTeamComped($teamId)) {
            $this->teams->setStatus(
                $teamId,
                $healthy ? TeamService::STATUS_ACTIVE : TeamService::STATUS_BLOCKED
            );
        }

        $this->activity->log(
            $healthy ? ActivityService::T_TRIAL : ActivityService::T_BLOCKED,
            $note . ($teamId !== null ? '' : ' (workspace not linked yet)'),
            $teamId,
            $email !== '' ? $email : null
        );
    }
}
