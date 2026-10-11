<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendSecurityAlert implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, bool|int|string|null>  $context
     */
    public function __construct(
        public string $alert,
        public array $context,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(): void
    {
        Log::channel(config('security.detection.alert_channel', 'security'))
            ->critical(__($this->alert), $this->context);
    }
}
