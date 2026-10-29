<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_suites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->foreignId('self_proposal_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('phase', 16);                      // red|green|refactor|qa|self
            $table->string('status', 16);                     // passed|failed|error
            $table->text('command');
            $table->string('image')->nullable();
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('passed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->longText('output')->nullable();
            $table->timestamps();
        });

        // Audit trail of every LLM call, including the compiled prompt, reasoning and cost.
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->foreignId('ai_skill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_skill_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('model_configuration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('step', 48);
            $table->string('status', 16)->default('running'); // running|completed|failed
            $table->longText('system_prompt')->nullable();
            $table->longText('prompt')->nullable();
            $table->longText('thoughts')->nullable();         // reasoning / thinking blocks
            $table->longText('response')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('is_test')->default(false);       // dashboard skill-playground runs
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
        });

        Schema::create('approval_gates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->foreignId('self_proposal_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('detail')->nullable();           // markdown / diff shown on the decision card
            $table->json('payload')->nullable();              // machine data needed to resume
            $table->string('status', 16)->default('pending'); // pending|approved|rejected
            $table->text('decision_note')->nullable();
            $table->string('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_gates');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('test_suites');
    }
};
