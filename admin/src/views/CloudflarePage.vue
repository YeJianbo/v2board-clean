<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, RefreshCw } from 'lucide-vue-next'
import SectionCard from '../components/common/SectionCard.vue'
import { fetchCloudflareCredentials, fetchCloudflareZones, fetchCloudflareRecords, mutateCloudflare } from '../services/cloudflare'

const tab = ref('credentials')
const credentials = ref([])
const credentialId = ref(null)
const selectedCredential = computed(() => credentials.value.find(item => item.id === credentialId.value))
const zones = ref([])
const zoneId = ref('')
const selectedZone = computed(() => zones.value.find(item => item.id === zoneId.value))
const zonePage = ref(1)
const zoneTotal = ref(0)
const records = ref([])
const recordPage = ref(1)
const recordTotal = ref(0)
const search = ref('')
const recordType = ref('')
const submittedQuery = ref({ name: '', type: '' })
const busy = ref(false)
const loading = ref({ credentials: false, zones: false, records: false })
const errors = ref({ credentials: '', zones: '', records: '' })
const credentialDialog = ref(false)
const recordDialog = ref(false)
const credentialForm = ref({})
const recordForm = ref({})
const formError = ref('')
const recordTypes = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'NS', 'CAA', 'SRV', 'PTR', 'HTTPS', 'SVCB', 'LOC', 'NAPTR', 'SSHFP', 'TLSA', 'URI', 'DNSKEY', 'DS', 'CERT', 'SMIMEA', 'OPENPGPKEY']
const canWrite = computed(() => Boolean(selectedCredential.value?.enabled && selectedZone.value))
let disposed = false
const reads = {}

async function read(kind, operation, apply) {
  if (disposed) return
  reads[kind]?.abort()
  const controller = new AbortController()
  reads[kind] = controller
  loading.value[kind] = true
  errors.value[kind] = ''
  try {
    const result = await operation(controller.signal)
    if (!disposed && reads[kind] === controller && !controller.signal.aborted) apply(result)
  } catch (error) {
    if (!disposed && reads[kind] === controller && !controller.signal.aborted) errors.value[kind] = error.message
  } finally {
    if (!disposed && reads[kind] === controller) {
      loading.value[kind] = false
      delete reads[kind]
    }
  }
}

async function loadCredentials() {
  await read('credentials', signal => fetchCloudflareCredentials({ signal }), result => {
    credentials.value = result
    if (!result.some(item => item.id === credentialId.value)) credentialId.value = result[0]?.id ?? null
  })
}

async function loadZones() {
  if (!credentialId.value) return
  const id = credentialId.value
  const page = zonePage.value
  await read('zones', signal => fetchCloudflareZones(id, page, { signal }), result => {
    if (credentialId.value !== id || zonePage.value !== page) return
    zones.value = result.data
    zoneTotal.value = Number(result.pagination?.total_count || 0)
    if (!zones.value.some(item => item.id === zoneId.value)) zoneId.value = zones.value[0]?.id || ''
  })
}

async function loadRecords() {
  if (!credentialId.value || !zoneId.value) return
  const params = { credential_id: credentialId.value, zone_id: zoneId.value, page: recordPage.value, per_page: 25, ...submittedQuery.value }
  await loadRecordPage(params)
}

async function loadRecordPage(params) {
  let correction
  await read('records', signal => fetchCloudflareRecords(params, { signal }), result => {
    if (credentialId.value !== params.credential_id || zoneId.value !== params.zone_id || recordPage.value !== params.page) return
    records.value = result.data
    recordTotal.value = Number(result.pagination?.total_count || 0)
    const total = result.pagination?.total_count == null ? NaN : Number(result.pagination.total_count)
    if (result.data.length || !Number.isSafeInteger(total) || total < 0) return
    const lastPage = Math.max(1, Math.ceil(total / params.per_page))
    if (params.page > lastPage) {
      recordPage.value = lastPage
      // Reuse the submitted query, not search fields edited while this read was pending.
      correction = loadRecordPage({ ...params, page: lastPage })
    }
  })
  await correction
}

watch(credentialId, () => {
  reads.zones?.abort()
  reads.records?.abort()
  zones.value = []
  zoneId.value = ''
  zoneTotal.value = 0
  zonePage.value = 1
  errors.value.zones = ''
  if (tab.value !== 'credentials') loadZones()
})
watch(zoneId, () => {
  reads.records?.abort()
  records.value = []
  recordTotal.value = 0
  recordPage.value = 1
  errors.value.records = ''
  if (tab.value === 'records') loadRecords()
})
watch(tab, value => {
  if (value !== 'credentials' && !zones.value.length && credentialId.value) loadZones()
  else if (value === 'records') loadRecords()
})

