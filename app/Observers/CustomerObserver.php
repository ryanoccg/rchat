<?php

namespace App\Observers;

use App\Models\Customer;
use App\Services\Workflow\WorkflowTriggerService;
use Illuminate\Support\Facades\Log;

class CustomerObserver
{
    /**
     * Handle the Customer "created" event.
     */
    public function created(Customer $customer): void
    {
        Log::channel('ai')->info('Customer created', [
            'customer_id' => $customer->id,
            'company_id' => $customer->company_id,
            'name' => $customer->name,
        ]);

        // Trigger workflows for new customer
        try {
            app(WorkflowTriggerService::class)->onCustomerCreated($customer);
        } catch (\Throwable $e) {
            Log::error('Failed to trigger workflows for new customer', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the Customer "retrieved" event.
     * Check if this is a returning customer.
     */
    public function retrieved(Customer $customer): void
    {
        // Check if customer is returning (created more than 30 days ago)
        if ($customer->created_at && $customer->created_at->lt(now()->subDays(30))) {
            try {
                app(WorkflowTriggerService::class)->onCustomerReturning($customer);
            } catch (\Throwable $e) {
                Log::error('Failed to trigger workflows for returning customer', [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
