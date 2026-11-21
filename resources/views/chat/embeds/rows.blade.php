{{-- Rows under an answer, read-only: a caption with a count, the columns, and a link to all of them when there are
     more. Reads $title, $note, $columns (labels), $rows (cells of text and an optional url) and $more (url, label).
     A host names it, with the rows, as its embed view for "table". --}}
<div class="fi-chat-rows">
    <div class="fi-chat-rows-head">
        <p class="fi-chat-rows-title">{{ $title }}</p>
        @if ($note ?? null)
            <p class="fi-chat-rows-note">{{ $note }}</p>
        @endif
    </div>
    @if ($rows)
        <div class="fi-chat-rows-scroll">
            <table>
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th scope="col">{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            @foreach ($row as $cell)
                                <td>@if ($cell['url'] ?? null)<a href="{{ $cell['url'] }}">{{ $cell['text'] }}</a>@else{{ $cell['text'] }}@endif</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@if ($more ?? null)
    <a href="{{ $more['url'] }}" class="fi-chat-rows-more">{{ $more['label'] }} <span aria-hidden="true" class="fi-chat-flip">→</span></a>
@endif
