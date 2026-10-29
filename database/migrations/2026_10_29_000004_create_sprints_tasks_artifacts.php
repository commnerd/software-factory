<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('goal')->nullable();
            $table->string('status', 24)->default('pending'); // pending|active|done
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sprint_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('acceptance_criteria')->nullable();
            $table->json('files')->nullable();                // suggested file paths
            $table->string('status', 24)->default('pending'); // pending|in_progress|done|blocked
            $table->string('tdd_phase', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Every file the factory writes into a project workspace (source, tests, workflows).
        Schema::create('project_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->string('path', 500);
            $table->string('kind', 16)->default('source');    // source|test|workflow|doc
            $table->longText('content');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['project_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_artifacts');
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('sprints');
    }
};
