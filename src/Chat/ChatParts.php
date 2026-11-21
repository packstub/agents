<?php

namespace Packstub\Agents\Chat;

/**
 * The parts of a rendered chat (a message, the header, the context ring):
 * each has a key and a revision, written on its root element as data-key and
 * data-rev. A page that refreshes says which revisions it shows; a part it
 * already has is not rendered again, and a stub goes in its place, which the
 * page's morph leaves alone. So the refresh of a long chat is short, and a
 * table under an old answer is not queried on every refresh.
 *
 *     @agentsPart('message-'.$message['id'], $rev) … @endAgentsPart
 *
 * Without a revision the part's own HTML is hashed (it is rendered, but not
 * sent when the page has it). A part has one root element.
 */
class ChatParts
{
    /** @var array<string, string> key => revision the page shows */
    protected array $have = [];

    /** @var list<array{string, ?string, bool}> */
    protected array $stack = [];

    /** @param  array<string, string>  $have */
    public function known(array $have): void
    {
        $this->have = array_filter($have, 'is_string');
        // A render that threw inside a part leaves it open: the next one starts clean.
        $this->stack = [];
    }

    /** A revision from whatever the part is drawn from. */
    public static function rev(mixed ...$inputs): string
    {
        return substr(md5((string) json_encode($inputs)), 0, 12);
    }

    /** Whether the part's body is to be rendered: false when the page has this revision. */
    public function begin(string $key, ?string $rev = null): bool
    {
        if ($rev !== null && ($this->have[$key] ?? null) === $rev) {
            $this->stack[] = [$key, $rev, false];

            return false;
        }

        $this->stack[] = [$key, $rev, true];
        ob_start();

        return true;
    }

    public function end(): string
    {
        [$key, $rev, $rendered] = array_pop($this->stack);

        if (! $rendered) {
            return $this->stub($key, (string) $rev);
        }

        $html = (string) ob_get_clean();

        if (trim($html) === '') {
            return '';
        }

        $rev ??= substr(md5($html), 0, 12);

        if (($this->have[$key] ?? null) === $rev) {
            return $this->stub($key, $rev);
        }

        return (string) preg_replace('/^(\s*<[a-zA-Z][\w:-]*)/', '$1 data-key="'.e($key).'" data-rev="'.e($rev).'"', $html, 1);
    }

    protected function stub(string $key, string $rev): string
    {
        return '<div data-key="'.e($key).'" data-rev="'.e($rev).'" data-agents-stub></div>';
    }
}
