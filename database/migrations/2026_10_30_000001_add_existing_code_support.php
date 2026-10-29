<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('repository_branch')->nullable()->after('repository_url');
            $table->boolean('existing_code')->default(false)->after('repository_branch'); // repo had code when imported
            $table->json('assessment')->nullable()->after('existing_code');               // where the project stands (see AssessmentPlanner)
            $table->string('current_stage', 32)->default('assessment')->change();
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            // feature = new behaviour (strict red→green); characterization = tests documenting existing behaviour
            $table->string('type', 24)->default('feature')->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', fn (Blueprint $t) => $t->dropColumn('type'));
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['repository_branch', 'existing_code', 'assessment']);
            $table->string('current_stage', 32)->default('requirements')->change();
        });
    }
};
