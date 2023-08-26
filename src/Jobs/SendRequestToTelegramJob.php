<?php

namespace DefStudio\Telegraph\Jobs;

use DefStudio\Telegraph\Client\TelegraphResponse;
use DefStudio\Telegraph\DTO\Attachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class SendRequestToTelegramJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param array<string, mixed> $data
     * @param Collection<string, Attachment> $files
     */
    public function __construct(public string $url, public array $data, public Collection $files, public $callback=[])
    {
    }

    public function handle(): void
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

        $response=$request->post($this->url, $this->data);
        $response=TelegraphResponse::fromResponse($response);
        //logging
        $ldata=[
            'callback'=>$this->callback,
            "is_callable"=>is_callable($this->callback['callback']??null),
            "data"=>$this->callback['data']??[],
            "response"=>$response->telegraphOk(),
            'tg_id'=>$response->telegraphMessageId()
        ];
        file_put_contents($_SERVER["DOCUMENT_ROOT"]."/ll.txt", print_r($ldata, 1), FILE_APPEND);

        if(is_callable($this->callback['callback']??null)){


            call_user_func($this->callback['callback'], $response, $this->callback['data']??[]);
        }

    }
}
