(function() {
  "use strict";
  if (typeof Craft.InspireFrontend === "undefined") {
    Craft.InspireFrontend = {};
  }
  (function($) {
    Craft.TabsManager = Garnish.Base.extend({
      init: function() {
        this.$selectedTab = null;
        var $tabs = $("#settings-tabs").find("> ul > li > a");
        for (var i = 0; i < $tabs.length; i++) {
          var $tab = $($tabs[i]), href = $tab.attr("href");
          if (href && href.charAt(0) === "#") {
            this.addListener($tab, "click", function(ev) {
              ev.preventDefault();
              this.selectTab(ev.currentTarget);
            });
          }
          if (!this.$selectedTab && $tab.hasClass("sel")) {
            this.$selectedTab = $tab;
          }
        }
      },
      selectTab: function(tab) {
        var $tab = $(tab);
        if (this.$selectedTab) {
          if (this.$selectedTab.get(0) === $tab.get(0)) {
            return;
          }
          this.deselectTab();
        }
        $tab.addClass("sel");
        $($tab.attr("href")).removeClass("hidden");
        Garnish.$win.trigger("resize");
        Garnish.$doc.trigger("scroll");
        this.$selectedTab = $tab;
      },
      deselectTab: function() {
        if (!this.$selectedTab) {
          return;
        }
        this.$selectedTab.removeClass("sel");
        if (this.$selectedTab.attr("href").charAt(0) === "#") {
          $(this.$selectedTab.attr("href")).addClass("hidden");
        }
        this.$selectedTab = null;
      }
    });
  })(jQuery);
  if (typeof Craft.InspireFrontend === "undefined") {
    Craft.InspireFrontend = {};
  }
  (function($) {
    Craft.InspireFrontend.ResizeElementAction = Garnish.Base.extend({
      init: function(imageWidth, imageHeight, type) {
        var settings = {
          width: imageWidth,
          height: imageHeight
        };
        new Craft.ElementActionTrigger({
          // handle: 'ImageResizer_ResizeImage',
          type,
          batch: true,
          validateSelection: function($selectedItems) {
            var documents = $selectedItems.find(".element.hasicon").length;
            return documents > 0 ? false : true;
          },
          activate: function($selectedItems) {
            new Craft.InspireFrontend.ResizeModal($selectedItems.find(".element"), $selectedItems, settings);
          }
        });
      }
    });
    Craft.InspireFrontend.BulkResizeAssetFolder = Garnish.Base.extend({
      init: function(imageWidth, imageHeight) {
        var settings = {
          width: imageWidth,
          height: imageHeight
        };
        $(".bulk-resize-btn").on("click", function() {
          settings.assetFolderId = $(this).data("id");
          settings.assetFolderName = $(this).data("name");
          settings.bulkResize = true;
          new Craft.InspireFrontend.ResizeModal("", "", settings);
        });
      }
    });
    Craft.InspireFrontend.ResizeModal = Garnish.Modal.extend({
      $element: null,
      $selectedItems: null,
      settings: null,
      $container: null,
      $footer: null,
      $body: null,
      $buttons: null,
      $closeBtn: null,
      $cancelBtn: null,
      $saveBtn: null,
      $footerSpinner: null,
      init: function($element, $selectedItems, settings) {
        this.$element = $element;
        this.$selectedItems = $selectedItems;
        this.settings = settings;
        this.desiredWidth = "450";
        this.desiredHeight = "340";
        var plural = $selectedItems.length == 1 ? "" : "s";
        this.$container = $('<div class="modal fitted image-resizer-modal"></div>').appendTo(Garnish.$bod);
        this.$footer = $('<div class="footer"/>').appendTo(this.$container);
        if (settings.bulkResize) {
          var actionDescription = "<strong>all images in " + settings.assetFolderName + "</strong>";
        } else {
          var actionDescription = "<strong>" + $selectedItems.length + "</strong> image" + plural;
        }
        var bodyHtml = '<div class="body"><div class="modal-content"><div class="modal-main"><div class="modal-elements"><h1>' + Craft.t("inspire-frontend", "Resize Images") + "</h1><p>" + Craft.t("inspire-frontend", "You are about to resize {desc} to be a maximum of {width}px wide and {height}px high. Alternatively, set the width and height limits below for on-demand resizing.", { desc: actionDescription, width: settings.width, height: settings.height }) + '</p><input class="text" type="text" id="settings-imageWidth" size="10" name="settings[imageWidth]" value="' + settings.width + '" autocomplete="off"> ' + Craft.t("inspire-frontend", "width") + ' &nbsp;&nbsp;<input class="text" type="text" id="settings-imageHeight" size="10" name="settings[imageHeight]" value="' + settings.height + '" autocomplete="off"> ' + Craft.t("inspire-frontend", "height") + "<p><strong>" + Craft.t("inspire-frontend", "Caution") + ":</strong> " + Craft.t("inspire-frontend", "This operation permanently alters your images.") + "</p></div></div></div></div>";
        var $body = $(bodyHtml).appendTo(this.$container);
        this.base(this.$container, this.settings);
        this.$footerSpinner = $('<div class="spinner hidden"/>').appendTo(this.$footer);
        this.$buttons = $('<div class="buttons rightalign first"/>').appendTo(this.$footer);
        this.$closeBtn = $('<div class="btn close hidden">' + Craft.t("inspire-frontend", "Close") + "</div>").appendTo(this.$buttons);
        this.$cancelBtn = $('<div class="btn cancel">' + Craft.t("inspire-frontend", "Cancel") + "</div>").appendTo(this.$buttons);
        this.$saveBtn = $('<div class="btn submit">' + Craft.t("inspire-frontend", "Resize") + "</div>").appendTo(this.$buttons);
        this.$body = $body;
        this.addListener(this.$closeBtn, "activate", "onFadeOut");
        this.addListener(this.$cancelBtn, "activate", "onFadeOut");
        this.addListener(this.$saveBtn, "activate", "saveSettings");
      },
      onFadeOut: function() {
        this.hide();
      },
      saveSettings: function() {
        var dataIds = [];
        var modal = this;
        if (this.$selectedItems.length) {
          this.$selectedItems.each(function(index, element) {
            dataIds[index] = $(element).data("id");
          });
        }
        this.$footerSpinner.removeClass("hidden");
        var imageWidth = this.$body.find("#settings-imageWidth").val();
        var imageHeight = this.$body.find("#settings-imageHeight").val();
        var taskId = this.generateId();
        if (this.settings.bulkResize) {
          var data = {
            taskId,
            bulkResize: this.settings.bulkResize,
            assetFolderId: this.settings.assetFolderId,
            imageWidth,
            imageHeight
          };
        } else {
          var data = {
            taskId,
            assetIds: dataIds,
            imageWidth,
            imageHeight
          };
        }
        Craft.sendActionRequest("POST", "inspire-frontend/base/resize-element-action", { data });
        new Craft.InspireFrontend.ResizeTaskProgress(this, taskId, function() {
          modal.$footerSpinner.addClass("hidden");
          modal.$closeBtn.removeClass("hidden");
          modal.$cancelBtn.addClass("hidden");
          modal.$saveBtn.addClass("hidden");
          setTimeout($.proxy(function() {
            if (Craft.elementIndex) {
              Craft.elementIndex.updateElements();
            }
          }), 1e3);
        });
      },
      generateId: function() {
        var text = "";
        var possible = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
        for (var i = 0; i < 5; i++) {
          text += possible.charAt(Math.floor(Math.random() * possible.length));
        }
        return text;
      }
    });
    Craft.InspireFrontend.ResizeTaskProgress = Garnish.Base.extend({
      modal: null,
      taskId: null,
      tasksById: null,
      completedTasks: null,
      updateTasksTimeout: null,
      completed: false,
      callback: null,
      init: function(modal, taskId, callback) {
        this.modal = modal;
        this.taskId = taskId;
        this.callback = callback;
        this.tasksById = {};
        this.completedTasks = [];
        setTimeout($.proxy(function() {
          Craft.sendActionRequest("POST", "queue/run");
        }, this), 500);
        setTimeout($.proxy(function() {
          this.updateTasks();
        }, this), 1500);
      },
      updateTasks: function() {
        this.completed = false;
        Craft.sendActionRequest("POST", "queue/get-job-info?dontExtendSession=1").then((response) => {
          console.log(response);
          this.showTaskInfo(response.data.jobs[0]);
        });
      },
      showTaskInfo: function(taskInfo) {
        var newTaskIds = [];
        if (taskInfo) {
          newTaskIds.push(taskInfo.id);
        } else {
          var progressTask = new Craft.InspireFrontend.ResizeTaskProgress.Task(this.modal, this.taskId, taskInfo);
          progressTask.complete();
          this.completed = true;
          this.callback();
        }
        for (var id in this.tasksById) {
          if (!Craft.inArray(id, newTaskIds)) {
            this.tasksById[id].complete();
            this.completedTasks.push(this.tasksById[id]);
            delete this.tasksById[id];
          }
        }
        if (taskInfo) {
          var anyTasksRunning = false, anyTasksFailed = false;
          if (!anyTasksRunning && (taskInfo.status == 1 || taskInfo.status == 2)) {
            anyTasksRunning = true;
          } else if (!anyTasksFailed && taskInfo.status == 4) {
            anyTasksFailed = true;
          }
          if (this.tasksById[taskInfo.id]) {
            this.tasksById[taskInfo.id].updateStatus(taskInfo);
          } else {
            this.tasksById[taskInfo.id] = new Craft.InspireFrontend.ResizeTaskProgress.Task(this.modal, this.taskId, taskInfo);
          }
          if (anyTasksRunning) {
            this.updateTasksTimeout = setTimeout($.proxy(this, "updateTasks"), 500);
          } else {
            this.completed = true;
            this.callback();
          }
        } else {
          this.completed = true;
          this.callback();
        }
      }
    });
    Craft.InspireFrontend.ResizeTaskProgress.Task = Garnish.Base.extend({
      taskId: null,
      modal: null,
      id: null,
      level: null,
      description: null,
      status: null,
      progress: null,
      $container: null,
      $statusContainer: null,
      $descriptionContainer: null,
      _progressBar: null,
      init: function(modal, taskId, info) {
        this.taskId = taskId;
        this.modal = modal;
        if (info) {
          this.id = info.id;
          this.level = info.level;
          this.description = info.description;
        }
        this.$container = $('<div class="task"/>').appendTo(this.modal.$body.find(".modal-main"));
        this.$statusContainer = $('<div class="task-status"/>').appendTo(this.$container);
        this.$container.data("task", this);
        if (info) {
          this.updateStatus(info);
        }
      },
      updateStatus: function(info) {
        if (this.status != info.status) {
          this.$statusContainer.empty();
          this.status = info.status;
          switch (this.status) {
            case 1: {
              this._progressBar = new Craft.ProgressBar(this.$statusContainer);
              this._progressBar.showProgressBar();
              break;
            }
            case 2: {
              this._progressBar = new Craft.ProgressBar(this.$statusContainer);
              this._progressBar.showProgressBar();
              break;
            }
            case 4: {
              $('<div class="error">' + Craft.t("inspire-frontend", 'Processing failed. <a class="go" href="' + Craft.getCpUrl("settings/plugins/inspire-frontend", { tab: "logs" }) + '">View logs</a>') + "</div>").appendTo(this.$statusContainer);
              break;
            }
          }
        }
        if (this.status == 1 || this.status == 2) {
          this._progressBar.setProgressPercentage(info.progress / 100);
        }
      },
      complete: function() {
        var data = { taskId: this.taskId };
        Craft.sendActionRequest("POST", "inspire-frontend/base/get-task-summary", { data }).then((response) => {
          var html = '<span class="success">' + Craft.t("inspire-frontend", "Success") + ": " + response.data.summary.success + ', </span><span class="skipped">' + Craft.t("inspire-frontend", "Skipped") + ": " + response.data.summary.skipped + ', </span><span class="error">' + Craft.t("inspire-frontend", "Error") + ": " + response.data.summary.error + ' </span><a class="go" href="' + Craft.getCpUrl("settings/plugins/inspire-frontend", { tab: "logs" }) + '">' + Craft.t("inspire-frontend", "View logs") + "</a>";
          this.$statusContainer.empty();
          $("<div>" + html + "</div>").appendTo(this.$statusContainer);
        });
      },
      destroy: function() {
        this.$container.remove();
        this.base();
      }
    });
  })(jQuery);
})();
//# sourceMappingURL=image-resizer.js.map
