<?php

namespace App\Payments\Providers;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Payments\Contracts\PaymentProviderInterface;
use App\Support\TenantContext;

class NullPaymentProvider implements PaymentProviderInterface
{
    public function createSubscription(Tenant $tenant, Plan $plan, array $options = []): Subscription
    {
        return TenantContext::withoutTenancy(function () use ($tenant, $plan) {
            return Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'provider' => 'manual',
                'status' => Subscription::STATUS_ACTIVE,
                'starts_at' => now(),
                'ends_at' => now()->addMonth(),
            ]);
        });
    }

    public function cancelSubscription(Subscription $subscription): bool
    {
        return $subscription->update([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    public function resumeSubscription(Subscription $subscription): bool
    {
        return $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'cancelled_at' => null,
        ]);
    }

    public function swapPlan(Subscription $subscription, Plan $newPlan): bool
    {
        return $subscription->update([
            'plan_id' => $newPlan->id,
        ]);
    }
}
