<?php

namespace App\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\CustomerModel;

/**
 * Sign-in, sign-up and sign-out for staff and shoppers, and changing your own
 * password. Every success returns a fresh session snapshot (see Session),
 * including the new CSRF token issued with the new session id.
 */
class Auth extends BaseApiController
{
    /** POST /api/auth/staff/login  { username, password } */
    public function staffLogin()
    {
        $b        = $this->body();
        $username = trim((string) ($b['username'] ?? ''));
        $password = (string) ($b['password'] ?? '');

        if ($username === '' || $password === '') {
            throw new ApiError(422, 'Enter your username and password.');
        }

        $result = $this->auth->attemptStaffLogin($username, $password);
        if (is_string($result)) {
            throw new ApiError(401, $result);
        }

        return $this->ok(Session::snapshotFor());
    }

    /** POST /api/auth/staff/logout */
    public function staffLogout()
    {
        $this->auth->logoutStaff();

        return $this->ok(Session::snapshotFor());
    }

    /** POST /api/auth/customer/login  { email, password } */
    public function customerLogin()
    {
        $b        = $this->body();
        $email    = trim((string) ($b['email'] ?? ''));
        $password = (string) ($b['password'] ?? '');

        if ($email === '' || $password === '') {
            throw new ApiError(422, 'Enter your email and password.');
        }

        $result = $this->auth->attemptCustomerLogin($email, $password);
        if (is_string($result)) {
            throw new ApiError(401, $result);
        }

        return $this->ok(Session::snapshotFor());
    }

    /** POST /api/auth/customer/register  { full_name, email, phone, password, confirm } — creates and signs in. */
    public function customerRegister()
    {
        $b = $this->trimStrings($this->body());
        $b['email'] = strtolower((string) ($b['email'] ?? ''));
        $min = $this->rc->minPasswordLength;

        $confirmError = isset($b['password'], $b['confirm']) && strlen((string) $b['password']) >= $min && $b['password'] !== $b['confirm']
            ? ['confirm' => 'The passwords do not match.'] : [];

        $this->check($b, [
            'full_name' => ['rules' => 'required|max_length[80]', 'errors' => ['required' => 'Enter your full name.', 'max_length' => 'Max 80 characters.']],
            'email'     => ['rules' => 'required|max_length[190]|valid_email', 'errors' => ['required' => 'Enter your email address.', 'max_length' => 'Enter a valid email address.', 'valid_email' => 'Enter a valid email address.']],
            'phone'     => ['rules' => 'required|ph_phone', 'errors' => ['required' => 'Enter a mobile number so we can confirm your order.', 'ph_phone' => 'Enter a valid mobile number, e.g. 0917 123 4567.']],
            'password'  => ['rules' => "required|min_length[{$min}]", 'errors' => ['required' => "At least {$min} characters.", 'min_length' => "At least {$min} characters."]],
        ], [], $confirmError);

        $customers = model(CustomerModel::class);
        if ($customers->where('email', $b['email'])->countAllResults() > 0) {
            throw ApiError::validation(['email' => 'An account with this email already exists. Sign in instead.']);
        }

        $customers->insert([
            'email'         => $b['email'],
            'full_name'     => $b['full_name'],
            'phone'         => $b['phone'],
            'password_hash' => password_hash((string) $b['password'], PASSWORD_DEFAULT),
            'last_login_at' => date('Y-m-d H:i:s'),
        ]);

        if (! $this->auth->signInCustomer((int) $customers->getInsertID())) {
            throw new ApiError(500, 'Could not create the account.');
        }

        return $this->ok(Session::snapshotFor(), 201);
    }

    /** POST /api/auth/customer/logout — leaves any staff session alone. */
    public function customerLogout()
    {
        $this->auth->logoutCustomer();

        return $this->ok(Session::snapshotFor());
    }

    /** POST /api/account/password  { current, new, confirm } — staff only (route filter). */
    public function changePassword()
    {
        $b     = $this->body();
        $error = $this->auth->changeOwnPassword(
            $this->auth->user()['id'],
            (string) ($b['current'] ?? ''),
            (string) ($b['new'] ?? ''),
            (string) ($b['confirm'] ?? ''),
        );
        if ($error !== null) {
            throw new ApiError(422, $error);
        }

        return $this->ok();
    }
}
