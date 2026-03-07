import Vue from 'vue'
import Emitter from './Emitter'
import FormComponent from './Form.vue'

const $ = window.jQuery

export default class Form extends Emitter {
  constructor (getActionTarget = () => {}) {
    super()

    this._rootEl = document.createElement('div')
    this._app = new Vue({
      render: (h) => h(FormComponent, {
        ref: 'embeddedVideoForm',
        props: {
          getActionTarget,
          onEvent: (event, payload) => this.trigger(event, payload)
        }
      })
    })

    this._app.$mount(this._rootEl)
    this._vm = this._app.$refs.embeddedVideoForm

    // Use Vue's rendered root element directly to avoid empty jQuery selections.
    if (this._app.$el) {
      this.$element = $(this._app.$el)
    } else {
      this.$element = $(this._rootEl).children().first()
    }
  }

  destroy () {
    this._app?.$destroy()
    if (this._rootEl) {
      this._rootEl.innerHTML = ''
    }
    this._app = null
    this._vm = null
    this.$element = null
    this.trigger('destroy')
  }

  request (url) {
    this._vm?.request(url)
  }

  focus () {
    this._vm?.focus()
  }

  clear () {
    this._vm?.clear()
  }

  setReplace (replace, id) {
    this._vm?.setReplace(replace, id)
  }

  save (url) {
    this._vm?.save(url)
  }

  setState (state) {
    this._vm?.setState(state)
  }
}
