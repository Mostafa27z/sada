<?php

namespace App\Payments\Contracts;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;

interface PaymentProviderInterface
{
    /**
     * Create a new subscription for a tenant.
     */
    public function createSubscription(Tenant $tenant, Plan $plan, array $options = []): Subscription;

    /**
     * Cancel an active subscription.
     */
    public function cancelSubscription(Subscription $subscription): bool;

    /**
     * Resume a cancelled subscription.
     */
    public function resumeSubscription(Subscription $subscription): bool;

    /**
     * Swap subscription to a new plan.
     */
    public function swapPlan(Subscription $subscription, Plan $newPlan): bool;
}
