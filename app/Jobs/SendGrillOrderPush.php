<?php

namespace App\Jobs;

use App\Services\GrillWebPushService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class SendGrillOrderPush implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, Queueable;

    public int $tries = 3;
    public int $timeout = 30;
    public int $uniqueFor = 300;

    public function __construct(public readonly int $orderId) {}

    public function handle(GrillWebPushService $push): void
    {
        $push->sendForOrder($this->orderId);
    }

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }
}
