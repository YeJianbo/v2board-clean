export function shouldSendAgentMessage(event) {
  return event.key === 'Enter' && !event.shiftKey && !event.isComposing && event.keyCode !== 229 && !event.repeat
}
