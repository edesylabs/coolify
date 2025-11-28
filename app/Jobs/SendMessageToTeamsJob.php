<?php

namespace App\Jobs;

use App\Notifications\Dto\TeamsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendMessageToTeamsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private TeamsMessage $message,
        private string $webhookUrl
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $actions = [];
        foreach ($this->message->buttons as $button) {
            $actions[] = [
                'type' => 'Action.OpenUrl',
                'title' => $button['text'],
                'url' => $button['url'],
            ];
        }

        $payload = [
            'type' => 'message',
            'attachments' => [
                [
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => [
                            [
                                'type' => 'Container',
                                'style' => 'emphasis',
                                'items' => [
                                    [
                                        'type' => 'TextBlock',
                                        'text' => $this->message->title,
                                        'weight' => 'bolder',
                                        'size' => 'large',
                                        'wrap' => true,
                                        'color' => $this->getTextColor(),
                                    ],
                                ],
                            ],
                            [
                                'type' => 'Container',
                                'items' => [
                                    [
                                        'type' => 'TextBlock',
                                        'text' => $this->message->description,
                                        'wrap' => true,
                                    ],
                                ],
                            ],
                        ],
                        'actions' => $actions,
                    ],
                ],
            ],
        ];

        Http::post($this->webhookUrl, $payload);
    }

    private function getTextColor(): string
    {
        return match ($this->message->color) {
            TeamsMessage::successColor() => 'good',
            TeamsMessage::errorColor() => 'attention',
            TeamsMessage::warningColor() => 'warning',
            default => 'default',
        };
    }
}
