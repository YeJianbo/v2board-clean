export function monacoWorkerBundle() {
  return {
    name: 'monaco-modular-worker-factory',
    transform(code, id) {
      if (!id.replaceAll('\\', '/').endsWith('/monaco-editor/esm/vs/internal/common/workers.js')) return null
      // This legacy factory imports every UI contribution. RichTemplateEditor registers
      // the required features itself; leave worker creation and Trusted Types intact.
      const result = code.replace(/^import ['"]\.\.\/\.\.\/editor\/(?:contrib|standalone\/browser)\/[^'"]+['"];?\r?\n/gm, '')
      return { code: result, map: null }
    },
  }
}
