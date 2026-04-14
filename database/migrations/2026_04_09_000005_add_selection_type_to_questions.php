<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('selection_type', 10)->default('single')->after('question_text');
        });

        DB::table('questions')
            ->whereNull('selection_type')
            ->orWhere('selection_type', '')
            ->update(['selection_type' => 'single']);
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('selection_type');
        });
    }
};

