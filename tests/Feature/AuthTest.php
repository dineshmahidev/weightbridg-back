<?php

namespace Tests\Feature;

class AuthTest extends ApiTestCase
{
    public function test_login_returns_token_and_user(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser('security_officer', $company, ['password' => 'secret-pass-1']);

        $response = $this->apiJson('POST', 'auth/login', [
            'email' => $user->email,
            'password' => 'secret-pass-1',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'user' => ['id', 'role' => ['slug', 'permissions'], 'companies']]]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_with_invalid_credentials_fails(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser('security_officer', $company, ['password' => 'secret-pass-1']);

        $this->apiJson('POST', 'auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser('security_officer', $company, ['password' => 'secret-pass-1', 'status' => 'inactive']);

        $this->apiJson('POST', 'auth/login', [
            'email' => $user->email,
            'password' => 'secret-pass-1',
        ])->assertStatus(403);
    }

    public function test_me_requires_authentication(): void
    {
        $this->apiJson('GET', 'auth/me')->assertStatus(401);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser('manager', $company);

        $this->actingAsApi($user)
            ->apiJson('GET', 'auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role.slug', 'manager');
    }

    public function test_logout_invalidates_token(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser('manager', $company);

        $this->actingAsApi($user)->apiJson('POST', 'auth/logout')->assertStatus(200);

        $this->apiJson('GET', 'auth/me')->assertStatus(401);
    }

    public function test_change_password_requires_correct_current_password(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser('manager', $company, ['password' => 'old-password-1']);

        $this->actingAsApi($user)->apiJson('POST', 'auth/change-password', [
            'current_password' => 'wrong',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(422);

        $this->actingAsApi($user->fresh())->apiJson('POST', 'auth/change-password', [
            'current_password' => 'old-password-1',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(200);

        $this->assertTrue($user->fresh()->verifyPassword('new-password-1'));
    }
}
