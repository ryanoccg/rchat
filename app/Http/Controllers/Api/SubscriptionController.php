<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    protected SubscriptionService $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * Get available plans
     */
    public function plans()
    {
        $plans = $this->subscriptionService->getPlans();

        return response()->json([
            'data' => $plans,
        ]);
    }

    /**
     * Get current subscription
     */
    public function current(Request $request)
    {
        $company = Company::find($request->company_id);
        $subscription = $company->subscription;

        if (!$subscription) {
            return response()->json([
                'data' => null,
                'message' => 'No active subscription',
            ]);
        }

        $usage = $this->subscriptionService->getCurrentUsage($company);
        $plan = $this->subscriptionService->getPlan($subscription->plan_name);

        return response()->json([
            'data' => [
                'subscription' => $subscription,
                'plan' => $plan,
                'usage' => $usage,
                'limits' => [
                    'message_limit' => $subscription->message_limit,
                    'storage_limit' => $subscription->storage_limit,
                    'team_member_limit' => $subscription->team_member_limit,
                    'platform_limit' => $subscription->platform_limit,
                ],
            ],
        ]);
    }

    /**
     * Subscribe to a plan (for free plan or trial)
     */
    public function subscribe(Request $request)
    {
        $validated = $request->validate([
            'plan' => 'required|string|in:free,starter,professional,enterprise',
            'plan_type' => 'nullable|string|in:monthly,yearly',
        ]);

        $company = Company::find($request->company_id);
        $planName = $validated['plan'];
        $planType = $validated['plan_type'] ?? 'monthly';

        // For free plan, create subscription directly
        if ($planName === 'free') {
            $subscription = $this->subscriptionService->createSubscription($company, $planName, $planType);
            
            return response()->json([
                'data' => $subscription,
                'message' => 'Subscribed to free plan successfully',
            ]);
        }

        // For paid plans, check if Stripe is configured
        if (!config('services.stripe.secret')) {
            // Create trial subscription without Stripe
            $subscription = $this->subscriptionService->createSubscription($company, $planName, $planType);
            
            return response()->json([
                'data' => $subscription,
                'message' => 'Trial subscription created (Stripe not configured)',
            ]);
        }

        // Create Stripe checkout session
        try {
            $checkoutUrl = $this->subscriptionService->createCheckoutSession($company, $planName, $planType);
            
            return response()->json([
                'checkout_url' => $checkoutUrl,
                'message' => 'Redirect to Stripe checkout',
            ]);
        } catch (\Exception $e) {
            // Fall back to trial if Stripe fails
            $subscription = $this->subscriptionService->createSubscription($company, $planName, $planType);
            
            return response()->json([
                'data' => $subscription,
                'message' => 'Trial subscription created (Stripe checkout failed)',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create checkout session for subscription
     */
    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'plan' => 'required|string|in:starter,professional,enterprise',
            'plan_type' => 'nullable|string|in:monthly,yearly',
        ]);

        $company = Company::find($request->company_id);
        
        try {
            $checkoutUrl = $this->subscriptionService->createCheckoutSession(
                $company,
                $validated['plan'],
                $validated['plan_type'] ?? 'monthly'
            );

            return response()->json([
                'checkout_url' => $checkoutUrl,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create checkout session',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create billing portal session
     */
    public function billingPortal(Request $request)
    {
        $company = Company::find($request->company_id);
        
        try {
            $portalUrl = $this->subscriptionService->createBillingPortalSession($company);

            return response()->json([
                'portal_url' => $portalUrl,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create billing portal session',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Change subscription plan
     */
    public function changePlan(Request $request)
    {
        $validated = $request->validate([
            'plan' => 'required|string|in:free,starter,professional,enterprise',
            'plan_type' => 'nullable|string|in:monthly,yearly',
        ]);

        $company = Company::find($request->company_id);
        $subscription = $company->subscription;

        if (!$subscription) {
            return response()->json([
                'message' => 'No active subscription to change',
            ], 400);
        }

        try {
            $subscription = $this->subscriptionService->changePlan(
                $subscription,
                $validated['plan'],
                $validated['plan_type'] ?? null
            );

            return response()->json([
                'data' => $subscription,
                'message' => 'Plan changed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to change plan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel subscription
     */
    public function cancel(Request $request)
    {
        $validated = $request->validate([
            'immediately' => 'nullable|boolean',
        ]);

        $company = Company::find($request->company_id);
        $subscription = $company->subscription;

        if (!$subscription) {
            return response()->json([
                'message' => 'No active subscription to cancel',
            ], 400);
        }

        $this->subscriptionService->cancelSubscription(
            $subscription,
            $validated['immediately'] ?? false
        );

        return response()->json([
            'message' => 'Subscription cancelled successfully',
        ]);
    }

    /**
     * Resume cancelled subscription
     */
    public function resume(Request $request)
    {
        $company = Company::find($request->company_id);
        $subscription = $company->subscription;

        if (!$subscription || $subscription->status !== 'cancelling') {
            return response()->json([
                'message' => 'No subscription to resume',
            ], 400);
        }

        $subscription->update([
            'status' => 'active',
            'cancelled_at' => null,
        ]);

        // TODO: Resume Stripe subscription if exists

        return response()->json([
            'data' => $subscription,
            'message' => 'Subscription resumed successfully',
        ]);
    }

    /**
     * Get usage statistics
     */
    public function usage(Request $request)
    {
        $company = Company::find($request->company_id);
        $subscription = $company->subscription;

        $usage = $this->subscriptionService->getCurrentUsage($company);

        return response()->json([
            'data' => [
                'usage' => $usage,
                'limits' => $subscription ? [
                    'message_limit' => $subscription->message_limit,
                    'storage_limit' => $subscription->storage_limit,
                    'team_member_limit' => $subscription->team_member_limit,
                    'platform_limit' => $subscription->platform_limit,
                ] : null,
                'percentage' => $subscription ? [
                    'messages' => $subscription->message_limit 
                        ? round(($usage['messages_sent'] / $subscription->message_limit) * 100, 1) 
                        : 0,
                    'storage' => $subscription->storage_limit 
                        ? round(($usage['storage_used_mb'] / $subscription->storage_limit) * 100, 1) 
                        : 0,
                    'team_members' => $subscription->team_member_limit 
                        ? round(($usage['active_team_members'] / $subscription->team_member_limit) * 100, 1) 
                        : 0,
                    'platforms' => $subscription->platform_limit 
                        ? round(($usage['active_platforms'] / $subscription->platform_limit) * 100, 1) 
                        : 0,
                ] : null,
            ],
        ]);
    }
}
