<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FlowController extends Controller
{
    private function startQuestion(int $questionnaireId): ?object
    {
        return DB::table('questions')
            ->where('questionnaire_id', $questionnaireId)
            ->where('is_start', 1)
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->first();
    }

    private function activeQuestionnaire(): ?object
    {
        return DB::table('questionnaires')->where('is_active', 1)->orderBy('id')->first();
    }

    public function questionnaires(): JsonResponse
    {
        $items = DB::table('questionnaires')->select('id', 'title', 'is_active', 'created_at')->orderByDesc('id')->get();
        $active = $items->firstWhere('is_active', 1);

        return response()->json([
            'questionnaires' => $items,
            'active_questionnaire_id' => $active?->id,
        ]);
    }

    public function createQuestionnaire(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
        ]);

        $id = DB::table('questionnaires')->insertGetId([
            'title' => trim($validated['title']),
            'is_active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Flow created.', 'id' => $id], 201);
    }

    public function activateQuestionnaire(int $id): JsonResponse
    {
        $exists = DB::table('questionnaires')->where('id', $id)->exists();
        if (!$exists) {
            return response()->json(['message' => 'Flow not found.'], 404);
        }

        DB::transaction(function () use ($id) {
            DB::table('questionnaires')->update(['is_active' => 0, 'updated_at' => now()]);
            DB::table('questionnaires')->where('id', $id)->update(['is_active' => 1, 'updated_at' => now()]);
        });

        return response()->json(['message' => 'Flow activated.']);
    }

    private function questionWithOptions(int $questionId): ?array
    {
        $question = DB::table('questions')->where('id', $questionId)->first();
        if (!$question) {
            return null;
        }

        $options = DB::table('answer_options')
            ->where('question_id', $questionId)
            ->orderBy('sort_order')
            ->get();

        return [
            'id' => $question->id,
            'question_text' => $question->question_text,
            'selection_type' => $question->selection_type ?? 'single',
            'options' => $options,
        ];
    }

    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $questionnaire = $this->activeQuestionnaire();
        if (!$questionnaire) {
            return response()->json(['message' => 'No active questionnaire found.'], 422);
        }

        $startQuestion = $this->startQuestion((int) $questionnaire->id);
        if (!$startQuestion) {
            return response()->json(['message' => 'Start question is not configured.'], 422);
        }

        $sessionId = DB::table('flow_sessions')->insertGetId([
            'questionnaire_id' => $questionnaire->id,
            'name' => trim((string) ($validated['name'] ?? '')),
            'email' => $validated['email'] ?? null,
            'phone' => trim((string) ($validated['phone'] ?? '')) ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'session_id' => $sessionId,
            'question' => $this->questionWithOptions((int) $startQuestion->id),
        ]);
    }

    public function saveLead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'integer', 'exists:flow_sessions,id'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'string', 'max:40'],
        ]);

        $session = DB::table('flow_sessions')->where('id', $validated['session_id'])->first();
        if (!$session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        if (!$session->completed_at) {
            return response()->json(['message' => 'Please complete the flow first.'], 422);
        }

        DB::table('flow_sessions')->where('id', $validated['session_id'])->update([
            'name' => trim($validated['name']),
            'email' => $validated['email'],
            'phone' => trim($validated['phone']),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Details saved.']);
    }

    public function pruneAnswers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'integer', 'exists:flow_sessions,id'],
            'keep_question_ids' => ['nullable', 'array'],
            'keep_question_ids.*' => ['integer', 'distinct', 'exists:questions,id'],
        ]);

        $keep = array_values(array_unique(array_map('intval', $validated['keep_question_ids'] ?? [])));

        $query = DB::table('flow_answers')->where('flow_session_id', $validated['session_id']);
        if (count($keep) > 0) {
            $query->whereNotIn('question_id', $keep);
        }
        $query->delete();

        DB::table('flow_sessions')->where('id', $validated['session_id'])->update([
            'completed_at' => null,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Pruned.']);
    }

    public function answer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'integer', 'exists:flow_sessions,id'],
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'answer_option_id' => ['nullable', 'integer', 'exists:answer_options,id'],
            'answer_option_ids' => ['nullable', 'array'],
            'answer_option_ids.*' => ['integer', 'distinct', 'exists:answer_options,id'],
        ]);

        $question = DB::table('questions')->where('id', $validated['question_id'])->first();
        if (!$question) {
            return response()->json(['message' => 'Question not found.'], 404);
        }

        $selectionType = $question->selection_type ?? 'single';

        $pickedIds = [];
        if ($selectionType === 'multi') {
            $pickedIds = $validated['answer_option_ids'] ?? [];
        } else {
            if (!($validated['answer_option_id'] ?? null)) {
                return response()->json(['message' => 'Please select an answer.'], 422);
            }
            $pickedIds = [(int) $validated['answer_option_id']];
        }

        $pickedIds = array_values(array_unique(array_map('intval', $pickedIds)));
        if (count($pickedIds) === 0) {
            return response()->json(['message' => 'Please select at least one answer.'], 422);
        }

        $validOptionCount = DB::table('answer_options')
            ->where('question_id', $validated['question_id'])
            ->whereIn('id', $pickedIds)
            ->count();
        if ($validOptionCount !== count($pickedIds)) {
            return response()->json(['message' => 'Invalid answer option for this question.'], 422);
        }

        DB::table('flow_answers')
            ->where('flow_session_id', $validated['session_id'])
            ->where('question_id', $validated['question_id'])
            ->delete();

        $now = now();
        $rows = [];
        foreach ($pickedIds as $pickedId) {
            $rows[] = [
                'flow_session_id' => $validated['session_id'],
                'question_id' => $validated['question_id'],
                'answer_option_id' => $pickedId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('flow_answers')->insert($rows);

        $routes = DB::table('answer_routes')
            ->where('question_id', $validated['question_id'])
            ->whereIn('answer_option_id', $pickedIds)
            ->get();

        if ($routes->count() !== count($pickedIds)) {
            return response()->json(['message' => 'Route not configured for selected answer.'], 422);
        }

        $targets = [];
        foreach ($routes as $route) {
            if ((int) $route->is_terminal === 1 || !$route->next_question_id) {
                $targets[] = 'terminal:' . ($route->terminal_message ?: 'Flow completed.');
            } else {
                $targets[] = 'next:' . (int) $route->next_question_id;
            }
        }

        $targets = array_values(array_unique($targets));
        if (count($targets) > 1) {
            return response()->json([
                'message' => 'Selected options lead to different paths. Please select options that map to the same next step.',
            ], 422);
        }

        $target = $targets[0] ?? null;
        if (!$target) {
            return response()->json(['message' => 'Route not configured for selected answer.'], 422);
        }

        if (str_starts_with($target, 'terminal:')) {
            DB::table('flow_sessions')->where('id', $validated['session_id'])->update([
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'done' => true,
                'result' => substr($target, strlen('terminal:')),
            ]);
        }

        $nextQuestionId = (int) substr($target, strlen('next:'));
        return response()->json([
            'done' => false,
            'question' => $this->questionWithOptions($nextQuestionId),
        ]);
    }

    public function adminData(Request $request): JsonResponse
    {
        $questionnaires = DB::table('questionnaires')->select('id', 'title', 'is_active', 'created_at')->orderByDesc('id')->get();
        $activeQuestionnaire = $questionnaires->firstWhere('is_active', 1);
        $activeQuestionnaireId = $activeQuestionnaire?->id;

        $requestedId = $request->query('questionnaire_id');
        $questionnaireId = $requestedId ? (int) $requestedId : (int) ($activeQuestionnaireId ?? 0);
        $questionnaire = $questionnaireId ? DB::table('questionnaires')->where('id', $questionnaireId)->first() : null;
        if (!$questionnaire) {
            return response()->json(['message' => 'Flow not found.'], 404);
        }

        $questions = DB::table('questions')
            ->where('questionnaire_id', $questionnaireId)
            ->orderBy('sort_order')
            ->get();
        $questionIds = $questions->pluck('id')->all();

        $options = count($questionIds) === 0
            ? collect([])
            : DB::table('answer_options')
                ->whereIn('question_id', $questionIds)
                ->orderBy('question_id')
                ->orderBy('sort_order')
                ->get();

        $routes = count($questionIds) === 0
            ? collect([])
            : DB::table('answer_routes')
                ->whereIn('question_id', $questionIds)
                ->orderBy('question_id')
                ->get();

        $sessions = DB::table('flow_sessions')
            ->where('questionnaire_id', $questionnaireId)
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        return response()->json([
            'questionnaire' => $questionnaire,
            'questionnaires' => $questionnaires,
            'active_questionnaire_id' => $activeQuestionnaireId,
            'questions' => $questions,
            'options' => $options,
            'routes' => $routes,
            'sessions' => $sessions,
        ]);
    }

    public function sessionAnswers(int $id): JsonResponse
    {
        $session = DB::table('flow_sessions')->where('id', $id)->first();
        if (!$session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        $rows = DB::table('flow_answers as fa')
            ->join('questions as q', 'q.id', '=', 'fa.question_id')
            ->join('answer_options as ao', 'ao.id', '=', 'fa.answer_option_id')
            ->where('fa.flow_session_id', $id)
            ->select([
                'fa.question_id',
                'q.question_text',
                'fa.answer_option_id',
                'ao.option_text',
                'fa.created_at',
            ])
            ->orderBy('fa.id')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $key = (string) $row->question_id;
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'question_id' => (int) $row->question_id,
                    'question_text' => $row->question_text,
                    'answers' => [],
                ];
            }
            $grouped[$key]['answers'][] = [
                'answer_option_id' => (int) $row->answer_option_id,
                'option_text' => $row->option_text,
            ];
        }

        return response()->json([
            'session' => [
                'id' => $session->id,
                'name' => $session->name,
                'email' => $session->email,
                'phone' => $session->phone,
                'completed_at' => $session->completed_at,
                'created_at' => $session->created_at,
            ],
            'qa' => array_values($grouped),
        ]);
    }

    public function addQuestion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'questionnaire_id' => ['nullable', 'integer', 'exists:questionnaires,id'],
            'question_text' => ['required', 'string', 'max:500'],
            'selection_type' => ['nullable', 'in:single,multi'],
            'is_start' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $questionnaire = ($validated['questionnaire_id'] ?? null)
            ? DB::table('questionnaires')->where('id', (int) $validated['questionnaire_id'])->first()
            : $this->activeQuestionnaire();
        if (!$questionnaire) {
            $qid = DB::table('questionnaires')->insertGetId([
                'title' => 'Untitled Flow',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $questionnaire = (object) ['id' => $qid];
        }

        if (($validated['is_start'] ?? false) === true) {
            DB::table('questions')
                ->where('questionnaire_id', $questionnaire->id)
                ->update(['is_start' => 0, 'updated_at' => now()]);
        }

        $payload = [
            'questionnaire_id' => $questionnaire->id,
            'question_text' => $validated['question_text'],
            'is_start' => ($validated['is_start'] ?? false) ? 1 : 0,
            'sort_order' => $validated['sort_order'] ?? 0,
            'pos_x' => 80,
            'pos_y' => 80,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('questions', 'selection_type')) {
            $payload['selection_type'] = $validated['selection_type'] ?? 'single';
        }

        $id = DB::table('questions')->insertGetId($payload);

        return response()->json(['message' => 'Question added.', 'id' => $id], 201);
    }

    public function updateQuestion(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'question_text' => ['required', 'string', 'max:500'],
            'selection_type' => ['nullable', 'in:single,multi'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_start' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $question = DB::table('questions')->where('id', $id)->first();
        if (!$question) {
            return response()->json(['message' => 'Question not found.'], 404);
        }

        if (($validated['is_start'] ?? false) === true) {
            DB::table('questions')
                ->where('questionnaire_id', $question->questionnaire_id)
                ->update(['is_start' => 0, 'updated_at' => now()]);
        }

        $payload = [
            'question_text' => $validated['question_text'],
            'sort_order' => $validated['sort_order'] ?? $question->sort_order,
            'is_start' => ($validated['is_start'] ?? false) ? 1 : 0,
            'is_active' => ($validated['is_active'] ?? true) ? 1 : 0,
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('questions', 'selection_type')) {
            $payload['selection_type'] = $validated['selection_type'] ?? ($question->selection_type ?? 'single');
        }

        DB::table('questions')->where('id', $id)->update($payload);

        return response()->json(['message' => 'Question updated.']);
    }

    public function deleteQuestion(int $id): JsonResponse
    {
        DB::table('questions')->where('id', $id)->delete();
        return response()->json(['message' => 'Question deleted.']);
    }

    public function saveQuestionPosition(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'pos_x' => ['required', 'integer', 'min:0', 'max:5000'],
            'pos_y' => ['required', 'integer', 'min:0', 'max:5000'],
        ]);

        DB::table('questions')->where('id', $id)->update([
            'pos_x' => $validated['pos_x'],
            'pos_y' => $validated['pos_y'],
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Position saved.']);
    }

    public function addOption(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'option_text' => ['required', 'string', 'max:300'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $id = DB::table('answer_options')->insertGetId([
            'question_id' => $validated['question_id'],
            'option_text' => $validated['option_text'],
            'sort_order' => $validated['sort_order'] ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Option added.', 'id' => $id], 201);
    }

    public function updateOption(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'question_id' => ['nullable', 'integer', 'exists:questions,id'],
            'option_text' => ['required', 'string', 'max:300'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $update = [
            'option_text' => $validated['option_text'],
            'sort_order' => $validated['sort_order'] ?? 0,
            'updated_at' => now(),
        ];
        if (array_key_exists('question_id', $validated) && $validated['question_id']) {
            $update['question_id'] = $validated['question_id'];
        }

        DB::table('answer_options')->where('id', $id)->update($update);

        return response()->json(['message' => 'Option updated.']);
    }

    public function deleteOption(int $id): JsonResponse
    {
        DB::table('answer_options')->where('id', $id)->delete();
        return response()->json(['message' => 'Option deleted.']);
    }

    public function saveRoute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'answer_option_id' => ['required', 'integer', 'exists:answer_options,id'],
            'next_question_id' => ['nullable', 'integer', 'exists:questions,id'],
            'is_terminal' => ['nullable', 'boolean'],
            'terminal_message' => ['nullable', 'string', 'max:500'],
        ]);

        $route = DB::table('answer_routes')
            ->where('question_id', $validated['question_id'])
            ->where('answer_option_id', $validated['answer_option_id'])
            ->first();

        if ($route) {
            DB::table('answer_routes')->where('id', $route->id)->update([
                'next_question_id' => ($validated['is_terminal'] ?? false) ? null : ($validated['next_question_id'] ?? null),
                'is_terminal' => ($validated['is_terminal'] ?? false) ? 1 : 0,
                'terminal_message' => ($validated['is_terminal'] ?? false) ? ($validated['terminal_message'] ?? null) : null,
                'updated_at' => now(),
            ]);
            return response()->json(['message' => 'Route updated.', 'id' => $route->id]);
        }

        $id = DB::table('answer_routes')->insertGetId([
            'question_id' => $validated['question_id'],
            'answer_option_id' => $validated['answer_option_id'],
            'next_question_id' => ($validated['is_terminal'] ?? false) ? null : ($validated['next_question_id'] ?? null),
            'is_terminal' => ($validated['is_terminal'] ?? false) ? 1 : 0,
            'terminal_message' => ($validated['is_terminal'] ?? false) ? ($validated['terminal_message'] ?? null) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Route saved.', 'id' => $id], 201);
    }

    public function updateRoute(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'next_question_id' => ['nullable', 'integer', 'exists:questions,id'],
            'is_terminal' => ['nullable', 'boolean'],
            'terminal_message' => ['nullable', 'string', 'max:500'],
        ]);

        DB::table('answer_routes')->where('id', $id)->update([
            'next_question_id' => ($validated['is_terminal'] ?? false) ? null : ($validated['next_question_id'] ?? null),
            'is_terminal' => ($validated['is_terminal'] ?? false) ? 1 : 0,
            'terminal_message' => ($validated['is_terminal'] ?? false) ? ($validated['terminal_message'] ?? null) : null,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Route updated.']);
    }

    public function deleteRoute(int $id): JsonResponse
    {
        DB::table('answer_routes')->where('id', $id)->delete();
        return response()->json(['message' => 'Route deleted.']);
    }

}
