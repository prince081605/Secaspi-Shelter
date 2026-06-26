<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * In-app messaging between a member and the shelter's staff pool. A member can only see their
 * own conversations; staff/admin (adminIndex) see every conversation and can reply to any.
 * New messages notify the other party through the existing AppNotification bell + email.
 */
class MessageController extends Controller
{
    /** A member's own conversations, newest activity first. */
    public function index(Request $request)
    {
        $me = $request->user();

        $conversations = Conversation::where('user_id', $me->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Conversation $c) => $this->listItem($c, $me));

        return response()->json(['conversations' => $conversations]);
    }

    /** Every conversation, for the staff inbox. */
    public function adminIndex(Request $request)
    {
        $me = $request->user();

        $conversations = Conversation::with('user')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Conversation $c) => $this->listItem($c, $me));

        return response()->json(['conversations' => $conversations]);
    }

    /** Member starts a conversation with an opening message. */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $me = $request->user();

        $conversation = Conversation::create([
            'user_id' => $me->id,
            'subject' => $request->input('subject'),
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $message = $conversation->messages()->create([
            'sender_id' => $me->id,
            'body' => $request->input('body'),
        ]);

        $this->notifyOtherParty($conversation, $me, $message);

        return response()->json(['conversation' => $this->thread($conversation->fresh(), $me)], 201);
    }

    /** Full thread. Marks the other side's messages read for the viewer. */
    public function show(Request $request, Conversation $conversation)
    {
        $me = $request->user();
        if (! $this->canAccess($me, $conversation)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        Message::where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $me->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['conversation' => $this->thread($conversation, $me)]);
    }

    /** Add a reply (member or staff) and notify the other party. */
    public function reply(Request $request, Conversation $conversation)
    {
        $me = $request->user();
        if (! $this->canAccess($me, $conversation)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'body' => ['required', 'string', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $message = $conversation->messages()->create([
            'sender_id' => $me->id,
            'body' => $request->input('body'),
        ]);

        $conversation->update(['last_message_at' => now()]);
        $this->notifyOtherParty($conversation, $me, $message);

        return response()->json(['conversation' => $this->thread($conversation->fresh(), $me)]);
    }

    private function canAccess(User $user, Conversation $conversation): bool
    {
        return $user->hasRoleAtLeast('staff') || $conversation->user_id === $user->id;
    }

    /**
     * Notify whoever didn't send this message: if the member sent it, ping the staff pool;
     * if staff sent it, ping the member who owns the conversation.
     */
    private function notifyOtherParty(Conversation $conversation, User $sender, Message $message): void
    {
        if ($sender->id === $conversation->user_id) {
            $recipients = User::whereIn('role', ['staff', 'admin'])->where('id', '!=', $sender->id)->get();
        } else {
            $recipients = User::where('id', $conversation->user_id)->get();
        }

        foreach ($recipients as $recipient) {
            (new NewMessage($conversation, $message))->sendTo($recipient);
        }
    }

    private function listItem(Conversation $conversation, User $viewer): array
    {
        $latest = Message::where('conversation_id', $conversation->id)->orderByDesc('id')->first();

        $unread = Message::where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $viewer->id)
            ->whereNull('read_at')
            ->count();

        return [
            'id' => $conversation->id,
            'subject' => $conversation->subject,
            'status' => $conversation->status,
            'last_message_at' => $conversation->last_message_at,
            'member' => $conversation->user ? $conversation->user->full_name : null,
            'latest' => $latest ? \Illuminate\Support\Str::limit($latest->body, 80) : null,
            'unread' => $unread,
        ];
    }

    private function thread(Conversation $conversation, User $viewer): array
    {
        $messages = $conversation->messages()->with('sender')->get()->map(fn (Message $m) => [
            'id' => $m->id,
            'body' => $m->body,
            'is_mine' => $m->sender_id === $viewer->id,
            'sender_name' => $m->sender ? $m->sender->full_name : 'Unknown',
            'created_at' => $m->created_at,
        ]);

        return [
            'id' => $conversation->id,
            'subject' => $conversation->subject,
            'status' => $conversation->status,
            'messages' => $messages,
        ];
    }
}
