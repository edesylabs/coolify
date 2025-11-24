<?php

namespace App\Events;

use App\Models\Application;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DomainProvisioningCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?int $teamId = null;

    public function __construct(
        public Application $application,
        public array $domains,
        public string $certificateType,
        public ?array $certificateDetails = null,
        ?int $teamId = null
    ) {
        if (is_null($teamId) && auth()->check() && auth()->user()->currentTeam()) {
            $teamId = auth()->user()->currentTeam()->id;
        } elseif (is_null($teamId)) {
            $teamId = $application->team->id ?? null;
        }
        $this->teamId = $teamId;
    }

    public function broadcastOn(): array
    {
        if (is_null($this->teamId)) {
            return [];
        }

        return [
            new PrivateChannel("team.{$this->teamId}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'application_uuid' => $this->application->uuid,
            'application_name' => $this->application->name,
            'domains' => $this->domains,
            'certificate_type' => $this->certificateType,
            'certificate_details' => $this->certificateDetails,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
