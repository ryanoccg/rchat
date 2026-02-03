<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Subscription Plans
    |--------------------------------------------------------------------------
    |
    | Define the subscription plans available in the application.
    | Each plan has limits for messages, storage, team members, and platforms.
    |
    */

    'plans' => [
        'free' => [
            'name' => 'Free',
            'description' => 'Perfect for getting started',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'stripe_monthly_price_id' => env('STRIPE_FREE_MONTHLY_PRICE_ID'),
            'stripe_yearly_price_id' => env('STRIPE_FREE_YEARLY_PRICE_ID'),
            'features' => [
                'message_limit' => 500,
                'storage_limit' => 100, // MB
                'team_member_limit' => 1,
                'platform_limit' => 1,
            ],
            'highlights' => [
                '500 messages/month',
                '100 MB storage',
                '1 team member',
                '1 messaging platform',
                'Basic AI responses',
                'Email support',
            ],
        ],

        'starter' => [
            'name' => 'Starter',
            'description' => 'For small businesses',
            'monthly_price' => 29,
            'yearly_price' => 290, // ~2 months free
            'stripe_monthly_price_id' => env('STRIPE_STARTER_MONTHLY_PRICE_ID'),
            'stripe_yearly_price_id' => env('STRIPE_STARTER_YEARLY_PRICE_ID'),
            'features' => [
                'message_limit' => 5000,
                'storage_limit' => 1024, // 1 GB
                'team_member_limit' => 3,
                'platform_limit' => 2,
            ],
            'highlights' => [
                '5,000 messages/month',
                '1 GB storage',
                '3 team members',
                '2 messaging platforms',
                'AI-powered responses',
                'Knowledge base',
                'Basic analytics',
                'Priority support',
            ],
        ],

        'professional' => [
            'name' => 'Professional',
            'description' => 'For growing teams',
            'monthly_price' => 79,
            'yearly_price' => 790, // ~2 months free
            'stripe_monthly_price_id' => env('STRIPE_PROFESSIONAL_MONTHLY_PRICE_ID'),
            'stripe_yearly_price_id' => env('STRIPE_PROFESSIONAL_YEARLY_PRICE_ID'),
            'features' => [
                'message_limit' => 25000,
                'storage_limit' => 5120, // 5 GB
                'team_member_limit' => 10,
                'platform_limit' => 4,
            ],
            'highlights' => [
                '25,000 messages/month',
                '5 GB storage',
                '10 team members',
                'All 4 messaging platforms',
                'Advanced AI with context',
                'Unlimited knowledge base',
                'Full analytics & reports',
                'API access',
                '24/7 support',
            ],
        ],

        'enterprise' => [
            'name' => 'Enterprise',
            'description' => 'For large organizations',
            'monthly_price' => 199,
            'yearly_price' => 1990, // ~2 months free
            'stripe_monthly_price_id' => env('STRIPE_ENTERPRISE_MONTHLY_PRICE_ID'),
            'stripe_yearly_price_id' => env('STRIPE_ENTERPRISE_YEARLY_PRICE_ID'),
            'features' => [
                'message_limit' => null, // Unlimited
                'storage_limit' => null, // Unlimited
                'team_member_limit' => null, // Unlimited
                'platform_limit' => null, // Unlimited
            ],
            'highlights' => [
                'Unlimited messages',
                'Unlimited storage',
                'Unlimited team members',
                'All messaging platforms',
                'Custom AI training',
                'White-label option',
                'Dedicated account manager',
                'SLA guarantee',
                'Custom integrations',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trial Period
    |--------------------------------------------------------------------------
    */
    'trial_days' => 14,

    /*
    |--------------------------------------------------------------------------
    | Default Plan
    |--------------------------------------------------------------------------
    */
    'default_plan' => 'free',
];
