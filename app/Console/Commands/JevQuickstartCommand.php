<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean as BooleanQuestion;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;

class JevQuickstartCommand extends Command
{
    protected $signature = 'jev:quickstart';

    protected $description = 'Classify the TypeSafe quickstart support ticket';

    public function handle(): int
    {
        $ticket = "Hi, I've been trying to connect my Stripe account for 3 days and the integration keeps failing. I'm losing sales. Please help ASAP.";

        $response = Classification::of($ticket)
            ->questions([
                'department' => new Choice(
                    'Which team should handle this',
                    [
                        'billing' => 'Payment or subscription issues',
                        'technical' => 'Bugs or integration problems',
                        'sales' => 'Pricing or account questions',
                    ],
                ),
                'frustration' => new Score(
                    'How frustrated the customer appears',
                    [
                        'Calm, just stating facts',
                        'Frustrated but civil',
                        'Very angry, strong language',
                    ],
                ),
                'is_urgent' => new BooleanQuestion(
                    'The message conveys urgency or time-sensitivity',
                ),
            ])
            ->classify();

        $this->line($response['department']->choice);
        $this->line(json_encode($response['frustration']->score, JSON_PRESERVE_ZERO_FRACTION));
        $this->line(json_encode($response['is_urgent']->probability, JSON_PRESERVE_ZERO_FRACTION));

        return self::SUCCESS;
    }
}
