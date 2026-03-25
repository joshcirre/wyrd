<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Question;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('question:fetch {--force : Fetch even if an active question exists}')]
#[Description('Fetch a new Would You Rather question from the API and save it')]
final class FetchQuestion extends Command
{
    public function handle(): int
    {
        if (! $this->option('force')) {
            $active = Question::query()
                ->where('is_active', true)
                ->where('expires_at', '>', now())
                ->exists();

            if ($active) {
                $this->warn('An active question already exists. Use --force to fetch anyway.');

                return self::FAILURE;
            }
        }

        $this->info('Fetching question from API...');

        $response = Http::timeout(5)
            ->retry(3, 500)
            ->get('https://api.truthordarebot.xyz/api/wyr', ['rating' => 'pg']);

        if (! $response->ok()) {
            $this->error('API request failed: HTTP '.$response->status());

            return self::FAILURE;
        }

        /** @var array{id?: string, question?: string} $data */
        $data = $response->json();

        $raw = $data['question'] ?? '';

        $this->line(sprintf('Raw question: <comment>%s</comment>', $raw));

        $stripped = (string) preg_replace('/^would you rather\s+/i', '', $raw);
        $stripped = mb_rtrim($stripped, '?');

        $lastOr = mb_strrpos($stripped, ' or ');

        if ($lastOr === false) {
            $this->error('Could not parse two options from the question — no " or " found.');

            return self::FAILURE;
        }

        $option1 = mb_substr($stripped, 0, $lastOr);
        $option2 = mb_substr($stripped, $lastOr + 4);

        if ($option1 === '' || $option2 === '') {
            $this->error('Parsed options are empty.');

            return self::FAILURE;
        }

        Question::query()->where('is_active', true)->update(['is_active' => false]);

        $question = Question::query()->create([
            'external_id' => $data['id'] ?? null,
            'option1' => $option1,
            'option2' => $option2,
            'expires_at' => now()->addSeconds(60),
            'is_active' => true,
        ]);

        $this->info(sprintf('Question created (ID: %s):', $question->id));
        $this->line(sprintf('  A: <comment>%s</comment>', $option1));
        $this->line(sprintf('  B: <comment>%s</comment>', $option2));
        $this->line(sprintf('  Expires: <comment>%s</comment>', $question->expires_at));

        return self::SUCCESS;
    }
}
