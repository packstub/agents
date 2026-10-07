<?php

namespace Packstub\Agents\Support;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Packstub\Agents\Ai\Side\DecisionAgent;
use Packstub\Agents\Facades\Agents;
use Throwable;

/**
 * What a reply typed over pending proposals decides, asked in order:
 *
 * 1. the app's own rule (Agents::decideTypedUsing()), when it has one and it decides;
 * 2. the word lists (resources/lang/<locale>/decisions.php, every locale's; a list in the app's
 *    lang/vendor/packstub-agents/<locale>/decisions.php replaces the package's): a reply made of nothing but yes
 *    phrases approves every proposal, one that is or opens with a no rejects them;
 * 3. the DecisionAgent side agent (config `decision_classifier`, off by default), for a reply the lists cannot read,
 *    which may decide each proposal on its own ("Yes, but only Alpha.").
 *
 * None of them deciding means the reply is a question of its own: the proposals are declined with a note the model
 * reads and it may propose again. The lists come before the classifier, so it never approves what they reject.
 */
class TypedDecisions
{
    public const string BY_APP = 'app';

    public const string BY_WORDS = 'words';

    public const string BY_CLASSIFIER = 'classifier';

    /** Longer replies are questions, whatever words they hold: the lists read up to this many, the classifier up to CLASSIFY_WORDS. */
    public const int LIST_WORDS = 6;

    public const int CLASSIFY_WORDS = 40;

    /**
     * Decide the pending proposals from the reply, or null when it is not a decision.
     *
     * @param  array<string, array{name: string, arguments: array<string, mixed>}>  $pending  call id => the proposed call
     * @return array{decisions: array<string, bool>, by: string, reason: ?string}|null
     */
    public function decide(string $text, array $pending, ?string $model = null): ?array
    {
        if ($pending === []) {
            return null;
        }

        // Built only for the rule and the classifier: it resolves every write tool, which a plain "yes" never needs.
        $built = null;
        $build = function () use ($pending, &$built): array {
            return $built ??= $this->proposals($pending);
        };

        if (($decided = $this->byApp($text, $build)) !== null) {
            return ['decisions' => $decided, 'by' => self::BY_APP, 'reason' => null];
        }

        if (($decision = self::fromWords($text)) !== null) {
            return ['decisions' => array_fill_keys(array_keys($pending), $decision), 'by' => self::BY_WORDS, 'reason' => null];
        }

        return $this->byClassifier($text, $build, $model);
    }

    /** What the word lists alone make of a reply: true, false, or null when it is not a decision they can read. */
    public static function fromWords(string $text): ?bool
    {
        $t = self::normalize($text);

        if ($t === '' || count(explode(' ', $t)) > self::LIST_WORDS) {
            return null;
        }

        $lists = self::lists();

        if (in_array($t, $lists['no'], true)) {
            return false;
        }

        // A reply that opens with a no-word is a no, whatever follows ("No, leave them."): the proposals are rejected,
        // which the model can undo by proposing again.
        $words = explode(' ', $t);
        if (in_array($words[0], $lists['no_openers'], true)) {
            return false;
        }

        // A yes runs the write, so only a reply made of nothing but yes phrases is one ("Sure, confirm it."). A yes-word
        // followed by anything else — a condition ("Yes, but only Alpha."), a question ("Ok wait, what does this change?"),
        // an "if" ("Si lo apruebo, ¿qué cambia?"), a "not now" ("Sure, after lunch.") — is not a decision, and neither is
        // a yes asked back ("Ok?", "¿Confirmar?"): the punctuation is gone from $t, so the question mark is read on $text.
        return ! preg_match('/[?¿？]/u', $text) && self::madeOf($words, $lists['yes']) ? true : null;
    }

    /**
     * The yes, no and no-opener lists of every locale the package ships and the app published, merged. A list the
     * app's file sets replaces the package's for that locale whole, so a phrase can be taken out as well as added
     * (the translator would merge the two by index); a key the app's file leaves out keeps the package's list.
     *
     * @return array{yes: list<string>, no: list<string>, no_openers: list<string>}
     */
    public static function lists(): array
    {
        $lists = ['yes' => [], 'no' => [], 'no_openers' => []];

        foreach (self::locales() as $locale) {
            $lines = [];

            foreach (self::paths() as $path) {
                $file = "{$path}/{$locale}/decisions.php";

                if (File::exists($file) && is_array($read = File::getRequire($file))) {
                    $lines = array_replace($lines, $read);
                }
            }

            foreach (array_keys($lists) as $key) {
                foreach ((array) ($lines[$key] ?? []) as $phrase) {
                    if (is_string($phrase) && ($phrase = self::normalize($phrase)) !== '') {
                        $lists[$key][] = $phrase;
                    }
                }
            }
        }

        return array_map(fn (array $list) => array_values(array_unique($list)), $lists);
    }

