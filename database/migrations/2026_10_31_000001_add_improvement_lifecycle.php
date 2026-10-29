<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('lifecycle', 16)->default('build')->after('status');   // build | improve
            $table->unsignedInteger('cycle')->default(0)->after('lifecycle');      // 0 = initial build, 1.. = improvement cycles
            $table->unsignedSmallInteger('cycle_interval_days')->nullable()->after('cycle'); // auto-start a cycle when idle this long
            $table->timestamp('cycle_started_at')->nullable()->after('cycle_interval_days');
            $table->timestamp('last_cycle_completed_at')->nullable()->after('cycle_started_at');
        });

        // Stage records, sprints and tasks now belong to a cycle.
        Schema::table('project_stages', function (Blueprint $table) {
            $table->unsignedInteger('cycle')->default(0)->after('project_id');
        });
        Schema::table('project_stages', function (Blueprint $table) {
            $table->dropForeign(['project_id']);   // the unique index below is what backs this FK on some engines
            $table->dropUnique(['project_id', 'stage']);
            $table->unique(['project_id', 'cycle', 'stage']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::table('sprints', fn (Blueprint $t) => $t->unsignedInteger('cycle')->default(0)->after('project_id'));

        Schema::create('improvement_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('cycle');                       // cycle in which it was proposed
            $table->string('title');
            $table->string('category', 24)->default('feature');     // feature|enhancement|bugfix|tests|refactor|performance|security|docs|devex|dependency
            $table->text('summary')->nullable();
            $table->text('rationale')->nullable();                  // why now / what evidence
            $table->json('evidence')->nullable();                   // files, signals
            $table->json('acceptance_criteria')->nullable();
            $table->json('files')->nullable();
            $table->unsignedTinyInteger('impact')->default(3);      // 1-5
            $table->unsignedTinyInteger('effort')->default(3);      // 1-5
            $table->decimal('score', 5, 2)->default(0);             // impact weighted against effort
            $table->string('source', 16)->default('survey');        // survey | assessment | user
            // proposed -> approved -> in_progress -> done | failed ; deferred / rejected leave the cycle
            $table->string('status', 16)->default('proposed');
            $table->unsignedInteger('built_in_cycle')->nullable();
            $table->text('decision_note')->nullable();
            $table->text('outcome')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            $table->unsignedInteger('cycle')->default(0)->after('project_id');
            $table->foreignId('improvement_proposal_id')->nullable()->after('sprint_id')->constrained('improvement_proposals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('improvement_proposal_id');
            $table->dropColumn('cycle');
        });
        Schema::dropIfExists('improvement_proposals');
        Schema::table('sprints', fn (Blueprint $t) => $t->dropColumn('cycle'));
        Schema::table('project_stages', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropUnique(['project_id', 'cycle', 'stage']);
            $table->unique(['project_id', 'stage']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->dropColumn('cycle');
        });
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn(['lifecycle', 'cycle', 'cycle_interval_days', 'cycle_started_at', 'last_cycle_completed_at']));
    }
};
