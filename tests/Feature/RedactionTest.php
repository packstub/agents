<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Packstub\Agents\Events\OutputRedacted;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentRedactor;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;
use Packstub\Agents\Tests\Fixtures\WidgetResource;
use Packstub\Agents\Tests\Fixtures\WidgetServer;

use function Pest\Laravel\actingAs;

// Secrets and personal data are replaced in what the assistant writes — while it streams, and as it is stored —
// and in the tool results kept with the answer.
beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::useServer(WidgetServer::class);
    Agents::useResources([WidgetResource::class]);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
});

const CARD = '4111 1111 1111 1111'; // the test card every gateway documents; it passes the Luhn check
const API_KEY = 'sk-ant-api03-Zk3vQ9xW7bT2mN5pL8rJ1dF4';

it('replaces card numbers, social security numbers and keys, the app\'s patterns and what its callback finds', function () {
    $redactor = new AgentRedactor;

    // Off: nothing is touched.
    expect(AgentRedactor::enabled())->toBeFalse()
        ->and($redactor->redact('Card '.CARD))->toBe('Card '.CARD)
        ->and($redactor->streaming('Card '.CARD))->toBe('Card '.CARD)
        ->and($redactor->redactResult(['card' => CARD]))->toBe(['card' => CARD]);

    config(['packstub-agents.redact.enabled' => true]);

    expect($redactor->redact('The card is '.CARD.', expiring 12/29.'))->toBe('The card is [redacted], expiring 12/29.')
        ->and($redactor->redact('Card 4111-1111-1111-1111 and 4111111111111111.'))->toBe('Card [redacted] and [redacted].')
        ->and($redactor->redact('Order 4111 1111 1111 1112 shipped.'))->toBe('Order 4111 1111 1111 1112 shipped.') // fails the Luhn check: not a card
        ->and($redactor->redact('Invoice 2026000123 for 1299.00, phone +40 721 000 111.'))->toBe('Invoice 2026000123 for 1299.00, phone +40 721 000 111.')
        ->and($redactor->redact('SSN 078-05-1120 on file.'))->toBe('SSN [redacted] on file.')
        ->and($redactor->redact('Part 000-12-3456 and 2024-05-1234.'))->toBe('Part 000-12-3456 and 2024-05-1234.')
        ->and($redactor->redact('Use '.API_KEY.' to call it.'))->toBe('Use [redacted] to call it.')
        ->and($redactor->redact('AWS AKIAIOSFODNN7EXAMPLE, GitHub ghp_'.str_repeat('a1B2', 9).'.'))->toBe('AWS [redacted], GitHub [redacted].')
        ->and($redactor->redact('Token 12|'.str_repeat('aB3d', 10).' is yours.'))->toBe('Token [redacted] is yours.') // a Sanctum token, as the Agent access page mints
        ->and($redactor->redact('Authorization: Bearer abcdefghijklmnopqrstuvwxyz0123456789'))->toBe('Authorization: [redacted]')
        ->and($redactor->redact("-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEA\n-----END RSA PRIVATE KEY-----"))->toBe('[redacted]')
        ->and($redactor->kinds())->toEqualCanonicalizing(['card', 'ssn', 'api_key'])
        ->and(AgentRedactor::luhn(CARD))->toBeTrue()
        ->and(AgentRedactor::luhn('0000 0000 0000 0000'))->toBeFalse()
        ->and(AgentRedactor::luhn('4111'))->toBeFalse();

    // A key that holds a card-like run of digits goes whole, not in pieces.
    expect($redactor->redact('sk-live-4111111111111111abcdEFGH'))->toBe('[redacted]');

    // The app's own: a labelled pattern (an invalid one is skipped), another replacement, a detector switched off, a callback.
    config([
        'packstub-agents.redact.patterns' => ['iban' => '/\bRO\d{2}[A-Z]{4}[A-Z0-9]{16}\b/', 'broken' => '/(unclosed/'],
        'packstub-agents.redact.replacement' => '•••',
        'packstub-agents.redact.detect.ssn' => false,
    ]);
    Agents::redactUsing(fn (string $text) => str_replace('Ada Lovelace', 'a customer', $text));
    $own = new AgentRedactor;

    expect($own->redact('IBAN RO49AAAA1B31007593840000 of Ada Lovelace, SSN 078-05-1120, card '.CARD.'.'))
        ->toBe('IBAN ••• of a customer, SSN 078-05-1120, card •••.')
        ->and($own->kinds())->toEqualCanonicalizing(['iban', 'card', 'custom']);

    // A plain list of detectors works too.
    config(['packstub-agents.redact.detect' => ['ssn']]);
    expect((new AgentRedactor)->redact('SSN 078-05-1120, card '.CARD))->toBe('SSN •••, card '.CARD);
});