    /**
     * The locales with a decisions file: the package's, then the app's under lang/vendor/packstub-agents.
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        $locales = [];

        foreach (self::paths() as $path) {
            foreach (File::isDirectory($path) ? File::directories($path) : [] as $dir) {
                if (File::exists($dir.'/decisions.php')) {
                    $locales[] = basename($dir);
                }
            }
        }

        return array_values(array_unique($locales));
    }

    /**
     * Where the decisions files live, the app's last.
     *
     * @return list<string>
     */
    protected static function paths(): array
    {
        return [__DIR__.'/../../resources/lang', lang_path('vendor/packstub-agents')];
    }

    /** Lowercase, punctuation and symbols out, one space between words: how a reply and a list entry are compared. */
    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{P}\p{S}]+/u', ' ', Str::lower($text))));
    }

    /**
     * Whether the words, in order, split into phrases that are all in the list.
     *
     * @param  array<int, string>  $words
     * @param  array<int, string>  $phrases
     */
    protected static function madeOf(array $words, array $phrases): bool
    {
        $count = count($words);
        $reachable = [0 => true];

        // At most LIST_WORDS words, so trying every slice stays cheap.
        for ($from = 0; $from < $count; $from++) {
            if (! isset($reachable[$from])) {
                continue;
            }
            for ($to = $from + 1; $to <= $count; $to++) {
                if (in_array(implode(' ', array_slice($words, $from, $to - $from)), $phrases, true)) {
                    $reachable[$to] = true;
                }
            }
        }

        return isset($reachable[$count]);
    }

    /**
     * The app's rule: true or false decides every proposal, an array decides them one by one (a call it leaves out,
     * or gives anything but true, is rejected), null leaves the reply to the lists. One that throws is reported and
     * left to the lists too.
     *
     * @param  Closure(): array<string, array{name: string, arguments: array<string, mixed>, question: string}>  $build  the pending calls with their questions
     * @return array<string, bool>|null
     */
    protected function byApp(string $text, Closure $build): ?array
    {
        if (! ($callback = Agents::typedDecider())) {
            return null;
        }

        try {
            $proposals = $build();
            $decided = $callback($text, $proposals);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return match (true) {
            is_bool($decided) => array_fill_keys(array_keys($proposals), $decided),
            is_array($decided) => array_map(fn (string $id) => ($decided[$id] ?? false) === true, array_combine(array_keys($proposals), array_keys($proposals))),
            default => null,
        };
    }

    /**
     * The classifier's reading, applied only when it decided every proposal: one it left undecided, or a failure,
     * makes the reply a question.
     *
     * @param  Closure(): array<string, array{name: string, arguments: array<string, mixed>, question: string}>  $build  the pending calls with their questions
     * @return array{decisions: array<string, bool>, by: string, reason: ?string}|null
     */
    protected function byClassifier(string $text, Closure $build, ?string $model): ?array
    {
        $words = self::normalize($text);

        if (! (bool) config('packstub-agents.decision_classifier.enabled', false) || $words === '' || count(explode(' ', $words)) > self::CLASSIFY_WORDS) {
            return null;
        }

        // With the assistant faked in a test the classifier runs only when it is faked too.
        if (! DecisionAgent::runsBeside(Agents::agentClass())) {
            return null;
        }

        try {
            $proposals = $build();
            $provider = Ai::textProvider(config('packstub-agents.decision_classifier.provider') ?: AgentModels::resolve($model)['provider']);
            $verdict = DecisionAgent::run(DecisionAgent::input(Str::limit($text, 1000), $proposals), $provider, config('packstub-agents.decision_classifier.model') ?: null);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $read = [];
        foreach ((array) ($verdict['decisions'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['id'], $proposals[(string) $entry['id']])) {
                $read[(string) $entry['id']] = $entry['decision'] ?? null;
            }
        }

        $decisions = [];
        foreach (array_keys($proposals) as $id) {
            $decision = $read[$id] ?? null;

            if (! in_array($decision, [DecisionAgent::APPROVE, DecisionAgent::REJECT], true)) {
                return null;
            }

            $decisions[$id] = $decision === DecisionAgent::APPROVE;
        }

        $reason = Str::limit(trim((string) ($verdict['reason'] ?? '')), 300);

        return ['decisions' => $decisions, 'by' => self::BY_CLASSIFIER, 'reason' => $reason !== '' ? $reason : null];
    }

    /**
     * The pending calls with the question each one asks, as a surface shows it.
     *
     * @param  array<string, array{name: string, arguments: array<string, mixed>}>  $pending
     * @return array<string, array{name: string, arguments: array<string, mixed>, question: string}>
     */
    protected function proposals(array $pending): array
    {
        $tools = AgentChat::writeTools();

        return collect($pending)->map(fn (array $call) => [
            'name' => (string) $call['name'],
            'arguments' => (array) $call['arguments'],
            'question' => AgentChat::question($tools->get($call['name']), (string) $call['name'], (array) $call['arguments']),
        ])->all();
    }
}
