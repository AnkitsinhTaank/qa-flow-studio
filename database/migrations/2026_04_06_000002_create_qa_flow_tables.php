<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaires', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questionnaire_id')->constrained()->cascadeOnDelete();
            $table->string('question_text', 500);
            $table->boolean('is_start')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('answer_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('option_text', 300);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('answer_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('answer_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('next_question_id')->nullable()->constrained('questions')->nullOnDelete();
            $table->boolean('is_terminal')->default(false);
            $table->string('terminal_message', 500)->nullable();
            $table->timestamps();

            $table->unique(['question_id', 'answer_option_id']);
        });

        Schema::create('flow_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questionnaire_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('flow_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('answer_option_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        $questionnaireId = DB::table('questionnaires')->insertGetId([
            'title' => 'Career Path Flow',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $q1 = DB::table('questions')->insertGetId([
            'questionnaire_id' => $questionnaireId,
            'question_text' => 'Are you interested in technical or non-technical roles?',
            'is_start' => 1,
            'sort_order' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $q2 = DB::table('questions')->insertGetId([
            'questionnaire_id' => $questionnaireId,
            'question_text' => 'Do you prefer frontend or backend?',
            'is_start' => 0,
            'sort_order' => 2,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $q3 = DB::table('questions')->insertGetId([
            'questionnaire_id' => $questionnaireId,
            'question_text' => 'Do you enjoy design and communication?',
            'is_start' => 0,
            'sort_order' => 3,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $a1 = DB::table('answer_options')->insertGetId([
            'question_id' => $q1,
            'option_text' => 'Technical',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $a2 = DB::table('answer_options')->insertGetId([
            'question_id' => $q1,
            'option_text' => 'Non-technical',
            'sort_order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $a3 = DB::table('answer_options')->insertGetId([
            'question_id' => $q2,
            'option_text' => 'Frontend',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $a4 = DB::table('answer_options')->insertGetId([
            'question_id' => $q2,
            'option_text' => 'Backend',
            'sort_order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $a5 = DB::table('answer_options')->insertGetId([
            'question_id' => $q3,
            'option_text' => 'Yes',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $a6 = DB::table('answer_options')->insertGetId([
            'question_id' => $q3,
            'option_text' => 'No',
            'sort_order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('answer_routes')->insert([
            [
                'question_id' => $q1,
                'answer_option_id' => $a1,
                'next_question_id' => $q2,
                'is_terminal' => 0,
                'terminal_message' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'question_id' => $q1,
                'answer_option_id' => $a2,
                'next_question_id' => $q3,
                'is_terminal' => 0,
                'terminal_message' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'question_id' => $q2,
                'answer_option_id' => $a3,
                'next_question_id' => null,
                'is_terminal' => 1,
                'terminal_message' => 'Suggested role: Frontend Developer',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'question_id' => $q2,
                'answer_option_id' => $a4,
                'next_question_id' => null,
                'is_terminal' => 1,
                'terminal_message' => 'Suggested role: Backend Developer',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'question_id' => $q3,
                'answer_option_id' => $a5,
                'next_question_id' => null,
                'is_terminal' => 1,
                'terminal_message' => 'Suggested role: Product / Marketing',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'question_id' => $q3,
                'answer_option_id' => $a6,
                'next_question_id' => null,
                'is_terminal' => 1,
                'terminal_message' => 'Suggested role: Operations / Support',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_answers');
        Schema::dropIfExists('flow_sessions');
        Schema::dropIfExists('answer_routes');
        Schema::dropIfExists('answer_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('questionnaires');
    }
};
