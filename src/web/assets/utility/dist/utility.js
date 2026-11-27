/* AI Alt Text utility: shows the numbers of an alt text bar's segment in a tooltip. It's one element that follows the
   segment, as a tooltip inside the table would be clipped by its scroll area. */
(function () {
  var tooltip = null;
  var anchorBar = null;
  var hideTimer = null;

  function cancelHide() {
    clearTimeout(hideTimer);
    hideTimer = null;
  }

  function hideNow() {
    cancelHide();

    if (tooltip) {
      tooltip.classList.remove('aat-tooltip-visible', 'aat-tooltip-gliding');
    }
  }

  // Hiding waits a moment, so crossing the gap between two segments, or tabbing from one to the next, carries on
  // from the same tooltip rather than hiding it and showing it again
  function hide() {
    cancelHide();
    hideTimer = setTimeout(hideNow, 60);
  }

  function show(segment) {
    var content = segment.querySelector('.aat-tip');

    if (!content) {
      return;
    }

    cancelHide();

    if (!tooltip) {
      tooltip = document.createElement('div');
      tooltip.className = 'aat-tooltip';
      document.body.appendChild(tooltip);
    }

    // Moving to another segment of the same bar glides across to it, anywhere else it's revealed again
    var bar = segment.parentNode;
    var glide = tooltip.classList.contains('aat-tooltip-visible') && bar === anchorBar;

    tooltip.innerHTML = content.innerHTML;

    var rect = segment.getBoundingClientRect();
    var size = tooltip.getBoundingClientRect();
    var left = rect.left + rect.width / 2 - size.width / 2;
    var top = rect.top - size.height - 8;
    var placement = 'top';

    // Keep it on screen, and below the segment when there's no room above it
    left = Math.max(8, Math.min(left, window.innerWidth - size.width - 8));

    if (top < 8) {
      top = rect.bottom + 8;
      placement = 'bottom';
    }

    if (!glide) {
      // Back to hidden at once, without a transition, so the reveal starts from the start
      tooltip.classList.add('aat-tooltip-instant');
      tooltip.classList.remove('aat-tooltip-visible', 'aat-tooltip-gliding');
    }

    tooltip.dataset.placement = placement;
    // The caret stays on the segment's middle, but inside the tooltip's rounded corners
    tooltip.style.setProperty('--aat-caret', Math.max(12, Math.min(rect.left + rect.width / 2 - left, size.width - 12)) + 'px');
    tooltip.style.left = left + 'px';
    tooltip.style.top = top + 'px';

    if (glide) {
      tooltip.classList.add('aat-tooltip-gliding');
    } else {
      void tooltip.offsetWidth;
      tooltip.classList.remove('aat-tooltip-instant');
      tooltip.classList.add('aat-tooltip-visible');
    }

    anchorBar = bar;
  }

  function segmentOf(event) {
    return event.target.closest ? event.target.closest('.aat-seg') : null;
  }

  document.addEventListener('mouseover', function (event) {
    var segment = segmentOf(event);

    if (segment) {
      show(segment);
    } else {
      hide();
    }
  });

  document.addEventListener('focusin', function (event) {
    var segment = segmentOf(event);

    if (segment) {
      show(segment);
    }
  });

  document.addEventListener('focusout', hide);
  document.addEventListener('scroll', hideNow, true);
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      hideNow();
    }
  });
})();
