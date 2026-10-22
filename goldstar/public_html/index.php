<?php
declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$containerBuilder = new ContainerBuilder();

// Settings
$settings = require __DIR__ . '/../app/settings.php';
$settings($containerBuilder);

// Dependencies
$dependencies = require __DIR__ . '/../app/dependencies.php';
$dependencies($containerBuilder);

$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

// Middleware
$middleware = require __DIR__ . '/../app/middleware.php';
$middleware($app);

// Routes
$routes = require __DIR__ . '/../app/routes.php';
$routes($app);

$displayErrorDetails = (bool) $container->get('settings')['displayErrorDetails'];

$app->addRoutingMiddleware();
$app->addErrorMiddleware($displayErrorDetails, true, true);

$app->run();

// Flush the HTTP response to Slack now, then let any deferred work (e.g. the
// chat.postMessage to the recognition channel, registered via
// register_shutdown_function) run after the client has its answer. This keeps
// slash-command replies inside Slack's 3-second deadline.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
}
