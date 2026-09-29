<?php

namespace App\Services\Ask;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** The sole Groq boundary. Keys stay in an Authorization header and are redacted from logs. */
class GroqClient
{
    public function enabled(): bool
    {
        return filled(config('services.groq.key'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $declarations
     * @return array<string, mixed>
     *
     * @throws AskException
     */
    public function complete(array $messages, array $declarations): array
    {
        $key = config('services.groq.key');

        if (blank($key)) {
            throw new AskException('not_configured', 503, 'Ask NileBot is temporarily unavailable. Please try again later.');
        }

        $payload = [
            'model' => config('services.groq.model'),
            'messages' => $messages,
            'temperature' => 0.3,
            'max_completion_tokens' => 4096,
            'reasoning_effort' => config('services.groq.reasoning_effort'),
            'reasoning_format' => config('services.groq.reasoning_format'),
        ];

        $started = microtime(true);

        if ($declarations !== []) {
            $payload['tools'] = array_map(fn (array $declaration) => ['type' => 'function', 'function' => $declaration], $declarations);
            $payload['tool_choice'] = 'auto';
        }

        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout((int) config('services.groq.timeout'))
                ->post(config('services.groq.base_url'), $payload);
        } catch (ConnectionException) {
            $this->logFailure('network_failure', null, $started);

            throw new AskException('unavailable', 503, "Couldn't reach the AI service. Check the internet connection and try again.");
        }

        if ($response->successful()) {
            $data = $response->json();

            $message = $data['choices'][0]['message'] ?? null;

            if (! is_array($data)
                || ! is_array($message)
                || (array_key_exists('tool_calls', $message) && ! is_array($message['tool_calls']))
                || (isset($message['content']) && ! is_string($message['content']) && $message['content'] !== null)
                || (! array_key_exists('content', $message) && ! array_key_exists('tool_calls', $message) && ! array_key_exists('refusal', $message))) {
                $this->logFailure('malformed_response', $response->status(), $started);

                throw new AskException('malformed_response', 502, 'The AI service sent an invalid response. Try again in a moment.');
            }

            return $data;
        }

        $this->fail($response, $started);
    }

    private function logFailure(string $category, ?int $status, float $started): void
    {
        Log::warning('ask.groq_failure', [
            'category' => $category,
            'status' => $status,
            'model' => config('services.groq.model'),
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    private function fail(Response $response, float $started): never
    {
        $status = $response->status();
        $text = Str::lower($response->body());

        if ($status === 429) {
            $this->logFailure('rate_limited', $status, $started);

            throw new AskException('busy', 429, "The AI service is busy or today's allowance has run out. Try again in a minute.");
        }

        if ($status === 404 || (str_contains($text, 'model') && (str_contains($text, 'not found') || str_contains($text, 'decommissioned') || str_contains($text, 'unavailable')))) {
            $this->logFailure('model_unavailable', $status, $started);

            throw new AskException('model_unavailable', 503, 'Ask NileBot is temporarily unavailable. Please try again later.');
        }

        if (in_array($status, [400, 401, 403], true) && (str_contains($text, 'api key') || str_contains($text, 'invalid_api_key') || str_contains($text, 'authentication') || $status !== 400)) {
            $this->logFailure('authentication_failed', $status, $started);

            throw new AskException('invalid_key', 502, 'Ask NileBot is temporarily unavailable. Please try again later.');
        }

        if ($status >= 500) {
            $this->logFailure('provider_unavailable', $status, $started);

            throw new AskException('unavailable', 503, 'The AI service is having trouble right now. Try again in a moment.');
        }

        $this->logFailure('bad_request', $status, $started);

        throw new AskException('bad_request', 502, "The AI service couldn't handle that question. Try asking it another way.");
    }
}
