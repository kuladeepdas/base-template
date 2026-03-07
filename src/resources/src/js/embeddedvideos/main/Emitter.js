export default class Emitter {
  constructor () {
    this._events = new Map()
  }

  on (type, callback) {
    if (!this._events.has(type)) {
      this._events.set(type, [])
    }

    this._events.get(type)?.push(callback)
  }

  off (type, callback) {
    if (this._events.size > 0) {
      if (typeof callback !== 'undefined' && this._events.has(type)) {
        const newList = this._events.get(type)?.filter((fn) => fn !== callback) || []

        if (newList.length > 0) {
          this._events.set(type, newList)
        } else {
          this._events.delete(type)
        }
      } else {
        this._events.delete(type)
      }
    }
  }

  trigger (type, event = {}) {
    if (this._events.size > 0 && this._events.has(type)) {
      const payload = Object.assign(event, { type })
      this._events.get(type)?.forEach(fn => fn.call(this, payload))
    }
  }
}