it('keeps a JSON tool result JSON, and holds back a value that is still being written', function () {
    config(['packstub-agents.redact.enabled' => true]);
    $redactor = new AgentRedactor;

    $chart = json_encode(['chart' => ['title' => 'Cards', 'labels' => ['A'], 'datasets' => [['label' => 'n', 'data' => [4111111111111111, 3]]]], 'note' => 'Paid with '.CARD, 'total' => 12]);
    $redacted = json_decode($redactor->redactResult($chart), true);

    expect($redacted['chart']['datasets'][0]['data'])->toBe(['[redacted]', 3])
        ->and($redacted['note'])->toBe('Paid with [redacted]')
        ->and($redacted['total'])->toBe(12)
        ->and($redactor->redactResult('{"rows":[{"name":"Alpha"}]}'))->toBe('{"rows":[{"name":"Alpha"}]}') // untouched, byte for byte
        ->and($redactor->redactResult('Plain text with '.API_KEY))->toBe('Plain text with [redacted]')
        ->and($redactor->redactResult(['key' => API_KEY, 'n' => 5]))->toBe(['key' => '[redacted]', 'n' => 5])
        ->and($redactor->redactResult(null))->toBeNull();

    // While it streams: the piece under way is not shown — digits that may become a card number, a word that may
    // become a key — and the whole value appears replaced once it is complete.
    $fresh = new AgentRedactor;
    expect($fresh->streaming('The card is 4111 1111'))->toBe('The card is ')
        ->and($fresh->streaming('The card is '.CARD))->toBe('The card is ')
        ->and($fresh->streaming('The card is '.CARD.' and'))->toBe('The card is [redacted] ')
        ->and($fresh->streaming('The key is sk-ant-api03-Zk3v'))->toBe('The key is ')
        ->and($fresh->streaming('The key is '.API_KEY."\n"))->toBe("The key is [redacted]\n")
        ->and($fresh->streaming("Two widgets are live.\n"))->toBe("Two widgets are live.\n")
        ->and($fresh->kinds())->toBe([]); // only what is stored counts as replaced
});

