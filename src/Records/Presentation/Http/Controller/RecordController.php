<?php

declare(strict_types=1);

namespace Records\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Organization\Domain\Contract\BranchContext;
use Records\Application\RecordSchema;
use Records\Infrastructure\Persistence\Eloquent\Record;
use Records\Infrastructure\Persistence\Eloquent\RecordType;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Infrastructure\Validation\TenantRule;

/** Generic list / create / update for any record type the business has defined. */
final class RecordController
{
    public function index(string $typeKey, BranchContext $branches): JsonResponse
    {
        $type = $this->type($typeKey);
        $allowed = $branches->allowedBranchIds();

        $rows = Record::query()
            ->where('record_type_id', $type->id)
            // Records with no branch are visible to everyone who can see the type.
            ->when($allowed !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhereIn('branch_id', $allowed)))
            ->orderByDesc('created_at')
            ->limit(2000)
            ->get();

        return new JsonResponse(['data' => $rows]);
    }

    public function store(Request $request, string $typeKey, RecordSchema $schema, DocumentNumbers $numbers, TenantContext $tenant, BranchContext $branches): JsonResponse
    {
        $type = $this->type($typeKey);
        $input = $this->input($request, $type);

        $record = DB::transaction(fn (): Record => Record::query()->create([
            'record_type_id' => $type->id,
            'record_no' => $numbers->next($tenant->requireId(), 'record', $type->id, $type->number_prefix),
            'branch_id' => $branches->activeBranchId(),
            'customer_id' => $type->link_customer ? ($input['customer_id'] ?? null) : null,
            'status' => $input['status'] ?? ($type->statuses[0] ?? null),
            'data' => $schema->validate($type, $input['data'] ?? []),
            'created_by' => $request->user()->id,
        ]));

        return new JsonResponse(['data' => $record], 201);
    }

    public function update(Request $request, string $typeKey, string $recordId, RecordSchema $schema, BranchContext $branches): JsonResponse
    {
        $type = $this->type($typeKey);
        $record = Record::query()->where('record_type_id', $type->id)->findOrFail($recordId);
        abort_unless($record->branch_id === null || $branches->canAccess($record->branch_id), 404);

        $input = $this->input($request, $type);

        if (array_key_exists('data', $input)) {
            $record->data = array_merge($record->data ?? [], $schema->validate($type, $input['data'], partial: true));
        }
        if (array_key_exists('status', $input)) {
            $record->status = $input['status'];
        }
        if ($type->link_customer && array_key_exists('customer_id', $input)) {
            $record->customer_id = $input['customer_id'];
        }
        $record->save();

        return new JsonResponse(['data' => $record]);
    }

    private function type(string $typeKey): RecordType
    {
        return RecordType::query()->where('key', $typeKey)->where('status', 'active')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function input(Request $request, RecordType $type): array
    {
        return $request->validate([
            'data' => ['sometimes', 'array', 'max:80'],
            'status' => ['sometimes', 'nullable', 'string', $type->statuses === null ? 'max:60' : 'in:'.implode(',', $type->statuses)],
            'customer_id' => ['sometimes', 'nullable', 'uuid', TenantRule::exists('customers')],
        ]);
    }
}
