<?php

declare(strict_types=1);

namespace Workflow\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Workflow\Application\Approvals;
use Workflow\Infrastructure\Persistence\Eloquent\ApprovalRequest;
use Workflow\Infrastructure\Persistence\Eloquent\ApprovalRule;
use Workflow\Infrastructure\Persistence\Eloquent\InboxNotification;

final class WorkflowController
{
    // ── Rules ────────────────────────────────────────────────────

    public function rules(): JsonResponse
    {
        return new JsonResponse(['data' => ApprovalRule::query()->orderBy('document_type')->orderBy('min_amount')->get(), 'meta' => ['types' => Approvals::TYPES]]);
    }

    public function saveRule(Request $request, ?string $id = null): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', 'in:'.implode(',', array_keys(Approvals::TYPES))],
            'min_amount' => ['required', 'numeric', 'min:0'],
            'levels' => ['required', 'array', 'min:1', 'max:5'],
            'levels.*' => ['string', 'exists:roles,name'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);
        $rule = $id === null ? ApprovalRule::query()->create($data + ['status' => 'active']) : tap(ApprovalRule::query()->findOrFail($id))->update($data);

        return new JsonResponse(['data' => $rule], $id === null ? 201 : 200);
    }

    // ── Requests ─────────────────────────────────────────────────

    /** `?box=mine` (asked by me), `waiting` (my turn to decide), or everything. */
    public function requests(Request $request): JsonResponse
    {
        $box = $request->validate(['box' => ['nullable', 'in:mine,waiting,all']])['box'] ?? 'waiting';
        $user = $request->user();
        $roles = $user->getRoleNames()->all();
        $owner = in_array('tenant_owner', $roles, true);

        $rows = ApprovalRequest::query()->orderByDesc('created_at')->limit(500)
            ->when($box === 'mine', fn ($q) => $q->where('requested_by', $user->id))
            ->when($box === 'waiting', fn ($q) => $q->where('status', 'pending'))
            ->get()
            ->filter(fn (ApprovalRequest $r) => $box !== 'waiting' || $owner || in_array($r->levels[$r->current_level] ?? null, $roles, true))
            ->values();
        $names = DB::table('users')->whereIn('id', $rows->pluck('requested_by'))->pluck('name', 'id');

        return new JsonResponse(['data' => $rows->map(fn (ApprovalRequest $r): array => $r->toArray() + [
            'requested_by_name' => $names[$r->requested_by] ?? null,
            'waiting_for' => $r->status === 'pending' ? ($r->levels[$r->current_level] ?? null) : null,
            'can_decide' => $r->status === 'pending' && $r->requested_by !== $user->id && ($owner || in_array($r->levels[$r->current_level] ?? null, $roles, true)),
        ])]);
    }

    public function decide(Request $request, string $id, Approvals $approvals): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:500']]);
        if ($data['decision'] === 'reject' && empty($data['note'])) {
            throw new \DomainException('Say why it is rejected.');
        }

        return new JsonResponse(['data' => $approvals->decide($id, $data['decision'] === 'approve', $data['note'] ?? null)]);
    }

    public function cancel(string $id, Approvals $approvals): JsonResponse
    {
        return new JsonResponse(['data' => $approvals->cancel($id)]);
    }

    // ── Notifications ────────────────────────────────────────────

    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();
        $roles = $user->getRoleNames()->all();
        $rows = InboxNotification::query()
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereIn('role', $roles))
            ->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('created_at')->limit(100)->get()
            ->map(fn (InboxNotification $n): array => $n->only(['id', 'kind', 'title', 'body', 'link', 'created_at'])
                + ['read' => $n->user_id ? $n->read_at !== null : in_array($user->id, $n->read_by ?? [], true)]);

        return new JsonResponse(['data' => $rows, 'meta' => ['unread' => $rows->where('read', false)->count()]]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $ids = $request->validate(['ids' => ['present', 'array'], 'ids.*' => ['uuid']])['ids'];
        foreach (InboxNotification::query()->whereIn('id', $ids)->get() as $n) {
            if ($n->user_id === $user->id) {
                $n->update(['read_at' => now()]);
            } elseif ($n->role !== null && $user->hasRole($n->role)) {
                $n->update(['read_by' => array_values(array_unique([...($n->read_by ?? []), $user->id]))]);
            }
        }

        return new JsonResponse(['data' => ['read' => count($ids)]]);
    }
}
