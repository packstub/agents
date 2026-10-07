<?php

namespace Packstub\Agents\Support\Decisions;

use Illuminate\Support\Facades\Http;
use Packstub\Agents\Ai\Side\DecisionAgent;
use Packstub\Agents\Contracts\DecisionClassifier;
use RuntimeException;

/**
 * The `jev` driver: TypeSafe's Jev decision model (https://docs.typesafe.ai/api.md), one choice question per
 * proposal over the proposals and the reply, answered with a confidence and no reason. Config
 * `decision_classifier.jev`: the key (TYPESAFE_API_KEY), the base URL, the model and the timeout. The reply and the
 * proposal questions are sent to TypeSafe.
 */
class JevDecisionClassifier implements DecisionClassifier
{
    public function classify(string $reply, array $proposals, ?string $model = null): array
    {
        $config = (array) config('packstub-agents.decision_classifier.jev', []);

        if (blank($config['key'] ?? null)) {
            throw new RuntimeException('The Jev decision classifier needs an API key: set TYPESAFE_API_KEY.');
        }

        // Questions are keyed p1, p2…, not by call id: an id that looks like a list index would turn the map into a list.
        $ids = [];
        $questions = [];
        foreach ($proposals as $id => $proposal) {
            $key = 'p'.(count($ids) + 1);
            $ids[$key] = (string) $id;
            $questions[$key] = [
                'type' => 'choice',
                'instructions' => "The assistant of a business application proposed the changes listed in the state and waits for the person to approve or reject each one; the person typed the reply below them. What does the reply decide about proposal {$id}: \"{$proposal['question']}\"?",
                'criteria' => [
                    self::APPROVE => 'The reply clearly says to make this change now.',
                    self::REJECT => 'The reply clearly says not to make this change, or approves only other proposals.',
                    self::QUESTION => 'The reply asks something, sets a condition this proposal does not meet, postpones, says "if", or is unclear.',
                ],
            ];
        }

        $response = Http::baseUrl(rtrim((string) ($config['url'] ?? 'https://api.typesafe.ai'), '/'))
            ->withToken((string) $config['key'])
            ->acceptJson()
            ->timeout((int) ($config['timeout'] ?? 5))
            ->post('/v1/systemone', [
                'state' => DecisionAgent::input($reply, $proposals),
                'model' => (string) ($config['model'] ?? 'jev-latest'),
                'questions' => $questions,
            ])
            ->throw()
            ->json();

        $decisions = [];
        foreach ((array) ($response['answers'] ?? []) as $key => $answer) {
            if (! isset($ids[$key]) || ! is_array($answer) || ! is_string($choice = $answer['choice'] ?? null)) {
                continue;
            }

            // Without a confidence the floor could not hold Jev's answer, so it decides nothing: the reply is a question.
            $confidence = $answer['confidence'] ?? $answer['probabilities'][$choice] ?? null;
            if (! is_numeric($confidence)) {
                continue;
            }

            $decisions[$ids[$key]] = ['decision' => $choice, 'confidence' => (float) $confidence];
        }

        return ['decisions' => $decisions, 'reason' => null];
    }
}
