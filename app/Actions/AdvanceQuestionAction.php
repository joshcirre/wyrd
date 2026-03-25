<?php

declare(strict_types=1);

namespace App\Actions;

use App\Events\QuestionAdvanced;
use App\Models\Question;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final readonly class AdvanceQuestionAction
{
    public function handle(): ?Question
    {
        $lock = Cache::lock('question-advance', 15);

        if (! $lock->get()) {
            // Another process is advancing — wait briefly then return whatever was created
            usleep(500_000);

            return Question::query()
                ->where('is_active', true)
                ->latest()
                ->first();
        }

        try {
            // Double-check: did another process already create a new question?
            $recent = Question::query()
                ->where('is_active', true)
                ->where('expires_at', '>', now())
                ->where('created_at', '>', now()->subSeconds(5))
                ->latest()
                ->first();

            if ($recent) {
                return $recent;
            }

            $question = $this->fetchNewQuestion();

            if ($question instanceof Question) {
                broadcast(new QuestionAdvanced($question));
            }

            return $question;
        } finally {
            $lock->release();
        }
    }

    private function fetchNewQuestion(): ?Question
    {
        Question::query()
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $response = Http::timeout(5)
            ->retry(3, 500)
            ->get('https://api.truthordarebot.xyz/api/wyr', ['rating' => 'pg']);

        if (! $response->ok()) {
            return null;
        }

        /** @var array{id?: string, question?: string} $data */
        $data = $response->json();

        $raw = $data['question'] ?? '';

        $stripped = (string) preg_replace('/^.*would you rather\s+/i', '', $raw);
        $stripped = mb_rtrim($stripped, '?');

        $lastOr = mb_strrpos($stripped, ' or ');

        if ($lastOr === false) {
            return null;
        }

        $option1 = mb_substr($stripped, 0, $lastOr);
        $option2 = mb_substr($stripped, $lastOr + 4);

        if ($option1 === '' || $option2 === '') {
            return null;
        }

        return Question::query()->create([
            'external_id' => $data['id'] ?? null,
            'option1' => $option1,
            'option2' => $option2,
            'expires_at' => now()->addSeconds(60),
            'is_active' => true,
        ]);
    }
}
