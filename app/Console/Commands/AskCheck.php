<?php

namespace App\Console\Commands;

use App\Services\Ask\AskException;
use App\Services\Ask\GroqClient;
use Illuminate\Console\Command;

/**
 * Proves the AI settings work before anyone relies on them: the key is accepted, the model exists
 * and a tool-calling round trip (the thing "Ask NileBot" depends on) comes back. It never prints
 * the key.
 */
class AskCheck extends Command
{
    protected $signature = 'ask:check';

    protected $description = 'Check the Groq key and model used by Ask NileBot';

    public function handle(GroqClient $groq): int
    {
        $model = config('services.groq.model');
        $this->line("Model: {$model}");

        if (! $groq->enabled()) {
            $this->error('No GROQ_API_KEY is set. Add it to the backend .env file, then run this again.');

            return self::FAILURE;
        }

        try {
            $hello = $groq->complete([['role' => 'user', 'content' => 'Reply with the single word: OK']], []);
            $said = (string) ($hello['choices'][0]['message']['content'] ?? '');
            $this->info('1/2  The key and model work. The AI said: '.($said === '' ? '(nothing)' : trim($said)));

            $tool = [[
                'name' => 'get_lucky_number',
                'description' => 'Returns the shop\'s lucky number.',
                'parameters' => ['type' => 'object', 'properties' => ['shop' => ['type' => 'string', 'description' => 'Any text.']]],
            ]];

            $messages = [['role' => 'user', 'content' => 'What is the shop\'s lucky number? Use the tool, then tell me the number.']];
            $first = $groq->complete($messages, $tool);
            $message = $first['choices'][0]['message'] ?? [];
            $calls = $message['tool_calls'] ?? [];
            $call = $calls[0] ?? null;

            if (! $call) {
                $this->warn('2/2  The AI answered without using the tool. Tool calling may not be supported by this model; try another GROQ_MODEL.');

                return self::FAILURE;
            }

            $callId = $call['id'] ?? null;
            if (! is_string($callId) || $callId === '') {
                $this->warn('2/2  The AI returned a tool call without an ID. The response is not compatible with this model.');

                return self::FAILURE;
            }

            $messages[] = ['role' => 'assistant', 'content' => $message['content'] ?? null, 'tool_calls' => $calls];
            $messages[] = ['role' => 'tool', 'tool_call_id' => $callId, 'name' => $call['function']['name'], 'content' => json_encode(['result' => ['lucky_number' => 4217]], JSON_THROW_ON_ERROR)];
            $second = $groq->complete($messages, $tool);
            $final = (string) ($second['choices'][0]['message']['content'] ?? '');

            if (! str_contains($final, '4217')) {
                $this->warn("2/2  The tool was called but the final answer didn't use its result: ".trim($final));

                return self::FAILURE;
            }

            $this->info('2/2  Tool calling works end to end. Ask NileBot is ready to use.');

            return self::SUCCESS;
        } catch (AskException $e) {
            $this->error("[{$e->errorCode}] {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
