<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Throwable;

#[Signature('jev:classify
    {text=請求が二重になっています。片方を返金してください。 : 分類する問い合わせ内容}
    {--model=jev-latest : TypeSafeのモデルID}
    {--timeout=30 : リクエストのタイムアウト秒数}')]
#[Description('問い合わせ分類を実行してTypeSafe APIキーとLaravel AI SDKの動作を確認する')]
class JevClassifyCommand extends Command
{
    private const QUESTION = 'この問い合わせは、どのサポート部門が担当するべきですか？';

    /** @var array<string, string> */
    private const DEPARTMENTS = [
        'billing' => '請求、請求書、支払い、返金に関する問い合わせ',
        'technical' => '不具合、障害、エラー、外部連携に関する問い合わせ',
        'sales' => '料金、プラン、アップグレード、新規契約に関する問い合わせ',
        'other' => 'ほかの部門に該当しない問い合わせ',
    ];

    public function handle(): int
    {
        if (blank(config('ai.providers.typesafe.key'))) {
            $this->components->error('TYPESAFE_API_KEYが設定されていません');

            return self::FAILURE;
        }

        $text = (string) $this->argument('text');
        $model = (string) $this->option('model');
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($timeout === false) {
            $this->components->error('--timeoutには1以上の整数を指定してください');

            return self::FAILURE;
        }

        try {
            $response = Classification::of($text)
                ->question('department', new Choice(
                    self::QUESTION,
                    self::DEPARTMENTS,
                ))
                ->timeout($timeout)
                ->classify('typesafe', $model);

            $answer = $response->answer('department');

            if (! $answer instanceof ChoiceAnswer) {
                $this->components->error('TypeSafeから想定外の回答形式が返されました');

                return self::FAILURE;
            }
        } catch (Throwable $exception) {
            $this->components->error('TypeSafeによる分類に失敗しました: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('TYPESAFE_API_KEYとLaravel AI SDKは正常に動作しています');
        $this->line('分類対象: '.$text);
        $this->line('質問内容: '.self::QUESTION);
        $this->table(
            ['部門', '判定基準', '確率'],
            collect($answer->probabilities)
                ->map(fn (float $probability, string $department): array => [
                    $department,
                    self::DEPARTMENTS[$department] ?? '不明',
                    number_format($probability * 100, 1).'%',
                ])
                ->values()
                ->all(),
        );
        $this->line('選択された部門: '.$answer->choice);
        $this->line('信頼度: '.($answer->confidence === null
            ? '未報告'
            : number_format($answer->confidence * 100, 1).'%'));
        $this->line('プロバイダー: '.($response->meta->provider ?? '不明'));
        $this->line('モデル: '.($response->meta->model ?? '不明'));
        $this->line("トークン: 入力 {$response->usage->inputTokens} / 出力 {$response->usage->outputTokens}");

        return self::SUCCESS;
    }
}
