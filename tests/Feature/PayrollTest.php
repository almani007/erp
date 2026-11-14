<?php

declare(strict_types=1);

namespace Tests\Feature;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** HR and payroll against the demo staff. Needs `erp_test` (see SaleFlowTest). */
final class PayrollTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_payroll_uses_attendance_and_advances_and_posts_balanced_journals(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'admin@brixelltd.com')->firstOrFail());
        $period = now()->format('Y-m');
        $employee = fn (string $code) => DB::table('employees')->where('code', $code)->first();

        // Two days absent for the HO cashier, three days worked and 4 hours overtime for the loader.
        $first = now()->startOfMonth();
        $this->postJson('/api/v1/attendance', ['date' => $first->toDateString(), 'rows' => [
            ['employee_id' => $employee('EMP-002')->id, 'status' => 'absent'],
            ['employee_id' => $employee('EMP-004')->id, 'status' => 'present', 'overtime_hours' => 4],
        ]])->assertOk();
        $this->postJson('/api/v1/attendance', ['date' => $first->copy()->addDay()->toDateString(), 'rows' => [
            ['employee_id' => $employee('EMP-002')->id, 'status' => 'absent'],
            ['employee_id' => $employee('EMP-004')->id, 'status' => 'present'],
        ]])->assertOk();

        $run = $this->postJson('/api/v1/payroll-runs', ['company_id' => DB::table('companies')->value('id'), 'period' => $period])
            ->assertCreated()->json('data');
        $line = collect($run['lines'])->firstWhere('employee_id', $employee('EMP-002')->id);

        // 42,000 + 3,000; two days at 45,000 / 30 = 3,000 off → gross 42,000; EOBI 370, advance 5,000 → 36,630.
        $this->assertSame('3000.0000', $line['absence_deduction']);
        $this->assertSame('42000.0000', $line['gross']);
        $this->assertSame('5000.0000', $line['advance_recovery']);
        $this->assertSame('36630.0000', $line['net']);

        // Loader: 2 days × 1,600 + 4 h × (1,600 / 8) = 4,000.
        $this->assertSame('4000.0000', collect($run['lines'])->firstWhere('employee_id', $employee('EMP-004')->id)['gross']);

        // A one-off bonus is a line adjustment.
        $this->putJson("/api/v1/payroll-runs/{$run['id']}/lines/{$line['id']}", ['bonus' => 2000])->assertOk();

        $approved = $this->postJson("/api/v1/payroll-runs/{$run['id']}/approve")->assertOk()->json('data');
        $totals = DB::table('journal_lines')->where('journal_entry_id', $approved['journal_entry_id'])->selectRaw('sum(debit) d, sum(credit) c')->first();
        $this->assertSame($totals->d, $totals->c);
        $this->assertEquals(5000, DB::table('employee_advances')->where('employee_id', $employee('EMP-002')->id)->value('recovered'));

        $this->postJson("/api/v1/payroll-runs/{$run['id']}/pay", ['method' => 'bank', 'date' => now()->toDateString()], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.status', 'paid');

        // Same period again is refused.
        $this->postJson('/api/v1/payroll-runs', ['company_id' => DB::table('companies')->value('id'), 'period' => $period])->assertStatus(422);
    }
}
