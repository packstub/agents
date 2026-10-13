<?php

namespace Packstub\Agents\Contracts;

/**
 * Reads a reply typed over pending proposals when the word lists cannot (TypedDecisions, step 3; config
 * `decision_classifier`): for each proposal whether the reply approves it, rejects it or decides nothing. The
 * container binds the driver config `decision_classifier.driver` names (`agent`: the DecisionAgent side agent,
 * `jev`: TypeSafe's Jev decision model); bind your own class to read replies another way.
 *
 * A proposal left out, or given anything but approve or reject, makes the reply a question, and so does one decided
 * with a confidence under `decision_classifier.min_confidence`. A classifier that throws is reported and the reply is
 * a question too.
 */
interface DecisionClassifier
{
    public const string APPROVE = 'approve';

    public const string REJECT = 'reject';

    public const string QUESTION = 'question';

    /**
     * @param  array<string, array{name: string, arguments: array<string, mixed>, question: string}>  $proposals  call id => the proposed call and the question it asks
     * @param  string|null  $model  the model the reply was sent with
     * @return array{decisions: array<string, array{decision: string, confidence?: float|null}>, reason?: string|null} call id => approve, reject or question, with the confidence when the classifier gives one (0 to 1)
     */
    public function classify(string $reply, array $proposals, ?string $model = null): array;
}
