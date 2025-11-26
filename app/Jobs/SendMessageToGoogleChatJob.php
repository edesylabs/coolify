<?php

namespace App\Jobs;

use App\Notifications\Dto\GoogleChatMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendMessageToGoogleChatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private GoogleChatMessage $message,
        private string $webhookUrl
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $buttons = [];
        foreach ($this->message->buttons as $button) {
            $buttons[] = [
                'textButton' => [
                    'text' => $button['text'],
                    'onClick' => [
                        'openLink' => [
                            'url' => $button['url'],
                        ],
                    ],
                ],
            ];
        }

        $widgets = [
            [
                'textParagraph' => [
                    'text' => $this->message->description,
                ],
            ],
        ];

        if (! empty($buttons)) {
            $widgets[] = [
                'buttons' => $buttons,
            ];
        }

        $payload = [
            'cards' => [
                [
                    'header' => [
                        'title' => $this->message->title,
                        'subtitle' => 'Coolify Notification',
                        'imageUrl' => 'https://coolify.io/favicon.png',
                        'imageStyle' => 'AVATAR',
                    ],
                    'sections' => [
                        [
                            'widgets' => $widgets,
                        ],
                    ],
                ],
            ],
        ];

        Http::post($this->webhookUrl, $payload);
    }
}
