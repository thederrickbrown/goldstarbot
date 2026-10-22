<?php
declare(strict_types=1);

namespace App\Support;

use Closure;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * All persistence + tally + achievement logic for stars.
 *
 * `gsb_stars` is the immutable ledger (one row per star given).
 * `gsb_star_totals` holds the authoritative per-(team,receiver) running total,
 * incremented atomically so concurrent /gs commands can't miscount a
 * milestone or deadlock on gap locks.
 *
 * The PDO connection is created lazily on first use so request paths which
 * never touch the database (signature rejection, /gs help) don't open MySQL.
 */
final class StarService
{
    /** @var Closure():PDO */
    private Closure $pdoFactory;
    private ?PDO $pdo = null;
    private LoggerInterface $logger;

    /**
     * @param Closure():PDO $pdoFactory
     */
    public function __construct(Closure $pdoFactory, LoggerInterface $logger)
    {
        $this->pdoFactory = $pdoFactory;
        $this->logger = $logger;
    }

    private function db(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = ($this->pdoFactory)();
        }
        return $this->pdo;
    }

    /**
     * Cache a Slack user's display info so leaderboards can render names.
     */
    public function upsertUser(string $slackId, string $username, string $displayName, string $teamId): void
    {
        $sql = 'INSERT INTO gsb_users (slack_user_id, slack_username, display_name, team_id)
                VALUES (:id, :username, :display, :team)
                ON DUPLICATE KEY UPDATE
                    slack_username = VALUES(slack_username),
                    display_name   = VALUES(display_name),
                    team_id        = VALUES(team_id)';
        $stmt = $this->db()->prepare($sql);
        $stmt->execute([
            ':id'      => $slackId,
            ':username' => $username,
            ':display' => $displayName,
            ':team'    => $teamId,
        ]);
    }

    /**
     * How many stars a user has received (all-time, per team).
     */
    public function receivedCount(string $receiverId, string $teamId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT total FROM gsb_star_totals WHERE team_id = :t AND receiver_slack_id = :r'
        );
        $stmt->execute([':t' => $teamId, ':r' => $receiverId]);
        $total = $stmt->fetchColumn();
        return $total === false ? 0 : (int) $total;
    }

    /**
     * Record a single star and award any newly reached achievements.
     *
     * The star is appended to the ledger and the authoritative total is bumped
     * with an atomic INSERT ... ON DUPLICATE KEY UPDATE, which takes an
     * exclusive lock on the single counter row. That serializes concurrent
     * increments for the same receiver, so each caller reads a distinct,
     * correct running total (no missed milestones, no gap-lock deadlocks).
     *
     * @return array{total:int,new_achievements:array<int,array{code:string,threshold:int,emoji:string,name:string}>}
     */
    public function giveStar(
        string $giverId,
        string $receiverId,
        string $teamId,
        string $channelId,
        string $reason
    ): array {
        $db = $this->db();
        $db->beginTransaction();
        try {
            $db->prepare(
                'INSERT INTO gsb_stars (giver_slack_id, receiver_slack_id, team_id, channel_id, reason)
                 VALUES (:g, :r, :t, :c, :reason)'
            )->execute([
                ':g'      => $giverId,
                ':r'      => $receiverId,
                ':t'      => $teamId,
                ':c'      => $channelId,
                ':reason' => $reason,
            ]);

            // Atomically bump and read back the authoritative total.
            $db->prepare(
                'INSERT INTO gsb_star_totals (team_id, receiver_slack_id, total)
                 VALUES (:t, :r, 1)
                 ON DUPLICATE KEY UPDATE total = total + 1'
            )->execute([':t' => $teamId, ':r' => $receiverId]);

            $totalStmt = $db->prepare(
                'SELECT total FROM gsb_star_totals WHERE team_id = :t AND receiver_slack_id = :r'
            );
            $totalStmt->execute([':t' => $teamId, ':r' => $receiverId]);
            $total = (int) $totalStmt->fetchColumn();
            $previous = $total - 1;

            $newAchievements = [];
            foreach (AchievementCatalog::newlyReached($previous, $total) as $tier) {
                if ($this->awardAchievement($receiverId, $teamId, $tier)) {
                    $newAchievements[] = $tier;
                }
            }

            $db->commit();
            return ['total' => $total, 'new_achievements' => $newAchievements];
        } catch (\Throwable $e) {
            $db->rollBack();
            $this->logger->error('StarService::giveStar failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Leaderboard of top receivers for a team.
     *
     * @return array<int,array{receiver_slack_id:string,display_name:?string,total:int}>
     */
    public function leaderboard(string $teamId, int $limit = 10): array
    {
        // Clamp; interpolated because bound LIMIT params are unreliable under
        // emulated prepares. $limit is coerced to an int, so no injection.
        $limit = max(1, min(50, $limit));
        $sql = 'SELECT t.receiver_slack_id, u.display_name, t.total
                FROM gsb_star_totals t
                LEFT JOIN gsb_users u ON u.slack_user_id = t.receiver_slack_id
                WHERE t.team_id = :t
                ORDER BY t.total DESC, t.updated_at DESC
                LIMIT ' . $limit;
        $stmt = $this->db()->prepare($sql);
        $stmt->execute([':t' => $teamId]);
        $rows = $stmt->fetchAll();

        return array_map(static function (array $r): array {
            return [
                'receiver_slack_id' => (string) $r['receiver_slack_id'],
                'display_name'      => $r['display_name'] !== null ? (string) $r['display_name'] : null,
                'total'             => (int) $r['total'],
            ];
        }, $rows ?: []);
    }

    /**
     * Distinct users who gave OR received a star for a team within the trailing
     * window — the "active users" that billing is based on.
     */
    public function activeUserCount(string $teamId, int $sinceDays): int
    {
        $since = max(1, $sinceDays);
        $sql = 'SELECT COUNT(*) FROM (
                    SELECT giver_slack_id AS u FROM gsb_stars
                        WHERE team_id = :t1 AND created_at >= (NOW() - INTERVAL ' . $since . ' DAY)
                    UNION
                    SELECT receiver_slack_id AS u FROM gsb_stars
                        WHERE team_id = :t2 AND created_at >= (NOW() - INTERVAL ' . $since . ' DAY)
                ) x';
        $stmt = $this->db()->prepare($sql);
        $stmt->execute([':t1' => $teamId, ':t2' => $teamId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * A user's total received, total given, and earned achievement codes.
     *
     * @return array{total:int,given:int,achievements:array<int,string>}
     */
    public function stats(string $userId, string $teamId): array
    {
        $total = $this->receivedCount($userId, $teamId);

        $givenStmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM gsb_stars WHERE giver_slack_id = :g AND team_id = :t'
        );
        $givenStmt->execute([':g' => $userId, ':t' => $teamId]);
        $given = (int) $givenStmt->fetchColumn();

        $achStmt = $this->db()->prepare(
            'SELECT code FROM gsb_achievements WHERE slack_user_id = :u AND team_id = :t ORDER BY threshold ASC'
        );
        $achStmt->execute([':u' => $userId, ':t' => $teamId]);
        $codes = array_map('strval', $achStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        return ['total' => $total, 'given' => $given, 'achievements' => $codes];
    }

    /**
     * @param array{code:string,threshold:int,emoji:string,name:string} $tier
     * @return bool true if newly awarded, false if the user already had it
     */
    private function awardAchievement(string $userId, string $teamId, array $tier): bool
    {
        $stmt = $this->db()->prepare(
            'INSERT IGNORE INTO gsb_achievements (slack_user_id, team_id, code, threshold)
             VALUES (:u, :t, :code, :threshold)'
        );
        $stmt->execute([
            ':u'         => $userId,
            ':t'         => $teamId,
            ':code'      => $tier['code'],
            ':threshold' => $tier['threshold'],
        ]);
        return $stmt->rowCount() > 0;
    }
}
