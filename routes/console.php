<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('qa:create-admin {email : Admin email} {--name= : Admin name} {--password= : Admin password}', function () {
    $email = strtolower(trim((string) $this->argument('email')));
    $name = trim((string) ($this->option('name') ?: 'Admin'));
    $password = (string) ($this->option('password') ?: Str::random(16));

    $existing = DB::table('users')->where('email', $email)->first();

    if ($existing) {
        $update = [
            'role' => 'admin',
            'updated_at' => now(),
        ];
        if ($this->option('name')) {
            $update['name'] = $name;
        }
        if ($this->option('password')) {
            $update['password'] = Hash::make($password);
        }

        DB::table('users')->where('id', $existing->id)->update($update);

        $this->info("Updated user #{$existing->id} to role=admin for {$email}.");
        if ($this->option('password')) {
            $this->warn("Password set to: {$password}");
        } else {
            $this->warn('Password unchanged (pass --password to set).');
        }
        return;
    }

    $id = DB::table('users')->insertGetId([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($password),
        'role' => 'admin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->info("Created admin user #{$id} for {$email}.");
    $this->warn("Password: {$password}");
})->purpose('Create (or promote) an admin user for QA Flow Studio');

Artisan::command('qa:reset-flow {--force : Do not ask for confirmation}', function () {
    if (!Schema::hasTable('questions')) {
        $this->error('Flow tables not found. Run migrations first: php artisan migrate');
        return 1;
    }
    if (!Schema::hasColumn('questions', 'selection_type')) {
        $this->error('Missing column questions.selection_type. Run migrations first: php artisan migrate');
        return 1;
    }

    if (!$this->option('force')) {
        $ok = $this->confirm('This will delete ALL questionnaires/questions/options/routes/sessions/answers. Continue?');
        if (!$ok) {
            $this->info('Canceled.');
            return 0;
        }
    }

    DB::transaction(function () {
        DB::table('flow_answers')->delete();
        DB::table('flow_sessions')->delete();
        DB::table('answer_routes')->delete();
        DB::table('answer_options')->delete();
        DB::table('questions')->delete();
        DB::table('questionnaires')->delete();

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("DELETE FROM sqlite_sequence WHERE name IN ('flow_answers','flow_sessions','answer_routes','answer_options','questions','questionnaires')");
        }

        $questionnaireId = DB::table('questionnaires')->insertGetId([
            'title' => 'Support Intake Flow',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $hasCanvas = Schema::hasColumn('questions', 'pos_x') && Schema::hasColumn('questions', 'pos_y');

        $q1 = DB::table('questions')->insertGetId(array_merge([
            'questionnaire_id' => $questionnaireId,
            'question_text' => 'What do you need help with?',
            'selection_type' => 'single',
            'is_start' => 1,
            'sort_order' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $hasCanvas ? ['pos_x' => 80, 'pos_y' => 80] : []));

        $q2 = DB::table('questions')->insertGetId(array_merge([
            'questionnaire_id' => $questionnaireId,
            'question_text' => 'Which billing issues apply? (Select all that apply)',
            'selection_type' => 'multi',
            'is_start' => 0,
            'sort_order' => 2,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $hasCanvas ? ['pos_x' => 360, 'pos_y' => 40] : []));

        $q3 = DB::table('questions')->insertGetId(array_merge([
            'questionnaire_id' => $questionnaireId,
            'question_text' => 'Which product are you using?',
            'selection_type' => 'single',
            'is_start' => 0,
            'sort_order' => 3,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $hasCanvas ? ['pos_x' => 360, 'pos_y' => 160] : []));

        $q4 = DB::table('questions')->insertGetId(array_merge([
            'questionnaire_id' => $questionnaireId,
            'question_text' => 'Select the symptoms you are facing (Select all that apply)',
            'selection_type' => 'multi',
            'is_start' => 0,
            'sort_order' => 4,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $hasCanvas ? ['pos_x' => 640, 'pos_y' => 160] : []));

        $a1 = DB::table('answer_options')->insertGetId(['question_id' => $q1, 'option_text' => 'Billing', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $a2 = DB::table('answer_options')->insertGetId(['question_id' => $q1, 'option_text' => 'Technical', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $a3 = DB::table('answer_options')->insertGetId(['question_id' => $q1, 'option_text' => 'Sales', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()]);

        $b1 = DB::table('answer_options')->insertGetId(['question_id' => $q2, 'option_text' => 'Refund', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $b2 = DB::table('answer_options')->insertGetId(['question_id' => $q2, 'option_text' => 'Invoice / Receipt', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $b3 = DB::table('answer_options')->insertGetId(['question_id' => $q2, 'option_text' => 'Payment failed', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()]);

        $t1 = DB::table('answer_options')->insertGetId(['question_id' => $q3, 'option_text' => 'Web App', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $t2 = DB::table('answer_options')->insertGetId(['question_id' => $q3, 'option_text' => 'Mobile App', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()]);

        $s1 = DB::table('answer_options')->insertGetId(['question_id' => $q4, 'option_text' => 'Login problem', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $s2 = DB::table('answer_options')->insertGetId(['question_id' => $q4, 'option_text' => 'App crash', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $s3 = DB::table('answer_options')->insertGetId(['question_id' => $q4, 'option_text' => 'Slow performance', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()]);

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
                'question_id' => $q1,
                'answer_option_id' => $a3,
                'next_question_id' => null,
                'is_terminal' => 1,
                'terminal_message' => 'Thanks! Our sales team will contact you soon.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $billingTerminal = 'Thanks! Our billing team will contact you soon.';
        DB::table('answer_routes')->insert([
            [
                'question_id' => $q2,
                'answer_option_id' => $b1,
                'next_question_id' => null,
                'is_terminal' => 1,
                'terminal_message' => $billingTerminal,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'question_id' => $q2,
                'answer_option_id' => $b2,
                'next_question_id' => null,
                'is_terminal' => 1,
                'terminal_message' => $billingTerminal,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'question_id' => $q2,
                'answer_option_id' => $b3,
                'next_question_id' => null,
                'is_terminal' => 1,
                'terminal_message' => $billingTerminal,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('answer_routes')->insert([
            [
                'question_id' => $q3,
                'answer_option_id' => $t1,
                'next_question_id' => $q4,
                'is_terminal' => 0,
                'terminal_message' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'question_id' => $q3,
                'answer_option_id' => $t2,
                'next_question_id' => $q4,
                'is_terminal' => 0,
                'terminal_message' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $techTerminal = 'Thanks! Our tech team will reach out soon. Please keep screenshots/logs ready.';
        DB::table('answer_routes')->insert([
            [
                'question_id' => $q4,
                'answer_option_id' => $s1,
                'next_question_id' => null,
                'is_terminal' => 1,
                'terminal_message' => $techTerminal,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'question_id' => $q4,
                'answer_option_id' => $s2,
                'next_question_id' => null,
                'is_terminal' => 1,
                'terminal_message' => $techTerminal,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'question_id' => $q4,
                'answer_option_id' => $s3,
                'next_question_id' => null,
                'is_terminal' => 1,
                'terminal_message' => $techTerminal,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    });

    $this->info('Flow reset + demo flow seeded successfully.');
    return 0;
})->purpose('Wipe current flow data and seed a demo flow (single + multi select questions)');

Artisan::command('qa:clear-entries {--force : Do not ask for confirmation}', function () {
    if (!Schema::hasTable('flow_sessions')) {
        $this->error('Flow tables not found. Run migrations first: php artisan migrate');
        return 1;
    }

    if (!$this->option('force')) {
        $ok = $this->confirm('This will delete ALL user submissions (sessions + answers). Continue?');
        if (!$ok) {
            $this->info('Canceled.');
            return 0;
        }
    }

    DB::transaction(function () {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            DB::table('flow_answers')->truncate();
            DB::table('flow_sessions')->truncate();
            return;
        }

        DB::table('flow_answers')->delete();
        DB::table('flow_sessions')->delete();

        if ($driver === 'sqlite') {
            DB::statement("DELETE FROM sqlite_sequence WHERE name IN ('flow_answers','flow_sessions')");
        }
    });

    $this->info('All entries cleared successfully.');
    return 0;
})->purpose('Delete all user entries (sessions + answers) and reset counters');
