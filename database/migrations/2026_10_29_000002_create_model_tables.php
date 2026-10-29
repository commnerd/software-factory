<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider', 32);                  // anthropic|openai|ollama|fake
            $table->string('model');                         // provider model identifier
            $table->string('tier', 16)->default('standard'); // cheap|standard|frontier
            $table->json('parameters')->nullable();          // temperature, max_tokens, thinking_budget...
            $table->decimal('input_cost_per_mtok', 10, 4)->default(0);
            $table->decimal('output_cost_per_mtok', 10, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // step -> model mapping. project_id NULL = global default, otherwise a per-project override.
        Schema::create('model_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('step', 48);
            $table->foreignId('model_configuration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fallback_model_configuration_id')->nullable()->constrained('model_configurations')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_routes');
        Schema::dropIfExists('model_configurations');
    }
};
