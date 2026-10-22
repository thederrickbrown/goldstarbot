<?php
/**
 * GoldStarBot configuration.
 *
 * Copy this file to `config.local.php` and fill in the real values.
 * config.local.php is git-ignored and should NEVER be committed.
 *
 * On SiteGround this file lives ABOVE public_html (next to app/ and src/),
 * so it is not web-accessible.
 */

declare(strict_types=1);

return [
    // ---- MySQL (SiteGround: Site Tools > MySQL) ----
    'db' => [
        'host'     => 'localhost',
        'database' => 'CHANGE_ME_dbname',
        'username' => 'CHANGE_ME_dbuser',
        'password' => 'CHANGE_ME_dbpass',
        'charset'  => 'utf8mb4',
    ],

    // ---- Slack app credentials (api.slack.com/apps > your app) ----
    'slack' => [
        // "Basic Information" > App Credentials > Signing Secret
        'signing_secret' => 'CHANGE_ME_signing_secret',

        // "OAuth & Permissions" > Bot User OAuth Token (starts with xoxb-)
        // Required only when post_target uses the dedicated channel.
        'bot_token'      => 'xoxb-CHANGE_ME',

        // Where the public recognition message is posted.
        //   'channel' = post to one central #recognition channel (uses bot_token)
        //   'in_channel' = post back into whatever channel /gs was run in
        //   'both' = do both
        'post_target'    => 'channel',

        // Channel ID (NOT name) of your recognition feed, e.g. 'C0123456789'.
        // Right-click the channel in Slack > View channel details > copy the ID
        // at the bottom. Only used when post_target is 'channel' or 'both'.
        'recognition_channel' => 'C_CHANGE_ME',

        // Reject requests whose signature timestamp is older than this (seconds).
        'max_request_age' => 300,
    ],

    // ---- Admin page (/admin) ----
    'admin' => [
        'username' => 'admin',
        // bcrypt hash of your admin password. Generate with:
        //   php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
        'password_hash' => 'CHANGE_ME_bcrypt_hash',
    ],

    // Show detailed errors in HTTP responses. Set false in production.
    'displayErrorDetails' => true,
];
