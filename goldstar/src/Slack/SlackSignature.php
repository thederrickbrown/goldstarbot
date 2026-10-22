<?php
declare(strict_types=1);

namespace App\Slack;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Verifies Slack request signatures.
 *
 * @see https://api.slack.com/authentication/verifying-requests-from-slack
 */
final class SlackSignature
{
    private string $signingSecret;
    private int $maxAge;

    public function __construct(string $signingSecret, int $maxAge = 300)
    {
        $this->signingSecret = $signingSecret;
        $this->maxAge = $maxAge;
    }

    /**
     * @param string $rawBody the exact raw request body bytes
     */
    public function isValid(Request $request, string $rawBody, int $now): bool
    {
        if ($this->signingSecret === '') {
            return false;
        }

        $timestamp = $request->getHeaderLine('X-Slack-Request-Timestamp');
        $signature = $request->getHeaderLine('X-Slack-Signature');

        if ($timestamp === '' || $signature === '') {
            return false;
        }

        // Guard against replay attacks.
        if (abs($now - (int) $timestamp) > $this->maxAge) {
            return false;
        }

        $base = 'v0:' . $timestamp . ':' . $rawBody;
        $computed = 'v0=' . hash_hmac('sha256', $base, $this->signingSecret);

        return hash_equals($computed, $signature);
    }
}
