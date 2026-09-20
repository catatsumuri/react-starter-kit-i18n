<?php

namespace App\Http\Controllers;

use App\Actions\LoadSurveyResponses;
use Inertia\Inertia;
use Inertia\Response;

class PlaygroundController extends Controller
{
    public function __invoke(LoadSurveyResponses $loadSurveyResponses): Response
    {
        $surveys = array_map(
            fn (array $survey): array => [
                'id' => (int) $survey['id'],
                'answer' => $survey['comment'],
            ],
            $loadSurveyResponses->handle(),
        );

        return Inertia::render('welcome', [
            'surveys' => $surveys,
        ]);
    }
}
