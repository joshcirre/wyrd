<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property Carbon $expires_at
 * @property bool $is_active
 */
final class Question extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    /** @return HasMany<Vote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    public function votesForOption(int $option): int
    {
        return $this->votes()->where('option', $option)->count();
    }

    public function totalVotes(): int
    {
        return $this->votes()->count();
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