async function refresh() {
  if (busy.value) return
  const currentTab = tab.value
  if (currentTab === 'credentials') await loadCredentials()
  else {
    const previousCredentialId = credentialId.value
    if (!credentials.value.length || errors.value.credentials) await loadCredentials()
    if (disposed || tab.value !== currentTab || !credentialId.value || errors.value.credentials) return
    // A changed selection already starts its dependent reads through the watchers.
    if (credentialId.value !== previousCredentialId) return
    if (currentTab === 'zones') await loadZones()
    else {
      const previousZoneId = zoneId.value
      if (!selectedZone.value || errors.value.zones) await loadZones()
      if (zoneId.value !== previousZoneId) return
      if (!disposed && tab.value === currentTab && !errors.value.zones) await loadRecords()
    }
  }
}

function openCredential(item) {
  if (busy.value) return
  credentialForm.value = {
    id: item?.id ?? null, revision: item?.revision ?? 1, name: item?.name || '', api_token: '',
    account_id: item?.account_id || '', zone_ids: (item?.allowed_zone_ids || []).join('\n'), enabled: item?.enabled || false,
  }
  formError.value = ''
  credentialDialog.value = true
}

function openRecord(item) {
  if (busy.value || !canWrite.value) return
  recordForm.value = {
    credential_id: credentialId.value, zone_id: zoneId.value, record_id: item?.id || null,
    type: item?.type || 'A', name: item?.name || selectedZone.value.name, content: item?.content || '',
    ttl: Number(item?.ttl || 1), proxied: Boolean(item?.proxied), priority: item?.priority ?? null,
    comment: item?.comment || '', data_json: item?.data && Object.keys(item.data).length ? JSON.stringify(item.data, null, 2) : '',
  }
  formError.value = ''
  recordDialog.value = true
}

async function mutate(operation, refreshOperation, message) {
  if (disposed || busy.value) return
  busy.value = true
  formError.value = ''
  try {
    await operation()
    if (disposed) return
    ElMessage.success(message)
    await refreshOperation()
  } catch (error) {
    if (!disposed && error !== 'cancel' && error !== 'close') {
      formError.value = error.message || String(error)
      ElMessage.error(formError.value)
    }
  } finally {
    if (!disposed) busy.value = false
  }
}

async function saveCredential() {
  const draft = { ...credentialForm.value }
  const ids = [...new Set(draft.zone_ids.split(/[\s,]+/).filter(Boolean).map(id => id.toLowerCase()))]
  if (!draft.name.trim() || !ids.length || ids.some(id => !/^[a-f0-9]{32}$/.test(id))) {
    formError.value = '请填写名称和有效的 Zone ID，每行一个'
    return
  }
  if (!draft.id && !draft.api_token.trim()) {
    formError.value = '请填写 API Token'
    return
  }
  const payload = { id: draft.id, revision: draft.revision, name: draft.name.trim(), account_id: draft.account_id.trim() || null, allowed_zone_ids: ids, enabled: draft.enabled }
  if (draft.api_token.trim()) payload.api_token = draft.api_token.trim()
  await mutate(async () => {
    const previous = credentials.value.find(item => item.id === draft.id)
    if (draft.enabled && !previous?.enabled) await ElMessageBox.confirm('启用后，绑定此凭据的 DDNS、证书和 DNS 操作可修改 Cloudflare。是否启用？', '启用凭据', { type: 'warning', confirmButtonText: '启用' })
    if (disposed) return
    await mutateCloudflare('saveCredential', payload)
    if (!disposed) { credentialDialog.value = false; credentialForm.value.api_token = '' }
  }, loadCredentials, '凭据已保存')
}

async function verifyCredential(item) {
  await mutate(() => mutateCloudflare('verifyCredential', { credential_id: item.id }), loadCredentials, 'Token 验证结果已更新')
}

async function deleteCredential(item) {
  await mutate(async () => {
    await ElMessageBox.confirm(`删除凭据“${item.name}”？仍被引用的凭据不能删除。`, '删除凭据', { type: 'warning', confirmButtonText: '删除' })
    if (disposed) return
    await mutateCloudflare('dropCredential', { id: item.id, revision: item.revision })
  }, loadCredentials, '凭据已删除')
}

