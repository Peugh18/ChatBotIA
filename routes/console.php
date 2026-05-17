<?php

use App\Jobs\AbandonedCartFollowUpJob;
use App\Jobs\ConfirmationFollowUpJob;
use App\Jobs\PaymentReminderJob;
use App\Jobs\PostSaleFollowUpJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── Roma CRM Automation Jobs ─────────────────────────────────────────────────

// Run every minute: re-engage clients who didn't reply to order confirmation
Schedule::job(new ConfirmationFollowUpJob)->everyMinute();

// Run every 2 hours: recover clients who abandoned their cart
Schedule::job(new AbandonedCartFollowUpJob)->everyTwoHours();

// Run every hour: remind clients waiting to pay
Schedule::job(new PaymentReminderJob)->hourly();

// Run every 6 hours: post-sale satisfaction messages for delivered orders
Schedule::job(new PostSaleFollowUpJob)->everySixHours();
