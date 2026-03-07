<template>
  <div class="embedded-videos_form" :data-state="state">
    <div class="field embedded-videos_form_field">
      <div class="heading">
        <label for="embedded-videos-url">URL</label>
      </div>
      <div class="input ltr">
        <input
          id="embedded-videos-url"
          ref="inputRef"
          class="text fullwidth"
          type="url"
          placeholder="https://"
          name="url"
          autocomplete="off"
          spellcheck="false"
          :value="url"
          @input="onInput"
          @change="request()"
          @keydown.meta.enter.prevent="onMetaEnter"
          @keydown.ctrl.enter.prevent="onMetaEnter"
        >
      </div>
    </div>
    <div class="embedded-videos_form_body" :style="formBodyStyle">
      <div class="embedded-videos_preview">
        <iframe ref="iframeRef" :src="iframeSrc" scrolling="no" @load="onIframeLoad"></iframe>
      </div>
      <div class="spinner"></div>
    </div>
  </div>
</template>

<script>
import { nextTick } from 'vue'
import { uniqueId, isUrl } from './utilities'

export default {
  name: 'EmbeddedVideoForm',
  props: {
    getActionTarget: {
      type: Function,
      required: true
    },
    onEvent: {
      type: Function,
      required: true
    }
  },

  data () {
    return {
      state: 'idle',
      url: '',
      replace: false,
      replaceAssetId: '',
      iframeSrc: 'about:blank',
      callbackId: null,
      timeoutId: null,
      requestDebounceId: null,
      previewHeight: 320,
      previewMonitorId: null,
      previewMonitorEndsAt: 0,
      lastRequestedUrl: ''
    }
  },

  computed: {
    formBodyStyle () {
      if (this.state === 'idle') {
        return { height: '0px' }
      }

      if (this.state === 'requesting') {
        return { height: '96px' }
      }

      const height = Math.max(1, this.previewHeight || 0)
      return { height: `${height}px` }
    }
  },

  beforeDestroy () {
    if (this.requestDebounceId !== null) {
      window.clearTimeout(this.requestDebounceId)
      this.requestDebounceId = null
    }
    this.stopPreviewMonitor()
  },

  methods: {
    getPreviewMap () {
      if (typeof window.EmbeddedVideosPreviewMap !== 'undefined') {
        return window.EmbeddedVideosPreviewMap
      }
      const map = new Map()
      window.EmbeddedVideosPreviewMap = map
      return map
    },

    emitEvent (event, payload) {
      this.onEvent(event, payload)
    },

    setState (nextState) {
      this.state = nextState
      this.emitEvent(nextState)
      void nextTick(() => {
        const element = this.$el
        const height = element instanceof HTMLElement ? element.offsetHeight : 0
        this.emitEvent('resize', { height })
      })
    },

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
    },

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
    },

    focus () {
      if (this.$refs.inputRef !== undefined && this.$refs.inputRef !== null) {
        this.$refs.inputRef.focus()
        this.$refs.inputRef.select()
      }
    },

    setupPreviewCallback (currentUrl) {
      this.clearPendingRequest()
      const cb = uniqueId('embeddedvideos')
      this.callbackId = cb

      const previewMap = this.getPreviewMap()
      previewMap.set(cb, () => {
        if (this.url !== currentUrl || this.state !== 'requesting') {
          return
        }

        const doc = this.$refs.iframeRef?.contentDocument
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
    },

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
    },

    setReplace (isReplace, id) {
      this.replace = isReplace
      this.replaceAssetId = id
    },

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
    },

    onMetaEnter () {
      if (this.state === 'requested' || this.state === 'blacklisted') {
        this.save()
      }
    },

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
    },

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
    },

    onIframeLoad () {
      this.updatePreviewHeight()
      this.startPreviewMonitor()
    },

    updatePreviewHeight () {
      const doc = this.$refs.iframeRef?.contentDocument
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
        this.emitEvent('resize', { height: nextHeight })
      }
    },

    startPreviewMonitor () {
      this.stopPreviewMonitor()
      this.previewMonitorEndsAt = Date.now() + 4000
      this.previewMonitorId = window.setInterval(() => {
        this.updatePreviewHeight()
        if (Date.now() >= this.previewMonitorEndsAt) {
          this.stopPreviewMonitor()
        }
      }, 120)
    },

    stopPreviewMonitor () {
      if (this.previewMonitorId !== null) {
        window.clearInterval(this.previewMonitorId)
        this.previewMonitorId = null
      }
    }
  }
}
</script>
