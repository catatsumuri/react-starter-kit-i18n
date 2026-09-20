<?php

namespace App\Actions;

use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean as BooleanQuestion;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Responses\ClassificationResponse;

class AnalyzeSurveyResponse
{
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

    public function handle(
        string $comment,
        string $model = 'jev-latest',
        int $timeout = 30,
    ): ClassificationResponse {
        return Classification::of($comment)
            ->questions($this->questions())
            ->timeout($timeout)
            ->classify('typesafe', $model);
    }

    /** @return array<string, BooleanQuestion|Choice|Score> */
    private function questions(): array
    {
        return [
            'primary_topic' => new Choice('この自由記述で最も中心となっている話題は何ですか？', self::TOPICS),
            'intent' => new Choice('回答者がこの自由記述で最も強く伝えようとしている意図は何ですか？', self::INTENTS),
            'sentiment' => new Score('この自由記述が表すイベントへの感情を評価してください。', self::SENTIMENT_LEVELS),
            'actionability' => new Score('この自由記述をイベント改善に利用できる具体性の程度を評価してください。', self::ACTIONABILITY_LEVELS),
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
}
