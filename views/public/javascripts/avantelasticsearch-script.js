function constructSuggestQuery(term)
{
    return SUGGEST_QUERY.replace('%s', term);
}

function constructSuggestions(data)
{
    var suggestions = [];
    var titles = [];
    var options = data["suggest"]["keywords-suggest"][0]["options"];
    var count = options.length;
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
            var value = FIND_URL + encodeURI(title);
            suggestions.push({"label": title, "value": value});
            //console.log('ADD:' + title + ' : ' + value);
        }
    }
    return suggestions;
}
