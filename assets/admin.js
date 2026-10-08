(function () {
  // The tab chosen stays in the address, so a reload opens the same tab
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t && t.name === 'tab' && t.type === 'radio' && t.checked && window.history && history.replaceState) {
      var url = new URL(window.location.href);
      url.searchParams.set('tab', t.value);
      history.replaceState(null, '', url.pathname + url.search + url.hash);
    }
  });

  // A country code becomes its flag where the platform draws flags (one glyph, narrower than two letters)
  function flagsDrawn() {
    try {
      var ctx = document.createElement('canvas').getContext('2d');
      if (!ctx) {
        return false;
      }
      ctx.font = '32px sans-serif';
      var one = ctx.measureText('🇩').width;
      var pair = ctx.measureText('🇩🇪').width;
      return one > 0 && pair > 0 && pair < one * 2 - 1;
    }
    catch (e) {
      return false;
    }
  }

  function ready() {
    var flags = document.querySelectorAll('.flag[data-cc]');
    if (flags.length && flagsDrawn()) {
      for (var i = 0; i < flags.length; i++) {
        var cc = flags[i].getAttribute('data-cc');
        if (/^[A-Z]{2}$/.test(cc)) {
          flags[i].textContent = String.fromCodePoint(0x1F1E6 + cc.charCodeAt(0) - 65, 0x1F1E6 + cc.charCodeAt(1) - 65);
          flags[i].className = 'flag glyph';
        }
      }
    }
    // The conversation dialog: focused when it opens, closed with Escape
    var dialog = document.getElementById('conversation');
    if (dialog) {
      dialog.focus();
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && dialog.getAttribute('data-close')) {
          window.location.href = dialog.getAttribute('data-close');
        }
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
  }
  else {
    ready();
  }
})();