it('never shows a secret while the answer streams, stores the answer and its tool results redacted, and reports once', function () {
    $user = $this->user();
    actingAs($user);
    Widget::query()->create(['name' => API_KEY, 'status' => 'live', 'price' => 10]);

    // Long enough for a snapshot to fall in the middle of the card number (one is written every ~120 characters).
    $answer = trim(str_repeat('word ', 23)).' '.CARD.' is the card on file, and the widget is named '.API_KEY.".\nThat is all.";
    $snapshots = [];
    AgentTurn::saving(function (AgentTurn $turn) use (&$snapshots) {
        $snapshots[] = (string) $turn->text;
    });

    // Without redaction the snapshots show the number as it arrives — what the page would have displayed.
    WidgetAgent::fake([$answer]);
    AgentChat::for($user)->send('Which card is on file?');
    expect(collect($snapshots)->contains(fn (string $text) => str_contains($text, '4111 1111')))->toBeTrue();

    config(['packstub-agents.redact.enabled' => true]);
    $snapshots = [];
    Event::fake([OutputRedacted::class]);
    Log::shouldReceive('critical')->once()->withArgs(fn (string $message, array $context) => str_starts_with($message, 'Agent output redacted: ')
        && collect(['card', 'api_key'])->diff($context['kinds'])->isEmpty() && filled($context['turn']) && $context['user'] === $user->id);

    WidgetAgent::fake([new ToolCall('c1', 'list-widgets', ['limit' => 5]), $answer]);
    $chat = AgentChat::for($user);
    $turn = $chat->send('Which card is on file?');

    expect($turn->status)->toBe(AgentTurn::DONE)
        ->and(count($snapshots))->toBeGreaterThan(3)
        ->and(collect($snapshots)->contains(fn (string $text) => str_contains($text, '4111') || str_contains($text, 'sk-ant')))->toBeFalse()
        ->and($turn->text)->toContain('[redacted] is the card on file, and the widget is named [redacted].')
        ->and($turn->text)->not->toContain('4111');

    $stored = ConversationMessage::query()->where('conversation_id', $chat->conversation())->where('role', 'assistant')->sole();
    $raw = json_encode($stored->getAttributes());
    $messages = $chat->messages();
    $result = json_decode($messages[1]['tools'][0]['result'], true);

    expect($raw)->not->toContain('4111 1111')->not->toContain('sk-ant-api03')
        ->and($stored->content)->toContain('[redacted] is the card on file')
        ->and($result['rows'][0]['name'])->toBe('[redacted]') // the tool result stays JSON
        ->and($result['total'])->toBe(1)
        ->and($messages[1]['html'])->toContain('[redacted]')
        ->and($messages[0]['text'])->toBe('Which card is on file?'); // what the person typed is theirs

    // The model reads the redacted result again as history.
    $history = app(AgentConversationStore::class)->getLatestConversationMessages($chat->conversation(), 10);
    expect(json_encode($history->first(fn ($m) => $m instanceof ToolResultMessage)->toolResults->first()->result))->toContain('[redacted]')->not->toContain('sk-ant');

    Event::assertDispatched(OutputRedacted::class, 1);
    Event::assertDispatched(OutputRedacted::class, fn (OutputRedacted $e) => in_array('card', $e->kinds, true) && $e->context['turn'] === $turn->id && $e->context['conversation'] === $chat->conversation());
});

it('redacts the result an approved tool wrote to the paused answer, and says nothing when there is nothing to replace', function () {
    $user = $this->user();
    actingAs($user);
    config(['packstub-agents.redact.enabled' => true]);
    Event::fake([OutputRedacted::class]);
    $store = app(AgentConversationStore::class);

    $conversation = $store->startConversation($user, 'Mint a token');
    $store->storeQuestion($conversation, $user, WidgetAgent::class, 'Mint a token for the importer.');
    $paused = ConversationMessage::query()->create([
        'id' => (string) Str::uuid7(), 'conversation_id' => $conversation, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => 'Shall I?', 'attachments' => [], 'usage' => [], 'meta' => [],
        'steps' => [['content' => 'Shall I?', 'reasoning' => '', 'replay_blocks' => [['type' => 'tool_use', 'id' => 'c1', 'input' => ['id' => 1]]], 'provider_tool_calls' => [], 'tool_calls' => [
            ['id' => 'c1', 'name' => 'rename-widget', 'arguments' => ['id' => 1, 'name' => 'Importer'], 'result_id' => null, 'approval_reason' => 'Rename?'],
        ]]],
        'status' => MessageStatus::Paused,
    ]);

    $store->storeApprovalResults($conversation, [new ToolResult('c1', 'rename-widget', ['id' => 1, 'name' => 'Importer'], json_encode(['renamed' => true, 'token' => '7|'.str_repeat('xY9z', 10)]))]);

    $call = $paused->fresh()->steps[0]['tool_calls'][0];
    expect(json_decode($call['result'], true))->toBe(['renamed' => true, 'token' => '[redacted]'])
        ->and($paused->fresh()->steps[0]['replay_blocks'])->toBe([['type' => 'tool_use', 'id' => 'c1', 'input' => ['id' => 1]]]); // what the provider resumes from is left as it was
    Event::assertDispatched(OutputRedacted::class, fn (OutputRedacted $e) => $e->kinds === ['api_key'] && $e->context['conversation'] === $conversation);

    // An answer with nothing in it is stored as it came, and nothing is logged or fired.
    Event::fake([OutputRedacted::class]);
    Log::shouldReceive('critical')->never();
    WidgetAgent::fake(['Two widgets are live.']);
    $chat = AgentChat::for($user);
    expect($chat->send('How many widgets are live?')->text)->toBe('Two widgets are live.')
        ->and($chat->messages()[1]['text'])->toBe('Two widgets are live.');
    Event::assertNotDispatched(OutputRedacted::class);
});
