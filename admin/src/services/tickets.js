import {
  buildSecureV2ApiUrl,
  requestDashboardApi,
  requestDashboardReadPost,
  requestDashboardMutation,
} from './api'

export function createEmptyManagedTickets() {
  return []
}

export function createEmptyManagedTicketsPagination() {
  return {
    page: 1,
    pageSize: 10,
    total: 0,
  }
}

export function createEmptyManagedTicketsFilters() {
  return {
    status: null,
    replyStatus: null,
    email: '',
  }
}

const TICKET_STATUS = {
  0: { text: '已开启', type: 'primary' },
  1: { text: '已关闭', type: 'info' },
}

const TICKET_REPLY_STATUS = {
  0: { text: '待回复', type: 'warning' },
  1: { text: '已回复', type: 'success' },
}

function formatTimestamp(value) {
  const timestamp = Number(value ?? 0)

  if (!timestamp) {
    return '--'
  }

  const date = new Date(timestamp * 1000)

  if (Number.isNaN(date.getTime())) {
    return '--'
  }

  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  const hours = String(date.getHours()).padStart(2, '0')
  const minutes = String(date.getMinutes()).padStart(2, '0')

  return `${year}-${month}-${day} ${hours}:${minutes}`
}

function normalizeTicket(ticket) {
  const status = Number(ticket?.status ?? 0)
  const statusInfo = TICKET_STATUS[status] || { text: '未知', type: 'info' }
  const replyStatus = Number(ticket?.reply_status ?? 0)
  const replyStatusInfo = TICKET_REPLY_STATUS[replyStatus] || { text: '未知', type: 'info' }
  const level = Number(ticket?.level ?? 0)

  const rawMessages = Array.isArray(ticket?.messages) ? ticket.messages : []
  const ticketUserId = Number(ticket?.user_id ?? 0)

  return {
    id: Number(ticket?.id ?? 0),
    userId: ticketUserId,
    userEmail: ticket?.user?.email || '--',
    subject: String(ticket?.subject || '--'),
    level,
    levelText: level === 2 ? '高' : level === 1 ? '中' : '低',
    levelType: level === 2 ? 'danger' : level === 1 ? 'warning' : 'info',
    status,
    statusText: statusInfo.text,
    statusType: statusInfo.type,
    replyStatus,
    replyStatusText: replyStatusInfo.text,
    replyStatusType: replyStatusInfo.type,
    createdAt: formatTimestamp(ticket?.created_at),
    updatedAt: formatTimestamp(ticket?.updated_at),
    messages: rawMessages.map(function mapMessage(msg) {
      const msgUserId = Number(msg?.user_id ?? 0)
      const isAdmin = Boolean(msg?.is_from_admin) || (ticketUserId > 0 && msgUserId !== ticketUserId)
      return {
        id: Number(msg?.id ?? 0),
        userId: msgUserId,
        message: String(msg?.message || ''),
        createdAt: formatTimestamp(msg?.created_at),
        isAdmin,
      }
    }),
  }
}

export async function fetchManagedTickets(options = {}) {
  const current = Number(options.page || 1)
  const pageSize = Number(options.pageSize || 10)
  const queryEntries = [
    ['current', current],
    ['pageSize', pageSize],
  ]

  const apiUrl = buildSecureV2ApiUrl('ticket/fetch', queryEntries)
  const body = {}

  if (options.status !== null && options.status !== undefined && options.status !== '') {
    body.status = options.status
  }

  if (options.email) {
    body.email = options.email
  }

  if (Array.isArray(options.replyStatus) && options.replyStatus.length > 0) {
    body.reply_status = options.replyStatus
  }

  if (Array.isArray(options.filter) && options.filter.length > 0) {
    body.filter = options.filter
  }

  if (Array.isArray(options.sort) && options.sort.length > 0) {
    body.sort = options.sort
  }

  const payload = await requestDashboardReadPost(apiUrl, body, { signal: options.signal })
  const rawData = payload?.data
  const listSource = Array.isArray(rawData) ? rawData : rawData?.data
  if (!Array.isArray(listSource) || listSource.some(ticket => !ticket || typeof ticket !== 'object' || Array.isArray(ticket)
    || !Number.isSafeInteger(Number(ticket.id)) || Number(ticket.id) < 1 || typeof ticket.subject !== 'string'
    || ticket.status == null || ![0, 1].includes(Number(ticket.status)))) {
    throw new Error('工单列表返回格式错误，请重新加载')
  }

  const total = Number(rawData?.total ?? payload?.total ?? 0)
  const currentPage = Number(rawData?.current_page ?? payload?.current_page ?? current)
  const perPage = Number(rawData?.per_page ?? payload?.per_page ?? pageSize)

  return {
    list: listSource.map(function mapTicket(ticket) {
      return normalizeTicket(ticket)
    }),
    pagination: {
      page: currentPage,
      pageSize: perPage,
      total: total,
    },
  }
}

export async function fetchTicketDetail(id, options = {}) {
  const ticketId = Number(id)
  if (!Number.isSafeInteger(ticketId) || ticketId < 1 || ticketId > 2147483647) throw new Error('工单 ID 无效')
  const apiUrl = buildSecureV2ApiUrl('ticket/fetch', [['id', ticketId]])
  const payload = await requestDashboardApi(apiUrl, options)
  const ticket = payload?.data
  if (!ticket || typeof ticket !== 'object' || Array.isArray(ticket) || Number(ticket.id) !== ticketId
    || typeof ticket.subject !== 'string' || ticket.status == null || ![0, 1].includes(Number(ticket.status)) || !Array.isArray(ticket.messages)
    || ticket.messages.some(message => !message || typeof message !== 'object' || Array.isArray(message) || typeof message.message !== 'string')) {
    throw new Error('工单详情返回格式错误，请重新打开工单')
  }
  return normalizeTicket(ticket)
}

export async function replyTicket(id, message) {
  const apiUrl = buildSecureV2ApiUrl('ticket/reply')
  return requestDashboardMutation(apiUrl, {
    id: Number(id),
    message: String(message),
  })
}

export async function closeTicket(id) {
  const apiUrl = buildSecureV2ApiUrl('ticket/close')
  return requestDashboardMutation(apiUrl, { id: Number(id) })
}

export { TICKET_STATUS, TICKET_REPLY_STATUS }