async function saveRecord() {
  const draft = { ...recordForm.value }
  const payload = { credential_id: draft.credential_id, zone_id: draft.zone_id, record_id: draft.record_id, type: draft.type, name: draft.name.trim(), ttl: Number(draft.ttl), proxied: draft.proxied, comment: draft.comment || null }
  if (!payload.name) { formError.value = '请填写完整记录名称'; return }
  if (draft.data_json.trim()) {
    try {
      payload.data = JSON.parse(draft.data_json)
      if (!payload.data || typeof payload.data !== 'object' || Array.isArray(payload.data)) throw new Error()
    } catch { formError.value = '结构化数据必须为 JSON 对象'; return }
  } else payload.content = draft.content
  if (['MX', 'SRV', 'URI'].includes(draft.type) && draft.priority !== null) payload.priority = Number(draft.priority)
  await mutate(async () => {
    await ElMessageBox.confirm(`保存将立即修改 DNS 记录 ${payload.name}，是否继续？`, '修改 DNS', { type: 'warning', confirmButtonText: '保存记录' })
    if (disposed) return
    await mutateCloudflare('saveRecord', payload)
    if (!disposed) recordDialog.value = false
  }, loadRecords, 'DNS 记录已保存')
}

async function deleteRecord(item) {
  const payload = { credential_id: credentialId.value, zone_id: zoneId.value, record_id: item.id }
  await mutate(async () => {
    await ElMessageBox.confirm(`删除 ${item.type} 记录 ${item.name}（${item.content}）？这会立即影响解析。`, '删除 DNS', { type: 'warning', confirmButtonText: '删除记录' })
    if (disposed) return
    await mutateCloudflare('dropRecord', payload)
  }, loadRecords, 'DNS 记录已删除')
}

function selectZone(item) { zoneId.value = item.id; tab.value = 'records' }
function searchRecords() {
  submittedQuery.value = { name: search.value.trim(), type: recordType.value }
  recordPage.value = 1
  return loadRecords()
}
function verificationText(item) { return { valid: 'Token 有效', failed: '验证失败', unverified: '未验证' }[item.verification_status] || '未验证' }
onMounted(loadCredentials)
onUnmounted(() => { disposed = true; Object.values(reads).forEach(controller => controller.abort()); credentialForm.value.api_token = '' })
</script>

