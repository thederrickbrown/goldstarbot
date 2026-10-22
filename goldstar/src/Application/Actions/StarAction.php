<?php
declare(strict_types=1);

namespace App\Application\Actions;

use App\Slack\SlackClient;
use App\Slack\SlackSignature;
use App\Support\AchievementCatalog;
use App\Support\ActivityService;
use App\Support\SettingsService;
use App\Support\StarService;
use App\Support\TeamService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Handles the /gs slash command.
 *
 *   /gs @user great work on the beta   -> give a star
 *   /gs leaderboard                    -> top receivers
 *   /gs stats [@user]                  -> a user's tally + achievements
 *   /gs help                           -> usage
 */
final class StarAction
{
    private StarService $stars;
    private TeamService $teams;
    private SlackClient $slack;
    private SlackSignature $signature;
    private ActivityService $activity;
    private LoggerInterface $logger;
    private ?SettingsService $settings;

    /** The slash command as Slack sent it (e.g. "/gs"), for help/hint text. */
    private string $commandName = '/gs';

    /** @var array<string,mixed> */
    private array $slackConfig;

    /**
     * @param array<string,mixed> $slackConfig
     */
    public function __construct(
        StarService $stars,
        TeamService $teams,
        SlackClient $slack,
        SlackSignature $signature,
        ActivityService $activity,
        LoggerInterface $logger,
        array $slackConfig,
        ?SettingsService $settings = null
    ) {
        $this->stars = $stars;
        $this->teams = $teams;
        $this->slack = $slack;
        $this->signature = $signature;
        $this->activity = $activity;
        $this->logger = $logger;
        $this->slackConfig = $slackConfig;
        $this->settings = $settings;
    }

    /** Log a read-only slash-command use to the activity audit trail. */
    private function logCommand(string $what, string $giverId, string $teamId): void
    {
        $this->activity->log(
            ActivityService::T_COMMAND,
            sprintf('<@%s> %s (%s)', $giverId, $what, $this->commandName),
            $teamId !== '' ? $teamId : null,
            $giverId !== '' ? $giverId : null
        );
    }

