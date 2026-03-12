(function() {
  "use strict";
  class Emitter {
    constructor() {
      this._events = /* @__PURE__ */ new Map();
    }
    on(type, callback) {
      var _a;
      if (!this._events.has(type)) {
        this._events.set(type, []);
      }
      (_a = this._events.get(type)) == null ? void 0 : _a.push(callback);
    }
    off(type, callback) {
      var _a;
      if (this._events.size > 0) {
        if (typeof callback !== "undefined" && this._events.has(type)) {
          const newList = ((_a = this._events.get(type)) == null ? void 0 : _a.filter((fn) => fn !== callback)) || [];
          if (newList.length > 0) {
            this._events.set(type, newList);
          } else {
            this._events.delete(type);
          }
        } else {
          this._events.delete(type);
        }
      }
    }
    trigger(type, event = {}) {
      var _a;
      if (this._events.size > 0 && this._events.has(type)) {
        const payload = Object.assign(event, { type });
        (_a = this._events.get(type)) == null ? void 0 : _a.forEach((fn) => fn.call(this, payload));
      }
    }
  }
  function monkeypatch(Class, method, callback) {
    if (!Class || !Class.prototype || typeof Class.prototype[method] !== "function" || typeof callback !== "function") {
      return;
    }
    const methodFn = Class.prototype[method];
    Class.prototype[method] = function() {
      const result = methodFn.apply(this, arguments);
      try {
        callback.call(this);
      } catch (error) {
        console.error(`[embedded-videos] monkeypatch callback failed for ${method}`, error);
      }
      return result;
    };
  }
  let uidCounter = 0;
  function uniqueId(prefix = "uid") {
    return `${prefix}${Math.random().toString(36).substr(2)}${uidCounter++}`;
  }
  function isUrl(url) {
    return /^https?:\/\//.test(url);
  }
  const $$3 = window.jQuery;
  class Form extends Emitter {
    constructor(getActionTarget = () => {
    }) {
      var _a, _b, _c;
      super();
      this.getActionTarget = getActionTarget;
      this.state = "idle";
      this.url = "";
      this.replace = false;
      this.replaceAssetId = "";
      this.iframeSrc = "about:blank";
      this.callbackId = null;
      this.timeoutId = null;
      this.requestDebounceId = null;
      this.previewHeight = 320;
      this.previewMonitorId = null;
      this.previewMonitorEndsAt = 0;
      this.lastRequestedUrl = "";
      this._rootEl = document.createElement("div");
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
    `;
      this.element = this._rootEl.firstElementChild;
      this.inputRef = (_a = this.element) == null ? void 0 : _a.querySelector("#embedded-videos-url");
      this.iframeRef = (_b = this.element) == null ? void 0 : _b.querySelector("iframe");
      this.formBody = (_c = this.element) == null ? void 0 : _c.querySelector(".embedded-videos_form_body");
      this.$element = $$3(this.element);
      this._bindEvents();
      this._render();
    }
    destroy() {
      var _a;
      this.clear();
      this.stopPreviewMonitor();
      if (this.requestDebounceId !== null) {
        window.clearTimeout(this.requestDebounceId);
        this.requestDebounceId = null;
      }
      (_a = this.element) == null ? void 0 : _a.remove();
      this.element = null;
      this.$element = null;
      this.trigger("destroy");
    }
    _bindEvents() {
      if (!this.inputRef || !this.iframeRef) {
        return;
      }
      this.inputRef.addEventListener("input", (event) => this.onInput(event));
      this.inputRef.addEventListener("change", () => this.request());
      this.inputRef.addEventListener("keydown", (event) => {
        const isMetaEnter = (event.metaKey || event.ctrlKey) && event.key === "Enter";
        if (!isMetaEnter) {
          return;
        }
        event.preventDefault();
        this.onMetaEnter();
      });
      this.iframeRef.addEventListener("load", () => this.onIframeLoad());
    }
    getPreviewMap() {
      if (typeof window.EmbeddedVideosPreviewMap !== "undefined") {
        return window.EmbeddedVideosPreviewMap;
      }
      const map = /* @__PURE__ */ new Map();
      window.EmbeddedVideosPreviewMap = map;
      return map;
    }
    emitEvent(event, payload) {
      this.trigger(event, payload);
    }
    setState(nextState) {
      this.state = nextState;
      this._render();
      this.emitEvent(nextState);
      window.requestAnimationFrame(() => {
        const element = this.element;
        const height = element instanceof HTMLElement ? element.offsetHeight : 0;
        this.emitEvent("resize", { height });
      });
    }
    _render() {
      if (!this.element || !this.inputRef || !this.iframeRef || !this.formBody) {
        return;
      }
      this.element.dataset.state = this.state;
      if (this.inputRef.value !== this.url) {
        this.inputRef.value = this.url;
      }
      const nextSrc = this.iframeSrc || "about:blank";
      const currentSrc = this.iframeRef.getAttribute("src") || "";
      if (currentSrc !== nextSrc) {
        this.iframeRef.setAttribute("src", nextSrc);
      }
      if (this.state === "idle") {
        this.formBody.style.height = "0px";
        return;
      }
      if (this.state === "requesting") {
        this.formBody.style.height = "96px";
        return;
      }
      const height = Math.max(1, this.previewHeight || 0);
      this.formBody.style.height = `${height}px`;
    }
    clearPendingRequest() {
      if (this.timeoutId !== null) {
        window.clearTimeout(this.timeoutId);
        this.timeoutId = null;
      }
      const cbId = this.callbackId;
      const previewMap = this.getPreviewMap();
      if (cbId !== null && cbId !== "" && previewMap !== void 0 && previewMap.has(cbId)) {
        previewMap.delete(cbId);
      }
    }
    focus() {
      if (this.inputRef !== void 0 && this.inputRef !== null) {
        this.inputRef.focus();
        this.inputRef.select();
      }
    }
    clear() {
      this.clearPendingRequest();
      this.stopPreviewMonitor();
      if (this.requestDebounceId !== null) {
        window.clearTimeout(this.requestDebounceId);
        this.requestDebounceId = null;
      }
      this.url = "";
      this.iframeSrc = "about:blank";
      this.previewHeight = 320;
      this.lastRequestedUrl = "";
      this.setState("idle");
      this.emitEvent("clear");
    }
    setupPreviewCallback(currentUrl) {
      this.clearPendingRequest();
      const cb = uniqueId("embeddedvideos");
      this.callbackId = cb;
      const previewMap = this.getPreviewMap();
      previewMap.set(cb, () => {
        var _a, _b;
        if (this.url !== currentUrl || this.state !== "requesting") {
          return;
        }
        const doc = (_a = this.iframeRef) == null ? void 0 : _a.contentDocument;
        const hasWarning = Boolean(doc == null ? void 0 : doc.querySelector("#warning"));
        const prevent = Boolean(((_b = window.EmbeddedVideos) == null ? void 0 : _b.preventNonWhitelistedUploads) ?? false);
        this.updatePreviewHeight();
        this.startPreviewMonitor();
        this.setState(hasWarning && prevent ? "blacklisted" : "requested");
      });
      this.timeoutId = window.setTimeout(() => {
        if (this.state === "requesting" && this.url === currentUrl) {
          Craft.cp.displayError(Craft.t("embeddedvideos", "Could not retrieve embed information."));
          this.setState("idle");
        }
      }, 15e3);
    }
    request(nextUrl) {
      if (this.state === "saving") {
        return;
      }
      const targetUrl = (nextUrl ?? this.url).trim();
      this.url = targetUrl;
      if (targetUrl !== "" && targetUrl === this.lastRequestedUrl && this.iframeSrc !== "about:blank" && this.state !== "idle") {
        return;
      }
      if (targetUrl === "") {
        this.clearPendingRequest();
        this.iframeSrc = "about:blank";
        this.setState("idle");
        return;
      }
      if (!isUrl(targetUrl)) {
        this.clearPendingRequest();
        this.iframeSrc = "about:blank";
        this.setState("idle");
        return;
      }
      if (!this.isSupportedProvider(targetUrl)) {
        this.clearPendingRequest();
        this.iframeSrc = "about:blank";
        this.setState("idle");
        return;
      }
      this.setupPreviewCallback(targetUrl);
      this.setState("requesting");
      this.previewHeight = 320;
      this.lastRequestedUrl = targetUrl;
      this.iframeSrc = Craft.getActionUrl("inspire-frontend/embedded-videos/preview", {
        showContent: 1,
        callback: this.callbackId,
        url: targetUrl
      });
      this._render();
    }
    setReplace(isReplace, id) {
      this.replace = isReplace;
      this.replaceAssetId = id;
    }
    save(nextUrl) {
      if (this.state !== "requested" && this.state !== "blacklisted") {
        return;
      }
      const targetUrl = (nextUrl ?? this.url).trim();
      const actionTarget = this.getActionTarget() || {};
      const data = {
        ...actionTarget,
        url: targetUrl,
        assetId: this.replaceAssetId
      };
      this.setState("saving");
      Craft.queue.push(async () => await new Promise((resolve, reject) => {
        Craft.sendActionRequest("POST", `inspire-frontend/embedded-videos/${this.replace ? "replace" : "save"}`, { data }).then((response) => {
          var _a, _b, _c;
          if (((_a = response.data) == null ? void 0 : _a.success) === true) {
            const payload = response.data.payload;
            this.clear();
            this.emitEvent("save", payload);
            resolve(void 0);
            return;
          }
          if (typeof ((_b = response.data) == null ? void 0 : _b.error) === "string" && response.data.error !== "") {
            Craft.cp.displayError(response.data.error);
          }
          this.setState("requested");
          reject(new Error(((_c = response.data) == null ? void 0 : _c.error) ?? "Unknown error"));
        }).catch((error) => {
          this.setState("requested");
          reject(error);
        });
      }));
    }
    onMetaEnter() {
      if (this.state === "requested" || this.state === "blacklisted") {
        this.save();
      }
    }
    onInput(event) {
      const target = event.target;
      this.url = (target == null ? void 0 : target.value) ?? "";
      if (this.requestDebounceId !== null) {
        window.clearTimeout(this.requestDebounceId);
      }
      this.requestDebounceId = window.setTimeout(() => {
        this.requestDebounceId = null;
        this.request();
      }, 250);
    }
    isSupportedProvider(url) {
      let host = "";
      try {
        host = new URL(url).hostname.toLowerCase();
      } catch (_error) {
        return false;
      }
      return host.includes("youtube.com") || host.includes("youtu.be") || host.includes("vimeo.com");
    }
    onIframeLoad() {
      this.updatePreviewHeight();
      this.startPreviewMonitor();
    }
    updatePreviewHeight() {
      var _a;
      const doc = (_a = this.iframeRef) == null ? void 0 : _a.contentDocument;
      if (!doc) {
        return;
      }
      const previewEl = doc.querySelector("#code, .image, .volume-preview");
      const previewRectHeight = previewEl instanceof HTMLElement ? Math.ceil(previewEl.getBoundingClientRect().height) : 0;
      const body = doc.body;
      const root = doc.documentElement;
      const fallbackHeight = Math.max(
        (body == null ? void 0 : body.scrollHeight) ?? 0,
        (body == null ? void 0 : body.offsetHeight) ?? 0,
        (root == null ? void 0 : root.scrollHeight) ?? 0,
        (root == null ? void 0 : root.offsetHeight) ?? 0
      );
      const nextHeight = previewRectHeight > 0 ? previewRectHeight : fallbackHeight;
      if (nextHeight > 0) {
        this.previewHeight = nextHeight;
        this._render();
        this.emitEvent("resize", { height: nextHeight });
      }
    }
    startPreviewMonitor() {
      this.stopPreviewMonitor();
      this.previewMonitorEndsAt = Date.now() + 4e3;
      this.previewMonitorId = window.setInterval(() => {
        this.updatePreviewHeight();
        if (Date.now() >= this.previewMonitorEndsAt) {
          this.stopPreviewMonitor();
        }
      }, 120);
    }
    stopPreviewMonitor() {
      if (this.previewMonitorId !== null) {
        window.clearInterval(this.previewMonitorId);
        this.previewMonitorId = null;
      }
    }
  }
  const $$2 = window.jQuery;
  class Modal extends Emitter {
    constructor(getActionTarget = () => {
    }) {
      super();
      this._getActionTarget = getActionTarget;
      this.form = null;
      this.hud = null;
      this.$footer = null;
    }
    create($target, settings = {}) {
      var _a;
      settings = Object.assign({
        hudClass: "hud embedded-videos_hud",
        mainClass: "embedded-videos_hud_main",
        minBodyWidth: 400
      }, settings);
      const cancelId = uniqueId();
      const saveId = uniqueId();
      const spinnerId = uniqueId();
      const cancelLabel = Craft.t("app", "Cancel");
      const saveLabel = Craft.t("app", "Save");
      this.$footer = $$2(`
      <div class="hud-footer embedded-videos_hud_footer">
        <div class="buttons right">
          <button id="${cancelId}" class="btn" type="button">${cancelLabel}</button>
          <button id="${saveId}" class="btn submit" type="button">${saveLabel}</button>
          <div id="${spinnerId}" class="spinner hidden"></div>
        </div>
      </div>
    `);
      this.$cancel = this.$footer.find(`#${cancelId}`);
      this.$save = this.$footer.find(`#${saveId}`);
      this.$spinner = this.$footer.find(`#${spinnerId}`);
      this.form = new Form(this._getActionTarget);
      const $content = ((_a = this.form) == null ? void 0 : _a.$element) && this.form.$element.length ? this.form.$element.add(this.$footer) : this.$footer;
      this.hud = new Garnish.HUD($target, $content, settings);
      this.$save.on("click", (e) => this.$save.hasClass("disabled") && e.stopImmediatePropagation());
      this.$cancel.on("click", () => {
        var _a2;
        return (_a2 = this.form) == null ? void 0 : _a2.clear();
      });
      this.$save.on("click", () => {
        var _a2;
        return (_a2 = this.form) == null ? void 0 : _a2.save();
      });
      this.form.on("save", (e) => this.trigger("save", e));
      this.hud.on("show", () => this.trigger("show"));
      this.hud.on("hide", () => this.trigger("hide"));
      this.form.on("clear", () => this.hide());
      this.form.on("save", () => this.hide());
      this.hud.on("show", () => {
        var _a2;
        return (_a2 = this.form) == null ? void 0 : _a2.request();
      });
      this.hud.on("show", () => {
        var _a2;
        return (_a2 = this.form) == null ? void 0 : _a2.focus();
      });
      this.hud.on("hide", () => {
        var _a2;
        return (_a2 = this.form) == null ? void 0 : _a2.setState("idle");
      });
      this.hideFooter();
      this.setSaveEnabled(false);
      this.form.on("idle", () => this.hideFooter());
      this.form.on("requesting", () => this.hideFooter());
      this.form.on("requested", () => this.showFooter());
      this.form.on("blacklisted", () => this.hideFooter());
      this.form.on("idle", () => {
        this.setSaveEnabled(false);
        this.setSaving(false);
      });
      this.form.on("requesting", () => {
        this.setSaveEnabled(false);
        this.setSaving(false);
      });
      this.form.on("requested", () => {
        this.setSaveEnabled(true);
        this.setSaving(false);
      });
      this.form.on("blacklisted", () => {
        this.setSaveEnabled(false);
        this.setSaving(false);
      });
      this.form.on("saving", () => {
        this.setSaveEnabled(false);
        this.setSaving(true);
      });
      this.form.on("resize", () => {
        var _a2;
        return (_a2 = this.hud) == null ? void 0 : _a2.updateSizeAndPosition();
      });
    }
    destroy() {
      if (this.$footer !== null) {
        this.$footer.remove();
        this.$footer = null;
      }
      if (this.form !== null) {
        this.form.destroy();
        this.form = null;
      }
      if (this.hud !== null) {
        this.hud.hide();
        this.hud.$hud.remove();
        this.hud.$shade.remove();
        this.hud = null;
      }
      this.trigger("destroy");
    }
    show($target, settings = {}) {
      var _a;
      this._blurActiveElement();
      if (this.hud !== null) {
        this.hud.setSettings(settings);
        this.hud.$trigger = $$2($target);
        if (this.hud.showing) {
          this.hud.queueUpdateSizeAndPosition();
        } else {
          this.hud.show();
        }
      } else {
        this.create($target, settings);
        (_a = this.form) == null ? void 0 : _a.focus();
      }
      this.trigger("show");
    }
    hide() {
      var _a;
      this._blurActiveElement();
      (_a = this.hud) == null ? void 0 : _a.hide();
      this.trigger("hide");
    }
    hideFooter() {
      var _a;
      (_a = this.hud) == null ? void 0 : _a.$hud.removeClass("show-footer");
    }
    showFooter() {
      var _a;
      (_a = this.hud) == null ? void 0 : _a.$hud.addClass("show-footer");
    }
    setSaving(enabled = true) {
      var _a, _b;
      (_a = this.$save) == null ? void 0 : _a.toggleClass("disabled", enabled);
      (_b = this.$spinner) == null ? void 0 : _b.toggleClass("hidden", !enabled);
    }
    setSaveEnabled(enabled = true) {
      var _a, _b;
      (_a = this.$save) == null ? void 0 : _a.prop("disabled", !enabled);
      (_b = this.$save) == null ? void 0 : _b.toggleClass("disabled", !enabled);
    }
    _blurActiveElement() {
      const active = document.activeElement;
      if (active instanceof HTMLElement && typeof active.blur === "function") {
        active.blur();
      }
    }
  }
  class EmbeddedVideos extends Emitter {
    constructor() {
      super();
      this._replaceAssetId = "";
      this._currentGetActionTarget = () => -1;
      this._preventNonWhitelistedUploads = void 0;
      this.modal = new Modal(() => this._getActionTarget());
      this.buttons = [];
      this.modal.on("hide", () => this.buttons.forEach((b) => b.setActive(false)));
      this.modal.on("save", (e) => this.trigger("save", e));
    }
    get preventNonWhitelistedUploads() {
      return this._preventNonWhitelistedUploads ?? false;
    }
    set preventNonWhitelistedUploads(prevent) {
      if (typeof this._preventNonWhitelistedUploads === "undefined") {
        this._preventNonWhitelistedUploads = prevent;
      }
    }
    destroy() {
      if (this.modal !== null) {
        this.modal.destroy();
        this.modal = null;
        this.trigger("destroy");
      }
    }
    setReplaceAssetId(id) {
      this._replaceAssetId = id;
    }
    addButton(button2, orientations = ["bottom", "top", "left", "right"], getActionTarget = () => {
    }, replace = false) {
      var _a;
      this.buttons.push(button2);
      (_a = button2.$element) == null ? void 0 : _a.on("click", () => {
        var _a2;
        if (this.modal !== null) {
          this._currentGetActionTarget = getActionTarget;
          this.buttons.forEach((b) => b.setActive(b === button2));
          this.modal.show(button2.$element, { orientations }, replace);
          (_a2 = this.modal.form) == null ? void 0 : _a2.setReplace(replace, this._replaceAssetId);
        }
      });
      this.trigger("addButton", { button: button2 });
    }
    removeButton(button2) {
      this.buttons = this.buttons.filter((b) => b !== button2);
      this.trigger("removeButton", { button: button2 });
    }
    _getActionTarget() {
      return this._currentGetActionTarget();
    }
  }
  const $$1 = window.jQuery;
  class Button {
    constructor(label, options = {}) {
      this.options = Object.assign({
        videoIcon: false
      }, options);
      this.$element = $$1('<div class="embedded-videos_button btn"></div>');
      if (this.options.videoIcon) {
        this.$icon = $$1('<span class="embedded-videos_button_icon" aria-hidden="true"></span>');
        this.$element.append(this.$icon);
      }
      this.$label = $$1('<span class="embedded-videos_button_label"></span>');
      this.$element.append(this.$label);
      this.setLabel(label);
    }
    destroy() {
      var _a;
      (_a = this.$element) == null ? void 0 : _a.remove();
      this.$element = null;
    }
    setLabel(label) {
      var _a;
      (_a = this.$label) == null ? void 0 : _a.text(Craft.t("embeddedvideos", label));
    }
    show() {
      var _a;
      (_a = this.$element) == null ? void 0 : _a.show();
    }
    hide() {
      var _a;
      (_a = this.$element) == null ? void 0 : _a.hide();
    }
    setActive(flag = true) {
      var _a;
      (_a = this.$element) == null ? void 0 : _a.toggleClass("active", flag);
    }
  }
  window.process = window.process || { env: { NODE_ENV: "production" } };
  const $ = window.jQuery || window.$;
  const embeddedVideos = new EmbeddedVideos();
  const button = new Button("Embed Video", { videoIcon: true });
  const replaceButton = new Button("Replace");
  let buttonInit = false;
  const applyMonkeypatch = () => {
    if (typeof Craft === "undefined" || typeof Craft.AssetIndex === "undefined" || typeof $ !== "function") {
      window.setTimeout(applyMonkeypatch, 50);
      return;
    }
    const normalizeKinds = (kinds) => {
      if (typeof kinds === "string") {
        return [kinds];
      }
      if (Array.isArray(kinds)) {
        return kinds;
      }
      return [];
    };
    const canShowEmbedButton = (allowedKinds) => {
      const kinds = normalizeKinds(allowedKinds);
      return kinds.length === 0 || kinds.includes("embeddedVideo");
    };
    const shouldHideUploadButton = (allowedKinds) => {
      const kinds = normalizeKinds(allowedKinds);
      return kinds.length === 1 && kinds.includes("embeddedVideo");
    };
    const ensureFieldSearchVisible = (assetSelectInput) => {
      if (!(assetSelectInput == null ? void 0 : assetSelectInput.$container) || assetSelectInput.$container.length === 0) {
        return;
      }
      const $searchEls = assetSelectInput.$container.find(
        ".search, .search-container, .elementselect .search, .elementselect .search-container"
      );
      $searchEls.each(function() {
        const $el = $(this);
        $el.removeClass("hidden");
        $el.show();
        $el.css("display", "");
      });
    };
    const ensureFieldSearchInput = (assetSelectInput) => {
      var _a;
      if (!(assetSelectInput == null ? void 0 : assetSelectInput.$container) || assetSelectInput.$container.length === 0) {
        return;
      }
      const $existing = assetSelectInput.$container.find(".elementselect__search-input-wrapper").first();
      if ($existing.length > 0) {
        ensureFieldSearchVisible(assetSelectInput);
        return;
      }
      const inputId = `embeddedvideos-search-${uniqueId("field")}`;
      const dir = $("html").attr("dir") || "ltr";
      const $field = assetSelectInput.$container.closest(".field");
      const labelId = $field.find(".heading label, label").first().attr("id") || null;
      const $search = $(`
      <div class="texticon search icon elementselect__search-input-wrapper">
        <input type="text" id="${inputId}" class="text fullwidth" autocomplete="off" placeholder="${Craft.t("app", "Search")}" dir="${dir}" role="combobox" aria-expanded="false">
        <div class="spinner hidden"></div>
      </div>
    `);
      if (labelId) {
        $search.find("input").attr("aria-labelledby", labelId);
      }
      const $controlsRow = assetSelectInput.$container.children(".flex").first();
      if ($controlsRow.length > 0 && ((_a = assetSelectInput.$addElementBtn) == null ? void 0 : _a.length) > 0) {
        assetSelectInput.$addElementBtn.after($search);
      } else {
        assetSelectInput.$container.append($search);
      }
      if (typeof assetSelectInput._attachSearchInput === "function") {
        assetSelectInput._attachSearchInput();
      } else if (typeof assetSelectInput.initSearchInput === "function") {
        assetSelectInput.initSearchInput();
      } else if (typeof assetSelectInput._initSearchInput === "function") {
        assetSelectInput._initSearchInput();
      }
      ensureFieldSearchVisible(assetSelectInput);
    };
    const getFieldLimit = (assetSelectInput) => {
      var _a, _b, _c;
      const rawLimit = ((_a = assetSelectInput == null ? void 0 : assetSelectInput.settings) == null ? void 0 : _a.limit) ?? ((_c = (_b = assetSelectInput == null ? void 0 : assetSelectInput.settings) == null ? void 0 : _b.criteria) == null ? void 0 : _c.limit) ?? null;
      const parsed = Number.parseInt(rawLimit, 10);
      return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
    };
    const getSelectedCount = (assetSelectInput) => {
      var _a;
      if (!assetSelectInput) {
        return 0;
      }
      if (typeof assetSelectInput.getSelectedElementIds === "function") {
        const ids = assetSelectInput.getSelectedElementIds();
        if (Array.isArray(ids)) {
          return ids.length;
        }
      }
      if (assetSelectInput.$elements && typeof assetSelectInput.$elements.length === "number") {
        return assetSelectInput.$elements.length;
      }
      const $elements = (_a = assetSelectInput.$container) == null ? void 0 : _a.find(".elements .element");
      return ($elements == null ? void 0 : $elements.length) ?? 0;
    };
    const updateFieldControlsVisibility = (assetSelectInput, fieldButton, allowedKinds = []) => {
      if (!(assetSelectInput == null ? void 0 : assetSelectInput.$container) || assetSelectInput.$container.length === 0) {
        return;
      }
      const $searchWrapper = assetSelectInput.$container.find(".elementselect__search-input-wrapper, .search, .search-container").first();
      const limit = getFieldLimit(assetSelectInput);
      const selectedCount = getSelectedCount(assetSelectInput);
      const atCapacity = limit !== null && selectedCount >= limit;
      if (atCapacity) {
        fieldButton.hide();
        if ($searchWrapper.length > 0) {
          $searchWrapper.addClass("hidden");
          $searchWrapper.hide();
        }
        return;
      }
      if (canShowEmbedButton(allowedKinds)) {
        fieldButton.show();
      } else {
        fieldButton.hide();
      }
      if ($searchWrapper.length > 0) {
        $searchWrapper.removeClass("hidden");
        $searchWrapper.show();
        $searchWrapper.css("display", "");
      }
    };
    const setupFieldCapacityWatcher = (assetSelectInput, fieldButton, allowedKinds = []) => {
      if (!(assetSelectInput == null ? void 0 : assetSelectInput.$container) || assetSelectInput.$container.length === 0) {
        return;
      }
      if (assetSelectInput.$container.data("embeddedVideoCapacityWatcherInitialized")) {
        return;
      }
      assetSelectInput.$container.data("embeddedVideoCapacityWatcherInitialized", true);
      const run = () => updateFieldControlsVisibility(assetSelectInput, fieldButton, allowedKinds);
      if (typeof assetSelectInput.on === "function") {
        assetSelectInput.on("selectionChange", run);
        assetSelectInput.on("updateElements", run);
        assetSelectInput.on("removeElements", run);
      }
      const host = assetSelectInput.$container.find(".elements").first().get(0) || assetSelectInput.$container.get(0);
      if (host && typeof MutationObserver !== "undefined") {
        const observer = new MutationObserver(run);
        observer.observe(host, { childList: true, subtree: true });
        assetSelectInput.$container.data("embeddedVideoCapacityObserver", observer);
      }
      run();
    };
    const getFieldActionTarget = (assetSelectInput) => {
      var _a, _b;
      const criteria = ((_a = assetSelectInput.settings) == null ? void 0 : _a.criteria) ?? {};
      if (typeof criteria.folderId !== "undefined" && criteria.folderId !== null) {
        return {
          targetType: "folder",
          targetId: criteria.folderId
        };
      }
      const source = criteria.source ?? criteria.sourceKey;
      if (typeof source === "string" && source.includes(":")) {
        const split = source.split(":");
        return {
          targetType: split[split.length - 2],
          targetUid: split[split.length - 1]
        };
      }
      const sources = Array.isArray((_b = assetSelectInput.settings) == null ? void 0 : _b.sources) ? assetSelectInput.settings.sources : [];
      const firstSource = sources.find((s) => typeof s === "string" && s.length > 0 && s !== "*");
      if (typeof firstSource === "string" && firstSource.includes(":")) {
        const split = firstSource.split(":");
        return {
          targetType: split[split.length - 2],
          targetUid: split[split.length - 1]
        };
      }
      if (typeof criteria.volumeUid === "string" && criteria.volumeUid.length > 0) {
        return {
          targetType: "volume",
          targetUid: criteria.volumeUid
        };
      }
      return {};
    };
    monkeypatch(Craft.AssetIndex, "createUploadInputs", function() {
      var _a;
      if (buttonInit) {
        const inHeader2 = this.$uploadButton.closest("#header").length > 0;
        const inModal2 = this.$uploadButton.closest(".modal").length > 0;
        if (inHeader2) {
          this.$uploadButton.before(button.$element);
          this.$uploadButton.before(replaceButton.$element);
        } else if (inModal2) {
          this.$uploadButton.after(button.$element);
          this.$uploadButton.after(replaceButton.$element);
        }
        return;
      }
      const $uploadButton = this.$uploadButton;
      if (typeof $uploadButton === "undefined" || $uploadButton === null) {
        return;
      }
      buttonInit = true;
      const inHeader = $uploadButton.closest("#header").length > 0;
      const inModal = $uploadButton.closest(".modal").length > 0;
      const allowedAssetKinds = ((_a = this.settings.criteria) == null ? void 0 : _a.kind) ?? [];
      let modalOrientations;
      if (inHeader) {
        this.$uploadButton.before(button.$element);
        this.$uploadButton.before(replaceButton.$element);
        modalOrientations = ["bottom", "left", "right", "top"];
      } else if (inModal) {
        this.$uploadButton.after(button.$element);
        this.$uploadButton.after(replaceButton.$element);
        modalOrientations = ["top", "right", "bottom", "left"];
      }
      const showButtonIfAllowed = (targetButton, allowedKinds = []) => {
        if (canShowEmbedButton(allowedKinds)) {
          targetButton.show();
        } else {
          targetButton.hide();
        }
      };
      const toggleUploadButton = (allowedKinds = []) => {
        if (shouldHideUploadButton(allowedKinds)) {
          this.$uploadButton.hide();
        } else {
          this.$uploadButton.show();
        }
      };
      showButtonIfAllowed(button, allowedAssetKinds);
      toggleUploadButton(allowedAssetKinds);
      replaceButton.hide();
      const getActionTarget = () => {
        var _a2;
        const sourcePathLength = ((_a2 = this.sourcePath) == null ? void 0 : _a2.length) ?? 0;
        if (sourcePathLength > 0) {
          const currentFolder = this.sourcePath[sourcePathLength - 1];
          if (typeof currentFolder.folderId !== "undefined") {
            return {
              targetType: "folder",
              targetId: currentFolder.folderId
            };
          }
        }
        const split = this.sourceKey.split(":");
        if (typeof split[split.length - 2] !== "undefined") {
          return {
            targetType: split[split.length - 2],
            targetUid: split[split.length - 1]
          };
        }
        return {};
      };
      embeddedVideos.addButton(button, modalOrientations, getActionTarget);
      embeddedVideos.addButton(replaceButton, modalOrientations, getActionTarget, true);
      let idsToSelect = [];
      embeddedVideos.on("save", (e) => {
        idsToSelect.push(e.assetId);
        this.updateElements();
      });
      this.on("updateElements", () => {
        idsToSelect.forEach((id) => this.view.selectElementById(id));
        idsToSelect = [];
        showButtonIfAllowed(button, allowedAssetKinds);
        toggleUploadButton(allowedAssetKinds);
        replaceButton.hide();
      });
      this.on("selectionChange", (e) => {
        var _a2;
        const selectedItems = ((_a2 = e.target.view) == null ? void 0 : _a2.elementSelect.$selectedItems) ?? [];
        if (selectedItems.length === 1) {
          const findAssetEl = $(selectedItems[0]).find("[data-embedded-video]");
          if (findAssetEl.length > 0) {
            button.hide();
            toggleUploadButton(allowedAssetKinds);
            replaceButton.show();
            embeddedVideos.setReplaceAssetId(selectedItems[0].attributes["data-id"].value);
          } else {
            showButtonIfAllowed(button, allowedAssetKinds);
            toggleUploadButton(allowedAssetKinds);
            replaceButton.hide();
          }
        } else {
          showButtonIfAllowed(button, allowedAssetKinds);
          toggleUploadButton(allowedAssetKinds);
          replaceButton.hide();
        }
      });
    });
    monkeypatch(Craft.AssetSelectInput, "_attachUploader", function() {
      var _a;
      if (!this.$addElementBtn || this.$addElementBtn.length === 0) {
        return;
      }
      if (!this.$container || this.$container.length === 0 || typeof this.$container.data !== "function") {
        return;
      }
      ensureFieldSearchVisible(this);
      const allowedAssetKinds = ((_a = this.settings.criteria) == null ? void 0 : _a.kind) ?? [];
      const normalizedKinds = normalizeKinds(allowedAssetKinds);
      const allowsEmbeddedVideo = normalizedKinds.includes("embeddedVideo");
      if (!allowsEmbeddedVideo) {
        return;
      }
      if (this.$container.data("embeddedVideoButtonInitialized")) {
        return;
      }
      this.$container.data("embeddedVideoButtonInitialized", true);
      const fieldButton = new Button("Embed Video", { videoIcon: true });
      fieldButton.$element.addClass("dashed");
      ensureFieldSearchInput(this);
      const $controlsRow = this.$container.children(".flex").first();
      const $rowSpinner = $controlsRow.children(".spinner").last();
      const $searchWrapper = $controlsRow.find(".elementselect__search-input-wrapper").first();
      if ($controlsRow.length > 0 && $searchWrapper.length > 0) {
        fieldButton.$element.insertBefore($searchWrapper);
        if ($rowSpinner.length > 0) {
          $searchWrapper.insertBefore($rowSpinner);
        } else {
          $controlsRow.append($searchWrapper);
        }
      } else if ($rowSpinner.length > 0) {
        $rowSpinner.before(fieldButton.$element);
      } else if ($controlsRow.length > 0) {
        $controlsRow.append(fieldButton.$element);
      } else {
        this.$addElementBtn.after(fieldButton.$element);
      }
      if (this.$uploadBtn && this.$uploadBtn.length > 0) {
        if (shouldHideUploadButton(allowedAssetKinds)) {
          this.$uploadBtn.hide();
        }
      }
      ensureFieldSearchVisible(this);
      updateFieldControlsVisibility(this, fieldButton, allowedAssetKinds);
      setupFieldCapacityWatcher(this, fieldButton, allowedAssetKinds);
      embeddedVideos.addButton(
        fieldButton,
        ["top", "right", "bottom", "left"],
        () => getFieldActionTarget(this)
      );
      fieldButton.$element.on("click", () => {
        embeddedVideos._activeFieldInput = this;
        embeddedVideos._activeSource = "field";
      });
      embeddedVideos.on("save", async (payload) => {
        var _a2, _b, _c, _d, _e, _f, _g;
        if (embeddedVideos._activeSource !== "field" || embeddedVideos._activeFieldInput !== this) {
          return;
        }
        const assetId = payload == null ? void 0 : payload.assetId;
        if (!assetId || typeof this.selectElements !== "function") {
          embeddedVideos._activeFieldInput = null;
          embeddedVideos._activeSource = null;
          return;
        }
        const siteId = ((_b = (_a2 = this.settings) == null ? void 0 : _a2.criteria) == null ? void 0 : _b.siteId) ?? Craft.siteId ?? null;
        const elementType = ((_c = this.settings) == null ? void 0 : _c.elementType) ?? "craft\\elements\\Asset";
        const viewMode = ((_d = this.settings) == null ? void 0 : _d.viewMode) ?? "list";
        const [ui, size] = (() => {
          switch (viewMode) {
            case "thumbs":
            case "large":
              return ["chip", "large"];
            case "cards":
            case "cards-grid":
              return ["card", null];
            default:
              return ["chip", "small"];
          }
        })();
        try {
          const response = await Craft.sendActionRequest("POST", "app/render-elements", {
            data: {
              elements: [{
                type: elementType,
                id: [assetId],
                siteId,
                instances: [{
                  context: "field",
                  ui,
                  size,
                  showActionMenu: this.settings.showActionMenu
                }]
              }]
            }
          });
          const rendered = (_g = (_f = (_e = response == null ? void 0 : response.data) == null ? void 0 : _e.elements) == null ? void 0 : _f[assetId]) == null ? void 0 : _g[0];
          if (!rendered) {
            embeddedVideos._activeFieldInput = null;
            embeddedVideos._activeSource = null;
            return;
          }
          const $rendered = $(rendered);
          const labelText = $rendered.find(".label").first().text();
          const selected = [{
            id: assetId,
            siteId,
            label: typeof labelText === "string" ? labelText.trim() : "",
            $element: $rendered,
            $modalElement: $rendered
          }];
          await this.selectElements(selected);
          if (typeof this.updateDisabledElementsInModal === "function") {
            this.updateDisabledElementsInModal();
          }
          updateFieldControlsVisibility(this, fieldButton, allowedAssetKinds);
        } catch (error) {
          Craft.cp.displayError(
            Craft.t("embeddedvideos", "Could not add the embedded video to this field.")
          );
          console.error("[embedded-videos] field save failed", error);
        } finally {
          embeddedVideos._activeFieldInput = null;
          embeddedVideos._activeSource = null;
        }
      });
    });
  };
  applyMonkeypatch();
  window.EmbeddedVideos = embeddedVideos;
})();
//# sourceMappingURL=embeddedvideos-main.js.map
