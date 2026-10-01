<?php

namespace Packstub\Agents\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The prompt guard (config `prompt_guard`) read a question as something
 * other than safe: the category it chose, its reason, the question as typed,
 * and whether the turn was refused for it (the category is on the refuse
 * list) or let through. An audit trail or an alert hangs off this.
 */
class PromptFlagged
{
    use Dispatchable;

    public function __construct(
        public string $category,
        public string $reason,
        public string $question,
        public bool $refused,
    ) {}
}
