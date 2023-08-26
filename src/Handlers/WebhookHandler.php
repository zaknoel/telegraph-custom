<?php

/** @noinspection PhpDocMissingThrowsInspection */

/** @noinspection PhpUnused */

/** @noinspection PhpUnhandledExceptionInspection */

namespace DefStudio\Telegraph\Handlers;

use DefStudio\Telegraph\DTO\CallbackQuery;
use DefStudio\Telegraph\DTO\Chat;
use DefStudio\Telegraph\DTO\InlineQuery;
use DefStudio\Telegraph\DTO\Message;
use DefStudio\Telegraph\DTO\User;
use DefStudio\Telegraph\Exceptions\TelegramWebhookException;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Models\TelegraphBot;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class WebhookHandler
{
    protected TelegraphBot $bot;
    protected TelegraphChat $chat;

    protected int $messageId;
    protected int $callbackQueryId;

    protected Request $request;
    protected Message|null $message = null;
    protected CallbackQuery|null $callbackQuery = null;

    protected Collection $data;

    protected Keyboard $originalKeyboard;

    public function __construct()
    {
        $this->originalKeyboard = Keyboard::make();
    }

    protected function handleCallbackQuery(): void
    {
        $this->extractCallbackQueryData();

        if (config('telegraph.debug_mode')) {
            Log::debug('Telegraph webhook callback', $this->data->toArray());
        }

        /** @var string $action */
        $action = $this->callbackQuery?->data()->get('action') ?? '';

        if (!$this->canHandle($action)) {
            report(TelegramWebhookException::invalidAction($action));
            $this->reply(__('telegraph::errors.invalid_action'));

            return;
        }

        $this->$action();
    }

    protected function handleCommand(Stringable $text): void
    {
        $command = (string) $text->after('/')->before(' ')->before('@');
        $parameter = (string) $text->after('@')->after(' ');

        if (!$this->canHandle($command)) {
            $this->handleUnknownCommand($text);

            return;
        }

        $this->$command($parameter);
    }

    protected function handleUnknownCommand(Stringable $text): void
    {
        if ($this->message?->chat()?->type() === Chat::TYPE_PRIVATE) {
            $command = (string) $text->after('/')->before(' ')->before('@');

            if (config('telegraph.report_unknown_webhook_commands', true)) {
                report(TelegramWebhookException::invalidCommand($command));
            }

            $this->chat->html(__('telegraph::errors.invalid_command'))->send();
        }
    }

    protected function handleMessage(): void
    {
        $this->extractMessageData();

        if (config('telegraph.debug_mode')) {
            Log::debug('Telegraph webhook message', $this->data->toArray());
        }

        $text = Str::of($this->message?->text() ?? '');

        if ($text->startsWith('/')) {
            $this->handleCommand($text);

            return;
        }


        if ($this->message?->newChatMembers()->isNotEmpty()) {
            foreach ($this->message->newChatMembers() as $member) {
                $this->handleChatMemberJoined($member);
            }

            return;
        }

        if ($this->message?->leftChatMember() !== null) {
            $this->handleChatMemberLeft($this->message->leftChatMember());

            return;
        }

        $this->handleChatMessage($text);
    }

    protected function canHandle(string $action): bool
    {
        if ($action === 'handle') {
            return false;
        }

        if (!method_exists($this, $action)) {
            return false;
        }

        $reflector = new ReflectionMethod($this::class, $action);
        if (!$reflector->isPublic()) {
            return false;
        }

        return true;
    }

    protected function extractCallbackQueryData(): void
    {
        $this->setupChat();

        assert($this->callbackQuery !== null);

        $this->messageId = $this->callbackQuery->message()?->id() ?? throw TelegramWebhookException::invalidData('message id missing');

        $this->callbackQueryId = $this->callbackQuery->id();

        /** @phpstan-ignore-next-line */
        $this->originalKeyboard = $this->callbackQuery->message()?->keyboard() ?? Keyboard::make();

        $this->data = $this->callbackQuery->data();
    }

    protected function extractMessageData(): void
    {
        $this->setupChat();

        assert($this->message !== null);

        $this->messageId = $this->message->id();

        $this->data = collect([
            'text' => $this->message->text(),
        ]);
    }

    protected function handleChatMemberJoined(User $member): void
    {
        // .. do nothing
    }

    protected function handleChatMemberLeft(User $member): void
    {
        // .. do nothing
    }

    protected function handleChatMessage(Stringable $text): void
    {
        // .. do nothing
    }

    protected function replaceKeyboard(Keyboard $newKeyboard): void
    {
        $this->chat->replaceKeyboard($this->messageId, $newKeyboard)->send();
    }

    protected function deleteKeyboard(): void
    {
        $this->chat->deleteKeyboard($this->messageId)->send();
    }

    protected function reply(string $message, bool $showAlert = false): void
    {
        if (isset($this->callbackQueryId)) {
            $this->bot->replyWebhook($this->callbackQueryId, $message, $showAlert)->send();

            return;
        }

        $this->chat->message($message)->send();
    }

    public function chatid(): void
    {
        $this->chat->html("Chat ID: {$this->chat->chat_id}")->send();
    }

    public function handle(Request $request, TelegraphBot $bot): void
    {
        $this->bot = $bot;

        $this->request = $request;

        if ($this->request->has('message')) {
            /* @phpstan-ignore-next-line */
            $this->message = Message::fromArray($this->request->input('message'));
            $this->handleMessage();

            return;
        }

        if ($this->request->has('edited_message')) {
            /* @phpstan-ignore-next-line */
            $this->message = Message::fromArray($this->request->input('edited_message'));
            $this->handleMessage();

            return;
        }

        if ($this->request->has('channel_post')) {
            /* @phpstan-ignore-next-line */
            $this->message = Message::fromArray($this->request->input('channel_post'));
            $this->handleMessage();

            return;
        }


        if ($this->request->has('callback_query')) {
            /* @phpstan-ignore-next-line */
            $this->callbackQuery = CallbackQuery::fromArray($this->request->input('callback_query'));
            $this->handleCallbackQuery();
        }

        if ($this->request->has('inline_query')) {
            /* @phpstan-ignore-next-line */
            $this->handleInlineQuery(InlineQuery::fromArray($this->request->input('inline_query')));
        }
    }

    protected function handleInlineQuery(InlineQuery $inlineQuery): void
    {
        // .. do nothing
    }

    protected function setupChat(): void
    {
        $telegramChat = $this->message?->chat() ?? $this->callbackQuery?->message()?->chat();

        assert($telegramChat !== null);

        /** @var TelegraphChat $chat */
        $chat = $this->bot->chats()->firstOrNew([
            'chat_id' => $telegramChat->id(),
        ]);
        $this->chat = $chat;

        if (!$this->chat->exists) {
            if (!$this->allowUnknownChat()) {
                throw new NotFoundHttpException();
            }

            if (config('telegraph.security.store_unknown_chats_in_db', false)) {
                $this->chat->name = Str::of("")
                    ->append("[", $telegramChat->type(), ']')
                    ->append(" ", $telegramChat->title());
                $this->chat->save();
            }
        }
    }

    protected function allowUnknownChat(): bool
    {
        return (bool) match (true) {
            $this->message !== null => config('telegraph.security.allow_messages_from_unknown_chats', false),
            $this->callbackQuery != null => config('telegraph.security.allow_callback_queries_from_unknown_chats', false),
            default => false,
        };
    }
}
