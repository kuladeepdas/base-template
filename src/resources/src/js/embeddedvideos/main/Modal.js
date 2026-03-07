import Emitter from './Emitter'
import Form from './Form'
import { uniqueId } from './utilities'

const $ = window.jQuery

export default class Modal extends Emitter {
  constructor (getActionTarget = () => {}) {
    super()
    this._getActionTarget = getActionTarget
    this.form = null
    this.hud = null
    this.$footer = null
  }

  create ($target, settings = {}) {
    settings = Object.assign({
      hudClass: 'hud embedded-videos_hud',
      mainClass: 'embedded-videos_hud_main',
      minBodyWidth: 400
    }, settings)

    const cancelId = uniqueId()
    const saveId = uniqueId()
    const spinnerId = uniqueId()
    const cancelLabel = Craft.t('app', 'Cancel')
    const saveLabel = Craft.t('app', 'Save')

    this.$footer = $(`
      <div class="hud-footer embedded-videos_hud_footer">
        <div class="buttons right">
          <button id="${cancelId}" class="btn" type="button">${cancelLabel}</button>
          <button id="${saveId}" class="btn submit" type="button">${saveLabel}</button>
          <div id="${spinnerId}" class="spinner hidden"></div>
        </div>
      </div>
    `)

    this.$cancel = this.$footer.find(`#${cancelId}`)
    this.$save = this.$footer.find(`#${saveId}`)
    this.$spinner = this.$footer.find(`#${spinnerId}`)

    this.form = new Form(this._getActionTarget)

    const $content = (this.form?.$element && this.form.$element.length)
      ? this.form.$element.add(this.$footer)
      : this.$footer

    this.hud = new Garnish.HUD($target, $content, settings)

    this.$save.on('click', (e) => this.$save.hasClass('disabled') && e.stopImmediatePropagation())
    this.$cancel.on('click', () => this.form?.clear())
    this.$save.on('click', () => this.form?.save())

    this.form.on('save', (e) => this.trigger('save', e))
    this.hud.on('show', () => this.trigger('show'))
    this.hud.on('hide', () => this.trigger('hide'))

    this.form.on('clear', () => this.hide())
    this.form.on('save', () => this.hide())
    this.hud.on('show', () => this.form?.request())
    this.hud.on('show', () => this.form?.focus())
    this.hud.on('hide', () => this.form?.setState('idle'))

    this.hideFooter()
    this.setSaveEnabled(false)
    this.form.on('idle', () => this.hideFooter())
    this.form.on('requesting', () => this.hideFooter())
    this.form.on('requested', () => this.showFooter())
    this.form.on('blacklisted', () => this.hideFooter())

    this.form.on('idle', () => {
      this.setSaveEnabled(false)
      this.setSaving(false)
    })
    this.form.on('requesting', () => {
      this.setSaveEnabled(false)
      this.setSaving(false)
    })
    this.form.on('requested', () => {
      this.setSaveEnabled(true)
      this.setSaving(false)
    })
    this.form.on('blacklisted', () => {
      this.setSaveEnabled(false)
      this.setSaving(false)
    })
    this.form.on('saving', () => {
      this.setSaveEnabled(false)
      this.setSaving(true)
    })
    this.form.on('resize', () => this.hud?.updateSizeAndPosition())
  }

  destroy () {
    if (this.$footer !== null) {
      this.$footer.remove()
      this.$footer = null
    }

    if (this.form !== null) {
      this.form.destroy()
      this.form = null
    }

    if (this.hud !== null) {
      this.hud.hide()
      this.hud.$hud.remove()
      this.hud.$shade.remove()
      this.hud = null
    }

    this.trigger('destroy')
  }

  show ($target, settings = {}) {
    this._blurActiveElement()

    if (this.hud !== null) {
      this.hud.setSettings(settings)
      this.hud.$trigger = $($target)

      if (this.hud.showing) {
        this.hud.queueUpdateSizeAndPosition()
      } else {
        this.hud.show()
      }
    } else {
      this.create($target, settings)
      this.form?.focus()
    }

    this.trigger('show')
  }

  hide () {
    this._blurActiveElement()
    this.hud?.hide()
    this.trigger('hide')
  }

  hideFooter () {
    this.hud?.$hud.removeClass('show-footer')
  }

  showFooter () {
    this.hud?.$hud.addClass('show-footer')
  }

  setSaving (enabled = true) {
    this.$save?.toggleClass('disabled', enabled)
    this.$spinner?.toggleClass('hidden', !enabled)
  }

  setSaveEnabled (enabled = true) {
    this.$save?.prop('disabled', !enabled)
    this.$save?.toggleClass('disabled', !enabled)
  }

  _blurActiveElement () {
    const active = document.activeElement
    if (active instanceof HTMLElement && typeof active.blur === 'function') {
      active.blur()
    }
  }
}
