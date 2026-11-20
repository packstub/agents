{{-- A chart under an answer, drawn with Chart.js (loaded when the first chart appears). Reads $chart: type, data, title. --}}
<div
    data-agents-ignore
    x-data="agentChart({
        src: @js(\Packstub\Agents\Chat\ChatAssets::url('agents-chart.js')),
        type: @js($chart['type']),
        data: @js($chart['data']),
        legend: @js(count($chart['data']['datasets']) > 1 || in_array($chart['type'], ['pie', 'doughnut'])),
    })"
    class="fi-chat-chart"
    style="height: 18rem"
>
    <canvas x-ref="canvas" role="img" aria-label="{{ $chart['title'] }}" style="width: 100%; height: 100%"></canvas>
</div>
