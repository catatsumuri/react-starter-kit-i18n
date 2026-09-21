<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean as BooleanQuestion;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;

class JevMultipleQuestionsCommand extends Command
{
    protected $signature = 'jev:multiple-questions';

    protected $description = 'Classify multiple questions about a flight cancellation';

    public function handle(): int
    {
        $state = [
            'ticket_message' => 'My flight was cancelled. Can I get a refund?',
            'refund_policy' => 'Cancelled flights are eligible for a full refund.',
        ];

        $response = Classification::of($state)
            ->questions([
                'refund_requested' => new BooleanQuestion(
                    'Does `ticket_message` request a refund?',
                ),
                'request_type' => new Choice(
                    'What is the main request in `ticket_message`?',
                    [
                        'refund' => 'The customer wants money returned.',
                        'rebooking' => 'The customer wants a replacement flight.',
                        'information' => 'The customer is asking for information only.',
                    ],
                ),
                'frustration' => new Score(
                    'How frustrated does the customer appear in `ticket_message`?',
                    [
                        'Calm and neutral.',
                        'Concerned but civil.',
                        'Very angry or using strong language.',
                    ],
                ),
            ])
            ->classify();

        $this->line(json_encode($response['refund_requested']->probability, JSON_PRESERVE_ZERO_FRACTION));
        $this->line($response['request_type']->choice);
        $this->line(json_encode($response['frustration']->score, JSON_PRESERVE_ZERO_FRACTION));

        return self::SUCCESS;
    }
}
