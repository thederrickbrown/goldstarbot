<?php
declare(strict_types=1);

use App\Application\Actions\Admin\AdminController;
use App\Application\Actions\Ops\OpsController;
use App\Application\Actions\BillingAction;
use App\Application\Actions\CheckoutController;
use App\Application\Actions\HomeAction;
use App\Application\Actions\LegalController;
use App\Application\Actions\SlackOAuthAction;
use App\Application\Actions\StripeWebhookAction;
use App\Application\Actions\StarAction;
use App\Application\Middleware\AdminAuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {

    $app->get('/', HomeAction::class);

    // Slack slash command endpoint. Configure your slash command's Request URL
    // to https://goldstarbot.com/star
    $app->post('/star', StarAction::class);
    $app->post('/star/', StarAction::class);

    // Public Slack setup guide.
    $app->get('/setup', [AdminController::class, 'setup']);

    // Public legal pages (required for Slack app distribution + billing).
    $app->get('/terms', [LegalController::class, 'terms']);
    $app->get('/privacy', [LegalController::class, 'privacy']);

    // Public free-trial checkout.
    $app->get('/checkout', [CheckoutController::class, 'show']);
    $app->post('/checkout', [CheckoutController::class, 'submit']);
    $app->get('/checkout/success', [CheckoutController::class, 'success']);

    // Stripe webhook (signature-verified).
    $app->post('/stripe/webhook', StripeWebhookAction::class);

    // Add to Slack (OAuth v2 install).
    $app->get('/slack/oauth/start', [SlackOAuthAction::class, 'start']);
    $app->get('/slack/oauth/callback', [SlackOAuthAction::class, 'callback']);

    // Password setup after checkout (token-gated, public).
    $app->get('/set-password', [AdminController::class, 'setPasswordForm']);
    $app->post('/set-password', [AdminController::class, 'setPassword']);

    // Sign in + password reset (clean public URLs; /admin/login kept for back-compat).
    $app->get('/login', [AdminController::class, 'loginForm']);
    $app->post('/login', [AdminController::class, 'login']);
    $app->get('/forgot', [AdminController::class, 'forgotForm']);
    $app->post('/forgot', [AdminController::class, 'forgot']);

    // Admin login (public).
    $app->get('/admin/login', [AdminController::class, 'loginForm']);
    $app->post('/admin/login', [AdminController::class, 'login']);
    $app->get('/admin/logout', [AdminController::class, 'logout']);

    // Customer console — same sidebar shell, scoped to the signed-in workspace.
    $app->group('/dashboard', function (RouteCollectorProxy $group) {
        $group->get('', [OpsController::class, 'custDashboard']);
        $group->get('/team', [OpsController::class, 'custTeam']);
        $group->get('/usage', [OpsController::class, 'custUsage']);
        $group->get('/billing', [OpsController::class, 'custBilling']);
    })->add(AdminAuthMiddleware::class);

    // Stripe Billing Portal redirect (the "Manage billing" target).
    $app->get('/billing', BillingAction::class)->add(AdminAuthMiddleware::class);

    // Superadmin operator console — sidebar shell (auth-gated).
    $app->group('/admin', function (RouteCollectorProxy $group) {
        $group->get('', [OpsController::class, 'dashboard']);
        $group->get('/workspaces', [OpsController::class, 'workspaces']);
        $group->get('/workspaces/{id}', [OpsController::class, 'workspaceDetail']);
        $group->post('/workspaces/{id}', [OpsController::class, 'saveWorkspace']);
        $group->post('/workspaces/{id}/action', [OpsController::class, 'workspaceAction']);
        $group->get('/users', [OpsController::class, 'users']);
        $group->get('/usage', [OpsController::class, 'usage']);
        $group->get('/billing', [OpsController::class, 'billing']);
        $group->get('/activity', [OpsController::class, 'activity']);
        // Legacy global settings page retired — settings are now per-workspace.
        $group->get('/settings', [OpsController::class, 'workspaces']);
    })->add(AdminAuthMiddleware::class);
};
