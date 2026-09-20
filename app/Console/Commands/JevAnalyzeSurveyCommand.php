<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean as BooleanQuestion;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
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

    /** @var array<string, string> */
    private const TOPICS = [
        '内容' => 'テーマ、具体例、難易度、深さ、実務への有用性など講演内容そのもの',
        '講師・話し方' => '説明の分かりやすさ、話す速度、回答の仕方など講師の伝え方',
        '資料' => 'スライド、文字量、配布資料、ダウンロード資料',
        'デモ' => '実演、画面操作、デモの分かりやすさや動作',
        '会場・設備' => '座席、温度、音、スクリーン、Wi-Fiなど会場環境',
        '運営' => '時間配分、休憩、質疑応答、案内、イベント進行',
        '次回参加' => '再参加の意思や次回への期待',
        '講演外' => '講演やイベントの改善とは直接関係しない話題',
        '特定不能' => '情報が少なく主題を判断できない',
    ];

    /** @var array<string, string> */
    private const INTENTS = [
        '称賛' => '良かった点や価値を肯定的に伝えている',
        '不満' => '問題点や不快だった点を伝えている',
        '改善提案' => '直してほしい点と改善の方向を示している',
        '追加要望' => 'もっと知りたい、追加してほしい、提供してほしいと求めている',
        '混合' => '肯定的評価と不満・要望の両方を含む',
        '実質的意見なし' => '分析できる評価や要望がほとんどない',
    ];

    private const SENTIMENT_LEVELS = [
        '強い不満や否定が中心',
        '不満や否定が中心だが強くはない',
        '中立、判断不能、または肯定と否定が同程度',
        '満足や肯定が中心だが改善点もあり得る',
        '強い満足や称賛が中心',
    ];

    private const ACTIONABILITY_LEVELS = [
        '改善に使える情報がない',
        '漠然とした感想だけで改善対象が分からない',
        '改善対象の領域は分かるが具体策は不明',
        '問題や要望が具体的で対応方針を検討できる',
        '実行可能な改善内容が明確に示されている',
    ];

    public function handle(Filesystem $files): int
    {
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
            $responses = $this->loadResponses((string) $this->option('path'));
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
                $classification = Classification::of($surveyResponse['comment'])
                    ->questions($this->questions())
                    ->timeout($timeout)
                    ->classify('typesafe', $model);

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
     * @return array<string, BooleanQuestion|Choice|Score>
     */
    private function questions(): array
    {
        return [
            'primary_topic' => new Choice(
                'この自由記述で最も中心となっている話題は何ですか？',
                self::TOPICS,
            ),
            'intent' => new Choice(
                '回答者がこの自由記述で最も強く伝えようとしている意図は何ですか？',
                self::INTENTS,
            ),
            'sentiment' => new Score(
                'この自由記述が表すイベントへの感情を評価してください。',
                self::SENTIMENT_LEVELS,
            ),
            'actionability' => new Score(
                'この自由記述をイベント改善に利用できる具体性の程度を評価してください。',
                self::ACTIONABILITY_LEVELS,
            ),
            'is_relevant' => new BooleanQuestion(
                'この自由記述には、講演またはイベント体験を評価・改善するために関連する情報がありますか？',
                [
                    'true' => '講演内容、伝え方、資料、会場、運営、再参加などに関する評価や要望がある',
                    'false' => '無関係な話題だけ、または意味のある評価情報がない',
                ],
            ),
            'has_expectation_gap' => new BooleanQuestion(
                '回答者が事前に期待していた内容と実際の講演とのズレを示していますか？',
                [
                    'true' => '期待した難易度、テーマ、具体性、内容などと違ったことを示している',
                    'false' => '期待とのズレを示していない',
                ],
            ),
            'mentions_correction' => new BooleanQuestion(
                '回答者が、アンケートへの入力ミスまたは回答内容の訂正を明示していますか？',
                [
                    'true' => '間違えて選択した、誤入力した、回答を訂正したいなどと明示している',
                    'false' => '入力ミスや回答の訂正について述べていない',
                ],
            ),
        ];
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

    /**
     * @return list<array{id: string, comment: string}>
     */
    private function loadResponses(string $path): array
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
