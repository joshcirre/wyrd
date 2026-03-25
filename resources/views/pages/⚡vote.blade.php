<?php

declare(strict_types=1);

use App\Events\QuestionAdvanced;
use App\Events\VoteUpdated;
use App\Models\Question;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
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
    public int $viewerCount = 1;

    public function mount(): void
    {
        $this->trackViewer();

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

    public function refreshViewerCount(): void
    {
        $this->trackViewer();
    }

    /** @return Collection<int, Question> */
    #[Computed]
    public function history(): Collection
    {
        return Question::query()
            ->where('is_active', false)
            ->withCount(['votes as votes1' => fn ($q) => $q->where('option', 1)])
            ->withCount(['votes as votes2' => fn ($q) => $q->where('option', 2)])
            ->latest()
            ->limit(20)
            ->get();
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

        if (! $this->hasVoted) {
            $question = Question::find($this->questionId);
            if ($question) {
                $myVote = $question
                    ->votes()
                    ->where('voter_identifier', $this->voterIdentifier())
                    ->first();
                $this->hasVoted = (bool) $myVote;
                $this->votedOption = $myVote?->option;
            }
        }
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

    private function trackViewer(): void
    {
        $ipHash = hash('sha256', (string) request()->ip());
        $viewers = Cache::get('active_viewers', []);
        $viewers[$ipHash] = now()->timestamp;
        $cutoff = now()->subSeconds(90)->timestamp;
        $viewers = array_filter($viewers, fn (int $ts) => $ts > $cutoff);
        Cache::put('active_viewers', $viewers, now()->addMinutes(5));
        $this->viewerCount = count($viewers);
    }

    private function voterIdentifier(): string
    {
        return hash('sha256', (string) request()->ip());
    }
};

?>

<div
    x-data="{
        timerInterval: null,
        secondsLeft: 60,
        timerColor: 'text-emerald-500',

        getExpiresAt() {
            return $wire.expiresAt
        },

        init() {
            this.tick()
            this.timerInterval = setInterval(() => this.tick(), 1000)

            $wire.on('timer-reset', () => {
                clearInterval(this.timerInterval)
                this.timerInterval = setInterval(() => this.tick(), 1000)
                this.tick()
            })
        },

        tick() {
            const expiresAt = this.getExpiresAt()
            if (! expiresAt) {
                return
            }
            const diff = Math.ceil((new Date(expiresAt) - Date.now()) / 1000)
            this.secondsLeft = Math.max(0, diff)

            if (this.secondsLeft <= 10) {
                this.timerColor = 'text-red-500'
            } else if (this.secondsLeft <= 20) {
                this.timerColor = 'text-amber-500'
            } else {
                this.timerColor = 'text-emerald-500'
            }

            if (this.secondsLeft <= 0) {
                clearInterval(this.timerInterval)
                $wire.advance()
            }
        },
    }"
    class="flex h-screen flex-col bg-white"
