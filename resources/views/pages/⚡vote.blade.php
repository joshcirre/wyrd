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
    class="flex h-screen flex-col overflow-hidden bg-stone-100 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100"
    wire:poll.30s="refreshViewerCount"
    x-data="{
        timerInterval: null,
        secondsLeft: 60,
        sidebarOpen: true,
        darkMode: false,

        getExpiresAt() {
            return $wire.expiresAt
        },

        init() {
            this.darkMode = document.documentElement.classList.contains('dark')

            this.tick()
            this.timerInterval = setInterval(() => this.tick(), 1000)

            $wire.on('timer-reset', () => {
                clearInterval(this.timerInterval)
                this.timerInterval = setInterval(() => this.tick(), 1000)
                this.tick()
            })
        },

        toggleDark() {
            this.darkMode = ! this.darkMode
            document.documentElement.classList.toggle('dark', this.darkMode)
            localStorage.setItem('flux-appearance', this.darkMode ? 'dark' : 'light')
        },

        tick() {
            const expiresAt = this.getExpiresAt()
            if (! expiresAt) { return }
            const diff = Math.floor((new Date(expiresAt) - Date.now()) / 1000)
            this.secondsLeft = Math.max(0, diff)

            if (this.secondsLeft <= 0) {
                clearInterval(this.timerInterval)
                $wire.advance()
            }
        },
    }"
