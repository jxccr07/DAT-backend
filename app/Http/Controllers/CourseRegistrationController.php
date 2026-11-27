<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterCourseRequest;
use App\Models\Course;
use App\Services\AdvisingService;
use App\Services\CourseRegistrationService;
use App\Twin\TwinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CourseRegistrationController extends Controller
{
    public function __construct(
        private readonly CourseRegistrationService $registration,
        private readonly TwinService $twin,
        private readonly AdvisingService $advising,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $overview = $this->registration->overview($request->user());

        // Attach "students like you" figures to every course in the list.
        if ($this->twin->isReady()) {
            $ids = collect($overview['available'])->pluck('id')->merge(collect($overview['registered'])->pluck('id'))->filter()->all();
            $insights = $this->twin->courseInsights($request->user(), $ids);
            foreach (['available', 'registered'] as $list) {
                $overview[$list] = collect($overview[$list])->map(fn ($c) => $c + ['twin' => $insights[$c['id']] ?? null])->values();
            }
        }

        return response()->json(['data' => $overview]);
    }

    public function store(RegisterCourseRequest $request): JsonResponse
    {
        $record = $this->registration->register($request->user(), (int) $request->validated('course_id'));
        $this->twin->refreshStatus($request->user());

        return response()->json([
            'data' => [
                'record_id' => $record->id,
                'id' => $record->course->id,
                'code' => $record->course->code,
                'name' => $record->course->name,
                'hours' => $record->course->hours,
            ],
        ], 201);
    }

    public function destroy(Request $request, int $course): Response
    {
        $student = $request->user();
        // The advisor hears about a drop only when the student asks for it (opt-in consent at the moment of the decision).
        $consented = $request->boolean('notify_advisor');

        // Capture the "price of the decision" before the drop changes the student's load.
        $preview = $consented && $student->advisor_id && $this->twin->isReady() && ($model = Course::query()->find($course))
            ? $this->twin->decision($student, 'drop', $model->id)
            : null;

        $this->registration->drop($student, $course);

        if ($preview) {
            $this->advising->alertOnDrop($student, $model, $preview);
        }
        $this->twin->markDropped($student, $course);
        $this->twin->refreshStatus($student);

        return response()->noContent();
    }
}
