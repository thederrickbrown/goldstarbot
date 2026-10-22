<?php
declare(strict_types=1);

namespace App\Application\Actions;

use App\Support\AccountService;
use App\Support\ActivityService;
use App\Support\AdminSession;
use App\Support\StripeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Sends the logged-in customer to the Stripe Billing Portal to manage or cancel
 * their subscription, update their card, and view invoices.
 */
final class BillingAction
{
    private AccountService $accounts;
    private StripeService $stripe;
    private ActivityService $activity;
    private LoggerInterface $logger;

    public function __construct(AccountService $accounts, StripeService $stripe, ActivityService $activity, LoggerInterface $logger)
    {
        $this->accounts = $accounts;
        $this->stripe = $stripe;
        $this->activity = $activity;
        $this->logger = $logger;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        AdminSession::start();
        if (!AdminSession::isAuthed()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $uid = (int) ($_SESSION['gsb_uid'] ?? 0);
        $acc = $uid > 0 ? $this->accounts->findById($uid) : null;
        $customerId = $acc !== null ? (string) ($acc['stripe_customer_id'] ?? '') : '';

        if ($customerId === '') {
            return $response->withHeader('Location', '/dashboard?ok=' . rawurlencode('No active subscription to manage yet.'))->withStatus(302);
        }

        $url = $this->stripe->createBillingPortalSession($customerId, 'https://goldstarbot.com/dashboard');
        if ($url === null) {
            return $response->withHeader('Location', '/dashboard?ok=' . rawurlencode('Couldn’t open billing right now — please try again.'))->withStatus(302);
        }

        $this->activity->log(
            ActivityService::T_BILLING,
            'Opened the billing portal',
            !empty($acc['team_id']) ? (string) $acc['team_id'] : null,
            (string) ($acc['email'] ?? '')
        );
        return $response->withHeader('Location', $url)->withStatus(303);
    }
}
