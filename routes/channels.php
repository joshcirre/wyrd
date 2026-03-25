<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (App\Models\User $user, int $id): bool {
    return $user->getKey() === $id;
});
