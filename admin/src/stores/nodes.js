import { ref } from 'vue';
import { defineStore } from 'pinia';
import {
  createEmptyManagedNodesFilters,
  createEmptyManagedNodeGroups,
  createEmptyManagedNodeRoutes,
  createEmptyManagedNodes,
  createEmptyManagedNodesPagination,
  deleteManagedNode,
  copyManagedNode,
  updateManagedNodeShow,
  fetchManagedNodeGroupOptions,
  fetchManagedNodeRoutes,
  fetchManagedNodes,
  saveManagedNode,
} from "../services/nodes";

export const useNodesStore = defineStore('nodes', () => {
  const managedNodes = ref(createEmptyManagedNodes());
  const managedNodesFilters = ref(createEmptyManagedNodesFilters());
  const managedNodesPagination = ref(createEmptyManagedNodesPagination());
  const managedNodesLoading = ref(false);
  const managedNodesError = ref("");
  const managedNodeGroups = ref(createEmptyManagedNodeGroups());
  const managedNodeGroupsLoading = ref(false);
  const managedNodeGroupsError = ref("");
  const managedNodeRoutes = ref(createEmptyManagedNodeRoutes());
  const managedNodeRoutesLoading = ref(false);
  const managedNodeRoutesError = ref("");

  let managedNodesRequestRevision = 0;
  let managedNodesController = null;
  let managedNodesReadOwner = null;
  async function loadManagedNodes(options = {}) {
    const revision = ++managedNodesRequestRevision;
    managedNodesController?.abort();
    const controller = new AbortController();
    managedNodesController = controller;
    managedNodesReadOwner = options.owner ?? null;
    const isSilent = Boolean(options.silent);
    if (!isSilent) managedNodesLoading.value = true;
    managedNodesError.value = "";
    const nextFilters = {
      ...managedNodesFilters.value,
      ...(options.filters || {}),
    };
    const nextPagination = {
      ...managedNodesPagination.value,
      ...options,
    };
    managedNodesFilters.value = nextFilters;

    try {
      const response = await fetchManagedNodes({
        page: nextPagination.page,
        limit: nextPagination.limit,
        type: nextFilters.type,
        word: nextFilters.word,
        status: nextFilters.status,
        signal: controller.signal,
      });

      if (revision !== managedNodesRequestRevision || controller.signal.aborted) return false;
      managedNodes.value = response.list;
      // The complete node set is independent of filters edited during this read.
      managedNodesPagination.value = response.pagination;
      return true;
    } catch (error) {
      if (revision !== managedNodesRequestRevision || controller.signal.aborted) return false;
      if (isSilent) {
        managedNodesError.value = error instanceof Error ? error.message : "节点状态刷新失败";
        return false;
      }
      managedNodes.value = createEmptyManagedNodes();
      managedNodesPagination.value = {
        page: Number(nextPagination.page || 1),
        limit: Number(
          nextPagination.limit || managedNodesPagination.value.limit || 10,
        ),
        total: 0,
      };
      managedNodesError.value =
        error instanceof Error ? error.message : "节点列表加载失败";
      return false;
    } finally {
      if (revision === managedNodesRequestRevision) {
        managedNodesLoading.value = false;
        managedNodesController = null;
        managedNodesReadOwner = null;
      }
    }
  }

  function createOptionsReader(fetchOptions, emptyOptions, data, loading, errorMessage, fallbackMessage) {
    let activeRead = null;

    function cancel(owner) {
      if (!activeRead || activeRead.owner !== owner) return;
      const read = activeRead;
      activeRead = null;
      read.controller.abort();
      loading.value = false;
    }

    function load(options = {}) {
      const owner = options.owner ?? null;
      if (activeRead && activeRead.owner === owner && !options.force) return activeRead.promise;
      if (activeRead) cancel(activeRead.owner);

      const read = { owner, controller: new AbortController(), promise: null };
      activeRead = read;
      loading.value = true;
      errorMessage.value = "";
      // Only the current reader owns state, even if the transport ignores abort.
      read.promise = (async () => {
        try {
          const response = await fetchOptions({ signal: read.controller.signal });
          if (activeRead !== read || read.controller.signal.aborted) return false;
          data.value = response;
          return true;
        } catch (error) {
          if (activeRead !== read || read.controller.signal.aborted) return false;
          data.value = emptyOptions();
          errorMessage.value = error instanceof Error ? error.message : fallbackMessage;
          return false;
        } finally {
          if (activeRead === read) {
            activeRead = null;
            loading.value = false;
          }
        }
      })();
      return read.promise;
    }

    return { load, cancel };
  }

  const groupsReader = createOptionsReader(
    fetchManagedNodeGroupOptions, createEmptyManagedNodeGroups,
    managedNodeGroups, managedNodeGroupsLoading, managedNodeGroupsError, "权限组列表加载失败",
  );
  const routesReader = createOptionsReader(
    fetchManagedNodeRoutes, createEmptyManagedNodeRoutes,
    managedNodeRoutes, managedNodeRoutesLoading, managedNodeRoutesError, "路由组列表加载失败",
  );

  function loadManagedNodeGroups(options = {}) {
    return groupsReader.load(options);
  }

  function loadManagedNodeRoutes(options = {}) {
    return routesReader.load(options);
  }

  function cancelManagedNodeReads(owner = null) {
    if (managedNodesController && managedNodesReadOwner === owner) {
      managedNodesRequestRevision += 1;
      managedNodesController.abort();
      managedNodesController = null;
      managedNodesReadOwner = null;
      managedNodesLoading.value = false;
    }
    groupsReader.cancel(owner);
    routesReader.cancel(owner);
  }

  async function saveManagedNodeItem(payload) {
    managedNodesError.value = "";

    try {
      return await saveManagedNode(payload);
    } catch (error) {
      const message =
        error instanceof Error ? error.message : "保存节点失败";
      managedNodesError.value = message;
      throw error;
    }
  }

  function resolveManagedNodeTarget(target) {
    if (target && typeof target === "object") {
      return target;
    }

    const key = String(target || "").trim();
    if (!key) {
      return null;
    }

    return managedNodes.value.find(function findNode(node) {
      return (
        String(node.nodeKey || "") === key ||
        String(node.id || "") === key ||
        String(node.rawId || "") === key
      );
    }) || null;
  }

  async function deleteManagedNodeItem(target) {
    managedNodesError.value = "";

    try {
      const node = resolveManagedNodeTarget(target);
      const id = node?.rawId || node?.id || target;
      const type = node ? node.storageType || node.type : "";
      return await deleteManagedNode(id, type);
    } catch (error) {
      const message =
        error instanceof Error ? error.message : "删除节点失败";
      managedNodesError.value = message;
      throw error;
    }
  }

  async function copyManagedNodeItem(target) {
    managedNodesError.value = "";

    try {
      const node = resolveManagedNodeTarget(target);
      const id = node?.rawId || node?.id || target;
      const type = node ? node.storageType || node.type : "";
      return await copyManagedNode(id, type);
    } catch (error) {
      const message = error instanceof Error ? error.message : "复制节点失败";
      managedNodesError.value = message;
      throw error;
    }
  }

  async function updateManagedNodeShowItem(target, show) {
    managedNodesError.value = "";

    try {
      const node = resolveManagedNodeTarget(target);
      const id = node?.rawId || node?.id || target;
      const type = node ? node.storageType || node.type : "";
      return await updateManagedNodeShow(id, show, type);
    } catch (error) {
      const message =
        error instanceof Error ? error.message : "更新节点显示状态失败";
      managedNodesError.value = message;
      throw error;
    }
  }

  return {
    cancelManagedNodeReads,
    loadManagedNodeGroups,
    loadManagedNodeRoutes,
    loadManagedNodes,
    saveManagedNode: saveManagedNodeItem,
    saveManagedNodeItem,
    deleteManagedNodeItem,
    copyManagedNodeItem,
    updateManagedNodeShowItem,
    managedNodeGroups,
    managedNodeGroupsError,
    managedNodeGroupsLoading,
    managedNodeRoutes,
    managedNodeRoutesError,
    managedNodeRoutesLoading,
    managedNodes,
    managedNodesError,
    managedNodesFilters,
    managedNodesLoading,
    managedNodesPagination,
  };
});
