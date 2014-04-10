# begin jquery anonymous wrapper
(($) ->
    'use strict'

    # on domready
    $(document).on
        ready: ->

            $('#_vcrdmngr_vcard').find('.remove-group-row').on
                click: (ev) ->
                    if !confirm vcrdmngr_i18n.confirm_remove_element
                        ev.preventDefault()
                        ev.stopPropagation()
                    return

            # end of domready
            return

    # end jquery anonymous wrapper
    return

)(jQuery)
