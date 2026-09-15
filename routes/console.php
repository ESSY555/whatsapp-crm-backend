<?php

use Illuminate\Support\Facades\Schedule;

// Scheduled messages and scheduled broadcasts are claimed every minute and
// handed to the queue.
Schedule::command('messages:dispatch-due')->everyMinute()->withoutOverlapping();

// Invoices roll to "overdue" shortly after midnight, before reminders run.
Schedule::command('invoices:mark-overdue')->dailyAt('00:05')->withoutOverlapping();

// Automated payment reminders go out once a day per business.
Schedule::command('reminders:process')->dailyAt('09:00')->withoutOverlapping();
