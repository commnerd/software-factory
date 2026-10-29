<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The factory's own improvement proposals (meta-programming).
        Schema::create('self_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('rationale')->nullable();
            $table->text('plan')->nullable();
            $table->string('status', 24)->default('proposed'); // proposed|implementing|testing|tested|awaiting_approval|staged|rejected|failed
            $table->json('changes')->nullable();               // [{path, content}] produced by the implementation step
            $table->string('test_status', 16)->nullable();     // passed|failed
            $table->longText('test_output')->nullable();
            $table->text('last_error')->nullable();
            $table->string('staged_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('self_proposals');
    }
};
