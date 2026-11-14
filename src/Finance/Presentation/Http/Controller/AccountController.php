<?php

declare(strict_types=1);

namespace Finance\Presentation\Http\Controller;

use Finance\Application\PostingAccounts;
use Finance\Domain\Enum\AccountType;
use Finance\Infrastructure\Persistence\Eloquent\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use Shared\Domain\Contract\AuditTrail;
use Shared\Infrastructure\Validation\TenantRule;

final class AccountController
{
    public function index(Request $request): JsonResponse
    {
        $company = $this->company($request);

        return new JsonResponse(['data' => Account::query()->where('company_id', $company->id)->orderBy('code')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $this->company($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('chart_of_accounts', 'code')->where('company_id', $company->id)],
            'name' => ['required', 'string', 'max:150'],
            'account_type' => ['required', Rule::enum(AccountType::class)],
            'parent_id' => ['nullable', 'uuid', Rule::exists('chart_of_accounts', 'id')->where('company_id', $company->id)],
            'is_control_account' => ['sometimes', 'boolean'],
        ]);

        $account = Account::query()->create($data + ['company_id' => $company->id, 'status' => 'active']);

        return new JsonResponse(['data' => $account], 201);
    }

    public function update(Request $request, string $accountId): JsonResponse
    {
        $account = Account::findOrFail($accountId);

        // Code and type stay fixed: changing them would re-classify posted history.
        $account->update($request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]));

        return new JsonResponse(['data' => $account->fresh()]);
    }

    public function postingAccounts(Request $request): JsonResponse
    {
        $company = $this->company($request);

        $mapped = DB::table('posting_accounts')->where('company_id', $company->id)->pluck('account_id', 'key');

        return new JsonResponse(['data' => collect(PostingAccounts::KEYS)
            ->map(static fn (string $key): array => ['key' => $key, 'account_id' => $mapped[$key] ?? null])]);
    }

    public function mapPostingAccount(Request $request, AuditTrail $audit): JsonResponse
    {
        $company = $this->company($request);

        $data = $request->validate([
            'key' => ['required', Rule::in(PostingAccounts::KEYS)],
            'account_id' => ['required', 'uuid', Rule::exists('chart_of_accounts', 'id')->where('company_id', $company->id)],
        ]);

        DB::transaction(function () use ($company, $data, $audit): void {
            $before = DB::table('posting_accounts')->where('company_id', $company->id)->where('key', $data['key'])->value('account_id');

            DB::table('posting_accounts')->updateOrInsert(
                ['company_id' => $company->id, 'key' => $data['key']],
                ['tenant_id' => $company->tenant_id, 'account_id' => $data['account_id']],
            );

            $audit->record('finance.posting_account.mapped', 'company', $company->id,
                ['key' => $data['key'], 'account_id' => $before], $data);
        });

        return new JsonResponse(['data' => $data]);
    }

    private function company(Request $request): Company
    {
        $id = $request->validate(['company_id' => ['required', 'uuid', TenantRule::exists('companies')]])['company_id'];

        return Company::findOrFail($id);
    }
}
