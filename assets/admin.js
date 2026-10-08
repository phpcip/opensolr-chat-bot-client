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
})();
