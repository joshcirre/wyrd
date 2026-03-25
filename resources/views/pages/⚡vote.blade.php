<?php

declare(strict_types=1);

use App\Events\QuestionAdvanced;
use App\Events\VoteUpdated;
use App\Models\Question;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('layouts::game')] class extends Component {
    public ?int $questionId = null;
    public string $option1 = '';
    public string $option2 = '';
    public string $expiresAt = '';
    public int $votesOption1 = 0;
    public int $votesOption2 = 0;
    public int $totalVotes = 0;
    public bool $hasVoted = false;
    public ?int $votedOption = null;
    public bool $loading = false;

    public function mount(): void
    {
        $question = Question::query()
            ->where('is_active', true)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $question) {
            $question = $this->fetchNewQuestion();
        }

        if ($question) {
            $this->loadQuestion($question);
        }
    }

    public function advance(): void
    {
        // Only advance if the current question has expired or there's no question
        if ($this->questionId) {
            $current = Question::find($this->questionId);
            if ($current && now()->lt($current->expires_at)) {
                // Not expired yet — sync the client with the real state
                $this->loadQuestion($current);
                $this->dispatch('timer-reset', expiresAt: $this->expiresAt);

                return;
            }
        }

        $lock = Cache::lock('question-advance', 15);

        if (! $lock->get()) {
            // Another request is already advancing — wait briefly then load whatever was created
            usleep(500_000);
            $latest = Question::query()
                ->where('is_active', true)
                ->latest()
                ->first();

            if ($latest) {
                $this->loadQuestion($latest);
                $this->dispatch('timer-reset', expiresAt: $this->expiresAt);
            }

            return;
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
                $this->loadQuestion($recent);
                $this->dispatch('timer-reset', expiresAt: $this->expiresAt);

                return;
            }

            $question = $this->fetchNewQuestion();

            if ($question) {
                $this->loadQuestion($question);
                broadcast(new QuestionAdvanced($question));
                $this->dispatch('timer-reset', expiresAt: $this->expiresAt);
            }
        } finally {
            $lock->release();
        }
    }

    public function vote(int $option): void
    {
        if ($this->hasVoted || ! $this->questionId || ! in_array($option, [1, 2])) {
            return;
        }

        $question = Question::find($this->questionId);

        if (! $question || now()->gte($question->expires_at)) {
            return;
        }

        $identifier = $this->voterIdentifier();

        if (
            $question
                ->votes()
                ->where('voter_identifier', $identifier)
                ->exists()
        ) {
            $this->hasVoted = true;

            return;
        }

        $question->votes()->create([
            'voter_identifier' => $identifier,
            'option' => $option,
        ]);

        $this->hasVoted = true;
        $this->votedOption = $option;
        $this->votesOption1 = $question->votesForOption(1);
        $this->votesOption2 = $question->votesForOption(2);
        $this->totalVotes = $question->totalVotes();

        broadcast(new VoteUpdated($question));
    }

    #[On('echo:voting,VoteUpdated')]
    public function onVoteUpdated(array $data): void
    {
        if ((int) $data['question_id'] !== $this->questionId) {
            return;
        }

        $this->votesOption1 = (int) $data['votes_option1'];
        $this->votesOption2 = (int) $data['votes_option2'];
        $this->totalVotes = (int) $data['total_votes'];
    }

    #[On('echo:voting,QuestionAdvanced')]
    public function onQuestionAdvanced(array $data): void
    {
        if ((int) $data['question_id'] === $this->questionId) {
            return;
        }

        $question = Question::find((int) $data['question_id']);

        if ($question) {
            $this->loadQuestion($question);
            $this->dispatch('timer-reset', expiresAt: $data['expires_at']);
        }
    }

    private function fetchNewQuestion(): ?Question
    {
        // Deactivate all active questions
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

        // Strip "Would you rather " prefix and trailing "?"
        $stripped = (string) preg_replace('/^would you rather\s+/i', '', $raw);
        $stripped = mb_rtrim($stripped, '?');

        // Split on the last occurrence of " or "
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

    private function loadQuestion(Question $question): void
    {
        $this->questionId = $question->id;
        $this->option1 = $question->option1;
        $this->option2 = $question->option2;
        $this->expiresAt = $question->expires_at->toISOString();
        $this->votesOption1 = $question->votesForOption(1);
        $this->votesOption2 = $question->votesForOption(2);
        $this->totalVotes = $question->totalVotes();

        $identifier = $this->voterIdentifier();
        $myVote = $question
            ->votes()
            ->where('voter_identifier', $identifier)
            ->first();
        $this->hasVoted = (bool) $myVote;
        $this->votedOption = $myVote?->option;
    }

    private function voterIdentifier(): string
    {
        return hash('sha256', request()->ip() . (string) session()->getId());
    }
};

?>

<div
    x-data="{
        expiresAt: @js($expiresAt),
        secondsLeft: 60,
        timerInterval: null,
        timerColor: 'text-emerald-400',

        init() {
            this.startTimer()

            $wire.on('timer-reset', ({ expiresAt }) => {
                this.expiresAt = expiresAt
                this.startTimer()
            })
        },

        startTimer() {
            clearInterval(this.timerInterval)
            this.tick()
            this.timerInterval = setInterval(() => this.tick(), 1000)
        },

        tick() {
            if (! this.expiresAt) {
                return
            }
            const diff = Math.ceil((new Date(this.expiresAt) - Date.now()) / 1000)
            this.secondsLeft = Math.max(0, diff)

            if (this.secondsLeft <= 10) {
                this.timerColor = 'text-red-400'
            } else if (this.secondsLeft <= 20) {
                this.timerColor = 'text-amber-400'
            } else {
                this.timerColor = 'text-emerald-400'
            }

            if (this.secondsLeft <= 0) {
                clearInterval(this.timerInterval)
                $wire.advance()
            }
        },
    }"
    class="flex min-h-screen flex-col items-center justify-center px-4 py-12"
