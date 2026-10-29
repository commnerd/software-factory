<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('brief')->nullable();               // the original requirement prompt
            $table->string('language', 32);                  // key into config('factory.languages')
            $table->string('framework')->nullable();
            $table->string('repository_url')->nullable();
            $table->string('status', 32)->default('draft');  // draft|running|awaiting_approval|paused|completed|failed
            $table->string('current_stage', 32)->default('requirements');
            $table->json('config')->nullable();              // overrides: image, install, test, test_dir...
            $table->decimal('budget_limit_usd', 10, 2)->nullable();
            $table->decimal('spent_usd', 10, 4)->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // One row per project per SDLC stage: status + the stage's produced document.
        Schema::create('project_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 32);
            $table->string('status', 32)->default('pending'); // pending|running|awaiting_approval|completed|failed
            $table->longText('output')->nullable();
            $table->text('feedback')->nullable();             // reviewer feedback fed back into re-runs
            $table->unsignedSmallInteger('runs')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_stages');
        Schema::dropIfExists('projects');
    }
};
