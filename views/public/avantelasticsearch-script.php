<script type="text/javascript">
jQuery(document).ready(function()
{
    var searchAllCheckbox = jQuery('#all');
    //var suggestUrl = '<?php echo url('/elasticsearch/suggest'); ?>';
    var suggestUrl = 'https://search-digitalarchive-6wn5q4bmsxnikvykh7xiswwo4q.us-east-2.es.amazonaws.com/mdi/_search';

    function searchAllIsChecked()
    {
        return searchAllCheckbox.is(":checked");
    }

    searchAllCheckbox.change(function (e)
    {
        Cookies.set('SEARCH-ALL', searchAllIsChecked(), {expires: 7});
    });

    // account for all=on

    function constructSuggestQuery(term)
    {
        var query =
            {
                "_source":["suggestions","item.title"],
                "suggest":{
                    "keywords-suggest":
                        {
                            "prefix":term,
                            "completion":
                                {
                                    "field":"suggestions",
                                    "skip_duplicates":false,
                                    "size":12,
                                    "fuzzy":
                                        {
                                            "fuzziness":0
                                        }
                                }
                        }
                }
            };

        query = JSON.stringify(query);
        console.log('QUERY: ' + query);
        return query;
    }

    jQuery( "#query" ).autocomplete(
    {
        source: function(request, response) {
            jQuery.ajax({
                url: suggestUrl,
                method: "POST",
                contentType: 'application/json; charset=UTF-8',
                crossDomain: true,
                dataType: "json",
                data: constructSuggestQuery(request.term),
                success: function(data)
                {
                    console.log('SUCCESS');
                    var suggestions = JSON.stringify(data);
                    console.log(suggestions);
                    var x = [{"label":"TTT", "value":"VVVV"}, {"label":"222", "value":"333"}];
                    response(x);
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