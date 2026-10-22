<?php
declare(strict_types=1);

namespace App\Support;

use Closure;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Append-only activity log the superadmin can review.
 * Logging never throws into the caller — a logging failure must not break a
 * star, a login, or a checkout.
 */
final class ActivityService
{
    public const T_STAR        = 'star';
    public const T_ACHIEVEMENT = 'achievement';
    public const T_WORKSPACE   = 'workspace';
    public const T_TRIAL       = 'trial';
    public const T_LOGIN       = 'login';
    public const T_LOGIN_FAIL  = 'login_fail';
    public const T_LOGOUT      = 'logout';
    public const T_PASSWORD    = 'password';
    public const T_COMMAND     = 'command';
    public const T_BILLING     = 'billing';
    public const T_BLOCKED     = 'blocked';
    public const T_ACCOUNT     = 'account';

    /** Best-effort client IP (SiteGround/CDN aware) for audit entries. */
    public static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', (string) $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }

    /** Friendly "Browser on OS" from the User-Agent, for readable audit logs. */
    public static function clientAgent(): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '') {
            return '';
        }
        $os = 'Unknown OS';
        if (stripos($ua, 'iPhone') !== false) {
            $os = 'iPhone';
        } elseif (stripos($ua, 'iPad') !== false) {
            $os = 'iPad';
        } elseif (stripos($ua, 'Android') !== false) {
            $os = 'Android';
        } elseif (stripos($ua, 'Windows') !== false) {
            $os = 'Windows';
        } elseif (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) {
            $os = 'macOS';
        } elseif (stripos($ua, 'Linux') !== false) {
            $os = 'Linux';
        }
        $br = 'Unknown browser';
        if (stripos($ua, 'Edg') !== false) {
            $br = 'Edge';
        } elseif (stripos($ua, 'OPR') !== false || stripos($ua, 'Opera') !== false) {
            $br = 'Opera';
        } elseif (stripos($ua, 'Firefox') !== false) {
            $br = 'Firefox';
        } elseif (stripos($ua, 'Chrome') !== false) {
            $br = 'Chrome';
        } elseif (stripos($ua, 'Safari') !== false) {
            $br = 'Safari';
        }
        return $br . ' on ' . $os;
    }

    /** " · from {ip} · {browser on os}" suffix for auth/audit entries. */
    public static function clientContext(): string
    {
        $bits = [];
        if (($ip = self::clientIp()) !== '') {
            $bits[] = 'from ' . $ip;
        }
        if (($ua = self::clientAgent()) !== '') {
            $bits[] = $ua;
        }
        return $bits === [] ? '' : ' · ' . implode(' · ', $bits);
    }

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

    public function log(string $type, string $message, ?string $teamId = null, ?string $actor = null): void
    {
        try {
            $this->db()->prepare(
                'INSERT INTO gsb_activity (type, message, team_id, actor) VALUES (:t, :m, :team, :a)'
            )->execute([
                ':t'    => $type,
                ':m'    => mb_substr($message, 0, 500),
                ':team' => $teamId,
                ':a'    => $actor,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('ActivityService: log failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<int,array{created_at:string,type:string,team_id:?string,actor:?string,message:string}>
     */
    public function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        try {
            $rows = $this->db()->query(
                'SELECT created_at, type, team_id, actor, message FROM gsb_activity ORDER BY id DESC LIMIT ' . $limit
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $this->logger->error('ActivityService: recent failed', ['error' => $e->getMessage()]);
            return [];
        }
        return array_map(static function (array $r): array {
            return [
                'created_at' => (string) $r['created_at'],
                'type'       => (string) $r['type'],
                'team_id'    => $r['team_id'] !== null ? (string) $r['team_id'] : null,
                'actor'      => $r['actor'] !== null ? (string) $r['actor'] : null,
                'message'    => (string) $r['message'],
            ];
        }, $rows ?: []);
    }
}
