<?php

namespace App\Livewire\Notifications;

use App\Models\GoogleChatNotificationSettings;
use App\Models\Team;
use App\Notifications\Test;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class GoogleChat extends Component
{
    use AuthorizesRequests;

    protected $listeners = ['refresh' => '$refresh'];

    #[Locked]
    public Team $team;

    #[Locked]
    public GoogleChatNotificationSettings $settings;

    #[Validate(['boolean'])]
    public bool $googleChatEnabled = false;

    #[Validate(['url', 'nullable'])]
    public ?string $googleChatWebhookUrl = null;

    #[Validate(['boolean'])]
    public bool $deploymentSuccessGoogleChatNotifications = false;

    #[Validate(['boolean'])]
    public bool $deploymentFailureGoogleChatNotifications = true;

    #[Validate(['boolean'])]
    public bool $statusChangeGoogleChatNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupSuccessGoogleChatNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupFailureGoogleChatNotifications = true;

    #[Validate(['boolean'])]
    public bool $scheduledTaskSuccessGoogleChatNotifications = false;

    #[Validate(['boolean'])]
    public bool $scheduledTaskFailureGoogleChatNotifications = true;

    #[Validate(['boolean'])]
    public bool $dockerCleanupSuccessGoogleChatNotifications = false;

    #[Validate(['boolean'])]
    public bool $dockerCleanupFailureGoogleChatNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverDiskUsageGoogleChatNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverReachableGoogleChatNotifications = false;

    #[Validate(['boolean'])]
    public bool $serverUnreachableGoogleChatNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverPatchGoogleChatNotifications = false;

    public function mount()
    {
        try {
            $this->team = auth()->user()->currentTeam();
            $this->settings = $this->team->googleChatNotificationSettings;
            $this->authorize('view', $this->settings);
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->authorize('update', $this->settings);
            $this->settings->google_chat_enabled = $this->googleChatEnabled;
            $this->settings->google_chat_webhook_url = $this->googleChatWebhookUrl;

            $this->settings->deployment_success_google_chat_notifications = $this->deploymentSuccessGoogleChatNotifications;
            $this->settings->deployment_failure_google_chat_notifications = $this->deploymentFailureGoogleChatNotifications;
            $this->settings->status_change_google_chat_notifications = $this->statusChangeGoogleChatNotifications;
            $this->settings->backup_success_google_chat_notifications = $this->backupSuccessGoogleChatNotifications;
            $this->settings->backup_failure_google_chat_notifications = $this->backupFailureGoogleChatNotifications;
            $this->settings->scheduled_task_success_google_chat_notifications = $this->scheduledTaskSuccessGoogleChatNotifications;
            $this->settings->scheduled_task_failure_google_chat_notifications = $this->scheduledTaskFailureGoogleChatNotifications;
            $this->settings->docker_cleanup_success_google_chat_notifications = $this->dockerCleanupSuccessGoogleChatNotifications;
            $this->settings->docker_cleanup_failure_google_chat_notifications = $this->dockerCleanupFailureGoogleChatNotifications;
            $this->settings->server_disk_usage_google_chat_notifications = $this->serverDiskUsageGoogleChatNotifications;
            $this->settings->server_reachable_google_chat_notifications = $this->serverReachableGoogleChatNotifications;
            $this->settings->server_unreachable_google_chat_notifications = $this->serverUnreachableGoogleChatNotifications;
            $this->settings->server_patch_google_chat_notifications = $this->serverPatchGoogleChatNotifications;

            $this->settings->save();
            refreshSession();
        } else {
            $this->googleChatEnabled = $this->settings->google_chat_enabled;
            $this->googleChatWebhookUrl = $this->settings->google_chat_webhook_url;

            $this->deploymentSuccessGoogleChatNotifications = $this->settings->deployment_success_google_chat_notifications;
            $this->deploymentFailureGoogleChatNotifications = $this->settings->deployment_failure_google_chat_notifications;
            $this->statusChangeGoogleChatNotifications = $this->settings->status_change_google_chat_notifications;
            $this->backupSuccessGoogleChatNotifications = $this->settings->backup_success_google_chat_notifications;
            $this->backupFailureGoogleChatNotifications = $this->settings->backup_failure_google_chat_notifications;
            $this->scheduledTaskSuccessGoogleChatNotifications = $this->settings->scheduled_task_success_google_chat_notifications;
            $this->scheduledTaskFailureGoogleChatNotifications = $this->settings->scheduled_task_failure_google_chat_notifications;
            $this->dockerCleanupSuccessGoogleChatNotifications = $this->settings->docker_cleanup_success_google_chat_notifications;
            $this->dockerCleanupFailureGoogleChatNotifications = $this->settings->docker_cleanup_failure_google_chat_notifications;
            $this->serverDiskUsageGoogleChatNotifications = $this->settings->server_disk_usage_google_chat_notifications;
            $this->serverReachableGoogleChatNotifications = $this->settings->server_reachable_google_chat_notifications;
            $this->serverUnreachableGoogleChatNotifications = $this->settings->server_unreachable_google_chat_notifications;
            $this->serverPatchGoogleChatNotifications = $this->settings->server_patch_google_chat_notifications;
        }
    }

    public function instantSaveGoogleChatEnabled()
    {
        try {
            $this->validate([
                'googleChatWebhookUrl' => 'required',
            ], [
                'googleChatWebhookUrl.required' => 'Google Chat Webhook URL is required.',
            ]);
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->googleChatEnabled = false;

            return handleError($e, $this);
        } finally {
            $this->dispatch('refresh');
        }
    }

    public function instantSave()
    {
        try {
            $this->syncData(true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('refresh');
        }
    }

    public function submit()
    {
        try {
            $this->resetErrorBag();
            $this->syncData(true);
            $this->saveModel();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveModel()
    {
        $this->syncData(true);
        refreshSession();
        $this->dispatch('success', 'Settings saved.');
    }

    public function sendTestNotification()
    {
        try {
            $this->authorize('sendTest', $this->settings);
            $this->team->notify(new Test(channel: 'google_chat'));
            $this->dispatch('success', 'Test notification sent.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.notifications.google-chat');
    }
}
