<?php
declare(strict_types=1);

namespace App\Support;

use Closure;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * User accounts + roles.
 *
 *  - superadmin: the operator (sees all workspaces, manages global Slack settings)
 *  - customer:   created at checkout, owns one workspace, sets their own password
 */
final class AccountService
{
    public const ROLE_SUPER    = 'superadmin';
    public const ROLE_CUSTOMER = 'customer';
    public const ST_ACTIVE     = 'active';
    public const ST_PENDING    = 'pending_password';

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

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM gsb_accounts WHERE email = :e');
        $stmt->execute([':e' => strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM gsb_accounts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function linkTeam(int $accountId, string $teamId): void
    {
        $this->db()->prepare('UPDATE gsb_accounts SET team_id = :t WHERE id = :id')
            ->execute([':t' => $teamId, ':id' => $accountId]);
    }

    /** @return array<string,mixed>|null */
    public function findByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $stmt = $this->db()->prepare('SELECT * FROM gsb_accounts WHERE setup_token = :t');
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function verify(array $account, string $password): bool
    {
        $hash = (string) ($account['password_hash'] ?? '');
        return $hash !== '' && password_verify($password, $hash);
    }

    public function count(): int
    {
        return (int) $this->db()->query('SELECT COUNT(*) FROM gsb_accounts')->fetchColumn();
    }

    /**
     * Ensure the superadmin exists (idempotent). Called at login-bootstrap so the
     * operator can always get in even before the migration seed runs.
     */
    public function ensureSuperadmin(string $email, string $passwordHash): void
    {
        if ($passwordHash === '') {
            return;
        }
        $this->db()->prepare(
            'INSERT INTO gsb_accounts (email, password_hash, role, status)
             VALUES (:e, :h, :r, :s)
             ON DUPLICATE KEY UPDATE role = VALUES(role)'
        )->execute([
            ':e' => strtolower(trim($email)),
            ':h' => $passwordHash,
            ':r' => self::ROLE_SUPER,
            ':s' => self::ST_ACTIVE,
        ]);
    }

    /**
     * Get-or-create a customer account for a checkout. Returns the account plus
     * a flag for whether the caller should send them to set a password.
     *
     * @return array{account:array<string,mixed>,needs_password:bool}
     */
    public function startCustomer(string $email, string $workspaceName): array
    {
        $email = strtolower(trim($email));
        $existing = $this->findByEmail($email);

        // Already has a usable account — don't reset it; send them to log in.
        if ($existing !== null && $existing['status'] === self::ST_ACTIVE && !empty($existing['password_hash'])) {
            return ['account' => $existing, 'needs_password' => false];
        }

        $token = bin2hex(random_bytes(24));
        $this->db()->prepare(
            'INSERT INTO gsb_accounts (email, role, status, workspace_name, setup_token)
             VALUES (:e, :r, :s, :w, :t)
             ON DUPLICATE KEY UPDATE workspace_name = VALUES(workspace_name), setup_token = VALUES(setup_token), status = VALUES(status)'
        )->execute([
            ':e' => $email,
            ':r' => self::ROLE_CUSTOMER,
            ':s' => self::ST_PENDING,
            ':w' => $workspaceName,
            ':t' => $token,
        ]);

        $account = $this->findByEmail($email);
        return ['account' => $account ?? [], 'needs_password' => true];
    }

    /** @return array<string,mixed>|null */
    public function findBySubscription(string $subscriptionId): ?array
    {
        if ($subscriptionId === '') {
            return null;
        }
        $stmt = $this->db()->prepare('SELECT * FROM gsb_accounts WHERE stripe_subscription_id = :s');
        $stmt->execute([':s' => $subscriptionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Start a password reset: set a fresh one-time token on the account (if it
     * exists) and return it, so a link to /set-password?token=… can be sent.
     * Returns null if no account has that email (caller should stay neutral).
     */
    public function startPasswordReset(string $email): ?string
    {
        $acc = $this->findByEmail($email);
        if ($acc === null) {
            return null;
        }
        $token = bin2hex(random_bytes(24));
        $this->db()->prepare('UPDATE gsb_accounts SET setup_token = :t WHERE id = :id')
            ->execute([':t' => $token, ':id' => $acc['id']]);
        return $token;
    }

    public function attachStripe(int $accountId, string $customerId, string $subscriptionId, string $subStatus): void
    {
        $this->db()->prepare(
            'UPDATE gsb_accounts SET stripe_customer_id = :c, stripe_subscription_id = :s, sub_status = :st WHERE id = :id'
        )->execute([':c' => $customerId, ':s' => $subscriptionId, ':st' => $subStatus, ':id' => $accountId]);
    }

    public function setSubStatus(string $subscriptionId, string $subStatus): void
    {
        if ($subscriptionId === '') {
            return;
        }
        $this->db()->prepare(
            'UPDATE gsb_accounts SET sub_status = :st WHERE stripe_subscription_id = :s'
        )->execute([':st' => $subStatus, ':s' => $subscriptionId]);
    }

    /**
     * Set a password from the setup token, activate the account, and clear the token.
     *
     * @return array<string,mixed>|null the activated account, or null if the token is bad
     */
    public function setPasswordByToken(string $token, string $password): ?array
    {
        $acc = $this->findByToken($token);
        if ($acc === null) {
            return null;
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->db()->prepare(
            'UPDATE gsb_accounts SET password_hash = :h, status = :s, setup_token = NULL WHERE id = :id'
        )->execute([':h' => $hash, ':s' => self::ST_ACTIVE, ':id' => $acc['id']]);

        return $this->findByEmail((string) $acc['email']);
    }
}