>
    {{-- Header --}}
    <header class="flex shrink-0 items-center justify-between border-b border-zinc-200 px-6 py-3">
        {{-- Logo --}}
        <span class="font-mono text-sm font-bold tracking-widest text-zinc-900 uppercase">Wyrd</span>

        {{-- Live viewer count --}}
        <div wire:poll.30s="refreshViewerCount" class="flex items-center gap-2">
            <span class="size-2 animate-pulse rounded-full bg-green-500"></span>
            <span class="font-mono text-xs font-semibold tracking-widest text-zinc-500 uppercase">
                {{ $viewerCount }} {{ Str::plural('viewer', $viewerCount) }} watching
            </span>
        </div>

        {{-- Timer --}}
        <div class="flex items-center gap-2">
            <span class="font-mono text-xs tracking-widest text-zinc-400 uppercase">Next in</span>
            <span class="w-8 font-mono text-sm font-bold tabular-nums" :class="timerColor" x-text="secondsLeft"></span>
        </div>
    </header>

    {{-- Progress bar --}}
    <div class="h-0.5 w-full shrink-0 bg-zinc-100">
        <div
            class="h-full transition-all duration-1000"
            :class="timerColor.replace('text-', 'bg-')"
            :style="`width: ${(secondsLeft / 60) * 100}%`"
        ></div>
    </div>

    {{-- Body --}}
    <div class="flex min-h-0 flex-1">
        {{-- Main content --}}
        <main class="flex flex-1 flex-col overflow-y-auto px-8 py-10 lg:px-16">
            @if ($questionId)
                {{-- Label --}}
                <p class="mb-5 font-mono text-xs font-semibold tracking-widest text-zinc-400 uppercase">Would you rather</p>

                {{-- Full question with colored options --}}
                <div class="mb-8 border-l-4 border-violet-300 pl-6">
                    <p class="text-3xl leading-tight font-bold text-zinc-900">
                        <span class="text-violet-600">{{ ucfirst($option1) }}</span>
                        <span class="text-zinc-400">or</span>
                        <span class="text-fuchsia-600">{{ $option2 }}</span>
                        ?
                    </p>
                </div>

                {{-- Vote bar --}}
                @if ($totalVotes > 0)
                    @php
                        $pct1 = round(($votesOption1 / $totalVotes) * 100);
                        $pct2 = 100 - $pct1;
                    @endphp

                    <div class="mb-8">
                        <div class="flex h-2 overflow-hidden rounded-full bg-zinc-100">
                            <div class="bg-violet-400 transition-all duration-500" style="width: {{ $pct1 }}%"></div>
                            <div class="bg-fuchsia-400 transition-all duration-500" style="width: {{ $pct2 }}%"></div>
                        </div>
                        <div class="mt-2 flex justify-between text-xs">
                            <span class="font-semibold text-violet-600">{{ $pct1 }}% &middot; {{ $votesOption1 }} votes</span>
                            <span class="text-zinc-400">{{ $totalVotes }} total</span>
                            <span class="font-semibold text-fuchsia-600">{{ $pct2 }}% &middot; {{ $votesOption2 }} votes</span>
                        </div>
                    </div>
                @endif

                {{-- Option cards --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {{-- Option A --}}
                    <button
                        wire:click="vote(1)"
                        @disabled($hasVoted)
                        class="{{
                            $hasVoted
                                ? ($votedOption === 1
                                    ? 'border-violet-300 bg-violet-50'
                                    : 'border-zinc-100 bg-zinc-50 opacity-50')
                                : 'cursor-pointer border-zinc-200 bg-white hover:border-violet-300 hover:bg-violet-50 active:scale-[0.98]'
                        }} relative rounded-xl border p-6 text-left transition-all duration-200 focus:outline-none"
                    >
                        <div class="mb-3 flex items-center justify-between">
                            <span class="font-mono text-xs font-semibold tracking-widest text-violet-500 uppercase">Option A</span>
                            @if ($hasVoted && $votedOption === 1)
                                <flux:badge color="violet" size="sm">Your vote</flux:badge>
                            @endif
                        </div>
                        <p class="text-base leading-snug font-semibold text-zinc-900">{{ ucfirst($option1) }}</p>
                    </button>

                    {{-- Option B --}}
                    <button
                        wire:click="vote(2)"
                        @disabled($hasVoted)
                        class="{{
                            $hasVoted
                                ? ($votedOption === 2
                                    ? 'border-fuchsia-300 bg-fuchsia-50'
                                    : 'border-zinc-100 bg-zinc-50 opacity-50')
                                : 'cursor-pointer border-zinc-200 bg-white hover:border-fuchsia-300 hover:bg-fuchsia-50 active:scale-[0.98]'
                        }} relative rounded-xl border p-6 text-left transition-all duration-200 focus:outline-none"
                    >
                        <div class="mb-3 flex items-center justify-between">
                            <span class="font-mono text-xs font-semibold tracking-widest text-fuchsia-500 uppercase">Option B</span>
                            @if ($hasVoted && $votedOption === 2)
                                <flux:badge color="fuchsia" size="sm">Your vote</flux:badge>
                            @endif
                        </div>
                        <p class="text-base leading-snug font-semibold text-zinc-900">{{ $option2 }}</p>
                    </button>
                </div>

                <p class="mt-6 text-sm text-zinc-400">
                    @if (! $hasVoted)
                        Click a card to cast your anonymous vote
                    @else
                        Waiting for the next question&hellip;
                    @endif
                </p>
            @else
                <div class="flex items-center gap-3 text-zinc-400">
                    <flux:icon.arrow-path class="size-5 animate-spin" />
                    <span class="text-sm">Loading question&hellip;</span>
                </div>
            @endif
        </main>

        {{-- Right sidebar: past questions --}}
        <aside class="hidden w-72 shrink-0 flex-col overflow-y-auto border-l border-zinc-200 bg-zinc-50 lg:flex">
            <div class="sticky top-0 border-b border-zinc-200 bg-zinc-50 px-5 py-4">
                <h2 class="font-mono text-xs font-semibold tracking-widest text-zinc-500 uppercase">Past Questions</h2>
            </div>

            <div class="divide-y divide-zinc-100">
                @forelse ($this->history as $pastQuestion)
                    @php
                        $total = $pastQuestion->votes1 + $pastQuestion->votes2;
                        $winnerIsA = $pastQuestion->votes1 >= $pastQuestion->votes2;
                        $winner = $winnerIsA ? $pastQuestion->option1 : $pastQuestion->option2;
                        $winnerPct = $total > 0 ? round((max($pastQuestion->votes1, $pastQuestion->votes2) / $total) * 100) : null;
                    @endphp

                    <div class="px-5 py-4">
                        <p class="mb-2 line-clamp-2 text-xs leading-snug text-zinc-600">
                            {{ ucfirst($pastQuestion->option1) }} or {{ $pastQuestion->option2 }}?
                        </p>

                        @if ($total > 0)
                            <div class="flex items-baseline gap-1.5">
                                <span class="{{ $winnerIsA ? 'text-violet-600' : 'text-fuchsia-600' }} text-xs font-bold">{{ $winnerPct }}%</span>
                                <span class="line-clamp-1 text-xs text-zinc-500">{{ $winner }}</span>
                            </div>
                        @else
                            <span class="text-xs text-zinc-400">No votes</span>
                        @endif
                    </div>
                @empty
                    <div class="px-5 py-10 text-center">
                        <p class="text-xs text-zinc-400">No past questions yet</p>
                    </div>
                @endforelse
            </div>
        </aside>
    </div>
</div>
