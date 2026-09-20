<?php

namespace App\Actions;

use RuntimeException;

class LoadSurveyResponses
{
    /** @return list<array{id: string, comment: string}> */
    public function handle(string $path = 'database/seeders/data/demo.csv'): array
    {
        $absolutePath = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);

        if (! is_file($absolutePath)) {
            throw new RuntimeException("CSVファイルを開けません: {$path}");
        }

        $stream = fopen($absolutePath, 'r');

        if ($stream === false) {
            throw new RuntimeException("CSVファイルを開けません: {$path}");
        }

        try {
            $headers = fgetcsv($stream, escape: '');

            if ($headers === false) {
                throw new RuntimeException("CSVファイルが空です: {$path}");
            }

            $idIndex = array_search('id', $headers, true);
            $commentIndex = array_search('comment', $headers, true);

            if ($idIndex === false || $commentIndex === false) {
                throw new RuntimeException('CSVにはid列とcomment列が必要です');
            }

            $responses = [];

            while (($row = fgetcsv($stream, escape: '')) !== false) {
                if (! isset($row[$idIndex], $row[$commentIndex])) {
                    continue;
                }

                $responses[] = [
                    'id' => (string) $row[$idIndex],
                    'comment' => (string) $row[$commentIndex],
                ];
            }

            return $responses;
        } finally {
            fclose($stream);
        }
    }
}
