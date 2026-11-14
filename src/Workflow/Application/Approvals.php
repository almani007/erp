<?php

declare(strict_types=1);

namespace Workflow\Application;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Identity\Infrastructure\Provider\IdentityServiceProvider;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Support\Decimal;
use Workflow\Infrastructure\Persistence\Eloquent\ApprovalRequest;
use Workflow\Infrastructure\Persistence\Eloquent\ApprovalRule;

/**
 * Configurable approvals. An action that a rule covers (by document type
 * and amount) is not carried out at once: it is saved exactly as requested
 * and goes through the rule's levels — each approved by someone holding
 * that level's role who is not the requester. After the last approval the
 * action runs, as the person who asked for it.
 *
 * Callers do:  if ($pending = $approvals->gate('supplier_payment', $amount, $summary, $payload)) return 202 with it;
 */
final class Approvals
{
    /** Human names for the document types that can be put under approval. */
    public const array TYPES = [
        'supplier_payment' => 'Supplier payment',
        'stock_adjustment' => 'Stock adjustment (by value)',
        'sales_invoice' => 'Invoice from a sales order',
        'employee_advance' => 'Employee advance or loan',
    ];

    public function __construct(
        private readonly ApprovalHandlers $handlers,
        private readonly Notifier $notifier,
        private readonly AuditTrail $audit,
        private readonly AuthFactory $auth,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public function gate(string $type, string $amount, string $summary, array $payload, ?string $branchId = null): ?ApprovalRequest
    {
        $rule = ApprovalRule::query()->where('document_type', $type)->where('status', 'active')
            ->where('min_amount', '<=', Decimal::round($amount, 4))->orderByDesc('min_amount')->first();
        if ($rule === null || $rule->levels === []) {
            return null;
        }

        $request = ApprovalRequest::query()->create([
            'rule_id' => $rule->id, 'document_type' => $type, 'summary' => mb_substr($summary, 0, 300), 'amount' => Decimal::round($amount, 4),
            'payload' => $payload, 'branch_id' => $branchId, 'requested_by' => $this->userId(), 'current_level' => 0,
            'levels' => $rule->levels, 'history' => [], 'status' => 'pending',
        ]);
        $this->notifyLevel($request);
        $this->audit->record('workflow.approval.requested', 'approval_request', $request->id, null, ['type' => $type, 'amount' => $amount]);

        return $request;
    }

    public function decide(string $requestId, bool $approve, ?string $note): ApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $approve, $note): ApprovalRequest {
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($requestId);
            if ($request->status !== 'pending') {
                throw new \DomainException("This request is already {$request->status}.");
            }

            /** @var User $user */
            $user = $this->auth->guard('sanctum')->user();
            $role = $request->levels[$request->current_level];
            $owner = $user->hasRole(IdentityServiceProvider::OWNER_ROLE);
            if (! $owner && ! $user->hasRole($role)) {
                throw new \DomainException('This step needs someone with the '.str_replace('_', ' ', $role).' role.');
            }
            if ($request->requested_by === $user->id && ! $owner) {
                throw new \DomainException('You cannot approve your own request.');
            }

            $history = $request->history ?? [];
            $history[] = ['level' => $request->current_level + 1, 'role' => $role, 'user_id' => $user->id, 'user' => $user->name,
                'decision' => $approve ? 'approved' : 'rejected', 'note' => $note, 'at' => now()->toIso8601String()];

            if (! $approve) {
                $request->update(['history' => $history, 'status' => 'rejected']);
                $this->notifier->toUser($request->requested_by, 'decision', "Rejected: {$request->summary}", $note, '/approvals');
                $this->audit->record('workflow.approval.rejected', 'approval_request', $request->id, null, ['level' => $request->current_level + 1], $note);

                return $request;
            }

            if ($request->current_level + 1 < count($request->levels)) {
                $request->update(['history' => $history, 'current_level' => $request->current_level + 1]);
                $this->notifyLevel($request);

                return $request;
            }

            // Last level: run the action, as it was asked for. A failure (stock gone, period closed) is kept on the request.
            $request->update(['history' => $history]);
            try {
                $result = DB::transaction(fn (): array => $this->handlers->run($request->document_type, $request->payload, $request->requested_by));
                $request->update(['status' => 'executed', 'result_type' => $result['type'], 'result_id' => $result['id'], 'result_label' => $result['label']]);
                $this->notifier->toUser($request->requested_by, 'decision', "Approved and done: {$request->summary}", $result['label'], '/approvals');
            } catch (\Throwable $e) {
                $request->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
                $this->notifier->toUser($request->requested_by, 'decision', "Approved but could not be done: {$request->summary}", $e->getMessage(), '/approvals');
            }
            $this->audit->record('workflow.approval.completed', 'approval_request', $request->id, null, ['status' => $request->status]);

            return $request;
        });
    }

    public function cancel(string $requestId): ApprovalRequest
    {
        $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($requestId);
        if ($request->status !== 'pending' || $request->requested_by !== $this->userId()) {
            throw new \DomainException('Only the requester can withdraw a pending request.');
        }
        $request->update(['status' => 'cancelled']);

        return $request;
    }

    private function notifyLevel(ApprovalRequest $request): void
    {
        $level = $request->current_level + 1;
        $this->notifier->toRole($request->levels[$request->current_level], 'approval',
            "Approval needed ({$level}/".count($request->levels)."): {$request->summary}", 'Amount '.number_format((float) $request->amount, 2), '/approvals');
    }

    private function userId(): string
    {
        return (string) $this->auth->guard('sanctum')->id();
    }
}