<template>
  <div class="cloudflare-page">
    <SectionCard header-actions>
      <template #actions>
        <el-button :disabled="busy" @click="refresh"><RefreshCw :size="15" />刷新</el-button>
        <el-button v-if="tab === 'credentials'" type="primary" :disabled="busy" @click="openCredential()"><Plus :size="15" />添加凭据</el-button>
        <el-button v-if="tab === 'records'" type="primary" :disabled="busy || !canWrite" @click="openRecord()"><Plus :size="15" />添加记录</el-button>
      </template>
      <el-tabs v-model="tab">
        <el-tab-pane label="凭据" name="credentials" />
        <el-tab-pane label="域名" name="zones" />
        <el-tab-pane label="DNS 记录" name="records" />
      </el-tabs>
      <el-alert v-if="errors[tab]" :title="errors[tab]" type="error" :closable="false" show-icon class="cf-alert" />
      <el-alert v-if="tab !== 'credentials' && errors.credentials" :title="errors.credentials" type="error" :closable="false" show-icon class="cf-alert" />
      <template v-if="tab === 'credentials'">
        <el-table v-loading="loading.credentials" :data="credentials" empty-text="尚未添加凭据" row-key="id">
          <el-table-column prop="name" label="名称" min-width="150" />
          <el-table-column label="授权域名" width="110"><template #default="{ row }">{{ row.allowed_zone_ids.length }} 个</template></el-table-column>
          <el-table-column label="写入状态" width="110"><template #default="{ row }"><el-tag :type="row.enabled ? 'success' : 'info'">{{ row.enabled ? '已启用' : '未启用' }}</el-tag></template></el-table-column>
          <el-table-column label="Token 状态" min-width="160"><template #default="{ row }"><span :title="row.verification_error || ''">{{ verificationText(row) }}</span><small v-if="row.verification_error" class="cf-error">{{ row.verification_error }}</small></template></el-table-column>
          <el-table-column label="操作" width="190"><template #default="{ row }"><el-button link :disabled="busy" @click="verifyCredential(row)">验证</el-button><el-button link :disabled="busy" @click="openCredential(row)">编辑</el-button><el-button link type="danger" :disabled="busy" @click="deleteCredential(row)">删除</el-button></template></el-table-column>
        </el-table>
      </template>
      <template v-else>
        <div class="cf-filters">
          <el-select v-model="credentialId" placeholder="选择凭据" :disabled="busy" aria-label="Cloudflare 凭据"><el-option v-for="item in credentials" :key="item.id" :label="item.name" :value="item.id" /></el-select>
          <template v-if="tab === 'records'">
            <el-select v-model="zoneId" placeholder="选择域名" :disabled="busy || loading.zones" aria-label="域名"><el-option v-for="item in zones" :key="item.id" :label="item.name" :value="item.id" /></el-select>
            <el-input v-model="search" placeholder="完整记录名称" clearable :disabled="busy" aria-label="记录名称" @keyup.enter="searchRecords" />
            <el-select v-model="recordType" clearable placeholder="所有类型" :disabled="busy" aria-label="记录类型"><el-option v-for="type in recordTypes" :key="type" :label="type" :value="type" /></el-select>
            <el-button :disabled="busy || !zoneId" @click="searchRecords">查询</el-button>
          </template>
        </div>
        <el-empty v-if="!credentials.length && !loading.credentials && !errors.credentials" description="先添加专用 API Token，再选择授权域名"><el-button type="primary" :disabled="busy" @click="openCredential()">添加凭据</el-button></el-empty>
        <template v-else-if="tab === 'zones'">
          <el-table v-loading="loading.zones" :data="zones" empty-text="暂无可读取的域名" row-key="id">
            <el-table-column prop="name" label="域名" min-width="200" />
            <el-table-column prop="status" label="状态" width="110" />
            <el-table-column prop="account" label="账户" min-width="130" />
            <el-table-column prop="id" label="Zone ID" min-width="280" />
            <el-table-column label="操作" width="130"><template #default="{ row }"><el-button link :disabled="busy" @click="selectZone(row)">查看 DNS</el-button></template></el-table-column>
          </el-table>
        </template>
        <template v-else>
          <el-alert v-if="credentialId && !selectedCredential?.enabled" title="凭据未启用，当前仅可读取 DNS" type="info" :closable="false" class="cf-alert" />
          <el-alert v-if="errors.zones" :title="errors.zones" type="error" :closable="false" class="cf-alert" />
          <el-table v-loading="loading.records || loading.zones" :data="records" empty-text="暂无 DNS 记录" row-key="id">
            <el-table-column prop="type" label="类型" width="85" />
            <el-table-column prop="name" label="名称" min-width="210" show-overflow-tooltip />
            <el-table-column prop="content" label="内容" min-width="260" show-overflow-tooltip />
            <el-table-column label="代理" width="90"><template #default="{ row }">{{ row.proxied ? '已代理' : '仅 DNS' }}</template></el-table-column>
            <el-table-column label="TTL" width="85"><template #default="{ row }">{{ row.ttl === 1 ? '自动' : row.ttl }}</template></el-table-column>
            <el-table-column label="操作" width="130"><template #default="{ row }"><el-button link :disabled="busy || !canWrite" @click="openRecord(row)">编辑</el-button><el-button link type="danger" :disabled="busy || !canWrite" @click="deleteRecord(row)">删除</el-button></template></el-table-column>
          </el-table>
          <el-pagination v-if="recordTotal > 25" v-model:current-page="recordPage" :total="recordTotal" :page-size="25" layout="prev, pager, next" :disabled="busy" @current-change="loadRecords" />
        </template>
        <el-pagination v-if="zoneTotal > 5" v-model:current-page="zonePage" :total="zoneTotal" :page-size="5" layout="prev, pager, next" :disabled="busy" @current-change="loadZones" />
      </template>
    </SectionCard>

    <el-dialog v-model="credentialDialog" :title="credentialForm.id ? '编辑凭据' : '添加凭据'" width="560px" class="cf-dialog" :close-on-click-modal="!busy" :close-on-press-escape="!busy" :show-close="!busy" destroy-on-close @closed="credentialForm.api_token = ''">
      <el-form label-position="top" :disabled="busy" @submit.prevent="saveCredential">
        <el-form-item label="名称" required><el-input v-model="credentialForm.name" maxlength="100" /></el-form-item>
        <el-form-item :label="credentialForm.id ? 'API Token（留空保留）' : 'API Token'" :required="!credentialForm.id"><el-input v-model="credentialForm.api_token" type="password" autocomplete="new-password" /></el-form-item>
        <el-alert title="使用仅授权所需域名和权限的专用 API Token，不使用 Global API Key。面板内 Zone 限制不能替代 Cloudflare 侧授权；面板失陷时，已存 Token 仍可能泄露。" type="warning" :closable="false" class="cf-alert" />
        <el-form-item label="账户 ID（账户级 Token 验证及 Mesh 使用）"><el-input v-model="credentialForm.account_id" maxlength="32" /></el-form-item>
        <el-form-item label="允许使用的 Zone ID" required><el-input v-model="credentialForm.zone_ids" type="textarea" :rows="4" placeholder="每行一个 Zone ID，可在 Cloudflare 域名概览右侧找到" /></el-form-item>
        <el-form-item label="允许写入"><el-switch v-model="credentialForm.enabled" /></el-form-item>
        <el-alert v-if="formError" :title="formError" type="error" :closable="false" />
      </el-form>
      <template #footer><el-button :disabled="busy" @click="credentialDialog = false">取消</el-button><el-button type="primary" :loading="busy" @click="saveCredential">保存</el-button></template>
    </el-dialog>

    <el-dialog v-model="recordDialog" :title="recordForm.record_id ? '编辑 DNS 记录' : '添加 DNS 记录'" width="600px" class="cf-dialog" :close-on-click-modal="!busy" :close-on-press-escape="!busy" :show-close="!busy" destroy-on-close>
      <el-form label-position="top" :disabled="busy" @submit.prevent="saveRecord">
        <div class="cf-form-grid">
          <el-form-item label="类型"><el-select v-model="recordForm.type"><el-option v-for="type in recordTypes" :key="type" :label="type" :value="type" /></el-select></el-form-item>
          <el-form-item label="TTL（1 为自动）"><el-input-number v-model="recordForm.ttl" :min="1" :max="86400" /></el-form-item>
        </div>
        <el-form-item label="完整记录名称" required><el-input v-model="recordForm.name" placeholder="例如 www.example.com" /></el-form-item>
        <el-form-item label="记录内容"><el-input v-model="recordForm.content" type="textarea" :rows="3" /></el-form-item>
        <el-form-item v-if="['MX', 'SRV', 'URI'].includes(recordForm.type)" label="优先级"><el-input-number v-model="recordForm.priority" :min="0" :max="65535" /></el-form-item>
        <el-form-item v-if="['A', 'AAAA', 'CNAME'].includes(recordForm.type)" label="Cloudflare 代理"><el-switch v-model="recordForm.proxied" /></el-form-item>
        <el-form-item label="备注"><el-input v-model="recordForm.comment" maxlength="500" /></el-form-item>
        <el-collapse><el-collapse-item title="结构化数据（CAA、SRV 等）"><el-input v-model="recordForm.data_json" type="textarea" :rows="5" placeholder="JSON 对象；填写后优先使用结构化数据" /></el-collapse-item></el-collapse>
        <el-alert v-if="formError" :title="formError" type="error" :closable="false" class="cf-alert" />
      </el-form>
      <template #footer><el-button :disabled="busy" @click="recordDialog = false">取消</el-button><el-button type="primary" :loading="busy" @click="saveRecord">保存记录</el-button></template>
    </el-dialog>
  </div>
</template>

<style scoped>
.cf-filters { display: flex; flex-wrap: wrap; gap: 10px; margin: 4px 0 18px; }
.cf-filters .el-select { width: 190px; }
.cf-filters .el-input { width: 240px; }
.cf-alert { margin-bottom: 16px; }
.cf-error { display: block; color: var(--el-color-danger); overflow-wrap: anywhere; margin-top: 4px; }
.cf-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.cf-form-grid .el-select, .cf-form-grid .el-input-number { width: 100%; }
.el-pagination { margin-top: 18px; }
:global(.cf-dialog) { max-width: calc(100vw - 32px); display: flex; flex-direction: column; max-height: calc(100dvh - 48px); margin-top: 24px; }
:global(.cf-dialog .el-dialog__body) { overflow-y: auto; min-height: 0; }
:global(.cf-dialog .el-dialog__footer) { flex-shrink: 0; }
@media (max-width: 600px) {
  .cf-filters .el-select, .cf-filters .el-input { flex: 1 1 100%; width: 100%; }
  .cf-form-grid { grid-template-columns: 1fr; gap: 0; }
}
</style>
