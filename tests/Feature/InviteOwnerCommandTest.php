<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InviteOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_invited_owner_with_an_organization_and_first_shop_without_a_token(): void
    {
        $this->artisan('beta:invite-owner', [
            '--name' => 'Invited Owner',
            '--email' => 'owner@example.com',
            '--organization' => 'Invited Business',
            '--shop' => 'Main Shop',
        ])
            ->expectsQuestion('Temporary password (minimum 8 characters)', 'password123')
            ->expectsOutputToContain('No API token was issued.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', ['name' => 'Invited Owner', 'email' => 'owner@example.com', 'organization_id' => 1]);
        $this->assertDatabaseHas('organizations', ['id' => 1, 'name' => 'Invited Business', 'owner_user_id' => 1]);
        $this->assertDatabaseHas('shops', ['id' => 1, 'organization_id' => 1, 'name' => 'Main Shop']);
        $this->assertDatabaseHas('user_shop_roles', ['user_id' => 1, 'shop_id' => 1, 'role' => Role::Owner->value]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
