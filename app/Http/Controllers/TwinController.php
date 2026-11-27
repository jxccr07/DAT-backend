<?php

namespace App\Http\Controllers;

use App\Services\CourseRegistrationService;
use App\Twin\TwinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TwinController extends Controller
{
    public function __construct(
        private readonly TwinService $twin,
        private readonly CourseRegistrationService $registration,
    ) {}

    public function status(Request $request): JsonResponse
    {
        if (! $this->twin->isReady()) {
            return response()->json(['data' => ['ready' => false]]);
        }

        return response()->json(['data' => $this->twin->status($request->user())]);
    }

    public function decision(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['drop', 'light', 'fail'])],
            'course' => ['nullable', 'integer', 'exists:courses,id', Rule::requiredIf(fn () => in_array($request->input('action'), ['drop', 'fail'], true))],
        ]);

        if (! $this->twin->isReady()) {
            return response()->json(['data' => null]);
        }

        $result = $this->twin->decision($request->user(), $data['action'], isset($data['course']) ? (int) $data['course'] : null);

        // Only a student seeing the price of dropping one of their own current courses counts as a "view".
        if ($data['action'] === 'drop' && $this->isRegistered($request->user()->id, (int) $data['course'])) {
            $this->twin->logView($request->user(), (int) $data['course'], $result['headline']);
        }

        return response()->json(['data' => $result]);
    }

    private function isRegistered(int $studentId, int $courseId): bool
    {
        $active = $this->registration->activeSemester();

        return $active && DB::table('student_academic_records')->where('student_id', $studentId)
            ->where('semester_id', $active->id)->where('course_id', $courseId)
            ->where('status', CourseRegistrationService::STATUS_CURRENT)->exists();
    }

    /** Inputs for the "what if" timeline. Nothing is logged: playing with scenarios is private. */
    public function timeline(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->twin->isReady() ? $this->twin->timeline($request->user()) : null]);
    }

    public function meta(): JsonResponse
    {
        $meta = $this->twin->meta();

        return response()->json(['data' => $meta ? $meta + ['impact' => $this->twin->impact()] : null]);
    }
}
