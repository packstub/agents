{{-- Under an answer: the record tables and charts its tools returned, each drawn by the view the host names for it
     (a chart by this package, or by Filament's chart widget in a panel; a table only where the host has one). Reads
     $message. Part of chat.blade.php, included with its variables; publish this file alone to change only this part. --}}
@if ($tableView = $host->embedView('table'))
    @foreach ($message['tables'] as $i => $table)
        <div class="fi-chat-table-ctn" data-key="table-{{ $message['id'] }}-{{ $i }}">
            @include($tableView, ['table' => $table, 'key' => 'agent-table-'.$message['id'].'-'.$i])
        </div>
    @endforeach
@endif

@if ($chartView = $host->embedView('chart'))
    @foreach ($message['charts'] as $i => $chart)
        <div class="fi-chat-chart-box" data-key="chart-{{ $message['id'] }}-{{ $i }}">
            @if ($chart['title'])
                <p class="fi-chat-chart-title">{{ $chart['title'] }}</p>
            @endif
            @include($chartView, ['chart' => $chart])
        </div>
    @endforeach
@endif
