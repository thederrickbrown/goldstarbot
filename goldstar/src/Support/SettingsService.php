<?php
declare(strict_types=1);

namespace App\Support;

use Closure;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Effective Slack settings = config.local.php defaults overlaid with values
 * saved from the admin Settings page (stored in gsb_settings).
 *
 * If the DB read fails, we fall back to the config defaults so signature
 * verification keeps working even if the database is momentarily unavailable.
 */
final class SettingsService
{
    /** Keys that may be overridden from the admin UI. */
    public const KEYS = ['signing_secret', 'bot_token', 'post_target', 'recognition_channel', 'max_request_age'];

    /** @var Closure():PDO */
    private Closure $pdoFactory;
    private LoggerInterface $logger;
    /** @var array<string,mixed> */
    private array $defaults;
    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    /** @param array<string,mixed> $defaults config['slack'] */
    public function __construct(Closure $pdoFactory, LoggerInterface $logger, array $defaults)
    {
        $this->pdoFactory = $pdoFactory;
        $this->logger = $logger;
        $this->defaults = $defaults;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $merged = $this->defaults;
        try {
            $rows = ($this->pdoFactory)()
                ->query('SELECT skey, svalue FROM gsb_settings')
                ->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($rows as $k => $v) {
                if ($v !== null && $v !== '') {
                    $merged[$k] = $v;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('SettingsService: load failed, using config defaults', ['error' => $e->getMessage()]);
        }
        return $this->cache = $merged;
    }

    /** @return mixed */
    public function get(string $key, $default = null)
    {
        $all = $this->all();
        return $all[$key] ?? $default;
    }

    /** Per-workspace override keys allowed from the workspace settings page. */
    public const TEAM_KEYS = ['recognition_channel', 'post_target'];

    /**
     * Effective value for a workspace: per-team override (ws:{teamId}:{key})
     * falls back to the global value, then the given default.
     *
     * @return mixed
     */
    public function getForTeam(string $teamId, string $key, $default = null)
    {
        $all = $this->all();
        $tk = 'ws:' . $teamId . ':' . $key;
        if (isset($all[$tk]) && $all[$tk] !== '' && $all[$tk] !== null) {
            return $all[$tk];
        }
        return $all[$key] ?? $default;
    }

    /** True if this workspace has been granted free (comped) access. */
    public function isTeamComped(string $teamId): bool
    {
        $all = $this->all();
        return isset($all['ws:' . $teamId . ':comp']) && (string) $all['ws:' . $teamId . ':comp'] === '1';
    }

    /** Grant or remove free access for a workspace. */
    public function setTeamComp(string $teamId, bool $on): void
    {
        $pdo = ($this->pdoFactory)();
        $pdo->prepare(
            'INSERT INTO gsb_settings (skey, svalue) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)'
        )->execute([':k' => 'ws:' . $teamId . ':comp', ':v' => $on ? '1' : '0']);
        $this->cache = null;
    }

    /**
     * Save per-workspace overrides. An empty value clears the override (falls
     * back to global). Only TEAM_KEYS are accepted.
     *
     * @param array<string,string> $kv
     */
    public function saveForTeam(string $teamId, array $kv): void
    {
        $pdo = ($this->pdoFactory)();
        $stmt = $pdo->prepare(
            'INSERT INTO gsb_settings (skey, svalue) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)'
        );
        foreach ($kv as $k => $v) {
            if (!in_array($k, self::TEAM_KEYS, true)) {
                continue;
            }
            $stmt->execute([':k' => 'ws:' . $teamId . ':' . $k, ':v' => $v]);
        }
        $this->cache = null;
    }

    /** True if a value is set (non-empty), used to mask secrets in the UI. */
    public function isSet(string $key): bool
    {
        $v = $this->get($key, '');
        return is_string($v) ? $v !== '' : !empty($v);
    }

    /**
     * Upsert the given key/value pairs.
     *
     * @param array<string,string> $kv
     */
    public function save(array $kv): void
    {
        $pdo = ($this->pdoFactory)();
        $stmt = $pdo->prepare(
            'INSERT INTO gsb_settings (skey, svalue) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)'
        );
        foreach ($kv as $k => $v) {
            if (!in_array($k, self::KEYS, true)) {
                continue;
            }
            $stmt->execute([':k' => $k, ':v' => $v]);
        }
        $this->cache = null;
    }
}
