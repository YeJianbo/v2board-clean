<script setup>
import { ref, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { ElMessage } from 'element-plus'
import NodeConfigDialog from './NodeConfigDialog.vue'
import { fetchManagedNodes, fetchManagedNodeGroupOptions, fetchManagedNodeRoutes, fetchManagedMachines, saveManagedNode } from '../../services/nodes'
import { buildManagedNodeSubmitPayload } from '../../utils/managedNodeSubmit'
import { findNodeForEdit } from '../../utils/quickEditTarget'
const props = defineProps({ nodeKey: { type:String, required:true } })
const emit = defineEmits(['close','saved'])
const node = ref(null), visible = ref(false), saving = ref(false)
const groups = ref([]), routes = ref([]), machines = ref([]), parents = ref([]), displayGroups = ref([])
let disposed = false
const loadController = new AbortController()
onBeforeUnmount(() => { disposed = true; loadController.abort() })
onMounted(async () => {
  try {
    const options = { signal: loadController.signal }
    const [list, g, r, m] = await Promise.all([
      fetchManagedNodes(options),
      fetchManagedNodeGroupOptions(options),
      fetchManagedNodeRoutes(options),
      fetchManagedMachines({ ...options, optionsOnly: true }),
    ])
    if (disposed) return
    const found = findNodeForEdit(list.list, props.nodeKey)
    if (!found) throw new Error('节点不存在或无权查看')
    node.value = JSON.parse(JSON.stringify(found))
    groups.value = g.map(item => ({value:item.id,label:item.name}))
    routes.value = r.map(item => ({value:item.id,label:`${item.remarks} · ${item.action}`}))
    machines.value = m
    displayGroups.value = [...new Set(list.list.map(n => n.displayGroup).filter(Boolean))]
    parents.value = list.list.filter(n => !n.parentKey && !n.parentId && n.nodeKey !== found.nodeKey && n.storageType === found.storageType).map(n => ({
      value:String(n.id),label:n.name,host:n.host,port:n.port,serverPort:n.serverPort,machineId:n.machineId,relayMachineId:n.relayMachineId,
      protocol:n.displayProtocol,storageType:n.storageType,parentKey:'',parentId:'',isChild:false,
    }))
    await nextTick()
    if (!disposed) visible.value = true
  } catch (e) { loadController.abort(); if (!disposed) { ElMessage.error(e.message); emit('close') } }
})
async function save(payload) {
  if (disposed || saving.value) return
  saving.value = true
  try {
    await saveManagedNode(buildManagedNodeSubmitPayload(payload,{isEditing:true,activeNode:node.value,protocol:node.value.displayProtocol,storageType:node.value.storageType}))
    if (!disposed) { ElMessage.success('节点已更新'); emit('saved'); emit('close') }
  } catch(e) { if (!disposed) ElMessage.error(e.message) } finally { saving.value = false }
}
</script>
<template>
  <NodeConfigDialog v-if="node" :model-value="visible" mode="edit" :node="node" :protocol="node.displayProtocol" :storage-type="node.storageType"
    :group-options="groups" :route-options="routes" :machine-options="machines" :parent-options="parents" :display-group-options="displayGroups" :submitting="saving"
    @groups-updated="list => { groups = list.map(item => ({ value: String(item.id), label: item.name })) }"
    @update:model-value="value => { visible = value; if (!value) emit('close') }" @submit="save" />
</template>
