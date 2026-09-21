<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Choice;

class JevChoiceCommand extends Command
{
    protected $signature = 'jev:choice';

    protected $description = 'Show triage decisions for a sample support ticket';

    public function handle(JevChoiceActionStub $actions): int
    {
        $ticket = 'My running shoes arrived in the wrong size after a delay, and I was charged twice. What can you do?';

        $answers = Classification::of($ticket)
            ->questions([
                'department' => new Choice('Which team should handle this?', [
                    'returns' => 'Exchanges, wrong or damaged items',
                    'shipping' => 'Delivery status, delays, lost packages',
                    'billing' => 'Charges, invoices, payment problems',
                ]),
                'return_reason' => new Choice('If the customer wants to return something, why?', [
                    'wrong_size' => "The item doesn't fit",
                    'wrong_item' => 'A different product was delivered',
                    'damaged' => 'The item arrived broken or faulty',
                    'changed_mind' => 'The item is fine, the customer no longer wants it',
                    'other' => 'A return reason that fits none of the above',
                ]),
                'shipping_issue' => new Choice('If this is a shipping problem, which kind is it?', [
                    'not_delivered' => 'The package never arrived',
                    'delayed' => 'The package is late but still on its way',
                    'wrong_address' => 'The package went to the wrong place',
                    'damaged_in_transit' => 'The package arrived damaged',
                    'other' => 'A shipping problem that fits none of the above',
                ]),
                'requested_resolution' => new Choice('What does the customer want to happen?', [
                    'exchange' => 'Swap the item for a different one',
                    'refund' => 'Money back',
                    'replacement' => 'The same item sent again',
                    'information' => 'Just an answer, no action needed',
                ]),
                'tone' => new Choice("What is the customer's tone?", [
                    'calm' => null,
                    'frustrated' => null,
                    'angry' => null,
                ]),
            ])
            ->classify();

        $department = $answers['department'];

        if ($department->confidence === null || $department->confidence < 0.3) {
            $actions->sendToManualTriage($ticket);
            $this->printCalls($actions);

            return self::SUCCESS;
        }

        if ($department->choice === 'returns') {
            $actions->assign($ticket, 'returns', $answers['return_reason']->choice);
        } elseif ($department->choice === 'shipping') {
            $actions->assign($ticket, 'shipping', $answers['shipping_issue']->choice);
        } else {
            $actions->assign($ticket, 'billing');
        }

        foreach ($department->probabilities as $team => $probability) {
            if ($team !== $department->choice && $probability > 0.25) {
                $actions->notify($ticket, $team);
            }
        }

        $resolution = $answers['requested_resolution'];

        if ($resolution->confidence === null || $resolution->confidence < 0.5) {
            $actions->askCustomerWhatTheyWant($ticket);
        } elseif ($resolution->choice === 'refund') {
            $actions->flagForRefundApproval($ticket);
        }

        if ($answers['tone']->choice === 'angry') {
            $actions->flagForSeniorAgent($ticket);
        }

        $this->printCalls($actions);

        return self::SUCCESS;
    }

    private function printCalls(JevChoiceActionStub $actions): void
    {
        foreach ($actions->calls() as $call) {
            $this->line($call);
        }
    }
}
