<?php
declare(strict_types=1);

use App\Application\Actions\Admin\AdminController;
use App\Application\Actions\Ops\OpsController;
use App\Application\Actions\BillingAction;
use App\Application\Actions\CheckoutController;
use App\Application\Actions\SlackOAuthAction;
use App\Application\Actions\StarAction;
use App\Application\Actions\StripeWebhookAction;
use App\Slack\SlackClient;
use App\Slack\SlackSignature;
use App\Support\AccountService;
use App\Support\ActivityService;
use App\Support\Mailer;
use App\Support\SettingsService;
use App\Support\StarService;
use App\Support\StripeService;
use App\Support\TeamService;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([

        LoggerInterface::class => function (ContainerInterface $c) {
            $settings = $c->get('settings')['logger'];

            $logger = new Logger($settings['name']);
            $logger->pushProcessor(new UidProcessor());

            // Ensure the log directory exists (shared hosting may not have it).
            $dir = dirname($settings['path']);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $logger->pushHandler(new StreamHandler($settings['path'], $settings['level']));

            return $logger;
        },

        // Shared PDO (MySQL) connection.
        PDO::class => function (ContainerInterface $c) {
            $db = $c->get('settings')['db'];
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $db['host'],
                $db['database'],
                $db['charset'] ?? 'utf8mb4'
            );

            return new PDO($dsn, $db['username'], $db['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
            ]);
        },

        SettingsService::class => function (ContainerInterface $c) {
            return new SettingsService(
                static function () use ($c): PDO {
                    return $c->get(PDO::class);
                },
                $c->get(LoggerInterface::class),
                $c->get('settings')['slack']
            );
        },

        SlackSignature::class => function (ContainerInterface $c) {
            $s = $c->get(SettingsService::class);
            return new SlackSignature(
                (string) $s->get('signing_secret', ''),
                (int) $s->get('max_request_age', 300)
            );
        },

        SlackClient::class => function (ContainerInterface $c) {
            return new SlackClient(
                (string) $c->get(SettingsService::class)->get('bot_token', ''),
                $c->get(LoggerInterface::class)
            );
        },

        StarService::class => function (ContainerInterface $c) {
            // Pass a lazy factory so the MySQL connection is only opened when a
            // DB-backed path actually runs (never for signature rejection / help).
            return new StarService(
                static function () use ($c): PDO {
                    return $c->get(PDO::class);
                },
                $c->get(LoggerInterface::class)
            );
        },

        TeamService::class => function (ContainerInterface $c) {
            return new TeamService(
                static function () use ($c): PDO {
                    return $c->get(PDO::class);
                },
                $c->get(LoggerInterface::class)
            );
        },

        StarAction::class => function (ContainerInterface $c) {
            return new StarAction(
                $c->get(StarService::class),
                $c->get(TeamService::class),
                $c->get(SlackClient::class),
                $c->get(SlackSignature::class),
                $c->get(ActivityService::class),
                $c->get(LoggerInterface::class),
                $c->get(SettingsService::class)->all(),
                $c->get(SettingsService::class)
            );
        },

        AccountService::class => function (ContainerInterface $c) {
            return new AccountService(
                static function () use ($c): PDO {
                    return $c->get(PDO::class);
                },
                $c->get(LoggerInterface::class)
            );
        },

        ActivityService::class => function (ContainerInterface $c) {
            return new ActivityService(
                static function () use ($c): PDO {
                    return $c->get(PDO::class);
                },
                $c->get(LoggerInterface::class)
            );
        },

        Mailer::class => function (ContainerInterface $c) {
            return new Mailer(
                (string) ($c->get('settings')['mail']['from'] ?? ''),
                $c->get(LoggerInterface::class)
            );
        },

        StripeService::class => function (ContainerInterface $c) {
            return new StripeService(
                $c->get('settings')['stripe'] ?? [],
                $c->get(LoggerInterface::class)
            );
        },

        CheckoutController::class => function (ContainerInterface $c) {
            return new CheckoutController(
                static function () use ($c): PDO {
                    return $c->get(PDO::class);
                },
                $c->get(AccountService::class),
                $c->get(ActivityService::class),
                $c->get(StripeService::class),
                $c->get(LoggerInterface::class)
            );
        },

        BillingAction::class => function (ContainerInterface $c) {
            return new BillingAction(
                $c->get(AccountService::class),
                $c->get(StripeService::class),
                $c->get(ActivityService::class),
                $c->get(LoggerInterface::class)
            );
        },

        SlackOAuthAction::class => function (ContainerInterface $c) {
            return new SlackOAuthAction(
                $c->get('settings')['slack'] ?? [],
                $c->get(TeamService::class),
                $c->get(AccountService::class),
                $c->get(ActivityService::class),
                $c->get(LoggerInterface::class)
            );
        },

        StripeWebhookAction::class => function (ContainerInterface $c) {
            return new StripeWebhookAction(
                $c->get(StripeService::class),
                $c->get(AccountService::class),
                $c->get(TeamService::class),
                $c->get(ActivityService::class),
                $c->get(Mailer::class),
                $c->get(SettingsService::class),
                $c->get(LoggerInterface::class)
            );
        },

        OpsController::class => function (ContainerInterface $c) {
            return new OpsController(
                static function () use ($c): PDO {
                    return $c->get(PDO::class);
                },
                $c->get(ActivityService::class),
                $c->get(StripeService::class),
                $c->get(AccountService::class),
                $c->get(TeamService::class),
                $c->get(SettingsService::class),
                $c->get(LoggerInterface::class)
            );
        },

        AdminController::class => function (ContainerInterface $c) {
            return new AdminController(
                $c->get(TeamService::class),
                $c->get(SettingsService::class),
                $c->get(AccountService::class),
                $c->get(ActivityService::class),
                $c->get(Mailer::class),
                $c->get(StripeService::class),
                $c->get(LoggerInterface::class),
                $c->get('settings')['admin'] ?? []
            );
        },
    ]);
};
