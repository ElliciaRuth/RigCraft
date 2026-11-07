<?php

namespace App\Libraries;

use App\Models\CustomerModel;
use App\Models\UserModel;
use CodeIgniter\Session\Session;
use Config\RigCraft;

/**
 * Sign-in state for both kinds of account, kept in the CodeIgniter session.
 *
 *   Staff     → session key 'uid' (users table)       — the console
 *   Shoppers  → session key 'cid' (customers table)   — the store
 *
 * They are independent: a shopper account can never open the console, and
 * signing out of one leaves the other alone. Both are re-read from the
 * database on each request, so a deactivated account or changed role takes
 * effect immediately. Get the shared instance with service('rcauth').
 */
class RcAuth
{
    /** Hash used when the account doesn't exist, so timing doesn't reveal valid names. */
    private const DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    private Session $session;
    private RigCraft $rc;

    private bool $userLoaded     = false;
    private ?array $user         = null;
    private bool $customerLoaded = false;
    private ?array $customer     = null;

    public function __construct()
    {
        $this->session = session();
        $this->rc      = config('RigCraft');
    }

    // =================================================================
    // Who is signed in
    // =================================================================

    /** The signed-in staff member, or null. */
    public function user(): ?array
    {
        if ($this->userLoaded) {
            return $this->user;
        }
        $this->userLoaded = true;

        $id = $this->session->get('uid');
        if (! $id) {
            return $this->user = null;
        }

        $u = model(UserModel::class)->select('id, username, full_name, role, is_active')->find($id);
        if (! $u || ! $u['is_active']) {
            $this->session->remove('uid');

            return $this->user = null;
        }

        return $this->user = [
            'id'        => (int) $u['id'],
            'username'  => $u['username'],
            'full_name' => $u['full_name'],
            'role'      => $u['role'],
        ];
    }

    /** The signed-in shopper, or null. */
    public function customer(): ?array
    {
        if ($this->customerLoaded) {
            return $this->customer;
        }
        $this->customerLoaded = true;

        $id = $this->session->get('cid');
        if (! $id) {
            return $this->customer = null;
        }

        $c = model(CustomerModel::class)->select('id, email, full_name, phone, is_active')->find($id);
        if (! $c || ! $c['is_active']) {
            $this->session->remove('cid');

            return $this->customer = null;
        }

        return $this->customer = [
            'id'        => (int) $c['id'],
            'email'     => $c['email'],
            'full_name' => $c['full_name'],
            'phone'     => $c['phone'],
        ];
    }

    /** The shopper who may use the cart: a signed-in customer, while no staff member is signed in here. */
    public function shopper(): ?array
    {
        return $this->user() ? null : $this->customer();
    }

    // =================================================================
    // CSRF — one token per session, sent back in the X-CSRF-Token header
    // =================================================================

    public function csrfToken(): string
    {
        $token = $this->session->get('csrf');
        if (! is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf', $token);
        }

        return $token;
    }

    public function csrfValid(?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals($this->csrfToken(), $token);
    }

    // =================================================================
    // Staff
    // =================================================================

    /** Returns the user on success, or an error message. Locks the session after repeated failures. */
    public function attemptStaffLogin(string $username, string $password): array|string
    {
        if ($msg = $this->lockedMessage('login')) {
            return $msg;
        }

        $users = model(UserModel::class);
        $u     = $users->where('username', strtolower(trim($username)))->first();

        $ok = password_verify($password, $u['password_hash'] ?? self::DUMMY_HASH) && $u && $u['is_active'];
        if (! $ok) {
            $this->recordFailure('login');

            return ($u && ! $u['is_active']) ? 'This account has been deactivated.' : 'Incorrect username or password.';
        }

        $this->clearFailures('login');
        $this->beginSession('uid', (int) $u['id']);

        $update = ['last_login_at' => date('Y-m-d H:i:s')];
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            $update['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $users->update($u['id'], $update);

        return $this->user() ?? 'Sign-in failed.';
    }

    public function logoutStaff(): void
    {
        $this->session->remove('uid');
        $this->freshSession();
        $this->userLoaded = false;
    }

    /** Change the signed-in user's own password. Returns null on success or an error message. */
    public function changeOwnPassword(int $userId, string $current, string $new, string $confirm): ?string
    {
        $users = model(UserModel::class);
        $hash  = $users->select('password_hash')->find($userId)['password_hash'] ?? null;

        if (! $hash || ! password_verify($current, $hash)) {
            return 'Your current password is incorrect.';
        }
        if (strlen($new) < $this->rc->minPasswordLength) {
            return 'New password must be at least ' . $this->rc->minPasswordLength . ' characters.';
        }
        if ($new !== $confirm) {
            return 'The new passwords do not match.';
        }
        if (password_verify($new, $hash)) {
            return 'Choose a password different from your current one.';
        }

        $users->update($userId, ['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);

        return null;
    }

    // =================================================================
    // Shoppers
    // =================================================================

    public function attemptCustomerLogin(string $email, string $password): array|string
    {
        if ($msg = $this->lockedMessage('cust')) {
            return $msg;
        }

        $customers = model(CustomerModel::class);
        $c         = $customers->where('email', strtolower(trim($email)))->first();

        $ok = password_verify($password, $c['password_hash'] ?? self::DUMMY_HASH) && $c && $c['is_active'];
        if (! $ok) {
            $this->recordFailure('cust');

            return ($c && ! $c['is_active']) ? 'This account has been deactivated.' : 'Incorrect email or password.';
        }

        $this->clearFailures('cust');
        $this->beginSession('cid', (int) $c['id']);

        $update = ['last_login_at' => date('Y-m-d H:i:s')];
        if (password_needs_rehash($c['password_hash'], PASSWORD_DEFAULT)) {
            $update['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $customers->update($c['id'], $update);

        return $this->customer() ?? 'Sign-in failed.';
    }

    /** Sign a newly created shopper account in. */
    public function signInCustomer(int $customerId): ?array
    {
        $this->beginSession('cid', $customerId);

        return $this->customer();
    }

    public function logoutCustomer(): void
    {
        $this->session->remove('cid');
        $this->freshSession();
        $this->customerLoaded = false;
    }

    // =================================================================
    // Internals
    // =================================================================

    /** New session id (prevents fixation) + new CSRF token, then remember who signed in. */
    private function beginSession(string $key, int $id): void
    {
        $this->freshSession();
        $this->session->set($key, $id);
        $this->userLoaded = $this->customerLoaded = false;
    }

    private function freshSession(): void
    {
        $this->session->regenerate(true);
        $this->session->remove('csrf');
    }

    private function lockedMessage(string $kind): ?string
    {
        $until = (int) $this->session->get("{$kind}_locked_until");

        return $until > time() ? 'Too many failed attempts. Try again in ' . ($until - time()) . ' seconds.' : null;
    }

    private function recordFailure(string $kind): void
    {
        $fails = (int) $this->session->get("{$kind}_failures") + 1;
        if ($fails >= $this->rc->loginMaxAttempts) {
            $this->session->set("{$kind}_locked_until", time() + $this->rc->loginLockSeconds);
            $fails = 0;
        }
        $this->session->set("{$kind}_failures", $fails);
    }

    private function clearFailures(string $kind): void
    {
        $this->session->remove(["{$kind}_failures", "{$kind}_locked_until"]);
    }
}
