export function hasNodeEntryOverride(form, originalEntryHost = "") {
  return Boolean(form.entryMachineId || form.entryHost || originalEntryHost);
}

export function nodeEntryDisplayHost(form, edited = false) {
  return edited ? String(form.entryHost || "") : String(form.entryHost || form.host || "");
}

export function setNodeEntryHost(form, value) {
  form.entryHost = value;
  if (!String(form.host || "").trim()) {
    form.host = value;
  }
}
