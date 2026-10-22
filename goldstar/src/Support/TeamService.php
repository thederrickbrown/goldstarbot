<?php
declare(strict_types=1);

namespace App\Support;

use Closure;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Manages the workspace (Slack team) allowlist.
 *
 * Every team that sends a command is recorded. New teams default to 'pending'
 * and cannot give stars until an admin approves them — this is what prevents an
 * un-invited workspace from writing into the system.
 */
final class TeamService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_BLOCKED = 'blocked';

    /** @var Closure():PDO */
    private Closure $pdoFactory;
    private ?PDO $pdo = null;
    private LoggerInterface $logger;

    /** @param Closure():PDO $pdoFactory */
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
     * Record that a team was seen (insert as pending if new, refresh its domain)
     * and return its current status.
     */
    public function ensureSeen(string $teamId, string $domain): string
    {
        $this->db()->prepare(
            'INSERT INTO gsb_teams (team_id, team_domain, status)
             VALUES (:t, :d, :s)
             ON DUPLICATE KEY UPDATE team_domain = VALUES(team_domain)'
        )->execute([':t' => $teamId, ':d' => $domain, ':s' => self::STATUS_PENDING]);

        $stmt = $this->db()->prepare('SELECT status FROM gsb_teams WHERE team_id = :t');
        $stmt->execute([':t' => $teamId]);
        $status = $stmt->fetchColumn();
        return $status === false ? self::STATUS_PENDING : (string) $status;
    }

    /**
     * Record a Slack OAuth install: store the per-workspace bot token + name.
     * Never downgrades an existing status.
     */
    public function install(string $teamId, string $teamName, string $botToken, string $botUserId): void
    {
        $this->db()->prepare(
            'INSERT INTO gsb_teams (team_id, team_domain, team_name, bot_token, bot_user_id, status, installed_at)
             VALUES (:t, :d, :n, :bt, :bu, :s, NOW())
             ON DUPLICATE KEY UPDATE
                team_domain = VALUES(team_domain),
                team_name   = VALUES(team_name),
                bot_token   = VALUES(bot_token),
                bot_user_id = VALUES(bot_user_id),
                installed_at = NOW()'
        )->execute([
            ':t'  => $teamId,
            ':d'  => $teamName,
            ':n'  => $teamName,
            ':bt' => $botToken,
            ':bu' => $botUserId,
            ':s'  => self::STATUS_PENDING,
        ]);
    }

    /** The per-workspace bot token captured at install (empty if none). */
    public function botToken(string $teamId): string
    {
        $stmt = $this->db()->prepare('SELECT bot_token FROM gsb_teams WHERE team_id = :t');
        $stmt->execute([':t' => $teamId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? '' : (string) $v;
    }

    public function isActive(string $teamId): bool
    {
        $stmt = $this->db()->prepare('SELECT status FROM gsb_teams WHERE team_id = :t');
        $stmt->execute([':t' => $teamId]);
        return $stmt->fetchColumn() === self::STATUS_ACTIVE;
    }

    /**
     * All teams with a star count, newest first.
     *
     * @return array<int,array{team_id:string,team_domain:?string,status:string,first_seen:string,stars:int,receivers:int}>
     */
    public function all(): array
    {
        $sql = 'SELECT t.team_id, t.team_domain, t.status, t.first_seen,
                       (SELECT COUNT(*) FROM gsb_stars s WHERE s.team_id = t.team_id) AS stars,
                       (SELECT COUNT(DISTINCT s.receiver_slack_id) FROM gsb_stars s WHERE s.team_id = t.team_id) AS receivers
                FROM gsb_teams t
                ORDER BY (t.status = \'pending\') DESC, t.first_seen DESC';
        $rows = $this->db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $r): array {
            return [
                'team_id'     => (string) $r['team_id'],
                'team_domain' => $r['team_domain'] !== null ? (string) $r['team_domain'] : null,
                'status'      => (string) $r['status'],
                'first_seen'  => (string) $r['first_seen'],
                'stars'       => (int) $r['stars'],
                'receivers'   => (int) $r['receivers'],
            ];
        }, $rows);
    }

    public function setStatus(string $teamId, string $status): void
    {
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_BLOCKED], true)) {
            return;
        }
        $approvedAt = $status === self::STATUS_ACTIVE ? date('Y-m-d H:i:s') : null;
        $this->db()->prepare(
            'UPDATE gsb_teams SET status = :s, approved_at = :a WHERE team_id = :t'
        )->execute([':s' => $status, ':a' => $approvedAt, ':t' => $teamId]);
    }

    /**
     * Add (or upsert) a team with an explicit status — used by the "Add team"
     * form so an admin can approve a workspace before it ever runs /gs.
     */
    public function add(string $teamId, string $domain, string $status = self::STATUS_ACTIVE): void
    {
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_BLOCKED], true)) {
            $status = self::STATUS_ACTIVE;
        }
        $approvedAt = $status === self::STATUS_ACTIVE ? date('Y-m-d H:i:s') : null;
        $this->db()->prepare(
            'INSERT INTO gsb_teams (team_id, team_domain, status, approved_at)
             VALUES (:t, :d, :s, :a)
             ON DUPLICATE KEY UPDATE team_domain = VALUES(team_domain), status = VALUES(status), approved_at = VALUES(approved_at)'
        )->execute([':t' => $teamId, ':d' => $domain, ':s' => $status, ':a' => $approvedAt]);
    }
}
