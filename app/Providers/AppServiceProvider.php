<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Conversation;
use App\Observers\CompanyObserver;
use App\Observers\CustomerObserver;
use App\Observers\MessageObserver;
use App\Observers\ConversationObserver;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use App\Mail\Transport\Smtp2GoTransport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register SMTP2GO mail transport
        Mail::extend('smtp2go', function (array $config = []) {
            return new Smtp2GoTransport($config['api_key'] ?? config('services.smtp2go.api_key'));
        });

        // Register observers
        Company::observe(CompanyObserver::class);
        Message::observe(MessageObserver::class);
        Customer::observe(CustomerObserver::class);
        Conversation::observe(ConversationObserver::class);
    }
}