    /** Effective per-workspace setting, falling back to global config. */
    private function teamSetting(string $teamId, string $key, $default)
    {
        if ($this->settings !== null && $teamId !== '') {
            try {
                return $this->settings->getForTeam($teamId, $key, $this->slackConfig[$key] ?? $default);
            } catch (\Throwable $e) {
                $this->logger->error('StarAction: teamSetting failed', ['error' => $e->getMessage()]);
            }
        }
        return $this->slackConfig[$key] ?? $default;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        // 1) Verify the request genuinely came from Slack.
        $rawBody = (string) $request->getBody();
        if (!$this->signature->isValid($request, $rawBody, time())) {
            $this->logger->warning('StarAction: signature verification failed');
            return $response->withStatus(401);
        }

        // 2) Pull slash-command fields.
        $params    = (array) $request->getParsedBody();
        $giverId   = (string) ($params['user_id'] ?? '');
        $giverName = (string) ($params['user_name'] ?? '');
        $teamId    = (string) ($params['team_id'] ?? '');
        $channelId = (string) ($params['channel_id'] ?? '');
        $text       = trim((string) ($params['text'] ?? ''));
        $teamDomain = (string) ($params['team_domain'] ?? '');
        $command    = trim((string) ($params['command'] ?? ''));
        if ($command !== '') {
            $this->commandName = $command;
        }

        if ($giverId === '' || $teamId === '') {
            return $this->ephemeral($response, 'Sorry, that request was missing required Slack fields.');
        }

        // 3) Route subcommands. DB-backed paths are wrapped so any database
        // fault returns a friendly ephemeral (HTTP 200) instead of a raw 500,
        // which Slack would render as a generic "dispatch_failed".
        $firstWord = strtolower(strtok($text, " \t") ?: '');
        try {
            // Workspace allowlist: record the team and block anything that isn't
            // approved (help stays available so people can see what the bot is).
            if ($firstWord !== 'help' && $firstWord !== '') {
                $status = $this->teams->ensureSeen($teamId, $teamDomain);
                if ($status !== TeamService::STATUS_ACTIVE) {
                    $this->activity->log(
                        ActivityService::T_BLOCKED,
                        sprintf('Blocked %s command from a %s workspace%s', $this->commandName, $status, $teamDomain !== '' ? ' (' . $teamDomain . ')' : ''),
                        $teamId,
                        $giverId
                    );
                    return $this->ephemeral(
                        $response,
                        "⛔ GoldStarBot isn't enabled for this workspace yet. Ask your admin to enable it."
                    );
                }
            }

            switch ($firstWord) {
                case '':
                case 'help':
                    $this->logCommand('help', $giverId, $teamId);
                    return $this->ephemeral($response, $this->helpText());
                case 'leaderboard':
                case 'top':
                case 'board':
                    $this->logCommand('viewed the leaderboard', $giverId, $teamId);
                    return $this->leaderboard($response, $teamId);
                case 'stats':
                case 'me':
                    $this->logCommand('viewed stats', $giverId, $teamId);
                    return $this->statsFor($response, $text, $giverId, $teamId);
                case 'achievements':
                case 'badges':
                case 'levels':
                    $this->logCommand('viewed achievements', $giverId, $teamId);
                    return $this->achievementsList($response, $giverId, $teamId);
                default:
                    return $this->giveStars($response, $text, $giverId, $giverName, $teamId, $channelId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('StarAction: unhandled failure', ['error' => $e->getMessage()]);
            return $this->ephemeral($response, 'Something went wrong on my end — please try again in a moment.');
        }
    }

    // ---------------------------------------------------------------------
    // Give
    // ---------------------------------------------------------------------

    private function giveStars(
        Response $response,
        string $text,
        string $giverId,
        string $giverName,
        string $teamId,
        string $channelId
    ): Response {
        $mentions = $this->parseMentions($text);

        if ($mentions === []) {
            return $this->ephemeral(
                $response,
                "I couldn't find anyone to star. Try `{$this->commandName} @teammate why they earned it`.\n"
                . "_Tip: pick the name from Slack's autocomplete so it links to a real user._"
            );
        }

        // Drop self-stars and duplicates.
        $selfStarred = isset($mentions[$giverId]);
        unset($mentions[$giverId]);

        if ($mentions === []) {
            $msg = $selfStarred
                ? "Nice try — you can't give *yourself* a star. 😉"
                : "I couldn't find anyone to star.";
            return $this->ephemeral($response, $msg);
        }

        $reason = $this->extractReason($text);

        // Record giver for leaderboards even if we lack their display name.
        try {
            $this->stars->upsertUser($giverId, $giverName, $giverName, $teamId);
        } catch (\Throwable $e) {
            $this->logger->error('StarAction: upsert giver failed', ['error' => $e->getMessage()]);
        }

        // Each star is its own transaction, so one receiver failing must not
        // abort the rest of the command. Record which landed and which didn't.
        $lines = [];
        $achievementLines = [];
        $awarded = [];
        $failed = [];
        foreach ($mentions as $receiverId => $receiverName) {
            try {
                $this->stars->upsertUser($receiverId, $receiverName, $receiverName, $teamId);
                $result = $this->stars->giveStar($giverId, $receiverId, $teamId, $channelId, $reason);
            } catch (\Throwable $e) {
                $this->logger->error('StarAction: giveStar failed', ['receiver' => $receiverId, 'error' => $e->getMessage()]);
                $failed[] = $receiverId;
                continue;
            }

            $awarded[] = $receiverId;
            $lines[] = sprintf('<@%s> now has *%d* %s.', $receiverId, $result['total'], $this->pluralStars($result['total']));
            $this->activity->log(
                ActivityService::T_STAR,
                sprintf('<@%s> gave a star to <@%s>%s', $giverId, $receiverId, $reason !== '' ? ' — ' . $reason : ''),
                $teamId,
                $giverId
            );

            foreach ($result['new_achievements'] as $tier) {
                $achievementLines[] = sprintf(
                    '%s <@%s> unlocked *%s* — %d stars!',
                    $tier['emoji'],
                    $receiverId,
                    $tier['name'],
                    $tier['threshold']
                );
                $this->activity->log(
                    ActivityService::T_ACHIEVEMENT,
                    sprintf('<@%s> unlocked %s (%d stars)', $receiverId, $tier['name'], $tier['threshold']),
                    $teamId,
                    $receiverId
                );
            }
        }

        // Nothing recorded at all — tell the giver privately, post nothing public.
        if ($awarded === []) {
            return $this->ephemeral($response, "Something went wrong recording that star — please try again in a moment.");
        }

        $blocks = $this->buildStarBlocks($giverId, $awarded, $reason, $lines, $achievementLines);
        $fallback = sprintf(':star: <@%s> gave a star!', $giverId);

        $failedNote = $failed === []
            ? ''
            : "\n\n_Heads up: I couldn't record a star for "
                . implode(', ', array_map(static fn(string $id): string => '<@' . $id . '>', $failed))
                . " — please retry just them._";

        return $this->deliver($response, $channelId, $fallback, $blocks, $failedNote, $teamId);
    }

    // ---------------------------------------------------------------------
    // Leaderboard
    // ---------------------------------------------------------------------

    private function leaderboard(Response $response, string $teamId): Response
    {
        $rows = $this->stars->leaderboard($teamId, 10);

        if ($rows === []) {
            return $this->ephemeral($response, "No stars have been given yet. Be the first: `{$this->commandName} @teammate why`!");
        }

        $medals = ['🥇', '🥈', '🥉'];
        $listing = [];
        foreach ($rows as $i => $row) {
            $rank = $medals[$i] ?? sprintf('*%d.*', $i + 1);
            $listing[] = sprintf('%s <@%s> — *%d* %s', $rank, $row['receiver_slack_id'], $row['total'], $this->pluralStars($row['total']));
        }

        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => '⭐ Gold Star Leaderboard', 'emoji' => true]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => implode("\n", $listing)]],
        ];

        // Leaderboard is informational — keep it private to the requester.
        return $this->json($response, [
            'response_type' => 'ephemeral',
            'text'          => 'Gold Star Leaderboard',
            'blocks'        => $blocks,
        ]);
    }

    // ---------------------------------------------------------------------
    // Stats
    // ---------------------------------------------------------------------

    private function statsFor(Response $response, string $text, string $giverId, string $teamId): Response
    {
        $mentions = $this->parseMentions($text);
        $targetId = $mentions === [] ? $giverId : array_key_first($mentions);

        $stats = $this->stars->stats($targetId, $teamId);

        $badgeLine = $stats['achievements'] === []
            ? '_No achievements yet._'
            : implode(' ', array_map([$this, 'badgeFor'], $stats['achievements']));

        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        "*<@%s>*\n:star: *%d* received  •  :gift: *%d* given\n\n*Achievements:*\n%s",
                        $targetId,
                        $stats['total'],
                        $stats['given'],
                        $badgeLine
                    ),
                ],
            ],
        ];

        return $this->json($response, [
            'response_type' => 'ephemeral',
            'text'          => 'Star stats',
            'blocks'        => $blocks,
        ]);
    }

    // ---------------------------------------------------------------------
    // Achievements list
    // ---------------------------------------------------------------------

    private function achievementsList(Response $response, string $userId, string $teamId): Response
    {
        $stats = $this->stars->stats($userId, $teamId);
        $total = $stats['total'];
        $earned = array_flip($stats['achievements']);

        $lines = [];
        $next = null;
        foreach (AchievementCatalog::all() as $tier) {
            if (isset($earned[$tier['code']])) {
                $status = '✅ earned';
            } else {
                $status = sprintf('%d %s', $tier['threshold'], $this->pluralStars($tier['threshold']));
                if ($next === null) {
                    $next = $tier;
                }
            }
            $lines[] = sprintf('%s *%s* — %s', $tier['emoji'], $tier['name'], $status);
        }

        $footer = $next === null
            ? sprintf('You have *%d* %s — you’ve unlocked every badge! 🏆', $total, $this->pluralStars($total))
            : sprintf(
                'You have *%d* %s — *%d* more to unlock %s *%s*.',
                $total,
                $this->pluralStars($total),
                $next['threshold'] - $total,
                $next['emoji'],
                $next['name']
            );

        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => '⭐ Gold Star Achievements', 'emoji' => true]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => implode("\n", $lines)]],
            ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $footer]]],
        ];

        return $this->json($response, [
            'response_type' => 'ephemeral',
            'text'          => 'Gold Star Achievements',
            'blocks'        => $blocks,
        ]);
    }

    // ---------------------------------------------------------------------
    // Delivery
    // ---------------------------------------------------------------------

    /**
     * Send the public star message according to the configured post_target.
     *
     * For the 'channel'/'both' targets the chat.postMessage call is DEFERRED to
     * after the HTTP response is flushed (see index.php's fastcgi_finish_request)
     * so we always ACK Slack inside its 3-second slash-command deadline instead
     * of blocking on a Slack API round trip.
     */
    private function deliver(Response $response, string $channelId, string $fallback, array $blocks, string $failedNote = '', string $teamId = ''): Response
    {
        $target = (string) $this->teamSetting($teamId, 'post_target', 'channel');

        // The in-channel reply is returned directly (no external call), so it is
        // always fast. Used for 'in_channel' and as the visible reply for 'both'.
        $inChannel = function () use ($response, $fallback, $blocks): Response {
            return $this->json($response, [
                'response_type' => 'in_channel',
                'text'          => $fallback,
                'blocks'        => $blocks,
            ]);
        };

        if ($target === 'in_channel') {
            return $inChannel();
        }

        // 'channel' or 'both' -> post to the central recognition channel, deferred.
        $recognitionChannel = (string) $this->teamSetting($teamId, 'recognition_channel', '');
        if ($recognitionChannel !== '') {
            $slack = $this->slack;
            // Post as the installing workspace using its own OAuth bot token
            // (multi-tenant). Falls back to the global token if none is stored.
            $teamToken = '';
            try {
                $teamToken = $this->teams->botToken($teamId);
            } catch (\Throwable $e) {
                $this->logger->error('StarAction: botToken lookup failed', ['error' => $e->getMessage()]);
            }
            $token = $teamToken !== '' ? $teamToken : null;
            register_shutdown_function(static function () use ($slack, $recognitionChannel, $fallback, $blocks, $token): void {
                $slack->postMessage($recognitionChannel, $fallback, $blocks, $token);
            });
        } else {
            $this->logger->error('StarAction: recognition_channel not configured for post_target=' . $target);
        }

        if ($target === 'both') {
            return $inChannel();
        }

        // 'channel': acknowledge privately to the giver.
        return $this->ephemeral($response, '⭐ Star recorded! Posting it to #starbot now.' . $failedNote);
    }

    // ---------------------------------------------------------------------
    // Blocks & formatting
    // ---------------------------------------------------------------------

    /**
     * @param array<int,string> $receiverIds
     * @param array<int,string> $lines
     * @param array<int,string> $achievementLines
     */
    private function buildStarBlocks(string $giverId, array $receiverIds, string $reason, array $lines, array $achievementLines): array
    {
        $recipients = implode(', ', array_map(static fn(string $id): string => '<@' . $id . '>', $receiverIds));
        $headline = sprintf(':star: <@%s> gave a gold star to %s!', $giverId, $recipients);

        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $headline]],
        ];

        if ($reason !== '') {
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '> ' . $this->escapeMrkdwn($reason)]];
        }

        $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => implode('  •  ', $lines)]]];

        if ($achievementLines !== []) {
            $blocks[] = ['type' => 'divider'];
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "🎉 *Achievement unlocked!*\n" . implode("\n", $achievementLines)]];
        }

        return $blocks;
    }

    private function badgeFor(string $code): string
    {
        // Render "emoji Name" for an earned achievement code.
        foreach (AchievementCatalog::all() as $tier) {
            if ($tier['code'] === $code) {
                return $tier['emoji'] . ' ' . $tier['name'];
            }
        }
        return $code;
    }

    private function pluralStars(int $n): string
    {
        return $n === 1 ? 'star' : 'stars';
    }

    private function helpText(): string
    {
        $c = $this->commandName;
        return "*GoldStarBot* ⭐ — give your teammates recognition.\n"
            . "• `{$c} @teammate why they earned it` — give a gold star\n"
            . "• `{$c} @a @b great teamwork` — star more than one person\n"
            . "• `{$c} leaderboard` — see the top stars\n"
            . "• `{$c} stats [@teammate]` — view stars & achievements\n"
            . "• `{$c} achievements` — all badge tiers & your progress\n"
            . "• `{$c} help` — this message";
    }

    // ---------------------------------------------------------------------
    // Parsing helpers
    // ---------------------------------------------------------------------

    /**
     * Extract Slack user mentions.
     *
     * With "Escape channels, users, and links" enabled on the slash command,
     * mentions arrive as <@U12345|username> (or <@U12345>).
     *
     * @return array<string,string> map of userId => username (preserves order)
     */
    private function parseMentions(string $text): array
    {
        $out = [];
        if (preg_match_all('/<@([UW][A-Z0-9]+)(?:\|([^>]*))?>/', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $id = $match[1];
                $name = $match[2] ?? '';
                if (!isset($out[$id])) {
                    $out[$id] = $name;
                }
            }
        }
        return $out;
    }

    /**
     * The reason is the command text with all mentions and a leading subcommand
     * word removed.
     */
    private function extractReason(string $text): string
    {
        $withoutMentions = preg_replace('/<@[UW][A-Z0-9]+(?:\|[^>]*)?>/', '', $text) ?? '';
        // Collapse whitespace and trim common leading connectors/punctuation.
        $reason = trim(preg_replace('/\s+/', ' ', $withoutMentions) ?? '');
        $reason = preg_replace('/^(for|to|:|-|–|,)\s+/i', '', $reason) ?? $reason;
        return trim($reason);
    }

    private function escapeMrkdwn(string $text): string
    {
        // Slack requires &, <, > to be HTML-escaped in message text.
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    // ---------------------------------------------------------------------
    // Response helpers
    // ---------------------------------------------------------------------

    private function ephemeral(Response $response, string $text): Response
    {
        return $this->json($response, ['response_type' => 'ephemeral', 'text' => $text]);
    }

    private function json(Response $response, array $payload): Response
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }
}
