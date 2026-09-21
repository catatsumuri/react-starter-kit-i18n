<?php

namespace App\Console\Commands;

class JevChoiceActionStub
{
    /** @var list<string> */
    private array $calls = [];

    public function sendToManualTriage(string $ticket): void
    {
        $this->calls[] = 'sendToManualTriage called';
    }

    public function assign(string $ticket, string $team, ?string $issue = null): void
    {
        $details = 'team='.$team;

        if ($issue !== null) {
            $details .= ', issue='.$issue;
        }

        $this->calls[] = 'assign called: '.$details;
    }

    public function notify(string $ticket, string $team): void
    {
        $this->calls[] = 'notify called: team='.$team;
    }

    public function askCustomerWhatTheyWant(string $ticket): void
    {
        $this->calls[] = 'askCustomerWhatTheyWant called';
    }

    public function flagForRefundApproval(string $ticket): void
    {
        $this->calls[] = 'flagForRefundApproval called';
    }

    public function flagForSeniorAgent(string $ticket): void
    {
        $this->calls[] = 'flagForSeniorAgent called';
    }

    /**
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
