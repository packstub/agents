// Chart.js as its own file (dist/agents-chart.js), loaded by agent-chart.js when an answer has a chart.
import { Chart, registerables } from 'chart.js'

Chart.register(...registerables)

export { Chart }
