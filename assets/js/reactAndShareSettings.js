(function ($, Drupal) {
  'use strict';

  var loadReactAndShare = function () {
    if (Drupal.eu_cookie_compliance.hasAgreed('statistics')) {
      window.rnsData = {
        apiKey: drupalSettings.reactAndShareApiKey
      };

      if (drupalSettings.siteName !== undefined) {
        window.rnsData.categories = [drupalSettings.siteName]
      }

      var scriptElement = document.createElement('script');
      scriptElement.src = 'https://cdn.reactandshare.com/plugin/rns.js';

      document.body.appendChild(scriptElement);

      $('.js-react-and-share__container .rns').show();
    }
    else {
      $('.js-react-and-share__container .js-react-and-share-cookie-compliance').show();
    }

    // Only load once.
    loadReactAndShare = function () {};
  };

  // Run after choosing cookie settings.
  $(document).on('eu_cookie_compliance.changeStatus', loadReactAndShare);

  // Run after page is ready.
  $(document).ready(function () {
    loadReactAndShare();
  });
})(jQuery, Drupal);
