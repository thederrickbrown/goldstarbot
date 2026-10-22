<?php
declare(strict_types=1);

use Slim\App;

return function (App $app) {
    // Parse application/x-www-form-urlencoded (Slack slash commands) and JSON
    // into the parsed body. The raw body remains available via (string) $request->getBody()
    // for signature verification.
    $app->addBodyParsingMiddleware();
};
