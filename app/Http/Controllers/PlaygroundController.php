<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use SplFileObject;

class PlaygroundController extends Controller
{
    public function __invoke(): Response
    {
        $file = new SplFileObject(database_path('seeders/data/demo.csv'));
        $file->setCsvControl(',', '"', '');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

        $headers = $file->fgetcsv();
        $surveys = [];

        foreach ($file as $index => $row) {
            if ($index === 0 || ! is_array($row) || count($headers) !== count($row)) {
                continue;
            }

            $survey = array_combine($headers, $row);

            if ($survey === false) {
                continue;
            }

            $surveys[] = [
                'id' => (int) $survey['id'],
                'answer' => $survey['comment'],
            ];
        }

        return Inertia::render('welcome', [
            'surveys' => $surveys,
        ]);
    }
}