>
    {{-- Top bar --}}
    <header class="flex shrink-0 items-center justify-between border-b border-zinc-200 bg-white px-6 py-3 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex items-center gap-4">
            <span class="font-mono text-xs font-bold tracking-[0.2em] text-zinc-700 dark:text-zinc-300">WYRD</span>
            @if ($questionId)
                <span class="text-zinc-300 dark:text-zinc-600">·</span>
                <span class="font-mono text-xs tracking-widest text-zinc-400 dark:text-zinc-500">
                    ROUND <span class="text-zinc-700 dark:text-zinc-300">{{ $questionId }}</span>
                </span>
            @endif
        </div>

        @if ($questionId)
            <div class="font-mono text-xs tracking-widest text-zinc-400 dark:text-zinc-500">
                VOTING <span class="tabular-nums text-zinc-900 dark:text-zinc-100" x-text="secondsLeft"></span>S
            </div>
        @endif

        <div class="flex items-center gap-3">
            {{-- Dark mode toggle --}}
            <button
                @click="toggleDark()"
                class="rounded p-1 text-zinc-400 transition-colors hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300"
                :title="darkMode ? 'Switch to light mode' : 'Switch to dark mode'"
            >
                <flux:icon name="sun" class="size-4" x-show="darkMode" />
                <flux:icon name="moon" class="size-4" x-show="! darkMode" />
            </button>

            <div class="flex items-center gap-2">
                <span class="size-1.5 animate-pulse rounded-full bg-emerald-500"></span>
                <span class="font-mono text-xs tracking-widest text-zinc-400 dark:text-zinc-500">
                    <span class="text-zinc-700 dark:text-zinc-300">{{ $viewerCount }}</span> WATCHING
                </span>
            </div>
        </div>
    </header>

    {{-- Timer progress bar --}}
    <div class="h-0.5 w-full shrink-0 bg-zinc-200 dark:bg-zinc-800">
        <div
            class="h-full transition-all duration-1000"
            :class="secondsLeft > 20 ? 'bg-emerald-500' : (secondsLeft > 10 ? 'bg-amber-500' : 'bg-red-500')"
            :style="`width: ${(secondsLeft / 60) * 100}%`"
        ></div>
    </div>

    {{-- Body --}}
    <div class="flex flex-1 overflow-hidden">

        {{-- Main --}}
        <main class="flex flex-1 flex-col items-center justify-center overflow-y-auto p-8 lg:p-14">
            @if ($questionId)
                <div class="-mt-20 w-full max-w-3xl">
                    {{-- Question --}}
                    <div class="mb-10 border-l-4 border-amber-400 pl-6">
                        <p class="mb-3 font-mono text-[10px] tracking-[0.25em] text-zinc-400 dark:text-zinc-500">WOULD YOU RATHER</p>
                        <h1 class="font-display text-4xl font-bold leading-snug text-zinc-900 dark:text-zinc-100 lg:text-5xl">
                            {{ ucfirst($option1) }} or {{ $option2 }}?
                        </h1>
                    </div>

                    {{-- Options --}}
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                        {{-- Option A --}}
                        <button
                            wire:click="vote(1)"
                            @class([
                                'group relative border-l-4 p-6 text-left transition-all duration-200',
                                'cursor-pointer border-blue-500 bg-white shadow-sm hover:bg-zinc-50 dark:bg-zinc-900 dark:hover:bg-zinc-800' => ! $hasVoted,
                                'cursor-default border-blue-500 bg-white shadow-sm dark:bg-zinc-900' => $hasVoted && $votedOption === 1,
                                'cursor-default border-zinc-200 bg-zinc-50 opacity-50 dark:border-zinc-700 dark:bg-zinc-800/50' => $hasVoted && $votedOption !== 1,
                            ])
                        >
                            <p class="mb-3 font-mono text-[10px] tracking-[0.2em] text-blue-500">A</p>
                            <p class="mb-6 text-xl font-semibold leading-snug text-zinc-900 dark:text-zinc-100">
                                &ldquo;{{ ucfirst($option1) }}&rdquo;
                            </p>
                            <div>
                                <div class="mb-1.5 flex justify-between font-mono text-[10px]">
                                    <span class="text-blue-500">{{ $votesOption1 }} VOTES</span>
                                    <span class="text-zinc-400 dark:text-zinc-500">{{ $totalVotes > 0 ? round($votesOption1 / $totalVotes * 100) : 0 }}%</span>
                                </div>
                                <div class="h-px w-full bg-zinc-200 dark:bg-zinc-700">
                                    <div
                                        class="h-px bg-blue-500 transition-all duration-700"
                                        style="width: {{ $totalVotes > 0 ? round($votesOption1 / $totalVotes * 100) : 0 }}%"
                                    ></div>
                                </div>
                            </div>
                            @if ($hasVoted && $votedOption === 1)
                                <flux:icon name="check-circle" class="absolute right-4 top-4 size-4 text-blue-500" />
                            @endif
                        </button>

                        {{-- Option B --}}
                        <button
                            wire:click="vote(2)"
                            @class([
                                'group relative border-l-4 p-6 text-left transition-all duration-200',
                                'cursor-pointer border-emerald-500 bg-white shadow-sm hover:bg-zinc-50 dark:bg-zinc-900 dark:hover:bg-zinc-800' => ! $hasVoted,
                                'cursor-default border-emerald-500 bg-white shadow-sm dark:bg-zinc-900' => $hasVoted && $votedOption === 2,
                                'cursor-default border-zinc-200 bg-zinc-50 opacity-50 dark:border-zinc-700 dark:bg-zinc-800/50' => $hasVoted && $votedOption !== 2,
                            ])
                        >
                            <p class="mb-3 font-mono text-[10px] tracking-[0.2em] text-emerald-600">B</p>
                            <p class="mb-6 text-xl font-semibold leading-snug text-zinc-900 dark:text-zinc-100">
                                &ldquo;{{ $option2 }}&rdquo;
                            </p>
                            <div>
                                <div class="mb-1.5 flex justify-between font-mono text-[10px]">
                                    <span class="text-emerald-600">{{ $votesOption2 }} VOTES</span>
                                    <span class="text-zinc-400 dark:text-zinc-500">{{ $totalVotes > 0 ? round($votesOption2 / $totalVotes * 100) : 0 }}%</span>
                                </div>
                                <div class="h-px w-full bg-zinc-200 dark:bg-zinc-700">
                                    <div
                                        class="h-px bg-emerald-500 transition-all duration-700"
                                        style="width: {{ $totalVotes > 0 ? round($votesOption2 / $totalVotes * 100) : 0 }}%"
                                    ></div>
                                </div>
                            </div>
                            @if ($hasVoted && $votedOption === 2)
                                <flux:icon name="check-circle" class="absolute right-4 top-4 size-4 text-emerald-600" />
                            @endif
                        </button>

                    </div>

                    @if ($totalVotes > 0)
                        <p class="mt-6 font-mono text-[10px] tracking-widest text-zinc-400 dark:text-zinc-500">
                            {{ $totalVotes }} {{ Str::plural('VOTE', $totalVotes) }} CAST
                        </p>
                    @endif
                </div>
            @else
                <div class="-mt-20 w-full max-w-3xl border-l-4 border-zinc-200 pl-6 dark:border-zinc-700">
                    <p class="mb-3 font-mono text-[10px] tracking-[0.25em] text-zinc-400 dark:text-zinc-500">WOULD YOU RATHER</p>
                    <h1 class="font-display text-4xl font-bold text-zinc-300 dark:text-zinc-600 lg:text-5xl">Waiting for next round...</h1>
                </div>
            @endif
        </main>

        {{-- Right sidebar --}}
        <aside
            class="hidden shrink-0 overflow-hidden border-l border-zinc-200 bg-white transition-all duration-300 dark:border-zinc-800 dark:bg-zinc-900 lg:flex lg:flex-col"
            :class="sidebarOpen ? 'w-80' : 'w-10'"
        >
            {{-- Toggle --}}
            <button
                @click="sidebarOpen = !sidebarOpen"
                class="flex w-full shrink-0 items-center border-b border-zinc-200 px-3 py-3 text-zinc-400 transition-colors hover:text-zinc-600 dark:border-zinc-800 dark:hover:text-zinc-300"
                :class="sidebarOpen ? 'justify-between' : 'justify-center'"
            >
                <span x-show="sidebarOpen" class="font-mono text-[10px] tracking-[0.25em] text-zinc-400 dark:text-zinc-500">PAST ROUNDS</span>
                <flux:icon
                    name="chevron-right"
                    class="size-3.5 transition-transform duration-300"
                    ::class="sidebarOpen ? 'rotate-180' : 'rotate-0'"
                />
            </button>

            {{-- Content --}}
            <div x-show="sidebarOpen" class="flex-1 overflow-y-auto p-5">
                @forelse ($this->history as $past)
                    @php
                        $pastTotal = $past->votes1 + $past->votes2;
                        $aWins = $past->votes1 > $past->votes2;
                        $bWins = $past->votes2 > $past->votes1;
                        $winner = $aWins ? $past->option1 : ($bWins ? $past->option2 : null);
                        $winPct = $pastTotal > 0 ? round(max($past->votes1, $past->votes2) / $pastTotal * 100) : 0;
                    @endphp
                    <div wire:key="past-{{ $past->id }}" class="mb-5 border-l-2 border-zinc-200 pl-3 transition-colors hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500">
                        <p class="mb-1 font-mono text-[10px] text-zinc-400 dark:text-zinc-500">ROUND {{ $past->id }}</p>
                        <p class="mb-1.5 line-clamp-2 text-xs leading-snug text-zinc-500 dark:text-zinc-400">{{ ucfirst($past->option1) }} or {{ $past->option2 }}?</p>
                        @if ($winner)
                            <p class="truncate font-mono text-[10px] text-emerald-600">
                                &uarr; {{ Str::limit($winner, 36) }} &middot; {{ $winPct }}%
                            </p>
                        @elseif ($pastTotal > 0)
                            <p class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500">— TIE &middot; {{ $pastTotal }} votes</p>
                        @else
                            <p class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500">— NO VOTES</p>
                        @endif
                    </div>
                @empty
                    <p class="font-mono text-[10px] text-zinc-300 dark:text-zinc-600">NO PAST ROUNDS YET</p>
                @endforelse
            </div>
        </aside>

    </div>
</div>
