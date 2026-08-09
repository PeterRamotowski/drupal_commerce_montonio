/**
 * @file
 * Admin form enhancements for Montonio payment gateway configuration.
 */

(function (Drupal, once) {
  /**
   * Updates default payment method options based on enabled methods.
   */
  Drupal.behaviors.montonioAdminForm = {
    attach(context, settings) {
      once(
        'montonio-admin',
        '[name*="[enabled_payment_methods]"]',
        context,
      ).forEach(function (checkbox) {
        const defaultMethod = document.querySelector(
          '[name*="[default_payment_method]"]',
        );

        if (!defaultMethod) {
          return;
        }

        const enabledMethods = document.querySelectorAll(
          '[name*="[enabled_payment_methods]"]',
        );

        const allOptions = {};
        enabledMethods.forEach(function (cb) {
          const label = cb.parentElement.querySelector('label');
          if (label) {
            allOptions[cb.value] = label.textContent;
          }
        });

        const updateDefaultMethodOptions = () => {
          const selectedValue = defaultMethod.value;

          const enabledValues = [];
          enabledMethods.forEach(function (cb) {
            if (cb.checked) {
              enabledValues.push(cb.value);
            }
          });

          defaultMethod.innerHTML = '';

          if (enabledValues.length > 0) {
            enabledValues.forEach(function (value) {
              if (allOptions[value]) {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = allOptions[value];
                defaultMethod.appendChild(option);
              }
            });
            if (enabledValues.includes(selectedValue)) {
              defaultMethod.value = selectedValue;
            }
          } else {
            Object.keys(allOptions).forEach(function (value) {
              const option = document.createElement('option');
              option.value = value;
              option.textContent = allOptions[value];
              defaultMethod.appendChild(option);
            });
          }
        };

        checkbox.addEventListener('change', updateDefaultMethodOptions);
      });
    },
  };
})(Drupal, once);
