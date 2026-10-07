<?php

namespace Packstub\Agents\Support\Decisions;

use Laravel\Ai\Ai;
use Packstub\Agents\Ai\Side\DecisionAgent;
use Packstub\Agents\Contracts\DecisionClassifier;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Support\AgentModels;

/**
 * The `agent` driver: the DecisionAgent side agent, on the provider of the model the reply was sent with (its
 * cheapest model) unless `decision_classifier.provider` / `.model` say otherwise. It gives a reason, no confidence.
 */
class AgentDecisionClassifier implements DecisionClassifier
{
    public function classify(string $reply, array $proposals, ?string $model = null): array
    {
        // With the assistant faked in a test the classifier runs only when it is faked too.
        if (! DecisionAgent::runsBeside(Agents::agentClass())) {
            return ['decisions' => []];
        }

        $provider = Ai::textProvider(config('packstub-agents.decision_classifier.provider') ?: AgentModels::resolve($model)['provider']);
        $verdict = DecisionAgent::run(DecisionAgent::input($reply, $proposals), $provider, config('packstub-agents.decision_classifier.model') ?: null);

        $decisions = [];
        foreach ((array) ($verdict['decisions'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['id']) && is_string($entry['decision'] ?? null)) {
                $decisions[(string) $entry['id']] = ['decision' => $entry['decision'], 'confidence' => null];
            }
        }

        return ['decisions' => $decisions, 'reason' => is_string($verdict['reason'] ?? null) ? $verdict['reason'] : null];
    }
}
