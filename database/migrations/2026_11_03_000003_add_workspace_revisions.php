<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_revisions', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->bigInteger('revision')->default(0);
        });
        DB::table('workspace_revisions')->insert(['id' => 1, 'revision' => 0]);
        Schema::create('workspace_commits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('payload_hash', 64);
            $table->bigInteger('revision');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_commits');
        Schema::dropIfExists('workspace_revisions');
    }
};
