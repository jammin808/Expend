<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The password reset email, sent from the queue. On Hostinger, website requests can't start the server's
 * mail program (proc_open is disabled for the web), but the scheduled queue worker can, so every email
 * the app sends goes through the queue and arrives within a minute or so.
 */
class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
