<?php

namespace App\Console\Commands;

class JevNoulRoutingActionStub
{
    /** @var list<string> */
    private array $calls = [];

    public function sendToReview(string $message): void
    {
        $this->calls[] = 'sendToReview called';
    }

    public function routeToAgent(string $message, string $priority): void
    {
        $this->calls[] = 'routeToAgent called: priority='.$priority;
    }

    public function routeToBot(string $message, string $priority): void
    {
        $this->calls[] = 'routeToBot called: priority='.$priority;
    }

    /**
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
