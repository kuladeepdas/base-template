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

  const ensureFieldSearchVisible = (assetSelectInput) => {
    if (!assetSelectInput?.$container || assetSelectInput.$container.length === 0) {
      return
    }

    const $searchEls = assetSelectInput.$container.find(
      '.search, .search-container, .elementselect .search, .elementselect .search-container'
    )

    $searchEls.each(function () {
      const $el = $(this)
      $el.removeClass('hidden')
      $el.show()
      $el.css('display', '')
    })
  }

  const ensureFieldSearchInput = (assetSelectInput) => {
    if (!assetSelectInput?.$container || assetSelectInput.$container.length === 0) {
      return
    }

    const $existing = assetSelectInput.$container.find('.elementselect__search-input-wrapper').first()
    if ($existing.length > 0) {
      ensureFieldSearchVisible(assetSelectInput)
      return
    }

    const inputId = `embeddedvideos-search-${uniqueId('field')}`
    const dir = $('html').attr('dir') || 'ltr'
    const $field = assetSelectInput.$container.closest('.field')
    const labelId = $field.find('.heading label, label').first().attr('id') || null

    const $search = $(`
      <div class="texticon search icon elementselect__search-input-wrapper">
        <input type="text" id="${inputId}" class="text fullwidth" autocomplete="off" placeholder="${Craft.t('app', 'Search')}" dir="${dir}" role="combobox" aria-expanded="false">
        <div class="spinner hidden"></div>
      </div>
    `)

    if (labelId) {
      $search.find('input').attr('aria-labelledby', labelId)
    }

    const $controlsRow = assetSelectInput.$container.children('.flex').first()
    if ($controlsRow.length > 0 && assetSelectInput.$addElementBtn?.length > 0) {
      assetSelectInput.$addElementBtn.after($search)
    } else {
      assetSelectInput.$container.append($search)
    }

    // Hook up Craft's inline search behavior when available on this class/version.
    if (typeof assetSelectInput._attachSearchInput === 'function') {
      assetSelectInput._attachSearchInput()
    } else if (typeof assetSelectInput.initSearchInput === 'function') {
      assetSelectInput.initSearchInput()
    } else if (typeof assetSelectInput._initSearchInput === 'function') {
      assetSelectInput._initSearchInput()
    }

    ensureFieldSearchVisible(assetSelectInput)
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

    if (!this.$container || this.$container.length === 0 || typeof this.$container.data !== 'function') {
      return
    }

    ensureFieldSearchVisible(this)

    const allowedAssetKinds = this.settings.criteria?.kind ?? []
    const normalizedKinds = normalizeKinds(allowedAssetKinds)
    const allowsEmbeddedVideo = normalizedKinds.includes('embeddedVideo')

    if (!allowsEmbeddedVideo) {
      return
    }

    if (this.$container.data('embeddedVideoButtonInitialized')) {
      return
    }

    this.$container.data('embeddedVideoButtonInitialized', true)

    const fieldButton = new Button('Embed Video', { videoIcon: true })
    fieldButton.$element.addClass('dashed')

    ensureFieldSearchInput(this)

    const $controlsRow = this.$container.children('.flex').first()
    const $rowSpinner = $controlsRow.children('.spinner').last()
    const $searchWrapper = $controlsRow.find('.elementselect__search-input-wrapper').first()

    if ($controlsRow.length > 0 && $searchWrapper.length > 0) {
      // Keep search on the right by placing buttons before the search wrapper.
      fieldButton.$element.insertBefore($searchWrapper)
      if ($rowSpinner.length > 0) {
        $searchWrapper.insertBefore($rowSpinner)
      } else {
        $controlsRow.append($searchWrapper)
      }
    } else if ($rowSpinner.length > 0) {
      $rowSpinner.before(fieldButton.$element)
    } else if ($controlsRow.length > 0) {
      $controlsRow.append(fieldButton.$element)
    } else {
      this.$addElementBtn.after(fieldButton.$element)
    }

    if (this.$uploadBtn && this.$uploadBtn.length > 0) {
      if (shouldHideUploadButton(allowedAssetKinds)) {
        this.$uploadBtn.hide()
      }
    }

    ensureFieldSearchVisible(this)

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
