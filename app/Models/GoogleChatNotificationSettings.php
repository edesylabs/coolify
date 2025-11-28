<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class GoogleChatNotificationSettings extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'google_chat_enabled',
        'google_chat_webhook_url',

        'deployment_success_google_chat_notifications',
        'deployment_failure_google_chat_notifications',
        'status_change_google_chat_notifications',
        'backup_success_google_chat_notifications',
        'backup_failure_google_chat_notifications',
        'scheduled_task_success_google_chat_notifications',
        'scheduled_task_failure_google_chat_notifications',
        'docker_cleanup_google_chat_notifications',
        'server_disk_usage_google_chat_notifications',
        'server_reachable_google_chat_notifications',
        'server_unreachable_google_chat_notifications',
        'server_patch_google_chat_notifications',
    ];

    protected $casts = [
        'google_chat_enabled' => 'boolean',
        'google_chat_webhook_url' => 'encrypted',

        'deployment_success_google_chat_notifications' => 'boolean',
        'deployment_failure_google_chat_notifications' => 'boolean',
        'status_change_google_chat_notifications' => 'boolean',
        'backup_success_google_chat_notifications' => 'boolean',
        'backup_failure_google_chat_notifications' => 'boolean',
        'scheduled_task_success_google_chat_notifications' => 'boolean',
        'scheduled_task_failure_google_chat_notifications' => 'boolean',
        'docker_cleanup_google_chat_notifications' => 'boolean',
        'server_disk_usage_google_chat_notifications' => 'boolean',
        'server_reachable_google_chat_notifications' => 'boolean',
        'server_unreachable_google_chat_notifications' => 'boolean',
        'server_patch_google_chat_notifications' => 'boolean',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isEnabled()
    {
        return $this->google_chat_enabled;
    }
}
