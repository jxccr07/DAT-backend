<?php

namespace App\Http\Controllers;

use App\Services\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The student's side of the conversation with their academic advisor. */
class MessageController extends Controller
{
    public function __construct(private readonly MessagingService $messages) {}

    public function index(Request $request): JsonResponse
    {
        $student = $request->user()->load('advisor.academicPlan');

        if (! $student->advisor_id) {
            return response()->json(['data' => ['advisor' => null, 'messages' => []]]);
        }

        $thread = $this->messages->thread($student->advisor_id, $student->id);
        $this->messages->markRead($student->advisor_id, $student->id, MessagingService::SENDER_STUDENT);

        return response()->json([
            'data' => [
                // Names repeat across advisors, so the email (unique) is what tells them apart.
                'advisor' => [
                    'full_name' => $student->advisor?->full_name,
                    'email' => $student->advisor?->email,
                    'department' => $student->advisor?->academicPlan?->major,
                ],
                'messages' => $thread,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $student = $request->user();
        abort_unless($student->advisor_id, 422, __('advising.no_advisor'));

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => ['id' => $this->messages->sendFromStudent($student, $data['body'])]], 201);
    }
}
