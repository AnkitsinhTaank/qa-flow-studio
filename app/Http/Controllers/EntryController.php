<?php

namespace App\Http\Controllers;

use App\Models\Entry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'subject' => ['required', 'string', 'max:190'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $entry = Entry::create($validated);

        return response()->json([
            'message' => 'Entry saved successfully.',
            'entry' => $entry,
        ], 201);
    }

    public function index(): JsonResponse
    {
        $entries = Entry::query()
            ->latest('id')
            ->get();

        return response()->json([
            'entries' => $entries,
        ]);
    }
}
