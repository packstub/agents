// A chart under an answer, for a page without Filament's chart widget: Chart.js, fetched once when the first chart
// appears. Colours come from the chat's palette (the --ac-* variables), unless the data names its own.
let library = null

const categorical = ['#6366f1', '#14b8a6', '#f59e0b', '#ef4444', '#8b5cf6', '#0ea5e9', '#84cc16', '#ec4899']

export default function agentChart({ src, type, data, legend }) {
    return {
        chart: null,

        async init() {
            library ??= import(/* @vite-ignore */ src)
            const { Chart } = await library
            if (! this.$refs.canvas?.isConnected) return

            const css = getComputedStyle(this.$el)
            const read = (name, fallback) => css.getPropertyValue(name).trim() || fallback
            const accent = read('--ac-primary-500', categorical[0])
            const text = read('--ac-gray-500', '#71717a')
            const grid = document.documentElement.classList.contains('dark') ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.08)'
            const round = ['pie', 'doughnut', 'polarArea'].includes(type)

            const datasets = (data.datasets ?? []).map((dataset, i) => {
                const color = round ? categorical : (i === 0 ? accent : categorical[i % categorical.length])
                return { backgroundColor: color, borderColor: round ? 'transparent' : color, ...dataset }
            })

            this.chart = new Chart(this.$refs.canvas, {
                type,
                data: { ...data, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    color: text,
                    plugins: { legend: { display: legend, labels: { color: text } } },
                    scales: round ? {} : { x: { ticks: { color: text }, grid: { color: grid } }, y: { ticks: { color: text }, grid: { color: grid } } },
                },
            })
        },

        destroy() {
            this.chart?.destroy()
        },
    }
}
