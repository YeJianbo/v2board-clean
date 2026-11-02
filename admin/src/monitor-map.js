import { createApp, h, shallowRef } from 'vue'
import MachineWorldMap from './components/machines/MachineWorldMap.vue'

export function mount(element, machines, onSelect) {
  const rows = shallowRef(machines)
  const app = createApp({ render: () => h(MachineWorldMap, { machines: rows.value, onSelect }) })
  app.mount(element)
  return { update(machines) { rows.value = machines }, destroy() { app.unmount() } }
}
