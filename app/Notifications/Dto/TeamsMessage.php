<?php

namespace App\Notifications\Dto;

class TeamsMessage
{
    public function __construct(
        public string $title,
        public string $description,
        public string $color = '0099ff',
        public array $buttons = []
    ) {}

    public static function infoColor(): string
    {
        return '0099ff';
    }

    public static function errorColor(): string
    {
        return 'ff0000';
    }

    public static function successColor(): string
    {
        return '00ff00';
    }

    public static function warningColor(): string
    {
        return 'ffa500';
    }

    public function addButton(string $text, string $url): self
    {
        $this->buttons[] = [
            'text' => $text,
            'url' => $url,
        ];

        return $this;
    }
}
