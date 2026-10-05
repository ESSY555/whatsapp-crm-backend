<?php

return [

    /*
    | Days a new business can use the product before it needs a plan. Trials
    | run on the plan flagged `is_trial_default` with broadcasts locked.
    */
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    /*
    | After a subscription's period ends the business keeps full access for
    | this many days ("grace") while a renewal banner is shown, then it
    | expires and outbound WhatsApp is blocked until a new receipt is approved.
    */
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 5),

    /*
    | Quota policy. `hard_block` rejects a send once the included messages for
    | the cycle are used (the v1 choice: no processor, so no way to bill
    | overage). Set to false to allow overage and flag it for manual review
    | instead; the send path reads this flag in QuotaService.
    */
    'hard_block' => (bool) env('BILLING_HARD_BLOCK', true),

    /*
    | The console-only platform administrator created by SuperAdminSeeder.
    | Set in .env; never commit real values.
    */
    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Crediping Admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

];
