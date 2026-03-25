<?php

declare(strict_types=1);

use App\Events\VoteUpdated;
use App\Models\Question;
use App\Models\Vote;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

it('renders the vote page', function () {
    $question = Question::factory()->create();

    Livewire::test('pages::vote')
        ->assertOk()
        ->assertSee($question->option1)
        ->assertSee($question->option2);
});

it('fetches a new question when none is active', function () {
    Http::fake([
        'api.truthordarebot.xyz/*' => Http::response([
            'id' => 'test-123',
            'answers' => ['Eat pizza every day', 'Eat tacos every day'],
        ]),
    ]);

    Livewire::test('pages::vote')
        ->assertOk()
        ->assertSee('Eat pizza every day');
});

it('allows a user to vote once per question', function () {
    Event::fake([VoteUpdated::class]);

    Question::factory()->create();

    $component = Livewire::test('pages::vote');
    $component->call('vote', 1);
    $component->assertSet('hasVoted', true);
    $component->assertSet('votedOption', 1);

    expect(Vote::count())->toBe(1);
    Event::assertDispatched(VoteUpdated::class);
});

it('prevents voting twice on the same question', function () {
    Event::fake([VoteUpdated::class]);

    Question::factory()->create();

    $component = Livewire::test('pages::vote');
    $component->call('vote', 1);
    $component->call('vote', 2);

    expect(Vote::count())->toBe(1);
    Event::assertDispatchedTimes(VoteUpdated::class, 1);
});

it('prevents voting on an expired question', function () {
    Event::fake([VoteUpdated::class]);

    Http::fake([
        'api.truthordarebot.xyz/*' => Http::response([
            'id' => 'new-123',
            'answers' => ['Option A', 'Option B'],
        ]),
    ]);

    $expired = Question::factory()->expired()->create();

    $component = Livewire::test('pages::vote');
    $component->set('questionId', $expired->id);
    $component->call('vote', 1);

    expect(Vote::count())->toBe(0);
    Event::assertNotDispatched(VoteUpdated::class);
});

it('shows vote percentages after voting', function () {
    Event::fake([VoteUpdated::class]);

    Question::factory()->create();

    $component = Livewire::test('pages::vote');
    $component->call('vote', 1);
    $component->assertSet('votesOption1', 1);
    $component->assertSet('votesOption2', 0);
    $component->assertSet('totalVotes', 1);
});

it('updates vote counts when VoteUpdated event is received', function () {
    $question = Question::factory()->create();

    $component = Livewire::test('pages::vote');
    $component->dispatch('echo:voting,VoteUpdated', [
        'question_id' => $question->id,
        'votes_option1' => 5,
        'votes_option2' => 3,
        'total_votes' => 8,
    ]);
    $component->assertSet('votesOption1', 5);
    $component->assertSet('votesOption2', 3);
    $component->assertSet('totalVotes', 8);
});
