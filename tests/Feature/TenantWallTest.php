<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Ai\Models\Conversation;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Tests\Fixtures\Models\Team;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::tenantModel(Team::class, 'slug');
});

it('keeps the chats one person started in one workspace out of the other workspaces they belong to', function () {
    $owner = $this->user();
    $acme = $this->team($owner, 'acme');
    $globex = $this->team($owner, 'globex');
    $store = app(AgentConversationStore::class);

    Agents::tenantUsing(fn () => $acme);
    $inAcme = $store->startConversation($owner, 'Acme payroll for March');

    Agents::tenantUsing(fn () => $globex);
    $inGlobex = $store->startConversation($owner, 'Globex payroll for March');

    expect(Conversation::query()->find($inAcme)->tenant)->toBe((string) $acme->id)
        ->and(AgentConversationStore::conversationsOf($owner)->pluck('id')->all())->toBe([$inGlobex])
        ->and(AgentChat::for($owner)->owns($inAcme))->toBeFalse()
        ->and(fn () => AgentChat::for($owner, $inAcme))->toThrow(ModelNotFoundException::class)
        ->and(AgentChat::search($owner, 'payroll')->pluck('conversation')->all())->toBe([$inGlobex])
        ->and(app(AgentTurns::class)->owned($inAcme, $owner))->toBeFalse()
        ->and($store->conversationBelongsTo($inAcme, $owner->getMorphClass(), $owner->getKey()))->toBeFalse()
        ->and($store->conversationBelongsTo($inGlobex, $owner->getMorphClass(), $owner->getKey()))->toBeTrue();

    Agents::tenantUsing(fn () => $acme);
    expect(AgentChat::for($owner, $inAcme)->conversation())->toBe($inAcme)
        ->and(app(AgentTurns::class)->owned($inAcme, $owner))->toBeTrue();
});

it('shows every chat of the person in an app without workspaces', function () {
    $owner = $this->user();
    $first = app(AgentConversationStore::class)->startConversation($owner, 'First');

    expect(Conversation::query()->find($first)->tenant)->toBeNull()
        ->and(AgentChat::for($owner, $first)->conversation())->toBe($first);
});
