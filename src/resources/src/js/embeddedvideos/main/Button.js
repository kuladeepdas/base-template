const $ = window.jQuery

export default class Button {
  constructor (label, options = {}) {
    this.options = Object.assign({
      videoIcon: false
    }, options)

    this.$element = $('<div class="embedded-videos_button btn"></div>')

    if (this.options.videoIcon) {
      this.$icon = $('<span class="embedded-videos_button_icon" aria-hidden="true"></span>')
      this.$element.append(this.$icon)
    }

    this.$label = $('<span class="embedded-videos_button_label"></span>')
    this.$element.append(this.$label)

    this.setLabel(label)
  }

  destroy () {
    this.$element?.remove()
    this.$element = null
  }

  setLabel (label) {
    this.$label?.text(Craft.t('embeddedvideos', label))
  }

  show () {
    this.$element?.show()
  }

  hide () {
    this.$element?.hide()
  }

  setActive (flag = true) {
    this.$element?.toggleClass('active', flag)
  }
}
