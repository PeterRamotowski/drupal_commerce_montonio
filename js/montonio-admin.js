/**
 * @file
 * Admin form enhancements for Montonio payment gateway configuration.
 */

(function (Drupal) {
  /**
   * Updates default payment method options based on enabled methods.
   */
  Drupal.behaviors.montonioAdminForm = {
    attach(context, settings) {
      const enabledMethods = context.querySelectorAll(
        '[name*="[enabled_payment_methods]"]'
      );
      const defaultMethod = context.querySelector(
        '[name*="[default_payment_method]"]'
      );

      if (enabledMethods.length && defaultMethod) {
        const allOptions = {};
        enabledMethods.forEach(function (checkbox) {
          const label = checkbox.parentElement.querySelector('label');
          if (label) {
            allOptions[checkbox.value] = label.textContent;
          }
        });

        const updateDefaultMethodOptions = () => {
          const selectedValue = defaultMethod.value;

          const enabledValues = [];
          enabledMethods.forEach(function (checkbox) {
            if (checkbox.checked) {
              enabledValues.push(checkbox.value);
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

        if (!enabledMethods[0].hasAttribute('data-montonio-processed')) {
          enabledMethods.forEach(function (checkbox) {
            checkbox.setAttribute('data-montonio-processed', 'true');
            checkbox.addEventListener('change', updateDefaultMethodOptions);
          });
        }
      }
    },
  };
})(Drupal);