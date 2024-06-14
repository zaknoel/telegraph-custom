<?php

/** @noinspection PhpUnhandledExceptionInspection */

namespace DefStudio\Telegraph\Concerns;

use DefStudio\Telegraph\DTO\Attachment;
use DefStudio\Telegraph\Jobs\SendRequestToTelegramJob;
use DefStudio\Telegraph\Telegraph;
use http\Client\Response;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;

/**
 * @mixin Telegraph
 */
trait InteractsWithTelegram
{
    protected string $endpoint;

    protected string|null $baseUrl = null;

    public function getUrl(): string
    {
        /** @phpstan-ignore-next-line */
        return (string)Str::of($this->getBaseUrl())
            ->append($this->getBotToken())
            ->append('/', $this->endpoint)
            ->when(!empty($this->data), fn(Stringable $str) => $str->append('?', http_build_query($this->data)));
    }

    protected function getBaseUrl(): string
    {
        /* @phpstan-ignore-next-line */
        return Str::of($this->baseUrl ?? config('telegraph.telegram_api_url', 'https://api.telegram.org/'))
            ->rtrim('/')
            ->append('/bot');
    }

    public function setBaseUrl(string|null $url): Telegraph
    {
        $telegraph = clone $this;

        $telegraph->baseUrl = $url;

        return $telegraph;
    }

    protected function sendRequestToTelegram(): Response
    {
        $asMultipart = $this->files->isNotEmpty();

        $request = $asMultipart
            ? Http::asMultipart()
            : Http::asJson();

        /** @var PendingRequest $request */
        $request = $this->files->reduce(
        /** @phpstan-ignore-next-line */
            function ($request, Attachment $attachment, string $key) {
                return $request->attach($key, $attachment->contents(), $attachment->filename());
            },
            $request
        );

        /** @phpstan-ignore-next-line */
        return $request->post($this->getApiUrl(), $this->prepareData());

    }

    public function getApiUrl(): string
    {
        /** @phpstan-ignore-next-line */
        return (string)Str::of($this->getBaseUrl())
            ->append($this->getBotToken())
            ->append('/', $this->endpoint);
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareData(): array
    {
        $asMultipart = $this->files->isNotEmpty();

        $data = $this->data;

        $data = $this->pipeTraits('preprocessData', $data);

        if ($asMultipart) {
            $data = collect($data)
                ->mapWithKeys(function ($value, $key) {
                    if (!is_array($value)) {
                        return [$key => $value];
                    }

                    return [$key => json_encode($value)];
                })->toArray();
        }


        return $data;
    }

    /**
     * @return array{url:string, payload:array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->getApiUrl(),
            'payload' => $this->prepareData(),
            'files' => $this->files->toArray(),
        ];
    }

    protected function dispatchRequestToTelegram(string $queue = null, $callback = []): PendingDispatch
    {
        return SendRequestToTelegramJob::dispatch($this->getApiUrl(), $this->prepareData(), $this->files, $callback)->onQueue($queue);
    }

    protected function getFilesBaseUrl(): string
    {
        /* @phpstan-ignore-next-line */
        return Str::of($this->baseUrl ?? config('telegraph.telegram_api_url', 'https://api.telegram.org/'))
            ->rtrim('/')
            ->append('/file/bot');
    }
}
