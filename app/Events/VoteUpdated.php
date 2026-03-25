<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Question;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class VoteUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly Question $question) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('voting')];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'question_id' => $this->question->id,
            'votes_option1' => $this->question->votesForOption(1),
            'votes_option2' => $this->question->votesForOption(2),
            'total_votes' => $this->question->totalVotes(),
        ];
    }
}
