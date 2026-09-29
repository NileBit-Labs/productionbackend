<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AskQuery;
use App\Services\Ask\AskAgent;
use App\Services\Ask\AskException;
use App\Services\Ask\GroqClient;
use App\Support\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Owner/manager only (see routes): the answers draw on sales, costs and customer balances. */
class AskController extends Controller
{
    public function status(Request $request, GroqClient $groq): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $role = $request->attributes->get('shopRole');

        return response()->json([
            'enabled' => $groq->enabled(),
            'is_owner' => $role === Role::Owner,
            'asked_today' => $this->askedToday($shop),
            'daily_limit' => (int) config('services.groq.daily_limit'),
            'suggestions' => $this->suggestions($role),
        ]);
    }

    public function ask(Request $request, AskAgent $agent, GroqClient $groq): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:2', 'max:500'],
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.text' => ['required', 'string', 'max:4000'],
        ]);

        $shop = $request->attributes->get('shop');
        $role = $request->attributes->get('shopRole');
        $started = microtime(true);
        $question = trim($data['question']);

        try {
            if (! $groq->enabled()) {
                throw new AskException('not_configured', 503, 'Ask NileBot is temporarily unavailable. Please try again later.');
            }

            if ($this->askedToday($shop) >= (int) config('services.groq.daily_limit')) {
                throw new AskException('daily_limit', 429, "You've reached today's limit of questions for this shop. It resets at midnight.");
            }

            $result = $agent->answer($shop, $role, $question, $data['history'] ?? []);
        } catch (AskException $e) {
            // Provider and grounding failures are retained for diagnosis, but do not use a shop's
            // daily allowance. The existing per-minute limiter still bounds repeated attempts.
            if (! in_array($e->errorCode, ['not_configured', 'daily_limit'], true)) {
                $this->record($request, $question, null, [], ['input' => 0, 'output' => 0], $started, 'error', $e->errorCode, false);
            }

            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->httpStatus);
        }

        $this->record($request, $question, $result['answer'], $result['tools'], $result['usage'], $started, $result['status'], null, true);

        return response()->json([
            'answer' => $result['answer'],
            'status' => $result['status'],
            'visuals' => $result['visuals'],
            'tools' => array_values(array_unique(array_column($result['tools'], 'label'))),
        ]);
    }

    private function askedToday($shop): int
    {
        return AskQuery::where('shop_id', $shop->id)
            ->where('counts_toward_limit', true)
            ->where('created_at', '>=', ReportRange::today($shop)->utcFrom())
            ->count();
    }

    /**
     * @param  array<int, array{name: string, label: string}>  $tools
     * @param  array{input: int, output: int}  $usage
     */
    private function record(Request $request, string $question, ?string $answer, array $tools, array $usage, float $started, string $status, ?string $error = null, bool $countsTowardLimit = false): void
    {
        AskQuery::create([
            'shop_id' => $request->attributes->get('shop')->id,
            'user_id' => $request->user()->id,
            'question' => Str::limit($question, 500, ''),
            'answer' => $answer,
            'tools' => array_column($tools, 'name'),
            'input_tokens' => $usage['input'],
            'output_tokens' => $usage['output'],
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'status' => $status,
            'error' => $error,
            'counts_toward_limit' => $countsTowardLimit,
        ]);
    }

    /** @return array<int, string> */
    private function suggestions(Role $role): array
    {
        return array_values(array_filter([
            'How are sales today?',
            'What were my best sellers this week?',
            $role === Role::Owner ? 'How much profit did I make this month?' : 'How much did we sell this month?',
            'Which products are running low?',
            'Who owes me money?',
            'How does this week compare with last week?',
            'Where is my money going this month?',
            'Which day of the week is my busiest?',
        ]));
    }
}
