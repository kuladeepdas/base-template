(function() {
  "use strict";
  window.EmbeddedVideosPreview = {
    addCallback(callbackName) {
      window.addEventListener("load", () => {
        var _a, _b;
        window.EmbeddedVideosPreview.simplifyControls();
        window.EmbeddedVideosPreview.applyRatio(document.getElementById("code"));
        const callback = ((_b = (_a = window.parent) == null ? void 0 : _a.EmbeddedVideosPreviewMap) == null ? void 0 : _b.get(callbackName)) ?? false;
        if (typeof callback === "function") {
          window.requestAnimationFrame(callback);
        }
      });
    },
    applyRatio(codeEl) {
      if (!codeEl) {
        return;
      }
      const iframeEl = Array.from(codeEl.children).find((el) => el.tagName.toLowerCase() === "iframe");
      if (typeof iframeEl !== "undefined") {
        const width = iframeEl.offsetWidth;
        const height = iframeEl.offsetHeight;
        codeEl.classList.add("is-ratio");
        if (width > 0 && height > 0) {
          iframeEl.style.aspectRatio = `${width} / ${height}`;
        }
        codeEl.style.paddingTop = "";
      } else {
        codeEl.classList.remove("is-ratio");
        codeEl.style.paddingTop = "";
      }
    },
    simplifyControls() {
      const iframeEl = document.querySelector("#code iframe");
      if (!(iframeEl instanceof HTMLIFrameElement)) {
        return;
      }
      const src = iframeEl.getAttribute("src") || "";
      if (src === "") {
        return;
      }
      let parsed;
      try {
        parsed = new URL(src, window.location.origin);
      } catch (_error) {
        return;
      }
      const host = parsed.hostname.toLowerCase();
      const isYouTube = host.includes("youtube.com") || host.includes("youtu.be");
      const isVimeo = host.includes("vimeo.com");
      if (!isYouTube && !isVimeo) {
        return;
      }
      if (isYouTube) {
        if (parsed.hostname.toLowerCase().includes("youtube.com") && parsed.pathname.startsWith("/embed/")) {
          parsed.hostname = "www.youtube-nocookie.com";
        }
        parsed.searchParams.set("controls", "1");
        parsed.searchParams.set("modestbranding", "1");
        parsed.searchParams.set("rel", "0");
        parsed.searchParams.set("iv_load_policy", "3");
        parsed.searchParams.set("playsinline", "1");
        parsed.searchParams.set("fs", "0");
        parsed.searchParams.set("disablekb", "1");
        parsed.searchParams.set("showinfo", "0");
        parsed.searchParams.set("cc_load_policy", "0");
      }
      if (isVimeo) {
        parsed.searchParams.set("controls", "1");
        parsed.searchParams.set("title", "0");
        parsed.searchParams.set("byline", "0");
        parsed.searchParams.set("portrait", "0");
        parsed.searchParams.set("badge", "0");
        parsed.searchParams.set("dnt", "1");
      }
      const nextSrc = parsed.toString();
      if (nextSrc !== src) {
        iframeEl.setAttribute("src", nextSrc);
      }
    }
  };
})();
//# sourceMappingURL=embeddedvideos-preview.js.map
