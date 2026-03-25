<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\AdvanceQuestionAction;
use App\Models\Question;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:ensure-active-question')]
#[Description('Ensure there is always an active question, advancing if the current one has expired')]
final class EnsureActiveQuestion extends Command
{
    public function handle(AdvanceQuestionAction $action): void
    {
        $active = Question::query()
            ->where('is_active', true)
            ->where('expires_at', '>', now())
            ->first();

        if ($active) {
            $this->components->info(sprintf('Active question #%s expires at %s', $active->id, $active->expires_at->toISOString()));

            return;
        }

        $this->components->warn('No active question found, advancing...');

        $question = $action->handle();

        if ($question instanceof Question) {
            $this->components->info(sprintf('New question #%s: %s or %s', $question->id, $question->option1, $question->option2));
        } else {
            $this->components->error('Failed to fetch a new question from the API.');
        }
    }
}
