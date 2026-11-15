<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use People\Presentation\Http\Controller\AdvanceController;
use People\Presentation\Http\Controller\AttendanceController;
use People\Presentation\Http\Controller\EmployeeController;
use People\Presentation\Http\Controller\LeaveController;
use People\Presentation\Http\Controller\TaxSlabController;
use People\Presentation\Http\Controller\PayrollController;

Route::middleware(['auth:sanctum', 'module:people'])->group(function (): void {
    $view = 'permission:people.employee.view';
    $manage = 'permission:people.employee.manage';
    $attendance = 'permission:people.attendance.manage';
    $payroll = 'permission:people.payroll.manage';

    Route::get('employees', [EmployeeController::class, 'index'])->middleware($view);
    Route::post('employees', [EmployeeController::class, 'store'])->middleware($manage);
    Route::put('employees/{employeeId}', [EmployeeController::class, 'update'])->middleware($manage);

    Route::get('attendance', [AttendanceController::class, 'sheet'])->middleware($view);
    Route::post('attendance', [AttendanceController::class, 'save'])->middleware($attendance);
    Route::get('attendance/summary', [AttendanceController::class, 'summary'])->middleware($view);

    Route::get('employee-advances', [AdvanceController::class, 'index'])->middleware($payroll);
    Route::post('employee-advances', [AdvanceController::class, 'store'])->middleware([$payroll, 'idempotent']);

    Route::get('payroll-runs', [PayrollController::class, 'index'])->middleware($payroll);
    Route::get('payroll-runs/{runId}', [PayrollController::class, 'show'])->middleware($payroll);
    Route::post('payroll-runs', [PayrollController::class, 'store'])->middleware($payroll);
    Route::put('payroll-runs/{runId}/lines/{lineId}', [PayrollController::class, 'adjust'])->middleware($payroll);
    Route::post('payroll-runs/{runId}/recalculate', [PayrollController::class, 'recalculate'])->middleware($payroll);
    Route::post('payroll-runs/{runId}/approve', [PayrollController::class, 'approve'])->middleware('permission:people.payroll.approve');
    Route::post('payroll-runs/{runId}/pay', [PayrollController::class, 'pay'])->middleware(['permission:people.payroll.approve', 'idempotent']);
    Route::post('payroll-runs/{runId}/cancel', [PayrollController::class, 'cancel'])->middleware($payroll);
    Route::get('payroll/tax-slabs', [TaxSlabController::class, 'show'])->middleware($payroll);
    Route::put('payroll/tax-slabs', [TaxSlabController::class, 'update'])->middleware('permission:people.payroll.approve');
    Route::post('payroll/tax-calculator', [TaxSlabController::class, 'calculate'])->middleware($payroll);

    // Leave: staff request their own; managers request for others, approve, and set up types and holidays.
    $leaveSetup = 'permission:people.leave.manage';
    $leaveAny = 'permission:people.leave.request|people.leave.manage|people.leave.approve';
    Route::get('leave-types', [LeaveController::class, 'types'])->middleware($leaveAny);
    Route::post('leave-types', [LeaveController::class, 'saveType'])->middleware($leaveSetup);
    Route::put('leave-types/{id}', [LeaveController::class, 'saveType'])->middleware($leaveSetup);
    Route::get('holidays', [LeaveController::class, 'holidays'])->middleware($leaveAny);
    Route::post('holidays', [LeaveController::class, 'storeHoliday'])->middleware($leaveSetup);
    Route::delete('holidays/{id}', [LeaveController::class, 'deleteHoliday'])->middleware($leaveSetup);
    Route::get('leave-requests', [LeaveController::class, 'index'])->middleware($leaveAny);
    Route::post('leave-requests', [LeaveController::class, 'store'])->middleware($leaveAny);
    Route::post('leave-requests/{id}/decide', [LeaveController::class, 'decide'])->middleware('permission:people.leave.approve');
    Route::post('leave-requests/{id}/cancel', [LeaveController::class, 'cancel'])->middleware($leaveAny);
    Route::get('leave-balances', [LeaveController::class, 'balances'])->middleware($leaveAny);
    Route::get('leave-register', [LeaveController::class, 'register'])->middleware('permission:people.leave.manage|people.leave.approve');
});
