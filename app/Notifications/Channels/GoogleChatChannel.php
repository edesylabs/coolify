<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToGoogleChatJob;
use Illuminate\Notifications\Notification;

class GoogleChatChannel
{
    /**
     * Send the given notification.
     */
    public function send(SendsGoogleChat $notifiable, Notification $notification): void
    {
        $message = $notification->toGoogleChat();
        $googleChatSettings = $notifiable->googleChatNotificationSettings;

        if (! $googleChatSettings || ! $googleChatSettings->isEnabled() || ! $googleChatSettings->google_chat_webhook_url) {
            return;
        }

        SendMessageToGoogleChatJob::dispatch($message, $googleChatSettings->google_chat_webhook_url);
    }
}
