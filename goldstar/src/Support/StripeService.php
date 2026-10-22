<?php
declare(strict_types=1);

namespace App\Support;

use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Invoice;
use Stripe\Stripe;
use Stripe\Subscription;
use Stripe\Webhook;

/**
 * Thin wrapper around Stripe for the hosted-checkout trial flow.
 */
final class StripeService
{
    /** @var array<string,mixed> */
    private array $cfg;
    private LoggerInterface $logger;

    /** @param array<string,mixed> $stripeConfig */
    public function __construct(array $stripeConfig, LoggerInterface $logger)
    {
        $this->cfg = $stripeConfig;
        $this->logger = $logger;
        if ($this->isConfigured()) {
            Stripe::setApiKey((string) $this->cfg['secret_key']);
        }
    }

    public function isConfigured(): bool
    {
        return !empty($this->cfg['secret_key']) && strpos((string) $this->cfg['secret_key'], 'sk_') === 0;
    }

    public function trialDays(): int
    {
        return (int) ($this->cfg['trial_days'] ?? 7);
    }

    public function minSeats(): int
    {
        return max(1, (int) ($this->cfg['min_seats'] ?? 5));
    }

    public function priceFor(string $plan): string
    {
        return $plan === 'enterprise'
            ? (string) ($this->cfg['enterprise_price_id'] ?? '')
            : (string) ($this->cfg['team_price_id'] ?? '');
    }

