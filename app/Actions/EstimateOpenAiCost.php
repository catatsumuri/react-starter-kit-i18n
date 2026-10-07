<?php

namespace App\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\AgentResponse;
use RuntimeException;
use Throwable;

class EstimateOpenAiCost
{
    private const SOURCE = 'https://configs.portkey.ai/pricing/openai.json';

    /** @return array<string, mixed> */
    public function handle(AgentResponse $response): array
    {
        try {
            if ($response->meta->provider !== 'openai' || $response->steps->count() > 1 || $response->toolCalls->isNotEmpty()) {
                throw new RuntimeException('Only single-step OpenAI text responses without tools are supported.');
            }

            $tier = $response->raw?->json('service_tier');
            $mode = match ($tier) {
                'default' => 'standard',
                'flex', 'priority', 'fast' => $tier,
                default => throw new RuntimeException('The response service tier is unknown.'),
            };

            $snapshot = Cache::remember('ai.pricing.portkey.openai.v1', 3600, function (): array {
                $models = Http::acceptJson()->connectTimeout(3)->timeout(10)->get(self::SOURCE)->throw()->json();

                if (! is_array($models)) {
                    throw new RuntimeException('The pricing response is invalid.');
                }

                return ['models' => $models, 'fetched_at' => now()->toIso8601String()];
            });

            $model = $snapshot['models'][$response->meta->model ?? ''] ?? null;

            if (! is_array($model)) {
                throw new RuntimeException('The response model is not listed in the pricing data.');
            }

            $rates = $model['pricing_config']['pay_as_you_go'] ?? null;

            if (isset($model['custom_pricing'])) {
                $tiers = $model['custom_pricing']['context_tier_map'] ?? [];
                ksort($tiers, SORT_NUMERIC);
                $contextTier = null;

                foreach ($tiers as $threshold => $name) {
                    if ($response->usage->inputTokens > (int) $threshold || (int) $threshold === 0) {
                        $contextTier = $name;
                    }
                }

                $rates = $model['custom_pricing']['regions']['default']['execution_modes'][$mode]['context_tiers'][$contextTier]['pricing_config']['pay_as_you_go'] ?? null;
            } elseif ($mode !== 'standard') {
                throw new RuntimeException('Pricing for this service tier is unavailable.');
            }

            if (! is_array($rates)) {
                throw new RuntimeException('Pricing for this model and context tier is unavailable.');
            }

            $counts = [
                'request_token' => $response->usage->uncachedInputTokens(),
                'cache_read_input_token' => $response->usage->cacheReadInputTokens ?? 0,
                'cache_write_input_token' => $response->usage->cacheWriteInputTokens ?? 0,
                'response_token' => $response->usage->outputTokens,
            ];
            $breakdown = [];

            foreach ($counts as $unit => $count) {
                $price = $rates[$unit]['price'] ?? null;

                if ($count < 0 || ($count > 0 && (! is_numeric($price) || ! is_finite((float) $price) || (float) $price < 0))) {
                    throw new RuntimeException('Pricing or usage for a required token category is invalid.');
                }

                $breakdown[$unit] = $count === 0 ? 0.0 : $count * (float) $price / 100;
            }

            return [
                'status' => 'estimated',
                'currency' => 'USD',
                'amount' => array_sum($breakdown),
                'breakdown' => $breakdown,
                'rates_cents_per_token' => $rates,
                'service_tier' => $tier,
                'source' => self::SOURCE,
                'fetched_at' => $snapshot['fetched_at'],
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'unknown',
                'amount' => null,
                'reason' => $exception instanceof RuntimeException ? $exception->getMessage() : 'Pricing data could not be retrieved.',
                'source' => self::SOURCE,
            ];
        }
    }
}
