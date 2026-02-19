(function () {
  function closestOfferContainer(el) {
    return el.closest('.wbcom-suo-offer, .wbcom-suo-cart-bump, .wbcom-suo-cart-popup');
  }

  function dismissOffer(offerId, element) {
    if (!offerId || !window.wbcomSuo || !window.fetch) {
      const box = closestOfferContainer(element);
      if (box) {
        box.style.display = 'none';
      }
      return;
    }

    const formData = new FormData();
    formData.append('action', 'wbcom_suo_dismiss_offer');
    formData.append('nonce', window.wbcomSuo.nonce || '');
    formData.append('offer_id', String(offerId));

    fetch(window.wbcomSuo.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    }).finally(function () {
      const box = closestOfferContainer(element);
      if (box) {
        box.style.display = 'none';
      }
    });
  }

  function syncCheckoutBumpSelection(selected) {
    document.cookie = 'wbcom_suo_checkout_bump=' + (selected ? '1' : '0') + '; path=/; max-age=3600; SameSite=Lax';

    if (!window.wbcomSuo || !window.fetch) {
      return;
    }

    const formData = new FormData();
    formData.append('action', 'wbcom_suo_toggle_checkout_bump');
    formData.append('nonce', window.wbcomSuo.nonce || '');
    formData.append('selected', selected ? '1' : '0');

    fetch(window.wbcomSuo.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    }).catch(function () {
      return null;
    });
  }

  function hydrateCountdowns() {
    var nodes = document.querySelectorAll('.wbcom-suo-countdown[data-countdown-end]');
    if (!nodes.length) {
      return;
    }

    function tick() {
      var now = Math.floor(Date.now() / 1000);
      nodes.forEach(function (node) {
        var end = parseInt(node.getAttribute('data-countdown-end') || '0', 10);
        if (!end) {
          return;
        }
        var diff = end - now;
        if (diff <= 0) {
          node.textContent = 'Offer expired';
          return;
        }

        var h = Math.floor(diff / 3600);
        var m = Math.floor((diff % 3600) / 60);
        var s = diff % 60;
        node.textContent = 'Offer expires in ' + String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
      });
    }

    tick();
    window.setInterval(tick, 1000);
  }

  function initExitIntentModal() {
    var modal = document.querySelector('[data-wbcom-suo-exit-intent="1"]');
    if (!modal) {
      return;
    }

    var offerId = parseInt(modal.getAttribute('data-offer-id') || '0', 10);
    var delaySeconds = parseInt(modal.getAttribute('data-delay-seconds') || '0', 10);
    var dismissKey = offerId > 0 ? 'wbcom_suo_exit_dismiss_' + String(offerId) : 'wbcom_suo_exit_dismiss';
    var alreadyDismissed = false;
    try {
      alreadyDismissed = window.sessionStorage && window.sessionStorage.getItem(dismissKey) === '1';
    } catch (e) {
      alreadyDismissed = false;
    }
    if (alreadyDismissed) {
      return;
    }

    var shown = false;
    var close = function () {
      modal.hidden = true;
      try {
        if (window.sessionStorage) {
          window.sessionStorage.setItem(dismissKey, '1');
        }
      } catch (e) {
        return null;
      }
    };
    var open = function () {
      if (shown) {
        return;
      }
      shown = true;
      if (delaySeconds > 0) {
        window.setTimeout(function () {
          modal.hidden = false;
        }, delaySeconds * 1000);
      } else {
        modal.hidden = false;
      }
    };

    document.addEventListener('mouseout', function (event) {
      if (shown) {
        return;
      }
      var to = event.relatedTarget || event.toElement;
      if (!to && event.clientY <= 5) {
        open();
      }
    });

    modal.addEventListener('click', function (event) {
      if (event.target === modal || event.target.closest('.wbcom-suo-exit-close')) {
        event.preventDefault();
        close();
      }
    });
  }

  document.addEventListener('click', function (event) {
    const dismiss = event.target.closest('.wbcom-suo-dismiss');
    if (dismiss) {
      event.preventDefault();
      dismissOffer(parseInt(dismiss.getAttribute('data-offer-id'), 10), dismiss);
      return;
    }

    const trigger = event.target.closest('.wbcom-suo-popup-trigger');
    if (trigger) {
      event.preventDefault();
      const offerId = trigger.getAttribute('data-offer-id');
      const popup = document.querySelector('.wbcom-suo-cart-popup[data-offer-id="' + offerId + '"]');
      if (popup) {
        popup.hidden = false;
      }
      return;
    }

    const close = event.target.closest('.wbcom-suo-popup-close');
    if (close) {
      event.preventDefault();
      const popup = close.closest('.wbcom-suo-cart-popup');
      if (popup) {
        popup.hidden = true;
      }
    }
  });

  document.addEventListener('change', function (event) {
    const field = event.target;
    if (!field || !field.matches('input[name="wbcom_suo_checkout_bump"]')) {
      return;
    }

    syncCheckoutBumpSelection(Boolean(field.checked));
  });

  document.addEventListener('DOMContentLoaded', function () {
    hydrateCountdowns();
    initExitIntentModal();

    const field = document.querySelector('input[name="wbcom_suo_checkout_bump"]');
    if (!field) {
      return;
    }
    syncCheckoutBumpSelection(Boolean(field.checked));
  });
})();
