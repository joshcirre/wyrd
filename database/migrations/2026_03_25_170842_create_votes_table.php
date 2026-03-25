<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('voter_identifier');
            $table->unsignedTinyInteger('option');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['question_id', 'voter_identifier']);
        });
    }
};
