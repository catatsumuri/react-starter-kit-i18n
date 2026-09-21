<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean as BooleanQuestion;

class JevNoulDuplicatesCommand extends Command
{
    protected $signature = 'jev:noul:duplicates';

    protected $description = 'Check a sample resume against candidate records';

    public function handle(): int
    {
        $resume = [
            'name' => 'Ana García',
            'location' => 'Seattle',
            'last_employer' => 'Acme',
        ];

        $candidates = [
            ['id' => 18, 'name' => 'Ana Garcia', 'location' => 'Seattle', 'last_employer' => 'Acme'],
            ['id' => 42, 'name' => 'Ana García', 'location' => 'Austin', 'last_employer' => 'Contoso'],
            ['id' => 77, 'name' => 'Anna Garcia', 'location' => 'Seattle', 'last_employer' => 'Contoso'],
        ];

        $questions = [];

        foreach ($candidates as $candidate) {
            $questions['same_as_record_'.$candidate['id']] = new BooleanQuestion([
                'potential_duplicate' => [
                    'name' => $candidate['name'],
                    'location' => $candidate['location'],
                    'last_employer' => $candidate['last_employer'],
                ],
                'question' => 'Is the resume for the same person as `potential_duplicate`?',
            ]);
        }

        $answers = Classification::of(['resume' => $resume])
            ->questions($questions)
            ->classify(model: 'jev-latest');

        $matches = [];

        foreach ($answers as $questionId => $answer) {
            if ($answer->probability > 0.7) {
                $matches[] = $questionId;
            }
        }

        $this->line(json_encode($matches, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
