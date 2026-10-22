<?php
declare(strict_types=1);

namespace App\Support;

use Psr\Log\LoggerInterface;

/**
 * Minimal transactional mailer over PHP mail() (SiteGround Exim). Fine for low
 * volume; swap the send() body for an SMTP/ESP client later without touching callers.
 */
final class Mailer
{
    private string $from;
    private LoggerInterface $logger;

    public function __construct(string $from, LoggerInterface $logger)
    {
        $this->from = $from !== '' ? $from : 'GoldStarBot <noreply@goldstarbot.com>';
        $this->logger = $logger;
    }

    public function send(string $to, string $subject, string $body): bool
    {
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $headers = 'From: ' . $this->from . "\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "X-Mailer: GoldStarBot\r\n";
        $ok = @mail($to, $subject, $body, $headers);
        $this->logger->info('email sent', ['to' => $to, 'subject' => $subject, 'ok' => $ok]);
        return (bool) $ok;
    }
}
