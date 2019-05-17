<script type="text/javascript">
jQuery(document).ready(function()
{
    var searchAllCheckbox = jQuery('#all');
    var suggestUrl = '<?php echo url('/elasticsearch/suggest'); ?>';

    function searchAllIsChecked()
    {
        return searchAllCheckbox.is(":checked");
    }

    searchAllCheckbox.change(function (e)
    {
        Cookies.set('SEARCH-ALL', searchAllIsChecked(), {expires: 7});
    });

    jQuery( "#query" ).autocomplete(
    {
        source: function(request, response) {
            jQuery.ajax({
                url: suggestUrl,
                dataType: "json",
                data: {
                    term : request.term,
                    all : searchAllIsChecked() ? 'on' : 'off'
                },
                success: function(data) {
                    response(data);
                }
            });
        },
        select: function (e, ui)
        {
            var query = ui.item.value;
            window.location.href = ui.item.value;
            return false;
        },
        focus: function (event, ui)
        {
            // Prevent the text of the item being hovered over from appearing in the search box.
            return false;
        }
    });
});
</script>