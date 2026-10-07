<?php

namespace App\Actions;

use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;

use function Laravel\Ai\agent;

class RunAiTest
{
    public function handle(string $selection): AgentResponse
    {
        $provider = Ai::textProvider('openai');
        $model = match ($selection) {
            'cheapest' => $provider->cheapestTextModel(),
            'smartest' => $provider->smartestTextModel(),
            default => $provider->defaultTextModel(),
        };

        return agent(instructions: 'Reply briefly in English.')
            ->prompt('This is a connectivity check. Say hello in one short sentence.', provider: Lab::OpenAI, model: $model, timeout: 30);
    }
}
