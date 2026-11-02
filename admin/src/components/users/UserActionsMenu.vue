<script setup>
defineProps({
  user: { type: Object, required: true },
  loginPending: Boolean,
  secretPending: Boolean,
})
defineEmits(['action'])
</script>

<template>
  <el-dropdown trigger="click" popper-class="user-actions-dropdown" :popper-options="{ modifiers: [{ name: 'preventOverflow', options: { altAxis: true, padding: 12 } }] }" @command="action => $emit('action', action)">
    <el-button class="user-action-trigger" size="small" plain :aria-label="`操作用户 ${user.email}`">操作</el-button>
    <template #dropdown>
      <el-dropdown-menu>
        <el-dropdown-item command="edit">编辑</el-dropdown-item>
        <el-dropdown-item command="traffic">流量详情</el-dropdown-item>
        <el-dropdown-item command="orders">TA的订单</el-dropdown-item>
        <el-dropdown-item command="tickets">TA的工单</el-dropdown-item>
        <el-dropdown-item command="invites">TA的邀请</el-dropdown-item>
        <el-dropdown-item divided command="assign">分配订单</el-dropdown-item>
        <el-dropdown-item command="subscribe">复制订阅 URL</el-dropdown-item>
        <el-dropdown-item command="login" :disabled="loginPending">生成临时登录链接</el-dropdown-item>
        <el-dropdown-item divided command="reset-traffic">重置流量</el-dropdown-item>
        <el-dropdown-item command="reset-secret" :disabled="secretPending">重置订阅（15 分钟过渡）</el-dropdown-item>
        <el-dropdown-item command="immediate" :disabled="secretPending" class="user-menu-warning">泄露紧急重置</el-dropdown-item>
        <el-dropdown-item command="revoke" :disabled="secretPending" class="user-menu-warning">提前撤销旧凭据</el-dropdown-item>
        <el-dropdown-item divided command="delete" class="user-menu-danger">删除用户</el-dropdown-item>
      </el-dropdown-menu>
    </template>
  </el-dropdown>
</template>

<style>
.user-actions-dropdown .el-scrollbar__wrap { max-height: calc(100dvh - 32px); overscroll-behavior: contain; }
.user-actions-dropdown .user-menu-warning { color: var(--el-color-warning); }
.user-actions-dropdown .user-menu-danger { color: var(--el-color-danger); }
.user-action-trigger { min-width: 58px; border-radius: 999px; color: var(--primary); border-color: var(--el-color-primary-light-7); background: var(--el-color-primary-light-9); }
@media (pointer: coarse) { .user-actions-dropdown .el-dropdown-menu__item { min-height: 44px; } }
</style>
