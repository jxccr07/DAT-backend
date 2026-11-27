<?php

use App\Http\Controllers\AdvisorController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CourseRegistrationController;
use App\Http\Controllers\DemoAdvisorController;
use App\Http\Controllers\DemoStudentController;
use App\Http\Controllers\MeetingRequestController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TwinController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->as('api.v1.')->group(function () {
    Route::get('/demo-student', DemoStudentController::class)
        ->middleware('throttle:20,1')
        ->name('demo-student');

    Route::get('/demo-advisor', DemoAdvisorController::class)
        ->middleware('throttle:20,1')
        ->name('demo-advisor');

    Route::post('/me/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('me.login');

    Route::prefix('me')->as('me.')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        // Shared by both roles: the account itself and the methodology page.
        Route::get('/', [StudentController::class, 'me'])->name('info');
        Route::get('/twin/meta', [TwinController::class, 'meta'])->name('twin.meta');

        Route::middleware('role:student')->group(function () {
            Route::controller(StudentController::class)->group(function () {
                Route::get('/schedule', 'schedule')->name('schedule');
                Route::get('/academic/plan', 'academicPlan')->name('academic.plan');
                Route::get('/academic/term', 'academicTerm')->name('academic.term');
                Route::get('/academic/grades', 'academicGrades')->name('academic.grades');
            });

            Route::controller(CourseRegistrationController::class)->prefix('registration')->as('registration.')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/courses', 'store')->name('store');
                Route::delete('/courses/{course}', 'destroy')->where('course', '[0-9]{1,18}')->name('destroy');
            });

            Route::post('/advisor-request', MeetingRequestController::class)->middleware('throttle:10,1')->name('advisor-request');

            Route::controller(MessageController::class)->prefix('messages')->as('messages.')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->middleware('throttle:30,1')->name('store');
            });

            Route::controller(TwinController::class)->prefix('twin')->as('twin.')->group(function () {
                Route::get('/', 'status')->name('status');
                Route::get('/decision', 'decision')->name('decision');
                Route::get('/timeline', 'timeline')->name('timeline');
            });
        });

        Route::controller(AdvisorController::class)->prefix('advisor')->as('advisor.')->middleware('role:advisor')->group(function () {
            Route::get('/', 'overview')->name('overview');
            Route::get('/students', 'students')->name('students');
            Route::get('/students/{student}', 'student')->where('student', '[0-9]{1,18}')->name('student');
            Route::post('/students/{student}/notes', 'storeNote')->where('student', '[0-9]{1,18}')->name('notes.store');
            Route::post('/students/{student}/messages', 'sendMessage')->where('student', '[0-9]{1,18}')->middleware('throttle:30,1')->name('messages.store');
            Route::post('/alerts/{alert}/seen', 'markAlertSeen')->where('alert', '[0-9]{1,18}')->name('alerts.seen');
            Route::get('/before-deadline', 'beforeDeadline')->name('before-deadline');
        });
    });
});
