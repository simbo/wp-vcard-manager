(function($) {
  'use strict';
  $(document).on({
    ready: function() {
      $('#_vcrdmngr_vcard').find('.remove-group-row').on({
        click: function(ev) {
          if (!confirm(vcrdmngr_i18n.confirm_remove_element)) {
            ev.preventDefault();
            ev.stopPropagation();
          }
        }
      });
    }
  });
})(jQuery);

//# sourceMappingURL=script.js.map
