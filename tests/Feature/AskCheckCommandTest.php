<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AskCheckCommandTest extends TestCase
{
    private function reply(?string $content = null, array $toolCalls = []): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $content] + ($toolCalls === [] ? [] : ['tool_calls' => $toolCalls])]]];
    }

    public function test_it_reports_a_missing_key_without_calling_anyone(): void
    {
        config(['services.groq.key' => null]);
        Http::fake();

        $this->artisan('ask:check')->expectsOutputToContain('No GROQ_API_KEY')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_it_passes_when_the_tool_round_trip_works_and_never_prints_the_key(): void
    {
        config(['services.groq.key' => 'super-secret-key']);
        Http::fake(['api.groq.com/openai/v1/chat/completions' => Http::sequence()
            ->push($this->reply('OK'))
            ->push($this->reply(null, [['id' => 'call-check', 'type' => 'function', 'function' => ['name' => 'get_lucky_number', 'arguments' => '{"shop":"x"}']]]))
            ->push($this->reply('The lucky number is 4217.')),
        ]);

        $this->artisan('ask:check')->expectsOutputToContain('Tool calling works end to end')->doesntExpectOutputToContain('super-secret-key')->assertSuccessful();
    }

    public function test_it_reports_a_refused_groq_key_without_printing_it(): void
    {
        config(['services.groq.key' => 'bad-key']);
        Http::fake(['api.groq.com/openai/v1/chat/completions' => Http::response(['error' => ['message' => 'Invalid API Key']], 401)]);

        $this->artisan('ask:check')->expectsOutputToContain('invalid_key')->doesntExpectOutputToContain('bad-key')->assertFailed();
    }

    public function test_it_fails_when_the_model_ignores_the_tool(): void
    {
        config(['services.groq.key' => 'k']);
        Http::fake(['api.groq.com/openai/v1/chat/completions' => Http::sequence()
            ->push($this->reply('OK'))
            ->push($this->reply('I do not know.')),
        ]);

        $this->artisan('ask:check')->expectsOutputToContain('without using the tool')->assertFailed();
    }

    public function test_it_fails_when_a_tool_call_has_no_id(): void
    {
        config(['services.groq.key' => 'k']);
        Http::fake(['api.groq.com/openai/v1/chat/completions' => Http::sequence()
            ->push($this->reply('OK'))
            ->push($this->reply(null, [['type' => 'function', 'function' => ['name' => 'get_lucky_number', 'arguments' => '{}']]])),
        ]);

        $this->artisan('ask:check')->expectsOutputToContain('without an ID')->assertFailed();
    }
}
