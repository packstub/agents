<?php

namespace Packstub\Agents\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The redactor replaced something in what the assistant wrote or in a tool
 * result kept with the answer (config `redact`): which kinds ("card", "ssn",
 * "api_key", a pattern's label, "custom") and where (the turn and the
 * conversation when a chat turn did it, the person, the workspace) — never
 * the values. A `critical` log line says the same.
 */
class OutputRedacted
{
    use Dispatchable;

    /**
     * @param  list<string>  $kinds
     * @param  array<string, mixed>  $context
     */
    public function __construct(public array $kinds, public array $context = []) {}
}
