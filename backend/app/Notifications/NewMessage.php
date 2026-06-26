<?php

namespace App\Notifications;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Str;

class NewMessage extends AppNotification
{
    public function __construct(private Conversation $conversation, private Message $message)
    {
    }

    public function type(): string
    {
        return 'new_message';
    }

    public function title(): string
    {
        return 'New message: ' . $this->conversation->subject;
    }

    public function message(): string
    {
        return Str::limit($this->message->body, 120);
    }

    public function data(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'message_id' => $this->message->id,
        ];
    }
}
