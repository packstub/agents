<?php

use Packstub\Agents\Facades\Agents;

$locales = collect(glob(__DIR__.'/../../resources/lang/*.json'))->map(fn ($file) => basename($file, '.json'))->reject('ru')->values()->all();

function langJson(string $locale): array
{
    return json_decode(file_get_contents(__DIR__.'/../../resources/lang/'.$locale.'.json'), true, flags: JSON_THROW_ON_ERROR);
}

function placeholders(string $text): array
{
    preg_match_all('/:[a-z_]+/i', $text, $found);
    $found = array_unique($found[0]);
    sort($found);

    return $found;
}

it('ships he and ar', function () {
    expect(glob(__DIR__.'/../../resources/lang/*.json'))->toContain(__DIR__.'/../../resources/lang/he.json', __DIR__.'/../../resources/lang/ar.json');
});

it('has every key of ru.json, no more, and the same placeholders, in :dataset', function (string $locale) {
    $ru = langJson('ru');
    $translated = langJson($locale);

    expect(array_keys($translated))->toEqualCanonicalizing(array_keys($ru));

    foreach ($translated as $key => $text) {
        expect(is_string($text) && $text !== '')->toBeTrue()
            ->and(str_contains($key, '|') ? array_diff(placeholders($text), placeholders($key)) : placeholders($text))
            ->toBe(str_contains($key, '|') ? [] : placeholders($ru[$key]), $locale.': '.$key);
    }
})->with($locales);

it('calls the assistant by the default name in the person\'s language, and keeps a brand name as it is', function () {
    app()->setLocale('ru');
    config(['packstub-agents.name' => 'Assistant']);
    expect(Agents::name())->toBe('Ассистент');

    config(['packstub-agents.name' => 'Ask Acme']);
    expect(Agents::name())->toBe('Ask Acme');
});
