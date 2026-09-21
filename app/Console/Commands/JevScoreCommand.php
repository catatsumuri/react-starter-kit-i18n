<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Score;

class JevScoreCommand extends Command
{
    protected $signature = 'jev:score';

    protected $description = 'Calculate priority from three TypeSafe scores';

    public function handle(): int
    {
        $ticket = "This is the third time PDF export has failed. In Safari 17 on macOS, open Reports, click Export PDF, and the spinner never finishes. CSV sometimes works for teammates but not for me. I'm done wasting time on this.";

        $answers = Classification::of($ticket)
            ->questions([
                'severity' => new Score('How severe is the reported issue?', [
                    'Cosmetic; no impact to functionality',
                    'Broken or degraded feature, but workaround exists',
                    'Blocking issue; no workaround exists',
                ]),
                'frustration' => new Score('How frustrated is the customer?', [
                    'Calm, just stating facts',
                    'Frustrated but civil',
                    'Very angry, strong language or threatening to leave',
                ]),
                'report_quality' => new Score('How much does the report give an engineer to work with?', [
                    'No detail; just says something is broken',
                    'Names the feature but no steps or environment',
                    'Steps to reproduce or environment, but not both',
                    'Steps to reproduce and environment',
                ]),
            ])
            ->classify();

        $severity = $answers['severity']->normalized();
        $frustration = $answers['frustration']->normalized();
        $reportQuality = $answers['report_quality']->normalized();

        $priority = 0.6 * $severity + 0.3 * $frustration + 0.1 * $reportQuality;

        $this->line('severity: '.$answers['severity']->score.' (normalized: '.$severity.')');
        $this->line('frustration: '.$answers['frustration']->score.' (normalized: '.$frustration.')');
        $this->line('report_quality: '.$answers['report_quality']->score.' (normalized: '.$reportQuality.')');
        $this->line('priority: '.$priority);

        return self::SUCCESS;
    }
}
