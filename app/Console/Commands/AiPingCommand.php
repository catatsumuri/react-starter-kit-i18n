<?php

namespace App\Console\Commands;

use App\Actions\EstimateOpenAiCost;
use App\Actions\RunAiTest;
use Illuminate\Console\Command;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;

class AiPingCommand extends Command
{
    protected $signature = 'ai:ping
        {--model=default : Model selection: default, cheapest, or smartest}
        {--usage : Show usage and billing-related response metadata}
        {--cost : Estimate text generation cost using public Portkey pricing}';

    protected $description = 'Check OpenAI connectivity with a short text response';

    public function handle(EstimateOpenAiCost $estimateCost, RunAiTest $runTest): int
    {
        if (blank(config('ai.providers.openai.key'))) {
            $this->error('Set OPENAI_API_KEY in .env before running ai:ping.');

            return self::FAILURE;
        }

        $selection = $this->option('model');

        if (! in_array($selection, ['default', 'cheapest', 'smartest'], true)) {
            $this->error('The --model option must be default, cheapest, or smartest.');

            return self::FAILURE;
        }

        $provider = Ai::textProvider(Lab::OpenAI->value);
        $model = match ($selection) {
            'cheapest' => $provider->cheapestTextModel(),
            'smartest' => $provider->smartestTextModel(),
            default => $provider->defaultTextModel(),
        };
        $this->line('Provider: openai');
        $this->line('Model: '.$model);

        $response = $runTest->handle($selection);

        $this->info($response->text);

        if ($this->option('usage')) {
            $this->line(json_encode([
                'provider' => $response->meta->provider,
                'model' => $response->meta->model,
                'usage' => $response->usage->toArray(),
                'raw_usage' => $response->raw?->json('usage'),
                'service_tier' => $response->raw?->json('service_tier'),
                'response_id' => $response->raw?->json('id'),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        if ($this->option('cost')) {
            $this->line(json_encode($estimateCost->handle($response), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
