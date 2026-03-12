import Emitter from './Emitter'
import { uniqueId, isUrl } from './utilities'

const $ = window.jQuery

export default class Form extends Emitter {
  constructor (getActionTarget = () => {}) {
    super()

    this.getActionTarget = getActionTarget
    this.state = 'idle'
    this.url = ''
    this.replace = false
    this.replaceAssetId = ''
    this.iframeSrc = 'about:blank'
    this.callbackId = null
    this.timeoutId = null
    this.requestDebounceId = null
    this.previewHeight = 320
    this.previewMonitorId = null
    this.previewMonitorEndsAt = 0
    this.lastRequestedUrl = ''

    this._rootEl = document.createElement('div')
    this._rootEl.innerHTML = `
      <div class="embedded-videos_form" data-state="idle">
        <div class="field embedded-videos_form_field">
          <div class="heading">
            <label for="embedded-videos-url">URL</label>
          </div>
          <div class="input ltr">
            <input
              id="embedded-videos-url"
              class="text fullwidth"
              type="url"
              placeholder="https://"
              name="url"
              autocomplete="off"
              spellcheck="false"
              value=""
            >
          </div>
        </div>
        <div class="embedded-videos_form_body" style="height: 0px;">
          <div class="embedded-videos_preview">
            <iframe src="about:blank" scrolling="no"></iframe>
          </div>
          <div class="spinner"></div>
        </div>
      </div>
    `

    this.element = this._rootEl.firstElementChild
    this.inputRef = this.element?.querySelector('#embedded-videos-url')
    this.iframeRef = this.element?.querySelector('iframe')
    this.formBody = this.element?.querySelector('.embedded-videos_form_body')
    this.$element = $(this.element)

    this._bindEvents()
    this._render()
  }

  destroy () {
    this.clear()
    this.stopPreviewMonitor()
    if (this.requestDebounceId !== null) {
      window.clearTimeout(this.requestDebounceId)
      this.requestDebounceId = null
    }
    this.element?.remove()
    this.element = null
    this.$element = null
    this.trigger('destroy')
  }

  _bindEvents () {
    if (!this.inputRef || !this.iframeRef) {
      return
    }

    this.inputRef.addEventListener('input', (event) => this.onInput(event))
    this.inputRef.addEventListener('change', () => this.request())
    this.inputRef.addEventListener('keydown', (event) => {
      const isMetaEnter = (event.metaKey || event.ctrlKey) && event.key === 'Enter'
      if (!isMetaEnter) {
        return
      }
      event.preventDefault()
      this.onMetaEnter()
    })

    this.iframeRef.addEventListener('load', () => this.onIframeLoad())
  }

  getPreviewMap () {
    if (typeof window.EmbeddedVideosPreviewMap !== 'undefined') {
      return window.EmbeddedVideosPreviewMap
    }
    const map = new Map()
    window.EmbeddedVideosPreviewMap = map
    return map
  }

  emitEvent (event, payload) {
    this.trigger(event, payload)
  }

  setState (nextState) {
    this.state = nextState
    this._render()
    this.emitEvent(nextState)
    window.requestAnimationFrame(() => {
      const element = this.element
      const height = element instanceof HTMLElement ? element.offsetHeight : 0
      this.emitEvent('resize', { height })
    })
  }

  _render () {
    if (!this.element || !this.inputRef || !this.iframeRef || !this.formBody) {
      return
    }

    this.element.dataset.state = this.state
    if (this.inputRef.value !== this.url) {
      this.inputRef.value = this.url
    }

    const nextSrc = this.iframeSrc || 'about:blank'
    const currentSrc = this.iframeRef.getAttribute('src') || ''
    if (currentSrc !== nextSrc) {
      this.iframeRef.setAttribute('src', nextSrc)
    }

    if (this.state === 'idle') {
      this.formBody.style.height = '0px'
      return
    }

    if (this.state === 'requesting') {
      this.formBody.style.height = '96px'
      return
    }

    const height = Math.max(1, this.previewHeight || 0)
    this.formBody.style.height = `${height}px`
  }

  clearPendingRequest () {
    if (this.timeoutId !== null) {
      window.clearTimeout(this.timeoutId)
      this.timeoutId = null
    }

    const cbId = this.callbackId
    const previewMap = this.getPreviewMap()
    if (cbId !== null && cbId !== '' && previewMap !== undefined && previewMap.has(cbId)) {
      previewMap.delete(cbId)
    }
  }

  focus () {
    if (this.inputRef !== undefined && this.inputRef !== null) {
      this.inputRef.focus()
      this.inputRef.select()
    }
  }

  clear () {
    this.clearPendingRequest()
    this.stopPreviewMonitor()
    if (this.requestDebounceId !== null) {
      window.clearTimeout(this.requestDebounceId)
      this.requestDebounceId = null
    }
    this.url = ''
    this.iframeSrc = 'about:blank'
    this.previewHeight = 320
    this.lastRequestedUrl = ''
    this.setState('idle')
    this.emitEvent('clear')
  }

