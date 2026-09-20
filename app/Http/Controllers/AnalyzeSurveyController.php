<?php

namespace App\Http\Controllers;

use App\Actions\AnalyzeSurveyResponse;
use App\Actions\LoadSurveyResponses;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use Throwable;
use UnexpectedValueException;

class AnalyzeSurveyController extends Controller
{
    private const INPUT_USD_PER_MILLION_TOKENS = 0.042;

    private const JPY_PER_USD = 160.0;

    public function __invoke(
        AnalyzeSurveyResponse $analyzeSurveyResponse,
        LoadSurveyResponses $loadSurveyResponses,
    ): Response {
        abort_if(blank(config('ai.providers.typesafe.key')), 503, 'TYPESAFE_API_KEYが設定されていません');

        $surveys = $loadSurveyResponses->handle();
        $analyses = [];
        $totalInputTokens = 0;
        $totalOutputTokens = 0;

        foreach ($surveys as $survey) {
            try {
                $classification = $analyzeSurveyResponse->handle($survey['comment']);
                $rows = $this->answerRows($classification);
            } catch (Throwable $exception) {
                abort(502, "回答ID {$survey['id']} の分析に失敗しました: {$exception->getMessage()}");
            }

            $inputTokens = $classification->usage->inputTokens;
            $outputTokens = $classification->usage->outputTokens;

            $analyses[] = [
                'id' => (int) $survey['id'],
                'rows' => $rows,
                'model' => $classification->meta->model ?? '不明',
                'inputTokens' => $inputTokens,
                'outputTokens' => $outputTokens,
                'costUsd' => $this->costUsd($inputTokens),
                'costJpy' => $this->costUsd($inputTokens) * self::JPY_PER_USD,
            ];

            $totalInputTokens += $inputTokens;
            $totalOutputTokens += $outputTokens;
        }

        $totalCostUsd = $this->costUsd($totalInputTokens);

        return Inertia::render('welcome', [
            'surveys' => array_map(
                fn (array $survey): array => [
                    'id' => (int) $survey['id'],
                    'answer' => $survey['comment'],
                ],
                $surveys,
            ),
            'analyses' => $analyses,
            'summary' => [
                'inputTokens' => $totalInputTokens,
                'outputTokens' => $totalOutputTokens,
                'costUsd' => $totalCostUsd,
                'costJpy' => $totalCostUsd * self::JPY_PER_USD,
            ],
        ]);
    }

    /** @return list<array{label: string, value: string, certainty: string}> */
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
            ['label' => '主題', 'value' => $topic->choice, 'certainty' => $this->confidence($topic->confidence)],
            ['label' => '発話意図', 'value' => $intent->choice, 'certainty' => $this->confidence($intent->confidence)],
            ['label' => '感情', 'value' => (string) $sentiment->label(), 'certainty' => $this->confidence($sentiment->confidence)],
            ['label' => '改善への具体性', 'value' => (string) $actionability->label(), 'certainty' => $this->confidence($actionability->confidence)],
            ['label' => '講演との関連性', 'value' => $isRelevant->isTrue() ? 'あり' : 'なし', 'certainty' => $this->probability($isRelevant)],
            ['label' => '期待とのズレ', 'value' => $hasExpectationGap->isTrue() ? 'あり' : 'なし', 'certainty' => $this->probability($hasExpectationGap)],
            ['label' => '入力ミス・訂正への言及', 'value' => $mentionsCorrection->isTrue() ? 'あり' : 'なし', 'certainty' => $this->probability($mentionsCorrection)],
        ];
    }

    private function confidence(?float $confidence): string
    {
        return $confidence === null ? '未報告' : $this->percentage($confidence);
    }

    private function probability(BooleanAnswer $answer): string
    {
        return '該当確率 '.$this->percentage($answer->probability);
    }

    private function percentage(float $probability): string
    {
        return number_format($probability * 100, 1).'%';
    }

    private function costUsd(int $inputTokens): float
    {
        return ($inputTokens / 1_000_000) * self::INPUT_USD_PER_MILLION_TOKENS;
    }
}
