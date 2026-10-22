<?php
declare(strict_types=1);

namespace App\Slack;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Thin wrapper around the Slack Web API + response_url delivery.
 */
final class SlackClient
{
    private string $botToken;
    private LoggerInterface $logger;
    private Client $http;

    public function __construct(string $botToken, LoggerInterface $logger)
    {
        $this->botToken = $botToken;
        $this->logger = $logger;
        // Cap both connect and total time so a slow/stalled Slack API can never
        // hang the request (belt-and-suspenders alongside deferred delivery).
        $this->http = new Client([
            'connect_timeout' => 2.0,
            'timeout'         => 4.0,
        ]);
    }

    /**
     * Post a message to a channel via chat.postMessage.
     *
     * @param array   $blocks Block Kit blocks
     * @param ?string $token  Per-workspace bot token; falls back to the default
     *                        token when null/empty (multi-tenant posting).
     * @return bool success
     */
    public function postMessage(string $channel, string $fallbackText, array $blocks, ?string $token = null): bool
    {
        $useToken = ($token !== null && $token !== '') ? $token : $this->botToken;
        if ($useToken === '') {
            $this->logger->error('SlackClient: bot_token is not configured; cannot postMessage.');
            return false;
        }

        try {
            $res = $this->http->post('https://slack.com/api/chat.postMessage', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $useToken,
                    'Content-Type'  => 'application/json; charset=utf-8',
                ],
                'json' => [
                    'channel' => $channel,
                    'text'    => $fallbackText,
                    'blocks'  => $blocks,
                ],
            ]);

            $body = json_decode((string) $res->getBody(), true);
            if (!is_array($body) || empty($body['ok'])) {
                $this->logger->error('SlackClient: chat.postMessage failed', ['response' => $body]);
                return false;
            }
            return true;
        } catch (Throwable $e) {
            $this->logger->error('SlackClient: chat.postMessage exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Deliver a message to a slash command's response_url (works without a bot token).
     */
    public function respondViaUrl(string $responseUrl, string $text, string $responseType = 'ephemeral', array $blocks = []): bool
    {
        if ($responseUrl === '') {
            return false;
        }

        $payload = [
            'response_type' => $responseType,
            'text'          => $text,
        ];
        if ($blocks !== []) {
            $payload['blocks'] = $blocks;
        }

        try {
            $this->http->post($responseUrl, [
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                'json'    => $payload,
            ]);
            return true;
        } catch (Throwable $e) {
            $this->logger->error('SlackClient: respondViaUrl exception', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
