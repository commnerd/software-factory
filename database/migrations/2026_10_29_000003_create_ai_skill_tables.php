<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_skills', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('kind', 16)->default('agent');    // agent (bound to a step) | snippet (include via {{> slug}})
            $table->string('step', 48)->nullable()->index(); // FactoryStep value for agent skills
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
        });

        // Immutable history: every edit publishes a new row; "current" is just a pointer.
        Schema::create('ai_skill_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->longText('system_prompt')->nullable();
            $table->longText('user_template');
            $table->json('variables')->nullable();           // declared variables [{name, description, required}]
            $table->string('output_format', 16)->default('text'); // text|json
            $table->string('change_note')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
            $table->unique(['ai_skill_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_skill_versions');
        Schema::dropIfExists('ai_skills');
    }
};
