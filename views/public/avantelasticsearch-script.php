<script type="text/javascript">
jQuery(document).ready(function()
{
    var activeIndex = '<?php echo $activeIndex; ?>';
    var localIndex = '<?php echo $localIndex; ?>';
    var sharedIndex = '<?php echo $sharedIndex; ?>';
    var suggestUrl = '<?php echo $suggestUrl; ?>';
    var findUrl = '<?php echo $findUrl; ?>';
    var searchAllCheckbox = jQuery('#all');

    function searchAllIsChecked()
    {
        return searchAllCheckbox.is(":checked");
    }

    searchAllCheckbox.change(function (e)
    {
        var checked = searchAllIsChecked();
        activeIndex = checked ? sharedIndex : localIndex;
        Cookies.set('SEARCH-ALL', checked, {expires: 7});
    });

    function constructSuggestQuery(term)
    {
        var query =
            {
                "_source":["item.title"],
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
        return query;
    }

    function constructSuggestUrl()
    {
        var url = 'https://search-digitalarchive-6wn5q4bmsxnikvykh7xiswwo4q.us-east-2.es.amazonaws.com/';
        url += activeIndex + '/_search';
        return url;
    }

    jQuery( "#query" ).autocomplete(
    {
        source: function(request, response) {
            jQuery.ajax({
                url: constructSuggestUrl(),
                method: "POST",
                contentType: 'application/json; charset=UTF-8',
                crossDomain: true,
                dataType: "json",
                data: constructSuggestQuery(request.term),
                success: function(data)
                {
                    var suggestions = [];
                    var titles = [];
                    var options = data["suggest"]["keywords-suggest"][0]["options"];
                    var count = options.length;
                    if (count === 0)
                    {
                        console.log('FOUND: ' + count);
                    }
                    for (i = 0; i < count; i++)
                    {
                        // Get the titles for this suggestion.
                        var option = options[i]["_source"]["item"]["title"];

                        // Use just the first title.
                        var title = option.split('\n')[0];

                        if ((titles.indexOf(title) === -1))
                        {
                            // The title is not already in the array so add it.
                            titles.push(title);
                            var value = findUrl + encodeURI(title);
                            if (activeIndex === sharedIndex)
                                value += '&all=on';
                            suggestions.push({"label":title, "value":value});
                            //console.log('ADD:' + title + ' : ' + value);
                        }
                    }

                    response(suggestions);
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