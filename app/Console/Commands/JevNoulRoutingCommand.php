<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean as BooleanQuestion;

class JevNoulRoutingCommand extends Command
{
    protected $signature = 'jev:noul:routing';

    protected $description = 'Route a sample support message using two Noul questions';

    public function handle(JevNoulRoutingActionStub $actions): int
    {
        $message = 'I have asked three times now. Can I please just talk to a real person?';

        $answers = Classification::of($message)
            ->questions([
                'is_human_escalation' => new BooleanQuestion(
                    'Is the customer asking for a human agent?',
                ),
                'is_repeat_contact' => new BooleanQuestion(
                    'Has the customer contacted support about this before?',
                    [
                        'true' => 'Mentions a prior attempt, ticket, or that they have asked before',
                        'false' => 'No sign of any previous contact',
                    ],
                ),
            ])
            ->classify(model: 'jev-latest');

        $wantsHuman = $answers['is_human_escalation']->probability;
        $repeat = $answers['is_repeat_contact']->probability;

        if (($wantsHuman > 0.2 && $wantsHuman < 0.8) || ($repeat > 0.2 && $repeat < 0.8)) {
            $actions->sendToReview($message);
        } else {
            $priority = $repeat > 0.8 ? 'high' : 'normal';

            if ($wantsHuman > 0.8) {
                $actions->routeToAgent($message, $priority);
            } else {
                $actions->routeToBot($message, $priority);
            }
        }

        foreach ($actions->calls() as $call) {
            $this->line($call);
        }

        return self::SUCCESS;
    }
}