>
    {{-- Header --}}
    <div class="mb-8 text-center">
        <h1 class="text-4xl font-bold tracking-tight text-white">Would You Rather?</h1>
        <p class="mt-2 text-zinc-400">Vote anonymously. New question every 60 seconds.</p>
    </div>

    {{-- Timer --}}
    <div class="mb-8 flex flex-col items-center gap-1">
        <span class="text-sm font-medium tracking-widest text-zinc-500 uppercase">Time remaining</span>
        <span class="font-mono text-6xl font-bold tabular-nums" :class="timerColor" x-text="secondsLeft"></span>
        <div class="mt-2 h-1.5 w-64 overflow-hidden rounded-full bg-zinc-800">
            <div
                class="h-full rounded-full transition-all duration-1000"
                :class="timerColor.replace('text-', 'bg-')"
                :style="`width: ${(secondsLeft / 60) * 100}%`"
            ></div>
        </div>
    </div>

    @if ($questionId)
        {{-- Vote counts (shown after voting) --}}
        @if ($hasVoted)
            <div class="mb-6 w-full max-w-2xl px-4">
                <div class="mb-1 flex items-center justify-between">
                    <span class="text-xs text-zinc-400">Option A</span>
                    <span class="text-xs text-zinc-400">Option B</span>
                </div>
                <div class="flex h-3 overflow-hidden rounded-full bg-zinc-800">
                    @php
                        $pct1 = $totalVotes > 0 ? round(($votesOption1 / $totalVotes) * 100) : 50;
                        $pct2 = $totalVotes > 0 ? round(($votesOption2 / $totalVotes) * 100) : 50;
                    @endphp

                    <div class="bg-violet-500 transition-all duration-500" style="width: {{ $pct1 }}%"></div>
                    <div class="bg-fuchsia-500 transition-all duration-500" style="width: {{ $pct2 }}%"></div>
                </div>
                <div class="mt-1 flex justify-between">
                    <span class="text-sm font-semibold text-violet-400">{{ $pct1 }}% ({{ $votesOption1 }})</span>
                    <span class="text-sm text-zinc-500">{{ $totalVotes }} votes total</span>
                    <span class="text-sm font-semibold text-fuchsia-400">{{ $pct2 }}% ({{ $votesOption2 }})</span>
                </div>
            </div>
        @endif

        {{-- Option cards --}}
        <div class="grid w-full max-w-2xl grid-cols-1 gap-4 sm:grid-cols-2">
            {{-- Option 1 --}}
            <button
                wire:click="vote(1)"
                @disabled($hasVoted)
                class="group {{
                    $hasVoted
                        ? ($votedOption === 1
                            ? 'cursor-default border-violet-500 bg-violet-500/10'
                            : 'cursor-default border-zinc-700 bg-zinc-900 opacity-50')
                        : 'cursor-pointer border-zinc-700 bg-zinc-900 hover:border-violet-500 hover:bg-violet-500/10 active:scale-95'
                }} relative flex flex-col items-center justify-center rounded-2xl border p-8 text-center transition-all duration-200 focus:outline-none"
            >
                @if ($hasVoted && $votedOption === 1)
                    <div class="absolute top-3 right-3">
                        <flux:badge color="violet" size="sm">Your vote</flux:badge>
                    </div>
                @endif

                <span class="mb-3 text-xs font-semibold tracking-widest text-violet-400 uppercase">Option A</span>
                <p class="text-lg leading-snug font-semibold text-white">{{ $option1 }}</p>
            </button>

            {{-- Divider --}}
            {{-- Option 2 --}}
            <button
                wire:click="vote(2)"
                @disabled($hasVoted)
                class="group {{
                    $hasVoted
                        ? ($votedOption === 2
                            ? 'cursor-default border-fuchsia-500 bg-fuchsia-500/10'
                            : 'cursor-default border-zinc-700 bg-zinc-900 opacity-50')
                        : 'cursor-pointer border-zinc-700 bg-zinc-900 hover:border-fuchsia-500 hover:bg-fuchsia-500/10 active:scale-95'
                }} relative flex flex-col items-center justify-center rounded-2xl border p-8 text-center transition-all duration-200 focus:outline-none"
            >
                @if ($hasVoted && $votedOption === 2)
                    <div class="absolute top-3 right-3">
                        <flux:badge color="fuchsia" size="sm">Your vote</flux:badge>
                    </div>
                @endif

                <span class="mb-3 text-xs font-semibold tracking-widest text-fuchsia-400 uppercase">Option B</span>
                <p class="text-lg leading-snug font-semibold text-white">{{ $option2 }}</p>
            </button>
        </div>

        @if (! $hasVoted)
            <p class="mt-6 text-sm text-zinc-500">Click a card to cast your anonymous vote</p>
        @else
            <p class="mt-6 text-sm text-zinc-500">Waiting for the next question...</p>
        @endif
    @else
        <div class="flex items-center gap-3 text-zinc-400">
            <flux:icon.arrow-path class="size-5 animate-spin" />
            <span>Loading question...</span>
        </div>
    @endif
</div>