    /**
     * Create a hosted Checkout Session for a subscription with a free trial.
     *
     * @param array<string,string> $meta
     */
    public function createCheckoutSession(
        string $plan,
        string $email,
        array $meta,
        string $successUrl,
        string $cancelUrl
    ): ?Session {
        $price = $this->priceFor($plan);
        if (!$this->isConfigured() || $price === '') {
            return null;
        }

        // Flat pricing: one unit, unlimited users (no per-seat quantity).
        $qty = 1;

        try {
            return Session::create([
                'mode'                => 'subscription',
                'line_items'          => [['price' => $price, 'quantity' => $qty]],
                'customer_email'      => $email,
                'client_reference_id' => substr($email, 0, 200),
                'subscription_data'   => [
                    'trial_period_days' => $this->trialDays(),
                    'metadata'          => $meta,
                ],
                'metadata'            => $meta,
                'allow_promotion_codes' => true,
                'success_url'         => $successUrl,
                'cancel_url'          => $cancelUrl,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Stripe: createCheckoutSession failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function retrieveSession(string $sessionId): ?Session
    {
        if (!$this->isConfigured() || $sessionId === '') {
            return null;
        }
        try {
            return Session::retrieve(['id' => $sessionId, 'expand' => ['subscription', 'customer']]);
        } catch (\Throwable $e) {
            $this->logger->error('Stripe: retrieveSession failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Live subscription state for the dashboard (the source of truth for "is it
     * canceled / when does it renew").
     *
     * @return array{status:string,cancel_at_period_end:bool,current_period_end:int,trial_end:int}|null
     */
    public function subscriptionInfo(string $subscriptionId): ?array
    {
        if (!$this->isConfigured() || $subscriptionId === '') {
            return null;
        }
        try {
            $s = Subscription::retrieve($subscriptionId);
            return [
                'status'               => (string) $s->status,
                'cancel_at_period_end' => (bool) $s->cancel_at_period_end,
                'current_period_end'   => (int) ($s->current_period_end ?? 0),
                'trial_end'            => (int) ($s->trial_end ?? 0),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Stripe: subscriptionInfo failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Platform-wide billing snapshot for the operator console: real MRR from
     * active subscriptions, trial/active counts, and recent invoices.
     *
     * @return array{mrr_cents:int,active:int,trialing:int,past_due:int,invoices:array<int,array<string,mixed>>}
     */
    public function platformBilling(int $invoiceLimit = 20, ?array $allowedCustomerIds = null): array
    {
        $out = ['mrr_cents' => 0, 'active' => 0, 'trialing' => 0, 'past_due' => 0, 'invoices' => []];
        if (!$this->isConfigured()) {
            return $out;
        }
        // Only count Stripe customers we actually have an account for (skip test
        // objects / customers created outside GoldStarBot). null = no filter.
        $allow = $allowedCustomerIds === null ? null : array_fill_keys(array_filter($allowedCustomerIds), true);
        $mine = static function ($customerId) use ($allow): bool {
            return $allow === null || (is_string($customerId) && isset($allow[$customerId]));
        };

        try {
            $subs = Subscription::all(['status' => 'all', 'limit' => 100]);
            foreach ($subs->autoPagingIterator() as $s) {
                if (!$mine($s->customer)) {
                    continue;
                }
                if ($s->status === 'active') {
                    $out['active']++;
                    foreach ($s->items->data as $it) {
                        $unit = (int) ($it->price->unit_amount ?? 0);
                        $qty = (int) ($it->quantity ?? 1);
                        $out['mrr_cents'] += $unit * $qty;
                    }
                } elseif ($s->status === 'trialing') {
                    $out['trialing']++;
                } elseif ($s->status === 'past_due' || $s->status === 'unpaid') {
                    $out['past_due']++;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Stripe: platformBilling subscriptions failed', ['error' => $e->getMessage()]);
        }
        try {
            // Over-fetch a little so filtering to our customers still fills the list.
            $invs = Invoice::all(['limit' => max(1, min(100, $allow === null ? $invoiceLimit : $invoiceLimit * 5))]);
            foreach ($invs->data as $inv) {
                if (!$mine($inv->customer)) {
                    continue;
                }
                if (count($out['invoices']) >= $invoiceLimit) {
                    break;
                }
                $out['invoices'][] = [
                    'id'       => (string) ($inv->number ?: $inv->id),
                    'email'    => (string) ($inv->customer_email ?? ''),
                    'amount'   => (int) ($inv->amount_due ?? ($inv->total ?? 0)),
                    'currency' => strtoupper((string) ($inv->currency ?? 'usd')),
                    'status'   => (string) ($inv->status ?? ''),
                    'created'  => (int) ($inv->created ?? 0),
                    'due'      => (int) ($inv->due_date ?? 0),
                    'url'      => (string) ($inv->hosted_invoice_url ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->error('Stripe: platformBilling invoices failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * Invoices for a single customer (customer billing page).
     *
     * @return array<int,array<string,mixed>>
     */
    public function customerInvoices(string $customerId, int $limit = 12): array
    {
        if (!$this->isConfigured() || $customerId === '') {
            return [];
        }
        try {
            $invs = Invoice::all(['customer' => $customerId, 'limit' => max(1, min(100, $limit))]);
            $out = [];
            foreach ($invs->data as $inv) {
                $out[] = [
                    'id'      => (string) ($inv->number ?: $inv->id),
                    'amount'  => (int) ($inv->amount_due ?? ($inv->total ?? 0)),
                    'status'  => (string) ($inv->status ?? ''),
                    'created' => (int) ($inv->created ?? 0),
                    'url'     => (string) ($inv->hosted_invoice_url ?? ''),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            $this->logger->error('Stripe: customerInvoices failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Create a Stripe Billing Portal session so the customer can manage/cancel
     * their subscription, update the card, and view invoices. Returns the URL.
     */
    public function createBillingPortalSession(string $customerId, string $returnUrl): ?string
    {
        if (!$this->isConfigured() || $customerId === '') {
            return null;
        }
        $params = ['customer' => $customerId, 'return_url' => $returnUrl];
        $configId = (string) ($this->cfg['portal_config_id'] ?? '');
        if ($configId !== '') {
            $params['configuration'] = $configId;
        }
        try {
            $session = \Stripe\BillingPortal\Session::create($params);
            return (string) $session->url;
        } catch (\Throwable $e) {
            $this->logger->error('Stripe: billing portal session failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Set the subscription's seat quantity, taking effect on the next invoice
     * (no mid-cycle proration). Returns true on success.
     */
    public function updateSeatQuantity(string $subscriptionId, int $quantity): bool
    {
        if (!$this->isConfigured() || $subscriptionId === '') {
            return false;
        }
        try {
            $sub = Subscription::retrieve($subscriptionId);
            $itemId = $sub->items->data[0]->id ?? '';
            if ($itemId === '') {
                return false;
            }
            Subscription::update($subscriptionId, [
                'items'              => [['id' => $itemId, 'quantity' => max(1, $quantity)]],
                'proration_behavior' => 'none',
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Stripe: updateSeatQuantity failed', ['sub' => $subscriptionId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Verify + parse a webhook payload. Returns null if the signature is invalid.
     */
    public function constructEvent(string $payload, string $sigHeader): ?Event
    {
        $secret = (string) ($this->cfg['webhook_secret'] ?? '');
        if ($secret === '') {
            $this->logger->error('Stripe: webhook_secret not configured');
            return null;
        }
        try {
            return Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\Throwable $e) {
            $this->logger->warning('Stripe: webhook signature verification failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
