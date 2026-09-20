<?php

namespace App\Console\Commands;

use App\Actions\AnalyzeSurveyResponse;
use App\Actions\LoadSurveyResponses;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

#[Signature('jev:analyze-survey
    {--path=database/seeders/data/demo.csv : 分析するCSVファイル}
    {--output=storage/app/jev-survey-analysis.md : Markdownの出力先}
    {--id=* : 分析対象の回答ID（複数指定可）}
    {--limit=0 : 分析する最大件数。0の場合は全件}
    {--model=jev-latest : TypeSafeのモデルID}
    {--timeout=30 : 1リクエストのタイムアウト秒数}')]
#[Description('アンケートの自由記述だけをJevで多軸分析する')]
class JevAnalyzeSurveyCommand extends Command
{
    private const INPUT_USD_PER_MILLION_TOKENS = 0.042;

    private const OUTPUT_USD_PER_MILLION_TOKENS = 0.0;

    private const JPY_PER_USD = 160.0;

    public function handle(
        Filesystem $files,
        AnalyzeSurveyResponse $analyzeSurveyResponse,
        LoadSurveyResponses $loadSurveyResponses,
    ): int {
        if (blank(config('ai.providers.typesafe.key'))) {
            $this->components->error('TYPESAFE_API_KEYが設定されていません');

            return self::FAILURE;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($limit === false || $timeout === false) {
            $this->components->error('--limitには0以上、--timeoutには1以上の整数を指定してください');

            return self::FAILURE;
        }

        try {
            $responses = $loadSurveyResponses->handle((string) $this->option('path'));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $selectedIds = array_map('strval', (array) $this->option('id'));

        if ($selectedIds !== []) {
            $responses = array_values(array_filter(
                $responses,
                fn (array $response): bool => in_array($response['id'], $selectedIds, true),
            ));
        }

        if ($limit > 0) {
            $responses = array_slice($responses, 0, $limit);
        }

        if ($responses === []) {
            $this->components->error('分析対象の回答が見つかりませんでした');

            return self::FAILURE;
        }

        $model = (string) $this->option('model');
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $markdown = [
            '# Jev アンケート自由記述分析',
            '',
            '- 入力CSV: '.$this->markdownText((string) $this->option('path')),
            '- 指定モデル: '.$this->markdownText($model),
            '- 分析件数: '.count($responses),
            '- Jev入力単価: $'.number_format(self::INPUT_USD_PER_MILLION_TOKENS, 3).' / 100万トークン',
            '- Jev出力単価: 無料',
            '- 為替レート: 1 USD = '.number_format(self::JPY_PER_USD, 0).' JPY',
            '',
        ];

        foreach ($responses as $surveyResponse) {
            try {
                $classification = $analyzeSurveyResponse->handle(
                    $surveyResponse['comment'],
                    $model,
                    $timeout,
                );

                $rows = $this->answerRows($classification);
            } catch (Throwable $exception) {
                $this->components->error("回答ID {$surveyResponse['id']} の分析に失敗しました: {$exception->getMessage()}");

                return self::FAILURE;
            }

            $markdown[] = '## 回答 ID '.$this->markdownText($surveyResponse['id']);
            $markdown[] = '';
            $markdown[] = '**自由記述**';
            $markdown[] = '';
            $markdown[] = '> '.$this->markdownText($surveyResponse['comment']);
            $markdown[] = '';
            $markdown[] = '| 分析軸 | 判定 | 確率・信頼度 |';
            $markdown[] = '| --- | --- | --- |';

            foreach ($rows as $row) {
                $markdown[] = '| '.implode(' | ', array_map($this->markdownTableCell(...), $row)).' |';
            }

            $markdown[] = '';
            $markdown[] = '- 応答モデル: '.$this->markdownText($classification->meta->model ?? '不明');
            $markdown[] = "- 使用トークン: 入力 {$classification->usage->inputTokens} / 出力 {$classification->usage->outputTokens}";
            $markdown[] = '- 推定コスト: '.$this->formatCost(
                $this->calculateCostUsd(
                    $classification->usage->inputTokens,
                    $classification->usage->outputTokens,
                ),
            );
            $markdown[] = '';

            $this->line("回答ID {$surveyResponse['id']} を分析しました");

            $totalInputTokens += $classification->usage->inputTokens;
            $totalOutputTokens += $classification->usage->outputTokens;
        }

        $markdown[] = '## 利用量';
        $markdown[] = '';
        $markdown[] = "- 入力トークン: {$totalInputTokens}";
        $markdown[] = "- 出力トークン: {$totalOutputTokens}";
        $markdown[] = '- 入力コスト: $'.number_format($this->inputCostUsd($totalInputTokens), 8).' USD';
        $markdown[] = '- 出力コスト: $'.number_format($this->outputCostUsd($totalOutputTokens), 8).' USD';
        $markdown[] = '- 合計コスト: '.$this->formatCost(
            $this->calculateCostUsd($totalInputTokens, $totalOutputTokens),
        );
        $markdown[] = '';

        try {
            $outputPath = $this->writeMarkdown(
                $files,
                (string) $this->option('output'),
                implode(PHP_EOL, $markdown),
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(count($responses).'件の分析が完了しました');
        $this->line('Markdown: '.$outputPath);
        $this->line('合計コスト: '.$this->formatCost(
            $this->calculateCostUsd($totalInputTokens, $totalOutputTokens),
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function answerRows(ClassificationResponse $response): array
    {
        $topic = $response->answer('primary_topic');
        $intent = $response->answer('intent');
        $sentiment = $response->answer('sentiment');
        $actionability = $response->answer('actionability');
        $isRelevant = $response->answer('is_relevant');
        $hasExpectationGap = $response->answer('has_expectation_gap');
        $mentionsCorrection = $response->answer('mentions_correction');

        if (! $topic instanceof ChoiceAnswer
            || ! $intent instanceof ChoiceAnswer
            || ! $sentiment instanceof ScoreAnswer
            || ! $actionability instanceof ScoreAnswer
            || ! $isRelevant instanceof BooleanAnswer
            || ! $hasExpectationGap instanceof BooleanAnswer
            || ! $mentionsCorrection instanceof BooleanAnswer) {
            throw new UnexpectedValueException('TypeSafeから想定外の回答形式が返されました');
        }

        return [
            ['主題', $topic->choice, $this->formatConfidence($topic->confidence)],
            ['発話意図', $intent->choice, $this->formatConfidence($intent->confidence)],
            ['感情', (string) $sentiment->label(), $this->formatConfidence($sentiment->confidence)],
            ['改善への具体性', (string) $actionability->label(), $this->formatConfidence($actionability->confidence)],
            ['講演との関連性', $isRelevant->isTrue() ? 'あり' : 'なし', $this->formatNoulProbability($isRelevant)],
            ['期待とのズレ', $hasExpectationGap->isTrue() ? 'あり' : 'なし', $this->formatNoulProbability($hasExpectationGap)],
            ['入力ミス・訂正への言及', $mentionsCorrection->isTrue() ? 'あり' : 'なし', $this->formatNoulProbability($mentionsCorrection)],
        ];
    }

    private function formatNoulProbability(BooleanAnswer $answer): string
    {
        return '該当確率 '.$this->formatPercentage($answer->probability);
    }

    private function formatConfidence(?float $confidence): string
    {
        return $confidence === null ? '未報告' : $this->formatPercentage($confidence);
    }

    private function formatPercentage(float $probability): string
    {
        return number_format($probability * 100, 1).'%';
    }

    private function calculateCostUsd(int $inputTokens, int $outputTokens): float
    {
        return $this->inputCostUsd($inputTokens) + $this->outputCostUsd($outputTokens);
    }

    private function inputCostUsd(int $tokens): float
    {
        return ($tokens / 1_000_000) * self::INPUT_USD_PER_MILLION_TOKENS;
    }

    private function outputCostUsd(int $tokens): float
    {
        return ($tokens / 1_000_000) * self::OUTPUT_USD_PER_MILLION_TOKENS;
    }

    private function formatCost(float $costUsd): string
    {
        return '$'.number_format($costUsd, 8).' USD / ¥'.number_format($costUsd * self::JPY_PER_USD, 4).' JPY';
    }

    private function markdownText(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\r", "\n"],
            ['\\\\', '<br>', '<br>', '<br>'],
            $value,
        );
    }

    private function markdownTableCell(string $value): string
    {
        return str_replace('|', '\\|', $this->markdownText($value));
    }

    private function writeMarkdown(Filesystem $files, string $path, string $markdown): string
    {
        if (blank($path)) {
            throw new RuntimeException('Markdownの出力先を指定してください');
        }

        $absolutePath = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        $directory = dirname($absolutePath);

        if (! $files->isDirectory($directory) && ! $files->makeDirectory($directory, 0755, true)) {
            throw new RuntimeException("Markdownの出力先ディレクトリを作成できません: {$directory}");
        }

        if ($files->put($absolutePath, $markdown.PHP_EOL) === false) {
            throw new RuntimeException("Markdownを書き込めません: {$absolutePath}");
        }

        return $absolutePath;
    }
}
