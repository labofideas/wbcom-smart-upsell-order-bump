(function () {
  var styleField = document.getElementById('wbcom-suo-template-style');
  var preview = document.getElementById('wbcom-suo-template-preview');

  if (!styleField || !preview) {
    return;
  }

  function applyPreviewStyle(value) {
    preview.className = preview.className
      .replace(/\bwbcom-suo-template-(minimal|modern|highlight|banner)\b/g, '')
      .trim();
    preview.className = (preview.className + ' wbcom-suo-template-' + value).trim();
  }

  applyPreviewStyle(styleField.value || 'minimal');
  styleField.addEventListener('change', function () {
    applyPreviewStyle(styleField.value || 'minimal');
  });
})();
