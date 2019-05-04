<?php
class AvantElasticsearchSuggest extends AvantElasticsearch
{
    public function createSuggestionsDataForTitle($titleFieldTexts, $itemTypeIsReference, $itemTitleIsPerson)
    {
        $suggestionsData = array();
        $titleIsPerson = $itemTypeIsReference && $itemTitleIsPerson;

        $suggestionsData['input'] = $this->createSuggestionInputsForTitle($titleFieldTexts, $titleIsPerson);

        if ($itemTypeIsReference)
        {
            // Give extra weight to references and even more weight to references for people
            // so that these items appear higher in the suggestions list.
            $suggestionsData['weight'] = $itemTitleIsPerson ? 5 : 2;
        }

        return $suggestionsData;
    }

    protected function createSuggestionInputsForTitle($fieldTexts, $titleIsPerson)
    {
        // This method creates a set of prefix inputs for a title that will allow the Elasticsearch completion
        // mechanism to match what a user is typing into the search box with words in the title. Each input
        // contains the title text with zero or more leading words removed as shown below.
        //
        //   The Quick Brown Fox Jumped
        //   Quick Brown Fox Jumped
        //   Brown Fox Jumped
        //   Fox Jumped
        //   Jumped
        //
        // The inputs above allow the user to type any of the following to match the title: 'the', 'quick', 'brown',
        // 'fox', 'jumped'. When a match occurs, the entire title is suggested to the user, not the matching input.
        // As such, this logic is not generating suggestions, but rather allowing the suggestion feature to work well.

        $allInputs = array();

        foreach ($fieldTexts as $fieldText)
        {
            // Strip away punctuation leaving only letters and numbers. For people titles, get rid of the numbers
            // too since they are probably birth and death dates that won't be useful for an input.
            $text = $this->stripPunctuation($fieldText['text'], $titleIsPerson);

            // Create an array of the stripped title's words.
            $parts = explode(' ', $text);
            $words = array();
            foreach ($parts as $part)
            {
                $words[] = $part;
            }
            $wordsCount = count($words);

            // Create the inputs for the title.
            if ($titleIsPerson & $wordsCount >= 3)
            {
                $inputs = $this->createSuggestionInputsForPersonTitle($words, $wordsCount);
            }
            else
            {
                $inputs = $this->createSuggestionInputsForAnyTitle($words, $wordsCount);
            }

            $allInputs = array_merge($allInputs, $inputs);
        }

        // Remove any duplicate inputs. Duplicates can occur when an item has multiple titles with shared phrases.
        $allInputs = array_unique($allInputs);
        return $allInputs;
    }

    protected function createSuggestionInputsForAnyTitle($words, $wordsCount, $ignoreAfter = '')
    {
        $inputs = array();

        if (!empty($ignoreAfter))
        {
            for ($i = 0; $i < $wordsCount; $i++)
            {
                if (strtolower($words[$i]) == $ignoreAfter)
                {
                    // The current word and all after it can be ignored.
                    $wordsCount = $i;
                    break;
                }
            }
        }

        // Use all the words in the title to construct the inputs, but limit the length of any input to just the
        // number of words a person might type while using autocomplete.
        $maxInputWords = min($wordsCount, 5);
        for ($i = 0; $i < $wordsCount; $i++)
        {
            $input = '';
            $inputWordsCount = 0;
            for ($j = $i; $j < $wordsCount; $j++)
            {
                $input .= $words[$j] . ' ';
                $inputWordsCount++;
                if ($inputWordsCount == $maxInputWords)
                {
                    break;
                }
            }
            $inputs[] = trim($input);
        }

        return $inputs;
    }

    protected function createSuggestionInputsForPersonTitle($words, $wordsCount)
    {
        // Emit a set of inputs for a person title that will be effective with the most common search which
        // is first name followed by last name. That is, someone is more likely to search for just 'mary mitchel'
        // without including her middle name(s). This logic account for the fact that installations can use either
        // of these two forms of names:
        //  1. Starts with last name:  <last> - <first> <middle> <last> aka <other names>
        //  2. Starts with first name: <first> <middle> <last> aka <other names>
        //
        // For form 1, if the original title is "McCaslin - Mary Louise McCaslin Mitchell aka Mae", emit:
        //   McCaslin Mary Louise McCaslin Mitchell
        //   Mary Louise McCaslin Mitchell
        //   Mary McCaslin Mitchell
        //   Mary Mitchell
        //
        // Note that aka (Also Known As) names are ignored since they appear at the end title and are not likely to
        // be effective as a prefix. Once 'aka' is seen in the title, the rest of the text is skipped.

        // Use the generic inputs creator to get the inputs for the title, ignoring anything after 'aka'.
        $inputs = $this->createSuggestionInputsForAnyTitle($words, $wordsCount, 'aka');

        // Determine whether the title starts with the first name or the last name.
        $startsWithFirstName = true;
        for ($index = 1; $index < $wordsCount; $index++)
        {
            if ($words[$index] == $words[0])
            {
                // This word appears twice. Assume it's the last name;
                $startsWithFirstName = false;
                break;
            }
        }

        // Get the first name.
        $firstName = $startsWithFirstName ? $words[0] : $words[1];
        $startsWithLastName = !$startsWithFirstName;

        // Prepend the first name onto the inputs to create variations of first name followed by the other names in
        // the title as shown in the example above. While this isn't perfect, it should be effective for finding people.
        $personInputs = array();
        foreach ($inputs as $index => $input)
        {
            if ($index == 0 || $startsWithLastName && $index == 1)
            {
                // Index 0 is the full title. For form 1, index 1 already starts with the first name.
                $personInputs[] = $input;
                continue;
            }
            if (($startsWithFirstName && $index == 1) || ($startsWithLastName && $index == 2))
            {
                // Skip this because prepending the first name would create a duplicate of the previous input.
                continue;
            }

            // Add the first name to the beginning of the input.
            $personInputs[] = "$firstName $input";
        }

        return $personInputs;
    }

    public function stripPunctuation($rawText, $stripNumbers = false)
    {
        // Remove apostrophes so that "Ann's" becomes "Anns".
        $text = str_replace("'", "", $rawText);

        // Replace any character that's not a letter, space, or digit (if stripping numbers) with a space.
        $pattern = $stripNumbers ? "/[^a-zA-Z ]+/" : "/[^a-zA-Z 0-9]+/";
        $text = preg_replace($pattern, " ", $text);

        // Remove occurrences of two or more adjacent spaces so there's only one space between each word.
        $text = trim(preg_replace('/\s\s+/', ' ', str_replace("\n", " ", $text)));

        return $text;
    }
}