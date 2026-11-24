<?php

use App\Enums\RoleName;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\StressCheckController;
use App\Http\Controllers\ApplicationAccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Staff\ExamController;
use App\Http\Controllers\Staff\ProfileController;
use App\Http\Controllers\Staff\SelfCareController;
use App\Http\Controllers\Staff\StressCheckController as EmployeeStressCheckController;
use App\Http\Controllers\SuperAdmin\AdminController as SuperAdminAdminController;
use App\Http\Controllers\SuperAdmin\CompanyController;
use App\Http\Controllers\SuperAdmin\LogController;
use App\Http\Middleware\CheckNeedChangePassword;
use App\Http\Middleware\CheckUserDisabled;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forgot-password', [AdminController::class, 'sendPasswordResetLink'])->middleware('throttle:forgot-password');
Route::post('/submit-application', [ApplicationAccountController::class, 'submitApplicationAccount']);

Route::group(['middleware' => ['auth:api', CheckUserDisabled::class]], function () {
    Route::get('/auth/check', [AuthController::class, 'authCheck']);
    Route::delete('/auth/logout', [AuthController::class, 'logout']);
    Route::put('/auth/language', [AuthController::class, 'updateLanguage']);

    // PREFIX: /super-admin/* && ROLE: super-admin
    Route::group(['middleware' => 'role:'.RoleName::SuperAdmin->value, 'prefix' => 'super-admin'], function () {
        Route::group(['prefix' => 'company'], function () {
            Route::get('/', [CompanyController::class, 'index']);
            Route::get('/{company}', [CompanyController::class, 'show'])->withTrashed();
            Route::post('/', [CompanyController::class, 'create']);
            Route::put('/{company}', [CompanyController::class, 'update']);
            Route::put('/{company}/note', [CompanyController::class, 'updateNote']);
            Route::delete('/{company}', [CompanyController::class, 'destroy']);
            Route::get('/{company}/log', [LogController::class, 'index'])->withTrashed();
            Route::get('/{company}/{stressCheckPeriod}/report', [StressCheckController::class, 'getAdminReport']);

            Route::post('/{company}/approve', [CompanyController::class, 'approve']);
            Route::post('/{company}/restore', [CompanyController::class, 'restore'])->withTrashed();
        });

        Route::delete('/admin/{user}', [SuperAdminAdminController::class, 'delete']);
        Route::delete('/employee/{user}', [EmployeeController::class, 'delete']);
    });

    // PREFIX: /admins/*
    Route::group(['prefix' => 'admins'], function () {
        // ROLE: super-admin
        Route::group(['middleware' => 'role:'.RoleName::SuperAdmin->value], function () {
            Route::post('/employee/{employee}/confirm-request-delete', [EmployeeController::class, 'confirmRequestDelete']);
        });

        // ROLE: super-admin | admin
        Route::group(['middleware' => 'role:'.RoleName::SuperAdmin->value.'|'.RoleName::Admin->value], function () {
            Route::get('/', [AdminController::class, 'index']);
            Route::post('/', [AdminController::class, 'create']);

            Route::group(['prefix' => 'employee'], function () {
                Route::get('/', [EmployeeController::class, 'index']);
                Route::get('/export', [EmployeeController::class, 'exportCsv']);
                Route::post('/import', [EmployeeController::class, 'importCsv']);
                Route::get('/{employee}', [EmployeeController::class, 'show']);
                Route::post('/', [EmployeeController::class, 'create']);
                Route::put('/{employee}', [EmployeeController::class, 'update']);
                Route::post('/{employee}/request-delete', [EmployeeController::class, 'requestDelete'])->middleware('throttle:request-delete');
            });

            Route::post('/{admin}/request-delete', [AdminController::class, 'requestDelete'])->middleware('throttle:request-delete');

            Route::group(['prefix' => 'stress-check'], function () {
                Route::post('/', [StressCheckController::class, 'create']);
                Route::put('/{stressCheckPeriod}', [StressCheckController::class, 'update']);
                Route::delete('/{stressCheckPeriod}', [StressCheckController::class, 'destroy']);
            });
        });
        // ROLE: super-admin | admin | doctor
        Route::group(['middleware' => 'role:'.RoleName::SuperAdmin->value.'|'.RoleName::Admin->value.'|'.RoleName::Doctor->value], function () {
            Route::get('/dashboard', [DashboardController::class, 'index']);

            Route::group(['prefix' => 'stress-check'], function () {
                Route::get('/', [StressCheckController::class, 'index']);
                Route::get('/{stressCheckPeriod}', [StressCheckController::class, 'show']);
                Route::get('/{stressCheckPeriod}/examinees', [StressCheckController::class, 'getExaminees']);
                Route::get('/{stressCheckPeriod}/examinees/export', [StressCheckController::class, 'exportExaminees']);
                Route::get('/{stressCheckPeriod}/report', [StressCheckController::class, 'getAdminReport']);
                Route::get('/{stressCheckPeriod}/examinees/{examinee}', [StressCheckController::class, 'getExamineeResult']);
            });

            Route::get('/{admin}', [AdminController::class, 'show']);
            Route::put('/{admin}', [AdminController::class, 'update']);
            Route::post('/{admin}/resend', [AdminController::class, 'resendInvitation'])->middleware('throttle:send-mail');
        });
    });

    // PREFIX: /staff/* && ROLE: staff | staff-manager
    Route::group(['middleware' => 'role:'.RoleName::Staff->value.'|'.RoleName::StaffManager->value, 'prefix' => 'staff'], function () {
        Route::post('/set-first-password', [ProfileController::class, 'setFirstPassword']);

        Route::group(['middleware' => CheckNeedChangePassword::class], function () {
            Route::put('/profile', [ProfileController::class, 'update']);
            Route::get('/profile', [ProfileController::class, 'show']);

            Route::group(['prefix' => 'stress-check'], function () {
                Route::get('/', [EmployeeStressCheckController::class, 'index']);
                Route::get('/active', [EmployeeStressCheckController::class, 'getActiveStressCheck']);
                Route::get('/{stressCheckPeriod}', [StressCheckController::class, 'show']);
                Route::get('/{stressCheckPeriod}/exam', [EmployeeStressCheckController::class, 'getExam']);
                Route::post('/{stressCheckPeriod}/exam', [ExamController::class, 'saveAnswer']);
                Route::get('/{stressCheckPeriod}/exam/result', [ExamController::class, 'getExamResult']);
                Route::get('/{stressCheckPeriod}/report', [StressCheckController::class, 'getAdminReport']);
            });

            Route::group(['prefix' => 'self-care'], function () {
                Route::get('/exam', [SelfCareController::class, 'getExam']);
                Route::post('/exam', [SelfCareController::class, 'submitExam']);
            });
        });
    });
});

Route::get('/invitation/{user}/verify', [AdminController::class, 'verifyInvitation'])->name('admin.invitation.verify')->middleware('signed');
Route::post('/invitation/{user}/setup-password', [AdminController::class, 'setupPassword'])->name('admin.setup-password')->middleware('signed');