  setupPreviewCallback (currentUrl) {
    this.clearPendingRequest()
    const cb = uniqueId('embeddedvideos')
    this.callbackId = cb

    const previewMap = this.getPreviewMap()
    previewMap.set(cb, () => {
      if (this.url !== currentUrl || this.state !== 'requesting') {
        return
      }

      const doc = this.iframeRef?.contentDocument
      const hasWarning = Boolean(doc?.querySelector('#warning'))
      const prevent = Boolean(window.EmbeddedVideos?.preventNonWhitelistedUploads ?? false)
      this.updatePreviewHeight()
      this.startPreviewMonitor()
      this.setState(hasWarning && prevent ? 'blacklisted' : 'requested')
    })

    this.timeoutId = window.setTimeout(() => {
      if (this.state === 'requesting' && this.url === currentUrl) {
        Craft.cp.displayError(Craft.t('embeddedvideos', 'Could not retrieve embed information.'))
        this.setState('idle')
      }
    }, 15000)
  }

  request (nextUrl) {
    if (this.state === 'saving') {
      return
    }

    const targetUrl = (nextUrl ?? this.url).trim()
    this.url = targetUrl

    if (
      targetUrl !== '' &&
      targetUrl === this.lastRequestedUrl &&
      this.iframeSrc !== 'about:blank' &&
      this.state !== 'idle'
    ) {
      return
    }

    if (targetUrl === '') {
      this.clearPendingRequest()
      this.iframeSrc = 'about:blank'
      this.setState('idle')
      return
    }

    if (!isUrl(targetUrl)) {
      this.clearPendingRequest()
      this.iframeSrc = 'about:blank'
      this.setState('idle')
      return
    }

    if (!this.isSupportedProvider(targetUrl)) {
      this.clearPendingRequest()
      this.iframeSrc = 'about:blank'
      this.setState('idle')
      return
    }

    this.setupPreviewCallback(targetUrl)
    this.setState('requesting')
    this.previewHeight = 320
    this.lastRequestedUrl = targetUrl

    this.iframeSrc = Craft.getActionUrl('inspire-frontend/embedded-videos/preview', {
      showContent: 1,
      callback: this.callbackId,
      url: targetUrl
    })
    this._render()
  }

  setReplace (isReplace, id) {
    this.replace = isReplace
    this.replaceAssetId = id
  }

  save (nextUrl) {
    if (this.state !== 'requested' && this.state !== 'blacklisted') {
      return
    }

    const targetUrl = (nextUrl ?? this.url).trim()
    const actionTarget = this.getActionTarget() || {}
    const data = {
      ...actionTarget,
      url: targetUrl,
      assetId: this.replaceAssetId
    }

    this.setState('saving')

    Craft.queue.push(async () => await new Promise((resolve, reject) => {
      Craft.sendActionRequest('POST', `inspire-frontend/embedded-videos/${this.replace ? 'replace' : 'save'}`, { data })
        .then((response) => {
          if (response.data?.success === true) {
            const payload = response.data.payload
            this.clear()
            this.emitEvent('save', payload)
            resolve(undefined)
            return
          }

          if (typeof response.data?.error === 'string' && response.data.error !== '') {
            Craft.cp.displayError(response.data.error)
          }

          this.setState('requested')
          reject(new Error(response.data?.error ?? 'Unknown error'))
        })
        .catch((error) => {
          this.setState('requested')
          reject(error)
        })
    }))
  }

  onMetaEnter () {
    if (this.state === 'requested' || this.state === 'blacklisted') {
      this.save()
    }
  }

  onInput (event) {
    const target = event.target
    this.url = target?.value ?? ''
    if (this.requestDebounceId !== null) {
      window.clearTimeout(this.requestDebounceId)
    }
    this.requestDebounceId = window.setTimeout(() => {
      this.requestDebounceId = null
      this.request()
    }, 250)
  }

  isSupportedProvider (url) {
    let host = ''
    try {
      host = new URL(url).hostname.toLowerCase()
    } catch (_error) {
      return false
    }

    return (
      host.includes('youtube.com') ||
      host.includes('youtu.be') ||
      host.includes('vimeo.com')
    )
  }

  onIframeLoad () {
    this.updatePreviewHeight()
    this.startPreviewMonitor()
  }

  updatePreviewHeight () {
    const doc = this.iframeRef?.contentDocument
    if (!doc) {
      return
    }

    const previewEl = doc.querySelector('#code, .image, .volume-preview')
    const previewRectHeight = previewEl instanceof HTMLElement
      ? Math.ceil(previewEl.getBoundingClientRect().height)
      : 0
    const body = doc.body
    const root = doc.documentElement
    const fallbackHeight = Math.max(
      body?.scrollHeight ?? 0,
      body?.offsetHeight ?? 0,
      root?.scrollHeight ?? 0,
      root?.offsetHeight ?? 0
    )
    const nextHeight = previewRectHeight > 0 ? previewRectHeight : fallbackHeight

    if (nextHeight > 0) {
      this.previewHeight = nextHeight
      this._render()
      this.emitEvent('resize', { height: nextHeight })
    }
  }

  startPreviewMonitor () {
    this.stopPreviewMonitor()
    this.previewMonitorEndsAt = Date.now() + 4000
    this.previewMonitorId = window.setInterval(() => {
      this.updatePreviewHeight()
      if (Date.now() >= this.previewMonitorEndsAt) {
        this.stopPreviewMonitor()
      }
    }, 120)
  }

  stopPreviewMonitor () {
    if (this.previewMonitorId !== null) {
      window.clearInterval(this.previewMonitorId)
      this.previewMonitorId = null
    }
  }
}
