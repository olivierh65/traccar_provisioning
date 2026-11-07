(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.traccarProvisioning = {
    attach(context) {
      once('traccar-copy', '[data-traccar-copy]', context).forEach((button) => {
        button.addEventListener('click', async () => {
          const selector = button.getAttribute('data-traccar-copy');
          const target = selector ? document.querySelector(selector) : null;
          if (!target) {
            return;
          }

          const text = target.textContent.trim();
          const original = button.textContent;

          try {
            await navigator.clipboard.writeText(text);
            button.textContent = Drupal.t('Copié !');
          }
          catch (error) {
            // Fallback for older browsers or non-secure contexts.
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
              document.execCommand('copy');
              button.textContent = Drupal.t('Copié !');
            }
            catch (fallbackError) {
              button.textContent = Drupal.t('Copie impossible');
            }
            document.body.removeChild(textarea);
          }

          window.setTimeout(() => {
            button.textContent = original;
          }, 2000);
        });
      });
    }
  };
})(Drupal, once);
