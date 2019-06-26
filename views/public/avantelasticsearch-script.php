<script type="text/javascript">

var FIND_URL = '<?php echo $findUrl; ?>';
var SUGGEST_QUERY = '<?php echo $query; ?>';
var SUGGEST_URL = '<?php echo $suggestUrl; ?>';

jQuery(document).ready(function()
{
    jQuery( "#query" ).autocomplete(
    {
        source: function(request, response)
        {
            jQuery.ajax({
                url: SUGGEST_URL,
                method: "POST",
                contentType: 'application/json; charset=UTF-8',
                crossDomain: true,
                dataType: "json",
                data: constructSuggestQuery(request.term),
                success: function(data)
                {
                    response(constructSuggestions(data));
                },
                error: function(jqXHR, textStatus, errorThrown)
                {
                    var jso = jQuery.parseJSON(jqXHR.responseText);
                    console.log('ERROR: ' + jqXHR.status + ' : ' + errorThrown + ' : ' + jso.error);
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