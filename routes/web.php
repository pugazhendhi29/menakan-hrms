<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AttendanceController;
use App\Controllers\AttendanceImportController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\DocumentController;
use App\Controllers\EmployeeController;
use App\Controllers\EmployeeImportController;
use App\Controllers\FileController;
use App\Controllers\HierarchyController;
use App\Controllers\WebmailController;
use App\Controllers\IdCardController;
use App\Controllers\NewsController;
use App\Controllers\LeaveController;
use App\Controllers\MyController;
use App\Controllers\NotificationController;
use App\Controllers\P60Controller;
use App\Controllers\PayslipController;
use App\Controllers\ReportController;
use App\Controllers\SettingController;
use App\Controllers\ShiftController;
use App\Controllers\ShiftImportController;
use App\Controllers\UserController;
use App\Core\Router;

/*
 * Every POST is CSRF-checked in the Kernel. Each route declares its middleware:
 * 'auth' (signed in), 'role:admin' (admin only), 'employee' (account linked to an employee).
 * Employee self-service routes under /my take no employee ID: the session decides whose data is shown.
 */
return function (Router $r): void {
    // ---- Public / guest
    $r->get('/login', [AuthController::class, 'showLogin'], ['guest']);
    $r->post('/login', [AuthController::class, 'login'], ['guest']);
    $r->get('/password/reset/{token:[a-f0-9]{64}}', [AuthController::class, 'showReset']);
    $r->post('/password/reset/{token:[a-f0-9]{64}}', [AuthController::class, 'reset']);

    $r->group(['auth'], function (Router $r) {
        $r->post('/logout', [AuthController::class, 'logout']);
        $r->get('/', [DashboardController::class, 'home']);
        $r->get('/account/password', [AccountController::class, 'showPassword']);
        $r->post('/account/password', [AccountController::class, 'changePassword']);
        $r->get('/notifications', [NotificationController::class, 'index']);
        $r->post('/notifications/read-all', [NotificationController::class, 'readAll']);
        $r->get('/notifications/{id}/open', [NotificationController::class, 'open']);

        // ---- Organisation chart (everyone signed in can view; only admins see edit links)
        $r->get('/hierarchy', [HierarchyController::class, 'index']);
        $r->get('/hierarchy/pdf', [HierarchyController::class, 'pdf']);

        // ---- My Email: link to the company webmail (everyone signed in)
        $r->get('/my-email', [WebmailController::class, 'index']);

        // ---- Private files (each method checks ownership; unknown or not-yours = 404)
        $r->get('/files/document/{id}', [FileController::class, 'document']);
        $r->get('/files/payslip/{id}', [FileController::class, 'payslip']);
        $r->get('/files/photo/{id}', [FileController::class, 'photo']);
        $r->get('/files/p60/{id}', [FileController::class, 'p60']);

        // ---- Employee self-service
        $r->group(['employee'], function (Router $r) {
            $r->get('/my', [MyController::class, 'dashboard']);
            $r->get('/my/profile', [MyController::class, 'profile']);
            $r->get('/my/history', [MyController::class, 'history']);
            $r->post('/my/history', [MyController::class, 'saveHistory']);
            $r->get('/my/documents', [MyController::class, 'documents']);
            $r->get('/my/attendance', [MyController::class, 'attendance']);
            $r->post('/my/attendance/check-in', [MyController::class, 'checkIn']);
            $r->post('/my/attendance/check-out', [MyController::class, 'checkOut']);
            $r->get('/my/shifts', [MyController::class, 'shifts']);
            $r->get('/my/leave', [MyController::class, 'leave']);
            $r->post('/my/leave', [MyController::class, 'requestLeave']);
            $r->post('/my/leave/{id}/cancel', [MyController::class, 'cancelLeave']);
            $r->get('/my/payslips', [MyController::class, 'payslips']);
            $r->get('/my/p60', [P60Controller::class, 'my']);
            $r->get('/my/id-card', [IdCardController::class, 'my']);
            $r->get('/my/news', [NewsController::class, 'my']);
            $r->get('/my/news/{id}', [NewsController::class, 'myShow']);
        });

        // ---- Admin
        $r->group(['role:admin'], function (Router $r) {
            $r->get('/dashboard', [DashboardController::class, 'admin']);

            $r->get('/employees', [EmployeeController::class, 'index']);
            $r->get('/employees/export', [EmployeeController::class, 'export']);
            $r->get('/employees/import', [EmployeeImportController::class, 'index']);
            $r->get('/employees/import/template', [EmployeeImportController::class, 'template']);
            $r->post('/employees/import', [EmployeeImportController::class, 'upload']);
            $r->get('/employees/import/{id}', [EmployeeImportController::class, 'show']);
            $r->post('/employees/import/{id}/confirm', [EmployeeImportController::class, 'confirm']);
            $r->post('/employees/import/{id}/cancel', [EmployeeImportController::class, 'cancel']);
            $r->get('/employees/import/{id}/errors', [EmployeeImportController::class, 'errors']);
            $r->get('/employees/create', [EmployeeController::class, 'create']);
            $r->post('/employees', [EmployeeController::class, 'store']);
            $r->get('/employees/{id}', [EmployeeController::class, 'show']);
            $r->get('/employees/{id}/edit', [EmployeeController::class, 'edit']);
            $r->post('/employees/{id}', [EmployeeController::class, 'update']);
            $r->post('/employees/{id}/delete', [EmployeeController::class, 'destroy']);
            $r->post('/employees/{id}/restore', [EmployeeController::class, 'restore']);
            $r->post('/employees/{id}/account', [EmployeeController::class, 'createAccount']);
            $r->post('/employees/{id}/photo', [EmployeeController::class, 'uploadPhoto']);
            $r->post('/employees/{id}/photo/delete', [EmployeeController::class, 'deletePhoto']);
            $r->post('/employees/{id}/leave/recalculate', [EmployeeController::class, 'recalculateLeave']);
            $r->post('/employees/{id}/leave/adjust', [EmployeeController::class, 'adjustLeave']);
            $r->post('/employees/{id}/id-card', [IdCardController::class, 'store']);
            $r->post('/id-cards/{id}', [IdCardController::class, 'update']);
            $r->post('/id-cards/{id}/regenerate', [IdCardController::class, 'regenerate']);
            $r->post('/id-cards/{id}/revoke', [IdCardController::class, 'revoke']);

            $r->get('/news', [NewsController::class, 'index']);
            $r->get('/news/create', [NewsController::class, 'create']);
            $r->post('/news', [NewsController::class, 'store']);
            $r->post('/news/sender-check', [NewsController::class, 'senderCheck']);
            $r->get('/news/templates', [NewsController::class, 'templates']);
            $r->get('/news/templates/create', [NewsController::class, 'templateForm']);
            $r->post('/news/templates', [NewsController::class, 'templateStore']);
            $r->get('/news/templates/{id}/edit', [NewsController::class, 'templateEdit']);
            $r->post('/news/templates/{id}', [NewsController::class, 'templateUpdate']);
            $r->post('/news/templates/{id}/delete', [NewsController::class, 'templateDelete']);
            $r->get('/news/{id}', [NewsController::class, 'show']);
            $r->get('/news/{id}/edit', [NewsController::class, 'edit']);
            $r->post('/news/{id}', [NewsController::class, 'update']);
            $r->post('/news/{id}/send', [NewsController::class, 'send']);
            $r->post('/news/{id}/unschedule', [NewsController::class, 'unschedule']);
            $r->post('/news/{id}/delete', [NewsController::class, 'delete']);

            $r->get('/documents', [DocumentController::class, 'index']);
            $r->get('/documents/upload', [DocumentController::class, 'create']);
            $r->post('/documents', [DocumentController::class, 'store']);
            $r->get('/documents/{id}/replace', [DocumentController::class, 'replaceForm']);
            $r->post('/documents/{id}/replace', [DocumentController::class, 'replace']);
            $r->post('/documents/{id}/verify', [DocumentController::class, 'verify']);
            $r->post('/documents/{id}/archive', [DocumentController::class, 'archive']);
            $r->get('/documents/matrix', [DocumentController::class, 'matrix']);

            $r->get('/attendance', [AttendanceController::class, 'index']);
            $r->get('/attendance/create', [AttendanceController::class, 'create']);
            $r->post('/attendance', [AttendanceController::class, 'store']);
            $r->get('/attendance/export', [AttendanceController::class, 'export']);
            $r->post('/attendance/approve', [AttendanceController::class, 'approve']);
            $r->get('/attendance/absences', [AttendanceController::class, 'absences']);
            $r->get('/attendance/{id}', [AttendanceController::class, 'show']);
            $r->get('/attendance/{id}/edit', [AttendanceController::class, 'edit']);
            $r->post('/attendance/{id}', [AttendanceController::class, 'update']);

            $r->get('/attendance-import', [AttendanceImportController::class, 'index']);
            $r->get('/attendance-import/template', [AttendanceImportController::class, 'template']);
            $r->post('/attendance-import', [AttendanceImportController::class, 'upload']);
            $r->get('/attendance-import/{id}', [AttendanceImportController::class, 'show']);
            $r->post('/attendance-import/{id}/confirm', [AttendanceImportController::class, 'confirm']);
            $r->post('/attendance-import/{id}/cancel', [AttendanceImportController::class, 'cancel']);
            $r->get('/attendance-import/{id}/errors', [AttendanceImportController::class, 'errors']);

            $r->get('/shifts', [ShiftController::class, 'index']);
            $r->get('/shifts/create', [ShiftController::class, 'create']);
            $r->post('/shifts', [ShiftController::class, 'store']);
            $r->get('/shifts/export', [ShiftController::class, 'export']);
            $r->post('/shifts/publish', [ShiftController::class, 'publish']);
            $r->get('/shifts/exceptions', [ShiftController::class, 'exceptions']);
            $r->post('/shifts/exceptions', [ShiftController::class, 'approveException']);
            $r->get('/shifts/{id}/edit', [ShiftController::class, 'edit']);
            $r->post('/shifts/{id}', [ShiftController::class, 'update']);
            $r->post('/shifts/{id}/cancel', [ShiftController::class, 'cancel']);

            $r->get('/shift-import', [ShiftImportController::class, 'index']);
            $r->get('/shift-import/template', [ShiftImportController::class, 'template']);
            $r->post('/shift-import', [ShiftImportController::class, 'upload']);
            $r->get('/shift-import/{id}', [ShiftImportController::class, 'show']);
            $r->post('/shift-import/{id}/confirm', [ShiftImportController::class, 'confirm']);
            $r->post('/shift-import/{id}/cancel', [ShiftImportController::class, 'cancel']);
            $r->get('/shift-import/{id}/errors', [ShiftImportController::class, 'errors']);

            $r->get('/leave', [LeaveController::class, 'index']);
            $r->get('/leave/create', [LeaveController::class, 'create']);
            $r->post('/leave', [LeaveController::class, 'store']);
            $r->get('/leave/balances', [LeaveController::class, 'balances']);
            $r->get('/leave/calendar', [LeaveController::class, 'calendar']);
            $r->get('/leave/{id}', [LeaveController::class, 'show']);
            $r->post('/leave/{id}/approve', [LeaveController::class, 'approve']);
            $r->post('/leave/{id}/reject', [LeaveController::class, 'reject']);
            $r->post('/leave/{id}/cancel', [LeaveController::class, 'cancel']);

            $r->get('/payslips', [PayslipController::class, 'index']);
            $r->get('/payslips/upload', [PayslipController::class, 'create']);
            $r->post('/payslips', [PayslipController::class, 'store']);
            $r->post('/payslips/bulk', [PayslipController::class, 'bulkPrepare']);
            $r->get('/payslips/bulk/{token:[a-f0-9]{16}}', [PayslipController::class, 'bulkPreview']);
            $r->post('/payslips/bulk/{token:[a-f0-9]{16}}', [PayslipController::class, 'bulkConfirm']);
            $r->post('/payslips/publish', [PayslipController::class, 'publish']);
            $r->post('/payslips/{id}/archive', [PayslipController::class, 'archive']);

            $r->get('/p60', [P60Controller::class, 'index']);
            $r->get('/p60/upload', [P60Controller::class, 'create']);
            $r->post('/p60', [P60Controller::class, 'store']);
            $r->post('/p60/bulk', [P60Controller::class, 'bulk']);
            $r->get('/p60/{id}/replace', [P60Controller::class, 'replaceForm']);
            $r->post('/p60/{id}/replace', [P60Controller::class, 'replace']);
            $r->post('/p60/{id}/delete', [P60Controller::class, 'delete']);

            $r->get('/users', [UserController::class, 'index']);
            $r->get('/users/create', [UserController::class, 'create']);
            $r->post('/users', [UserController::class, 'store']);
            $r->get('/users/{id}/edit', [UserController::class, 'edit']);
            $r->post('/users/{id}', [UserController::class, 'update']);
            $r->post('/users/{id}/toggle', [UserController::class, 'toggle']);
            $r->post('/users/{id}/reset-link', [UserController::class, 'resetLink']);
            $r->post('/users/{id}/revoke-sessions', [UserController::class, 'revokeSessions']);

            $r->get('/reports', [ReportController::class, 'index']);
            $r->get('/reports/{key:[a-z_]+}', [ReportController::class, 'show']);

            $r->get('/settings', [SettingController::class, 'index']);
            $r->post('/settings', [SettingController::class, 'update']);
            $r->get('/settings/{table:[a-z_]+}', [SettingController::class, 'lookup']);
            $r->post('/settings/{table:[a-z_]+}', [SettingController::class, 'saveLookup']);
            $r->post('/settings/{table:[a-z_]+}/{id}/toggle', [SettingController::class, 'toggleLookup']);
        });
    });
};
