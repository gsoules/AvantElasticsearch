function constructSuggestQuery(term)
{
    // Remove quotes so that they don't invalidate the json code that the term gets inserted into.
    var cleanTerm = term.replace(/['"]+/g, '');
    return SUGGEST_QUERY.replace('%s', cleanTerm);
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
        }
    }
    return suggestions;
}
