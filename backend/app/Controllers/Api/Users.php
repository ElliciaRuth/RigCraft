<?php

namespace App\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\UserModel;

/** Staff account management (route filter apiauth:admin). */
class Users extends BaseApiController
{
    /** GET /api/users */
    public function index()
    {
        return $this->ok([
            'items'               => model(UserModel::class)->listWithActivity(),
            'min_password_length' => $this->rc->minPasswordLength,
        ]);
    }

    /** POST /api/users  { full_name, username, role, is_active, password } */
    public function create()
    {
        $b   = $this->input();
        $min = $this->rc->minPasswordLength;
        $b['username'] = strtolower((string) ($b['username'] ?? ''));

        $this->check($b, $this->commonRules() + [
            'username' => ['rules' => 'required|regex_match[/^[a-z0-9._-]{3,30}$/]', 'errors' => ['required' => 'Choose a username.', 'regex_match' => '3–30 characters: letters, numbers, dot, dash or underscore.']],
            'password' => ['rules' => "required|min_length[{$min}]", 'errors' => ['required' => "At least {$min} characters.", 'min_length' => "At least {$min} characters."]],
        ]);

        $users = model(UserModel::class);
        if ($users->where('username', $b['username'])->countAllResults() > 0) {
            throw ApiError::validation(['username' => 'That username is already taken.']);
        }

        $users->insert([
            'username'      => $b['username'],
            'full_name'     => $b['full_name'],
            'password_hash' => password_hash((string) $b['password'], PASSWORD_DEFAULT),
            'role'          => $b['role'],
            'is_active'     => (int) $b['is_active'],
        ]);

        return $this->ok(['id' => (int) $users->getInsertID()], 201);
    }

    /** PUT /api/users/{id}  { full_name, role, is_active, password? } — usernames can't change. */
    public function update(string $id = '')
    {
        $id  = (int) $id;
        $b   = $this->input();
        $min = $this->rc->minPasswordLength;

        $this->check($b, $this->commonRules() + [
            'password' => ['rules' => "permit_empty|min_length[{$min}]", 'errors' => ['min_length' => "At least {$min} characters, or leave blank to keep the current one."]],
        ]);

        $users    = model(UserModel::class);
        $existing = $users->select('id, role, is_active')->find($id);
        if (! $existing) {
            throw ApiError::notFound('User not found.');
        }

        // Guard rails: don't lock yourself out, and always keep one active admin.
        $me = $this->auth->user();
        if ($id === $me['id'] && ($b['role'] !== 'admin' || ! $b['is_active'])) {
            throw ApiError::conflict('You cannot remove your own admin access or deactivate yourself.');
        }
        if ($existing['role'] === 'admin' && $existing['is_active'] && ($b['role'] !== 'admin' || ! $b['is_active'])
            && $users->activeAdminCount() <= 1) {
            throw ApiError::conflict('RigCraft needs at least one active administrator.');
        }

        $data = ['full_name' => $b['full_name'], 'role' => $b['role'], 'is_active' => (int) $b['is_active']];
        if (($b['password'] ?? '') !== '') {
            $data['password_hash'] = password_hash((string) $b['password'], PASSWORD_DEFAULT);
        }
        $users->update($id, $data);

        return $this->ok(['id' => $id]);
    }

    private function input(): array
    {
        $raw = $this->body();
        $b   = $this->trimStrings($raw);
        $b['password']  = (string) ($raw['password'] ?? '');   // passwords are not trimmed
        $b['role']      = ($b['role'] ?? '') === '' ? 'technician' : $b['role'];
        $b['is_active'] = filter_var($b['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);

        return $b;
    }

    private function commonRules(): array
    {
        return [
            'full_name' => ['rules' => 'required|max_length[80]', 'errors' => ['required' => "Enter the person's full name.", 'max_length' => 'Max 80 characters.']],
            'role'      => ['rules' => 'in_list[' . implode(',', $this->rc->roles) . ']', 'errors' => ['in_list' => 'Pick a role.']],
        ];
    }
}
