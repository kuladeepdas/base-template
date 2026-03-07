import './main.css'
import EmbeddedVideos from './EmbeddedVideos'
import Button from './Button'
import { monkeypatch } from './utilities'

// Ensure browser runtime has a process shim for bundled deps expecting process.env.
window.process = window.process || { env: { NODE_ENV: 'production' } }

const $ = window.jQuery || window.$

const embeddedVideos = new EmbeddedVideos()
const button = new Button('Embed Video', { videoIcon: true })
const replaceButton = new Button('Replace')
let buttonInit = false

const applyMonkeypatch = () => {
  if (typeof Craft === 'undefined' || typeof Craft.AssetIndex === 'undefined' || typeof $ !== 'function') {
    window.setTimeout(applyMonkeypatch, 50)
    return
  }

  const normalizeKinds = (kinds) => {
    if (typeof kinds === 'string') {
      return [kinds]
    }
    if (Array.isArray(kinds)) {
      return kinds
    }
    return []
  }

  const canShowEmbedButton = (allowedKinds) => {
    const kinds = normalizeKinds(allowedKinds)
    return kinds.length === 0 || kinds.includes('embeddedVideo')
  }

  const shouldHideUploadButton = (allowedKinds) => {
    const kinds = normalizeKinds(allowedKinds)
    return kinds.length === 1 && kinds.includes('embeddedVideo')
  }

  const getFieldActionTarget = (assetSelectInput) => {
    const criteria = assetSelectInput.settings?.criteria ?? {}

    if (typeof criteria.folderId !== 'undefined' && criteria.folderId !== null) {
      return {
        targetType: 'folder',
        targetId: criteria.folderId
      }
    }

    const source = criteria.source ?? criteria.sourceKey
    if (typeof source === 'string' && source.includes(':')) {
      const split = source.split(':')
      return {
        targetType: split[split.length - 2],
        targetUid: split[split.length - 1]
      }
    }

    const sources = Array.isArray(assetSelectInput.settings?.sources) ? assetSelectInput.settings.sources : []
    const firstSource = sources.find((s) => typeof s === 'string' && s.length > 0 && s !== '*')
    if (typeof firstSource === 'string' && firstSource.includes(':')) {
      const split = firstSource.split(':')
      return {
        targetType: split[split.length - 2],
        targetUid: split[split.length - 1]
      }
    }

    if (typeof criteria.volumeUid === 'string' && criteria.volumeUid.length > 0) {
      return {
        targetType: 'volume',
        targetUid: criteria.volumeUid
      }
    }

    return {}
  }

  monkeypatch(Craft.AssetIndex, 'createUploadInputs', function () {
  if (buttonInit) {
    const inHeader = this.$uploadButton.closest('#header').length > 0
    const inModal = this.$uploadButton.closest('.modal').length > 0

    if (inHeader) {
      this.$uploadButton.before(button.$element)
      this.$uploadButton.before(replaceButton.$element)
    } else if (inModal) {
      this.$uploadButton.after(button.$element)
      this.$uploadButton.after(replaceButton.$element)
    }

    return
  }

  const $uploadButton = this.$uploadButton
  if (typeof $uploadButton === 'undefined' || $uploadButton === null) {
    return
  }

  buttonInit = true
  const inHeader = $uploadButton.closest('#header').length > 0
  const inModal = $uploadButton.closest('.modal').length > 0
  const allowedAssetKinds = this.settings.criteria?.kind ?? []

  let modalOrientations
  if (inHeader) {
    this.$uploadButton.before(button.$element)
    this.$uploadButton.before(replaceButton.$element)
    modalOrientations = ['bottom', 'left', 'right', 'top']
  } else if (inModal) {
    this.$uploadButton.after(button.$element)
    this.$uploadButton.after(replaceButton.$element)
    modalOrientations = ['top', 'right', 'bottom', 'left']
  }

  const showButtonIfAllowed = (targetButton, allowedKinds = []) => {
    if (canShowEmbedButton(allowedKinds)) {
      targetButton.show()
    } else {
      targetButton.hide()
    }
  }

  const toggleUploadButton = (allowedKinds = []) => {
    if (shouldHideUploadButton(allowedKinds)) {
      this.$uploadButton.hide()
    } else {
      this.$uploadButton.show()
    }
  }

  showButtonIfAllowed(button, allowedAssetKinds)
  toggleUploadButton(allowedAssetKinds)
  replaceButton.hide()

  const getActionTarget = () => {
    const sourcePathLength = this.sourcePath?.length ?? 0
    if (sourcePathLength > 0) {
      const currentFolder = this.sourcePath[sourcePathLength - 1]
      if (typeof currentFolder.folderId !== 'undefined') {
        return {
          targetType: 'folder',
          targetId: currentFolder.folderId
        }
      }
    }

    const split = this.sourceKey.split(':')
    if (typeof split[split.length - 2] !== 'undefined') {
      return {
        targetType: split[split.length - 2],
        targetUid: split[split.length - 1]
      }
    }

    return {}
  }

  embeddedVideos.addButton(button, modalOrientations, getActionTarget)
  embeddedVideos.addButton(replaceButton, modalOrientations, getActionTarget, true)

  let idsToSelect = []

  embeddedVideos.on('save', (e) => {
    idsToSelect.push(e.assetId)
    this.updateElements()
  })

  this.on('updateElements', () => {
    idsToSelect.forEach((id) => this.view.selectElementById(id))
    idsToSelect = []
    showButtonIfAllowed(button, allowedAssetKinds)
    toggleUploadButton(allowedAssetKinds)
    replaceButton.hide()
  })

  this.on('selectionChange', (e) => {
    const selectedItems = e.target.view?.elementSelect.$selectedItems ?? []

    if (selectedItems.length === 1) {
      const findAssetEl = $(selectedItems[0]).find('[data-embedded-video]')
      if (findAssetEl.length > 0) {
        button.hide()
        toggleUploadButton(allowedAssetKinds)
        replaceButton.show()
        embeddedVideos.setReplaceAssetId(selectedItems[0].attributes['data-id'].value)
      } else {
        showButtonIfAllowed(button, allowedAssetKinds)
        toggleUploadButton(allowedAssetKinds)
        replaceButton.hide()
      }
    } else {
      showButtonIfAllowed(button, allowedAssetKinds)
      toggleUploadButton(allowedAssetKinds)
      replaceButton.hide()
    }
  })
  })

  monkeypatch(Craft.AssetSelectInput, '_attachUploader', function () {
    if (!this.$addElementBtn || this.$addElementBtn.length === 0) {
      return
    }

    if (this.$container.data('embeddedVideoButtonInitialized')) {
      return
    }

    this.$container.data('embeddedVideoButtonInitialized', true)

    const allowedAssetKinds = this.settings.criteria?.kind ?? []
    const fieldButton = new Button('Embed Video', { videoIcon: true })
    fieldButton.$element.addClass('dashed')

    const $anchor = (this.$uploadBtn && this.$uploadBtn.length > 0)
      ? this.$uploadBtn
      : this.$addElementBtn

    $anchor.after(fieldButton.$element)

    if (this.$uploadBtn && this.$uploadBtn.length > 0) {
      if (shouldHideUploadButton(allowedAssetKinds)) {
        this.$uploadBtn.hide()
      } else {
        this.$uploadBtn.show()
      }
    }

    if (canShowEmbedButton(allowedAssetKinds)) {
      fieldButton.show()
    } else {
      fieldButton.hide()
    }

    embeddedVideos.addButton(
      fieldButton,
      ['top', 'right', 'bottom', 'left'],
      () => getFieldActionTarget(this)
    )

    // Track which field instance opened the HUD, so only that field handles save.
    fieldButton.$element.on('click', () => {
      embeddedVideos._activeFieldInput = this
      embeddedVideos._activeSource = 'field'
    })

    // When embedding from the field-level button, add the new asset directly to this field.
    embeddedVideos.on('save', async (payload) => {
      if (embeddedVideos._activeSource !== 'field' || embeddedVideos._activeFieldInput !== this) {
        return
      }

      const assetId = payload?.assetId
      if (!assetId || typeof this.selectElements !== 'function') {
        embeddedVideos._activeFieldInput = null
        embeddedVideos._activeSource = null
        return
      }

      const siteId = this.settings?.criteria?.siteId ?? Craft.siteId ?? null
      const elementType = this.settings?.elementType ?? 'craft\\elements\\Asset'
      const viewMode = this.settings?.viewMode ?? 'list'
      const [ui, size] = (() => {
        switch (viewMode) {
          case 'thumbs':
          case 'large':
            return ['chip', 'large']
          case 'cards':
          case 'cards-grid':
            return ['card', null]
          default:
            return ['chip', 'small']
        }
      })()

      try {
        const response = await Craft.sendActionRequest('POST', 'app/render-elements', {
          data: {
            elements: [{
              type: elementType,
              id: [assetId],
              siteId,
              instances: [{
                context: 'field',
                ui,
                size,
                showActionMenu: this.settings.showActionMenu
              }]
            }]
          }
        })

        const rendered = response?.data?.elements?.[assetId]?.[0]
        if (!rendered) {
          embeddedVideos._activeFieldInput = null
          embeddedVideos._activeSource = null
          return
        }

        const $rendered = $(rendered)
        const labelText = $rendered.find('.label').first().text()
        const selected = [{
          id: assetId,
          siteId,
          label: typeof labelText === 'string' ? labelText.trim() : '',
          $element: $rendered,
          $modalElement: $rendered
        }]

        await this.selectElements(selected)
        if (typeof this.updateDisabledElementsInModal === 'function') {
          this.updateDisabledElementsInModal()
        }
      } catch (error) {
        Craft.cp.displayError(
          Craft.t('embeddedvideos', 'Could not add the embedded video to this field.')
        )
        // eslint-disable-next-line no-console
        console.error('[embedded-videos] field save failed', error)
      } finally {
        embeddedVideos._activeFieldInput = null
        embeddedVideos._activeSource = null
      }
    })
  })
}

applyMonkeypatch()

window.EmbeddedVideos = embeddedVideos
