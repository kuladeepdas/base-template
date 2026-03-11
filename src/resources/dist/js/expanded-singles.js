(function() {
  "use strict";
  (function($) {
    $('#global-header #crumbs .menubtn[aria-controls="site-crumb-menu"');
    var storedSiteId = Craft.cp.getSiteId() || Craft.primarySiteId;
    var updateSingleUrls = function(siteId = null) {
      $("#main-content #sidebar nav a[data-cp-nav]").each(function(i, e) {
        var siteUrls = $(this).data("site-urls");
        var url = siteUrls[siteId];
        if (!url) {
          return;
        }
        var $link = $(this).parent().find("a.cp-nav-link-mask");
        var $coreLink = $(this).parent().find("a:not(.cp-nav-link-mask)");
        if ($link.length) {
          $link.attr("href", url);
        } else {
          $link = $('<a class="cp-nav-link-mask" role="button" tabindex="0" aria-current="false" href="' + url + '">' + $(this).text() + "</a>");
          $(this).parent().append($link);
        }
        if ($coreLink.length) {
          $coreLink.removeAttr("href").removeAttr("role").removeAttr("tabindex");
        }
      });
    };
    Garnish.requestAnimationFrame($.proxy(function() {
      updateSingleUrls(storedSiteId);
    }, this));
  })(jQuery);
})();
//# sourceMappingURL=expanded-singles.js.map
