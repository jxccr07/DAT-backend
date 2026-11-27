<?php

namespace App\Http\Controllers;

use App\Services\CourseRegistrationService;
use App\Services\DropDeadlineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The student asks their advisor for a conversation about a course, from the drop screen. */
class MeetingRequestController extends Controller
{
    public function __invoke(Request $request, DropDeadlineService $deadline, CourseRegistrationService $registration): JsonResponse
    {
        $student = $request->user();
        abort_unless($student->advisor_id, 422, __('advising.no_advisor'));

        $data = $request->validate(['course' => ['nullable', 'integer']]);
        $courseId = isset($data['course']) ? (int) $data['course'] : null;

        // Only one of the student's own current courses can be attached.
        if ($courseId !== null) {
            $active = $registration->activeSemester();
            $mine = $active && DB::table('student_academic_records')->where('student_id', $student->id)
                ->where('semester_id', $active->id)->where('course_id', $courseId)
                ->where('status', CourseRegistrationService::STATUS_CURRENT)->exists();
            $courseId = $mine ? $courseId : null;
        }

        $id = $deadline->requestMeeting($student, $courseId);

        return response()->json(['message' => __('advising.meeting_requested'), 'data' => ['id' => $id]], 201);
    }
}
