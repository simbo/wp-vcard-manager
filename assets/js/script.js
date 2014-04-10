(function($) {
  'use strict';
  $(document).on({
    ready: function() {
      $('#_vcrdmngr_vcard').find('.remove-group-row').on({
        click: function(ev) {
          if (!confirm(VCRDMNGR_CONFIRMATION)) {
            ev.preventDefault();
            ev.stopPropagation();
          }
        }
      });
    }
  });
})(jQuery);

//# sourceMappingURL=script.js.map
