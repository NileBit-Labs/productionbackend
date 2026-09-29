<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_registered_user_receives_a_frontend_password_reset_link(): void
    {
        config(['app.frontend_url' => 'https://pos.example.test']);
        Notification::fake();
        $user = User::factory()->create(['email' => 'jane+reset@example.test']);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent.');

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification, array $channels, User $notifiable): bool {
            $url = $notification->toMail($notifiable)->actionUrl;

            return $channels === ['mail']
                && str_starts_with($url, 'https://pos.example.test/reset-password?')
                && str_contains($url, 'email='.urlencode($notifiable->email))
                && str_contains($url, 'token=');
        });
    }

    public function test_an_unknown_email_receives_the_same_response_without_a_notification(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'unknown@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent.');

        Notification::assertNothingSent();
    }

    public function test_a_delivery_failure_still_returns_the_generic_response(): void
    {
        $user = User::factory()->create();
        Password::shouldReceive('sendResetLink')
            ->once()
            ->with(['email' => $user->email])
            ->andThrow(new RuntimeException('SMTP unavailable'));

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent.');
    }

    public function test_a_valid_reset_token_changes_the_password_and_revokes_sessions(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $user->createToken('first-device');
        $user->createToken('second-device');
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertOk()
            ->assertJsonPath('message', 'Password reset. Please sign in with your new password.');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password123', $user->password));
        $this->assertCount(0, $user->tokens);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_an_invalid_token_cannot_change_a_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => 'invalid-token',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_an_expired_token_cannot_change_a_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $token = Password::broker()->createToken($user);
        $this->travelTo(Carbon::now()->addMinutes(61));

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_password_confirmation_is_required_before_a_reset(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password123',
            'password_confirmation' => 'different-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_password_reset_requests_are_rate_limited_per_email_and_ip(): void
    {
        $email = 'rate-limited-reset@example.com';
        RateLimiter::clear('password-reset:'.$email.'|127.0.0.1');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/forgot-password', ['email' => $email])->assertOk();
        }

        $this->postJson('/api/auth/forgot-password', ['email' => $email])->assertTooManyRequests();
    }
}
