// The chat's script for a page without Filament (dist/agents-chat.js, a classic script placed before the chat).
// It registers the chat on the Alpine the page starts — Livewire's, or the app's own on window.Alpine — and, when the
// page turns out to have none, starts the copy bundled here. data-alpine on the script tag overrides the guess:
// "bundled" always starts the bundled copy, "external" never does.
import Alpine from 'alpinejs'
import collapse from '@alpinejs/collapse'
import agentChat from './agent-chat.js'
import agentChart from './agent-chart.js'

const mode = document.currentScript?.dataset.alpine || 'auto'
let registered = false

const register = (alpine) => {
    if (registered || ! alpine) return
    registered = true
    alpine.plugin(collapse)
    alpine.data('agentChat', agentChat)
    alpine.data('agentChart', agentChart)
}

const startBundled = () => {
    if (registered) return
    window.Alpine = Alpine
    Alpine.start()
}

document.addEventListener('alpine:init', () => register(window.Alpine))

if (mode !== 'bundled' && window.Alpine?.data && window.Alpine.version) {
    // Alpine is already running (a page swapped in by a navigation): register on it now.
    register(window.Alpine)
} else if (mode !== 'external') {
    // Once every other script had its chance to start Alpine.
    const decide = () => setTimeout(() => { if (mode === 'bundled' || ! window.Alpine) startBundled() }, 0)
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', decide) : decide()
}
