/* search_table.js - Personnel database table UI */
(function ($, win) {
  "use strict";

  var selectedRow = null;

  function getCsrfTokenValue() {
    var token = "";
    if (typeof win.getCsrfToken === "function") {
      token = win.getCsrfToken();
      if (token) {
        return token;
      }
    }
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) {
      token = meta.getAttribute("content") || "";
      if (token) {
        return token;
      }
    }
    var input = document.querySelector('input[name="csrf_token"]');
    if (input && input.value) {
      return input.value;
    }
    var hiddenInput = document.getElementById("csrfTokenInput");
    return hiddenInput ? hiddenInput.value : "";
  }

  function showPageLoader(text) {
    var loader = document.getElementById("pageLoader");
    if (!loader) {
      return;
    }
    var label = loader.querySelector(".loading-text");
    if (label && text) {
      label.textContent = text;
    }
    loader.style.display = "flex";
  }

  function hidePageLoader() {
    var loader = document.getElementById("pageLoader");
    if (!loader) {
      return;
    }
    $(loader).fadeOut("slow", function () {
      $(this).removeClass("is-visible");
    });
  }

  function showTableToast(message, type) {
    if (typeof win.showToast === "function") {
      win.showToast(message, type === "error" ? "error" : "success");
      return;
    }

    var existing = document.querySelector(".toast.table-toast");
    if (existing) {
      existing.remove();
    }

    var toast = document.createElement("div");
    toast.className =
      "toast table-toast toast-" + (type === "error" ? "error" : "success");
    toast.setAttribute("role", "status");

    var content = document.createElement("div");
    content.className = "toast-content";

    var icon = document.createElement("i");
    icon.className =
      "fas " + (type === "error" ? "fa-exclamation-circle" : "fa-check-circle");

    var span = document.createElement("span");
    span.textContent = String(message);

    content.appendChild(icon);
    content.appendChild(span);
    toast.appendChild(content);

    toast.style.cssText = [
      "position:fixed",
      "top:20px",
      "right:20px",
      "z-index:10000",
      "padding:1rem 1.5rem",
      "border-radius:8px",
      "color:#fff",
      "font-weight:500",
      "box-shadow:0 4px 12px rgba(0,0,0,0.15)",
      "transform:translateX(100%)",
      "transition:transform 0.3s ease",
      "display:flex",
      "align-items:center",
      "gap:0.5rem",
      "min-width:300px",
      type === "error"
        ? "background:linear-gradient(145deg,#cc0000,#ff0000)"
        : "background:linear-gradient(145deg,#006400,#00ff00)",
    ].join(";");

    document.body.appendChild(toast);

    win.requestAnimationFrame(function () {
      toast.style.transform = "translateX(0)";
    });

    win.setTimeout(function () {
      toast.style.transform = "translateX(100%)";
      win.setTimeout(function () {
        toast.remove();
      }, 300);
    }, 3000);
  }

  function parseRecordId(value) {
    var id = parseInt(String(value), 10);
    return Number.isFinite(id) && id > 0 ? id : 0;
  }

  var pendingPurchaseId = 0;
  var purchaseConfirmModal = null;

  function getPurchaseConfirmModal() {
    var el = document.getElementById("purchaseConfirmModal");
    if (!el || !win.bootstrap || !win.bootstrap.Modal) {
      return null;
    }
    if (!purchaseConfirmModal) {
      purchaseConfirmModal = new win.bootstrap.Modal(el, {
        backdrop: false,
        keyboard: true,
        focus: true,
      });
    }
    return purchaseConfirmModal;
  }

  function showPurchaseConfirm(recordId, points) {
    var id = parseRecordId(recordId);
    if (!id) {
      showTableToast("Invalid record ID", "error");
      return;
    }

    pendingPurchaseId = id;

    var pointsEl = document.getElementById("purchaseConfirmPoints");
    if (pointsEl) {
      if (points !== undefined && points !== null && points !== "") {
        pointsEl.textContent = "Price: " + String(points) + " points";

        pointsEl.hidden = false;
      } else {
        pointsEl.textContent = "";
        pointsEl.hidden = true;
      }
    }

    var modal = getPurchaseConfirmModal();
    if (modal) {
      modal.show();
      return;
    }

    if (win.confirm("Do you want to purchase this record?")) {
      executePurchase(id);
    }
  }

  function executePurchase(recordId) {
    var id = parseRecordId(recordId);
    if (!id) {
      showTableToast("Invalid record ID", "error");
      return;
    }

    showPageLoader("Processing Purchase...");

    var csrfToken = getCsrfTokenValue();
    var headers = {
      "Content-Type": "application/json",
      Accept: "application/json",
    };
    if (csrfToken) {
      headers["X-CSRF-Token"] = csrfToken;
    }

    fetch("purchase_record", {
      method: "POST",
      credentials: "same-origin",
      headers: headers,
      body: JSON.stringify({
        record_id: id,
        action: "purchase",
        type: "search",
        csrf_token: csrfToken,
      }),
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (data) {
        if (data && data.success) {
          showTableToast(
            data.message || "Row purchased successfully!",
            "success",
          );
          win.setTimeout(function () {
            win.location.href = "my_purchases";
          }, 1500);
        } else {
          showTableToast(
            (data && data.message) || "Failed to purchase row",
            "error",
          );
        }
      })
      .catch(function () {
        showTableToast("An error occurred during purchase", "error");
      })
      .finally(function () {
        hidePageLoader();
      });
  }

  function purchaseRow(recordId, points) {
    showPurchaseConfirm(recordId, points);
  }

  function clearSelection() {
    $(".data-row").removeClass("selected").attr("aria-selected", "false");
    $("td").removeClass("selected-cell");
    selectedRow = null;
  }

  function selectRowElement(row) {
    clearSelection();
    row.addClass("selected").attr("aria-selected", "true");
    selectedRow = row;
    row.trigger("focus");
  }

  function navigateRows(direction) {
    if (!selectedRow || !selectedRow.length) {
      return;
    }
    var rows = $(".data-row");
    var index = rows.index(selectedRow);
    var nextIndex = direction === "up" ? index - 1 : index + 1;
    if (nextIndex >= 0 && nextIndex < rows.length) {
      selectRowElement(rows.eq(nextIndex));
    }
  }

  function navigateCells(direction) {
    if (!selectedRow || !selectedRow.length) {
      return;
    }
    var cells = selectedRow.find("td");
    var activeCell = selectedRow.find("td.selected-cell");
    var cellIndex = activeCell.length ? cells.index(activeCell) : -1;

    if (direction === "left" && cellIndex > 0) {
      cells.removeClass("selected-cell");
      cells.eq(cellIndex - 1).addClass("selected-cell");
      return;
    }
    if (direction === "right" && cellIndex < cells.length - 1) {
      cells.removeClass("selected-cell");
      cells.eq(cellIndex + 1).addClass("selected-cell");
    }
  }

  $(function () {
    // Hide loader immediately when DOM is ready for better UX
    hidePageLoader();

    $(win).on("load", function () {
      hidePageLoader();
    });

    win.setTimeout(function () {
      hidePageLoader();
    }, 1000);

    $(".compact-progress-fill[data-width]").each(function () {
      var width = parseFloat(String($(this).attr("data-width")));
      if (Number.isFinite(width)) {
        $(this).css("width", Math.max(0, Math.min(100, width)) + "%");
      }
    });

    $(document).on("submit", "form", function () {
      if ($(this).find('input[name="search"]').length) {
        showPageLoader("Searching Database...");
      }
    });

    $(document).on("submit", ".pagination form", function () {
      showPageLoader("Loading Page...");
    });

    $(document).on("click", ".data-row", function (event) {
      if ($(event.target).closest(".locked-cell, .purchase-btn").length) {
        return;
      }
      selectRowElement($(this));
    });

    $(document).on("click", ".data-row td", function (event) {
      if ($(event.target).closest(".locked-cell, .purchase-btn").length) {
        return;
      }
      event.stopPropagation();
      var row = $(this).closest(".data-row");
      selectRowElement(row);
      row.find("td").removeClass("selected-cell");
      $(this).addClass("selected-cell");
    });

    $(document).on("keydown", function (event) {
      if (!selectedRow || !selectedRow.length) {
        return;
      }
      switch (event.key) {
        case "ArrowUp":
          event.preventDefault();
          navigateRows("up");
          break;
        case "ArrowDown":
          event.preventDefault();
          navigateRows("down");
          break;
        case "ArrowLeft":
          event.preventDefault();
          navigateCells("left");
          break;
        case "ArrowRight":
          event.preventDefault();
          navigateCells("right");
          break;
        default:
          break;
      }
    });

    $(document).on("click", ".sortable", function () {
      var column = $(this).data("column");
      if (!column) {
        return;
      }

      var currentSort = "none";
      if ($(this).hasClass("sorted-asc")) {
        currentSort = "asc";
      } else if ($(this).hasClass("sorted-desc")) {
        currentSort = "desc";
      }

      $(".sortable").removeClass("sorted-asc sorted-desc");

      var newSort = currentSort === "asc" ? "desc" : "asc";
      $(this).addClass(newSort === "asc" ? "sorted-asc" : "sorted-desc");

      var url = new URL(win.location.href);
      url.searchParams.set("sort", String(column));
      url.searchParams.set("order", newSort);
      win.location.href = url.toString();
    });

    $(document).on("click", ".locked-cell", function (event) {
      event.stopPropagation();
      var tooltip = $(this).find(".unlock-tooltip");
      $(".unlock-tooltip").not(tooltip).removeClass("show");
      tooltip.toggleClass("show");
    });

    $(document).on("click", function (event) {
      if (!$(event.target).closest(".locked-cell").length) {
        $(".unlock-tooltip").removeClass("show");
      }
    });

    $(document).on("click", ".purchase-btn", function (event) {
      event.preventDefault();
      event.stopPropagation();

      var recordId = $(this).attr("data-id") || $(this).attr("data-record-id");
      var points = $(this).attr("data-points");
      purchaseRow(recordId, points);
    });

    $(document).on("click", "#purchaseConfirmYes", function () {
      var id = pendingPurchaseId;
      pendingPurchaseId = 0;
      var modal = getPurchaseConfirmModal();
      if (modal) {
        var active = document.activeElement;
        if (active && typeof active.blur === "function") {
          active.blur();
        }
        modal.hide();
      }
      if (id) {
        executePurchase(id);
      }
    });

    $("#purchaseConfirmModal").on("hidden.bs.modal", function () {
      pendingPurchaseId = 0;
      var active = document.activeElement;
      if (active && typeof active.blur === "function") {
        active.blur();
      }
    });

    // Column Width Controls - Advanced
    var COLUMN_WIDTH_STORAGE_KEY = "table_column_width";
    var DEFAULT_COLUMN_WIDTH = 100;
    var MIN_COLUMN_WIDTH = 50;
    var MAX_COLUMN_WIDTH = 300;
    var COLUMN_WIDTH_STEP = 10;

    var currentColumnWidth =
      parseInt(localStorage.getItem(COLUMN_WIDTH_STORAGE_KEY)) ||
      DEFAULT_COLUMN_WIDTH;

    // Ensure width is within bounds
    currentColumnWidth = Math.max(
      MIN_COLUMN_WIDTH,
      Math.min(MAX_COLUMN_WIDTH, currentColumnWidth),
    );

    function updateColumnWidthLabel() {
      var label = document.getElementById("columnWidthLabel");
      if (label) {
        label.textContent = currentColumnWidth + "px";
      }
    }

    function updateButtonStates() {
      $("#decreaseColumnWidth").prop(
        "disabled",
        currentColumnWidth <= MIN_COLUMN_WIDTH,
      );
      $("#increaseColumnWidth").prop(
        "disabled",
        currentColumnWidth >= MAX_COLUMN_WIDTH,
      );
    }

    function saveColumnWidth() {
      localStorage.setItem(
        COLUMN_WIDTH_STORAGE_KEY,
        currentColumnWidth.toString(),
      );
    }

    function updateColumnWidth() {
      var table = document.querySelector(".table-bordered");
      if (table) {
        table.style.tableLayout = "fixed";
        var cells = table.querySelectorAll("th, td");
        cells.forEach(function (cell) {
          cell.style.minWidth = currentColumnWidth + "px";
          cell.style.maxWidth = currentColumnWidth + "px";
          cell.style.width = currentColumnWidth + "px";
        });
      }
      updateColumnWidthLabel();
      updateButtonStates();
      saveColumnWidth();
    }

    // Initialize column width on page load
    updateColumnWidth();

    $("#increaseColumnWidth").on("click", function () {
      if (currentColumnWidth < MAX_COLUMN_WIDTH) {
        currentColumnWidth += COLUMN_WIDTH_STEP;
        updateColumnWidth();
      }
    });

    $("#decreaseColumnWidth").on("click", function () {
      if (currentColumnWidth > MIN_COLUMN_WIDTH) {
        currentColumnWidth -= COLUMN_WIDTH_STEP;
        updateColumnWidth();
      }
    });

    $("#resetColumnWidth").on("click", function () {
      currentColumnWidth = DEFAULT_COLUMN_WIDTH;
      updateColumnWidth();
    });

    // Keyboard shortcuts
    $(document).on("keydown", function (event) {
      // Only if not typing in an input
      if ($(event.target).is("input, textarea, select")) {
        return;
      }

      // Ctrl/Cmd + Plus to increase width
      if (
        ((event.ctrlKey || event.metaKey) && event.key === "+") ||
        event.key === "="
      ) {
        event.preventDefault();
        if (currentColumnWidth < MAX_COLUMN_WIDTH) {
          currentColumnWidth += COLUMN_WIDTH_STEP;
          updateColumnWidth();
        }
      }

      // Ctrl/Cmd + Minus to decrease width
      if (
        ((event.ctrlKey || event.metaKey) && event.key === "-") ||
        event.key === "_"
      ) {
        event.preventDefault();
        if (currentColumnWidth > MIN_COLUMN_WIDTH) {
          currentColumnWidth -= COLUMN_WIDTH_STEP;
          updateColumnWidth();
        }
      }

      // Ctrl/Cmd + 0 to reset width
      if ((event.ctrlKey || event.metaKey) && event.key === "0") {
        event.preventDefault();
        currentColumnWidth = DEFAULT_COLUMN_WIDTH;
        updateColumnWidth();
      }
    });
  });
})(jQuery, globalThis);
