<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Question;
use App\Models\Vote;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('question:test-api {--samples=3 : Number of API responses to sample}')]
#[Description('Test the Would You Rather API — verifies connectivity, parsing, and DB state')]
final class TestApi extends Command
{
    public function handle(): int
    {
        $failures = 0;

        // 1. API connectivity
        $this->line('<options=bold>1. API Connectivity</>');
        $response = Http::timeout(5)->get('https://api.truthordarebot.xyz/api/wyr', ['rating' => 'pg']);

        if ($response->ok()) {
            $this->info('   API reachable (HTTP 200)');
        } else {
            $this->error('   API unreachable — HTTP '.$response->status());
            $failures++;
        }

        // 2. Response shape
        $this->line('<options=bold>2. Response Shape</>');
        /** @var array{id?: string, question?: string} $data */
        $data = $response->json();
        $keys = ['id', 'question'];

        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $this->info(sprintf('   "%s" present: %s', $key, $data[$key]));
            } else {
                $this->error(sprintf('   "%s" missing or empty in response', $key));
                $failures++;
            }
        }

        // 3. Parsing
        $this->line('<options=bold>3. Option Parsing</>');
        $samples = (int) $this->option('samples');

        for ($i = 1; $i <= $samples; $i++) {
            $r = Http::timeout(5)->get('https://api.truthordarebot.xyz/api/wyr', ['rating' => 'pg']);
            /** @var array{question?: string} $d */
            $d = $r->json();
            $raw = $d['question'] ?? '';
            $stripped = (string) preg_replace('/^would you rather\s+/i', '', $raw);
            $stripped = mb_rtrim($stripped, '?');
            $lastOr = mb_strrpos($stripped, ' or ');

            if ($lastOr !== false) {
                $a = mb_substr($stripped, 0, $lastOr);
                $b = mb_substr($stripped, $lastOr + 4);
                $this->info(sprintf('   Sample %d: OK', $i));
                $this->line('     A: '.$a);
                $this->line('     B: '.$b);
            } else {
                $this->warn(sprintf('   Sample %d: UNPARSEABLE — %s', $i, $raw));
                $failures++;
            }
        }

        // 4. Database state
        $this->line('<options=bold>4. Database State</>');
        $totalQuestions = Question::query()->count();
        $activeQuestions = Question::query()->where('is_active', true)->where('expires_at', '>', now())->count();
        $totalVotes = Vote::query()->count();

        $this->line(sprintf('   Total questions: <comment>%d</comment>', $totalQuestions));
        $this->line(sprintf('   Active (non-expired): <comment>%d</comment>', $activeQuestions));
        $this->line(sprintf('   Total votes: <comment>%d</comment>', $totalVotes));

        if ($activeQuestions === 0) {
            $this->warn('   No active question — run: php artisan question:fetch');
        }

        // Summary
        $this->newLine();

        if ($failures === 0) {
            $this->info('All checks passed.');

            return self::SUCCESS;
        }

        $this->error($failures.' check(s) failed.');

        return self::FAILURE;
    }
}
