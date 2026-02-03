<?php

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\UsageTracking;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Customer as StripeCustomer;
use Stripe\Subscription as StripeSubscription;
use Stripe\BillingPortal\Session as PortalSession;
use Stripe\Checkout\Session as CheckoutSession;

class SubscriptionService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Get available plans
     */
    public function getPlans(): array
    {
        return config('plans.plans', []);
    }

    /**
     * Get plan details by name
     */
    public function getPlan(string $planName): ?array
    {
        return config("plans.plans.{$planName}");
    }

    /**
     * Create or update subscription for a company
     */
    public function createSubscription(Company $company, string $planName, string $planType = 'monthly'): Subscription
    {
        $plan = $this->getPlan($planName);
        
        if (!$plan) {
            throw new \InvalidArgumentException("Invalid plan: {$planName}");
        }

        $price = $planType === 'yearly' ? $plan['yearly_price'] : $plan['monthly_price'];

        // Cancel existing subscription if any
        $existingSubscription = $company->subscription;
        if ($existingSubscription && $existingSubscription->isActive()) {
            $this->cancelSubscription($existingSubscription);
        }

        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_name' => $planName,
            'plan_type' => $planType,
            'status' => $planName === 'free' ? 'active' : 'trial',
            'message_limit' => $plan['features']['message_limit'],
            'storage_limit' => $plan['features']['storage_limit'],
            'team_member_limit' => $plan['features']['team_member_limit'],
            'platform_limit' => $plan['features']['platform_limit'],
            'price' => $price,
            'trial_ends_at' => $planName !== 'free' ? Carbon::now()->addDays(config('plans.trial_days', 14)) : null,
            'current_period_start' => now(),
            'current_period_end' => $planType === 'yearly' ? now()->addYear() : now()->addMonth(),
        ]);

        return $subscription;
    }

    /**
     * Create a Stripe checkout session for subscription
     */
    public function createCheckoutSession(Company $company, string $planName, string $planType = 'monthly'): string
    {
        $plan = $this->getPlan($planName);
        
        if (!$plan) {
            throw new \InvalidArgumentException("Invalid plan: {$planName}");
        }

        $priceId = $planType === 'yearly' 
            ? $plan['stripe_yearly_price_id'] 
            : $plan['stripe_monthly_price_id'];

        if (!$priceId) {
            throw new \RuntimeException("Stripe price ID not configured for plan: {$planName}");
        }

        // Get or create Stripe customer
        $stripeCustomerId = $this->getOrCreateStripeCustomer($company);

        $session = CheckoutSession::create([
            'customer' => $stripeCustomerId,
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'success_url' => config('app.url') . '/settings/billing?success=true',
            'cancel_url' => config('app.url') . '/settings/billing?canceled=true',
            'metadata' => [
                'company_id' => $company->id,
                'plan_name' => $planName,
                'plan_type' => $planType,
            ],
        ]);

        return $session->url;
    }

    /**
     * Create billing portal session
     */
    public function createBillingPortalSession(Company $company): string
    {
        $stripeCustomerId = $company->subscription?->stripe_customer_id;

        if (!$stripeCustomerId) {
            $stripeCustomerId = $this->getOrCreateStripeCustomer($company);
        }

        $session = PortalSession::create([
            'customer' => $stripeCustomerId,
            'return_url' => config('app.url') . '/settings/billing',
        ]);

        return $session->url;
    }

    /**
     * Get or create Stripe customer
     */
    protected function getOrCreateStripeCustomer(Company $company): string
    {
        $subscription = $company->subscription;
        
        if ($subscription?->stripe_customer_id) {
            return $subscription->stripe_customer_id;
        }

        $owner = $company->users()->first();

        $customer = StripeCustomer::create([
            'email' => $owner?->email,
            'name' => $company->name,
            'metadata' => [
                'company_id' => $company->id,
            ],
        ]);

        // Save to subscription if exists
        if ($subscription) {
            $subscription->update(['stripe_customer_id' => $customer->id]);
        }

        return $customer->id;
    }

    /**
     * Handle successful subscription from Stripe webhook
     */
    public function handleStripeSubscriptionCreated(array $payload): void
    {
        $stripeSubscription = $payload['data']['object'];
        $metadata = $stripeSubscription['metadata'] ?? [];
        
        $companyId = $metadata['company_id'] ?? null;
        $planName = $metadata['plan_name'] ?? 'starter';
        $planType = $metadata['plan_type'] ?? 'monthly';

        if (!$companyId) {
            Log::warning('Stripe subscription created without company_id metadata', $payload);
            return;
        }

        $company = Company::find($companyId);
        if (!$company) {
            Log::warning('Company not found for Stripe subscription', ['company_id' => $companyId]);
            return;
        }

        $plan = $this->getPlan($planName);
        $price = $planType === 'yearly' ? $plan['yearly_price'] : $plan['monthly_price'];

        $subscription = $company->subscription;
        
        if ($subscription) {
            $subscription->update([
                'stripe_subscription_id' => $stripeSubscription['id'],
                'stripe_customer_id' => $stripeSubscription['customer'],
                'status' => 'active',
                'plan_name' => $planName,
                'plan_type' => $planType,
                'price' => $price,
                'message_limit' => $plan['features']['message_limit'],
                'storage_limit' => $plan['features']['storage_limit'],
                'team_member_limit' => $plan['features']['team_member_limit'],
                'platform_limit' => $plan['features']['platform_limit'],
                'current_period_start' => Carbon::createFromTimestamp($stripeSubscription['current_period_start']),
                'current_period_end' => Carbon::createFromTimestamp($stripeSubscription['current_period_end']),
                'trial_ends_at' => null,
            ]);
        } else {
            Subscription::create([
                'company_id' => $companyId,
                'stripe_subscription_id' => $stripeSubscription['id'],
                'stripe_customer_id' => $stripeSubscription['customer'],
                'status' => 'active',
                'plan_name' => $planName,
                'plan_type' => $planType,
                'price' => $price,
                'message_limit' => $plan['features']['message_limit'],
                'storage_limit' => $plan['features']['storage_limit'],
                'team_member_limit' => $plan['features']['team_member_limit'],
                'platform_limit' => $plan['features']['platform_limit'],
                'current_period_start' => Carbon::createFromTimestamp($stripeSubscription['current_period_start']),
                'current_period_end' => Carbon::createFromTimestamp($stripeSubscription['current_period_end']),
            ]);
        }
    }

    /**
     * Handle subscription updated from Stripe webhook
     */
    public function handleStripeSubscriptionUpdated(array $payload): void
    {
        $stripeSubscription = $payload['data']['object'];
        
        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription['id'])->first();
        
        if (!$subscription) {
            Log::warning('Subscription not found for Stripe update', ['id' => $stripeSubscription['id']]);
            return;
        }

        $status = match ($stripeSubscription['status']) {
            'active' => 'active',
            'past_due' => 'past_due',
            'canceled' => 'cancelled',
            'unpaid' => 'unpaid',
            default => $stripeSubscription['status'],
        };

        $subscription->update([
            'status' => $status,
            'current_period_start' => Carbon::createFromTimestamp($stripeSubscription['current_period_start']),
            'current_period_end' => Carbon::createFromTimestamp($stripeSubscription['current_period_end']),
        ]);
    }

    /**
     * Handle subscription cancelled from Stripe webhook
     */
    public function handleStripeSubscriptionDeleted(array $payload): void
    {
        $stripeSubscription = $payload['data']['object'];
        
        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription['id'])->first();
        
        if ($subscription) {
            $subscription->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
        }
    }

    /**
     * Cancel a subscription
     */
    public function cancelSubscription(Subscription $subscription, bool $immediately = false): void
    {
        if ($subscription->stripe_subscription_id) {
            try {
                $stripeSubscription = StripeSubscription::retrieve($subscription->stripe_subscription_id);
                
                if ($immediately) {
                    $stripeSubscription->cancel();
                } else {
                    $stripeSubscription->update($subscription->stripe_subscription_id, [
                        'cancel_at_period_end' => true,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to cancel Stripe subscription', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $subscription->update([
            'status' => $immediately ? 'cancelled' : 'cancelling',
            'cancelled_at' => $immediately ? now() : null,
        ]);
    }

    /**
     * Change subscription plan
     */
    public function changePlan(Subscription $subscription, string $newPlanName, string $planType = null): Subscription
    {
        $plan = $this->getPlan($newPlanName);
        
        if (!$plan) {
            throw new \InvalidArgumentException("Invalid plan: {$newPlanName}");
        }

        $planType = $planType ?? $subscription->plan_type;
        $price = $planType === 'yearly' ? $plan['yearly_price'] : $plan['monthly_price'];

        // Update Stripe subscription if exists
        if ($subscription->stripe_subscription_id) {
            $priceId = $planType === 'yearly' 
                ? $plan['stripe_yearly_price_id'] 
                : $plan['stripe_monthly_price_id'];

            if ($priceId) {
                try {
                    $stripeSubscription = StripeSubscription::retrieve($subscription->stripe_subscription_id);
                    
                    StripeSubscription::update($subscription->stripe_subscription_id, [
                        'items' => [[
                            'id' => $stripeSubscription->items->data[0]->id,
                            'price' => $priceId,
                        ]],
                        'proration_behavior' => 'create_prorations',
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to update Stripe subscription', [
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $subscription->update([
            'plan_name' => $newPlanName,
            'plan_type' => $planType,
            'price' => $price,
            'message_limit' => $plan['features']['message_limit'],
            'storage_limit' => $plan['features']['storage_limit'],
            'team_member_limit' => $plan['features']['team_member_limit'],
            'platform_limit' => $plan['features']['platform_limit'],
        ]);

        return $subscription->fresh();
    }

    /**
     * Get current usage for a company
     */
    public function getCurrentUsage(Company $company): array
    {
        $startOfMonth = now()->startOfMonth();
        
        // Sum up all usage for the month
        $usage = UsageTracking::where('company_id', $company->id)
            ->where('period_date', '>=', $startOfMonth)
            ->selectRaw('SUM(message_count) as total_messages, SUM(storage_used) as total_storage')
            ->first();

        return [
            'messages_sent' => (int) ($usage?->total_messages ?? 0),
            'storage_used_mb' => (int) (($usage?->total_storage ?? 0) / (1024 * 1024)), // Convert bytes to MB
            'active_team_members' => $company->users()->count(),
            'active_platforms' => $company->platformConnections()->where('is_active', true)->count(),
        ];
    }

    /**
     * Check if company can perform action based on subscription limits
     */
    public function canSendMessage(Company $company): bool
    {
        $subscription = $company->subscription;
        
        if (!$subscription || !$subscription->isActive()) {
            return false;
        }

        $usage = $this->getCurrentUsage($company);
        return !$subscription->hasReachedMessageLimit($usage['messages_sent']);
    }

    /**
     * Check if company can add team member
     */
    public function canAddTeamMember(Company $company): bool
    {
        $subscription = $company->subscription;
        
        if (!$subscription || !$subscription->isActive()) {
            return false;
        }

        $usage = $this->getCurrentUsage($company);
        return !$subscription->hasReachedTeamLimit($usage['active_team_members']);
    }

    /**
     * Check if company can add platform
     */
    public function canAddPlatform(Company $company): bool
    {
        $subscription = $company->subscription;
        
        if (!$subscription || !$subscription->isActive()) {
            return false;
        }

        $usage = $this->getCurrentUsage($company);
        return !$subscription->hasReachedPlatformLimit($usage['active_platforms']);
    }

    /**
     * Increment message usage
     */
    public function incrementMessageUsage(Company $company): void
    {
        $today = now()->toDateString();
        
        UsageTracking::updateOrCreate(
            [
                'company_id' => $company->id,
                'period_date' => $today,
            ],
            [
                'message_count' => \DB::raw('COALESCE(message_count, 0) + 1'),
            ]
        );
    }
}
