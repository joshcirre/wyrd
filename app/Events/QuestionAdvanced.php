<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Question;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class QuestionAdvanced implements ShouldBroadcastNow
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
            'option1' => $this->question->option1,
            'option2' => $this->question->option2,
            'expires_at' => $this->question->expires_at->toISOString(),
        ];
    }
}
