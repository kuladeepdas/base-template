export function monkeypatch (Class, method, callback) {
  if (!Class || !Class.prototype || typeof Class.prototype[method] !== 'function' || typeof callback !== 'function') {
    return
  }

  const methodFn = Class.prototype[method]

  Class.prototype[method] = function () {
    const result = methodFn.apply(this, arguments)
    try {
      callback.call(this)
    } catch (error) {
      // Never block Craft's native behavior if plugin-side UI patching fails.
      // eslint-disable-next-line no-console
      console.error(`[embedded-videos] monkeypatch callback failed for ${method}`, error)
    }
    return result
  }
}

let uidCounter = 0
export function uniqueId (prefix = 'uid') {
  return `${prefix}${Math.random().toString(36).substr(2)}${uidCounter++}`
}

export function isUrl (url) {
  return /^https?:\/\//.test(url)
}
