<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController extends Controller
{
    protected SubscriptionService $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * Handle Stripe webhook events
     */
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        // Verify webhook signature if secret is configured
        if ($webhookSecret) {
            try {
                $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
            } catch (\UnexpectedValueException $e) {
                Log::error('Stripe webhook: Invalid payload', ['error' => $e->getMessage()]);
                return response()->json(['error' => 'Invalid payload'], 400);
            } catch (SignatureVerificationException $e) {
                Log::error('Stripe webhook: Signature verification failed', ['error' => $e->getMessage()]);
                return response()->json(['error' => 'Invalid signature'], 400);
            }
        } else {
            // No signature verification in development
            $event = json_decode($payload, true);
        }

        $eventType = is_array($event) ? $event['type'] : $event->type;
        $eventData = is_array($event) ? $event : $event->toArray();

        Log::info('Stripe webhook received', ['type' => $eventType]);

        try {
            match ($eventType) {
                'customer.subscription.created' => $this->subscriptionService->handleStripeSubscriptionCreated($eventData),
                'customer.subscription.updated' => $this->subscriptionService->handleStripeSubscriptionUpdated($eventData),
                'customer.subscription.deleted' => $this->subscriptionService->handleStripeSubscriptionDeleted($eventData),
                'invoice.payment_succeeded' => $this->handleInvoicePaymentSucceeded($eventData),
                'invoice.payment_failed' => $this->handleInvoicePaymentFailed($eventData),
                'checkout.session.completed' => $this->handleCheckoutSessionCompleted($eventData),
                default => Log::info("Unhandled Stripe event: {$eventType}"),
            };
        } catch (\Exception $e) {
            Log::error('Stripe webhook processing error', [
                'type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Webhook processing failed'], 500);
        }

        return response()->json(['received' => true]);
    }

    /**
     * Handle successful invoice payment
     */
    protected function handleInvoicePaymentSucceeded(array $payload): void
    {
        $invoice = $payload['data']['object'];
        $subscriptionId = $invoice['subscription'] ?? null;

        if ($subscriptionId) {
            Log::info('Invoice payment succeeded', ['subscription_id' => $subscriptionId]);
            // Additional logic like sending receipts can be added here
        }
    }

    /**
     * Handle failed invoice payment
     */
    protected function handleInvoicePaymentFailed(array $payload): void
    {
        $invoice = $payload['data']['object'];
        $subscriptionId = $invoice['subscription'] ?? null;

        if ($subscriptionId) {
            Log::warning('Invoice payment failed', ['subscription_id' => $subscriptionId]);
            // TODO: Send email notification to customer
        }
    }

    /**
     * Handle completed checkout session
     */
    protected function handleCheckoutSessionCompleted(array $payload): void
    {
        $session = $payload['data']['object'];
        
        Log::info('Checkout session completed', [
            'session_id' => $session['id'],
            'customer' => $session['customer'],
            'subscription' => $session['subscription'] ?? null,
        ]);

        // The subscription.created event will handle the actual subscription creation
    }
}
