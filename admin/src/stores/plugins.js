import { ref } from 'vue';
import { defineStore } from 'pinia';
import {
  createEmptyPlugins,
  createEmptyPluginsFilters,
  createEmptyPluginsPagination,
  createEmptyPluginTypes,
  deletePlugin,
  disablePlugin,
  enablePlugin,
  fetchPlugins,
  fetchPluginTypes,
  installPlugin,
  savePluginConfig,
  uninstallPlugin,
} from "../services/plugins";

export const usePluginsStore = defineStore('plugins', () => {
  const plugins = ref(createEmptyPlugins());
  const pluginsFilters = ref(createEmptyPluginsFilters());
  const pluginsPagination = ref(createEmptyPluginsPagination());
  const pluginsLoading = ref(false);
  const pluginsError = ref("");
  const pluginTypes = ref(createEmptyPluginTypes());
  const pluginTypesLoading = ref(false);
  const pluginTypesError = ref("");

  let pluginsRevision = 0;
  let pluginTypesRevision = 0;
  async function loadPlugins(options = {}) {
    const revision = ++pluginsRevision;
    const isCurrent = () => revision === pluginsRevision && !options.signal?.aborted;
    pluginsLoading.value = true;
    pluginsError.value = "";
    const nextFilters = {
      ...pluginsFilters.value,
      ...(options.filters || {}),
    };
    try {
      const response = await fetchPlugins({
        type: nextFilters.type,
        status: nextFilters.status,
        signal: options.signal,
      });
      if (!isCurrent()) return false;
      plugins.value = response.list;
      pluginsFilters.value = nextFilters;
      pluginsPagination.value = response.pagination;
      return true;
    } catch (error) {
      if (!isCurrent()) return false;
      pluginsError.value =
        error instanceof Error ? error.message : "插件列表加载失败";
      return false;
    } finally {
      if (revision === pluginsRevision) pluginsLoading.value = false;
    }
  }

  async function togglePlugin(code, enabled) {
    const pluginCode = String(code || "").trim();

    if (!pluginCode) {
      throw new Error("缺少插件标识");
    }

    if (enabled) {
      await enablePlugin(pluginCode);
    } else {
      await disablePlugin(pluginCode);
    }
  }

  async function installPluginItem(code) {
    const pluginCode = String(code || "").trim();

    if (!pluginCode) {
      throw new Error("缺少插件标识");
    }

    await installPlugin(pluginCode);
  }

  async function uninstallPluginItem(code) {
    const pluginCode = String(code || "").trim();

    if (!pluginCode) {
      throw new Error("缺少插件标识");
    }

    await uninstallPlugin(pluginCode);
  }

  async function deletePluginItem(code) {
    const pluginCode = String(code || "").trim();

    if (!pluginCode) {
      throw new Error("缺少插件标识");
    }

    await deletePlugin(pluginCode);
  }

  async function loadPluginTypes(options = {}) {
    const revision = ++pluginTypesRevision;
    const isCurrent = () => revision === pluginTypesRevision && !options.signal?.aborted;
    pluginTypesLoading.value = true;
    pluginTypesError.value = "";

    try {
      const list = await fetchPluginTypes(options);
      if (!isCurrent()) return;
      pluginTypes.value = list;
      return list;
    } catch (error) {
      if (!isCurrent()) return;
      pluginTypesError.value = error?.message || "插件类型加载失败";
      throw error;
    } finally {
      if (revision === pluginTypesRevision) pluginTypesLoading.value = false;
    }
  }

  async function savePluginConfigItem(code, config) {
    const pluginCode = String(code || "").trim();

    if (!pluginCode) {
      throw new Error("缺少插件标识");
    }

    const submitted = JSON.parse(JSON.stringify(config));
    await savePluginConfig(pluginCode, submitted);
    const plugin = plugins.value.find(item => item.code === pluginCode);
    if (!plugin?.config) return;
    const updateField = (key, field) => Object.prototype.hasOwnProperty.call(submitted, key)
      ? { ...field, value: submitted[key] }
      : field;
    plugin.config = Array.isArray(plugin.config)
      ? plugin.config.map(field => updateField(field.key, field))
      : Object.fromEntries(Object.entries(plugin.config).map(([key, field]) => [key, updateField(key, field)]));
  }

  return {
    plugins,
    pluginsError,
    pluginsFilters,
    pluginsLoading,
    pluginsPagination,
    pluginTypes,
    pluginTypesLoading,
    pluginTypesError,
    loadPlugins,
    loadPluginTypes,
    togglePlugin,
    installPlugin: installPluginItem,
    uninstallPlugin: uninstallPluginItem,
    deletePlugin: deletePluginItem,
    savePluginConfig: savePluginConfigItem,
  };
});
