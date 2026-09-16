<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Plan;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends ApiController
{
    public function __construct(private BillingService $billing)
    {
    }

    public function index()
    {
        $plans = Plan::query()->withCount(['subscriptions as active_subscriptions_count' => fn ($q) => $q->current()])
            ->orderBy('sort_order')->orderBy('price')->get()
            ->map(fn (Plan $plan) => $this->billing->presentPlan($plan) + ['active_subscriptions_count' => $plan->active_subscriptions_count]);

        return $this->successResponse('Plans retrieved successfully.', $plans->values());
    }

    public function store(Request $request)
    {
        $plan = Plan::create($this->validated($request));
        if ($plan->is_trial_default) {
            Plan::query()->whereKeyNot($plan->id)->update(['is_trial_default' => false]);
        }

        return $this->successResponse('Plan created successfully.', $this->billing->presentPlan($plan), 201);
    }

    public function update(Request $request, int $plan)
    {
        $record = Plan::query()->findOrFail($plan);
        $record->update($this->validated($request, $record));
        if ($record->is_trial_default) {
            Plan::query()->whereKeyNot($record->id)->update(['is_trial_default' => false]);
        }

        return $this->successResponse('Plan updated successfully.', $this->billing->presentPlan($record->fresh()));
    }

    /**
     * Plans with history are deactivated rather than deleted so past
     * subscriptions and payment requests keep their reference.
     */
    public function destroy(int $plan)
    {
        $record = Plan::query()->findOrFail($plan);

        if ($record->subscriptions()->exists() || $record->paymentRequestsExist()) {
            $record->update(['is_active' => false]);
            return $this->successResponse('Plan has existing subscriptions and was deactivated instead of deleted.', $this->billing->presentPlan($record->fresh()));
        }

        $record->delete();

        return $this->successResponse('Plan deleted successfully.');
    }

    private function validated(Request $request, ?Plan $existing = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::unique('plans', 'code')->ignore($existing?->id)],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'interval_days' => ['required', 'integer', 'min:1', 'max:730'],
            'utility_quota' => ['nullable', 'integer', 'min:0'],
            'marketing_quota' => ['nullable', 'integer', 'min:0'],
            'allows_broadcasts' => ['required', 'boolean'],
            'max_team_members' => ['nullable', 'integer', 'min:1'],
            'is_trial_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }
}
