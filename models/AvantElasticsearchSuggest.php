<?php
class AvantElasticsearchSuggest extends AvantElasticsearch
{
    public function CreateSuggestionsDataForTitle($titleFieldTexts, $isReferenceType, $isSubjectPeople)
    {
        $suggestionsData = array();
        $isPersonReference = $isReferenceType && $isSubjectPeople;

        $suggestionsData['input'] = $this->createSuggestionInputsForTitle($titleFieldTexts, $isPersonReference);

        if ($isReferenceType)
        {
            // Give extra weight to references and even more weight to references for people
            // so that these items appear higher in the suggestions list.
            $suggestionsData['weight'] = $isSubjectPeople ? 5 : 2;
        }

        return $suggestionsData;
    }

    protected function createSuggestionInputsForTitle($fieldTexts, $isPersonReference)
    {
        $allSuggestions = array();

        foreach ($fieldTexts as $fieldText)
        {
            // Strip away punctuation leaving only letters and numbers. For people, get rid of the numbers
            // too since they are probably birth and death dates that won't be useful for suggestions.
            $text = $this->stripPunctuation($fieldText['text'], $isPersonReference);

            // Create an array of the stripped title's words.
            $parts = explode(' ', $text);
            $words = array();
            foreach ($parts as $part)
            {
                $words[] = $part;
            }

            // Limit the number of words in the suggestions to just enough to be really useful.
            $wordsCount = count($words);

            // Create the suggestions.
            if ($isPersonReference & $wordsCount >= 3)
            {
                $suggestions = $this->createSuggestionsForPersonTitle($words, $wordsCount);
            }
            else
            {
                $suggestions = $this->createSuggestionsForAnyTitle($words, $wordsCount);
            }
            $allSuggestions = array_merge($allSuggestions, $suggestions);
        }

        $allSuggestions = array_unique($allSuggestions);
        return $allSuggestions;
    }

    protected function createSuggestionsForAnyTitle($words, $wordsCount, $ignoreAfter = '')
    {
        $suggestions = array();

        // Emit a set of suggestions for the title that will allow a prefix search to be effective. For example:
        //  The Quick Brown Fox Jumped
        //  Quick Brown Fox Jumped
        //  Brown Fox Jumped
        //  Fox Jumped
        //  Jumped

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

        // Use all the words to construct the suggestion, but limit the length of the input
        // to just the number of words a person might type while using autocomplete.
        $maxSuggestionWords = min($wordsCount, 5);
        for ($i = 0; $i < $wordsCount; $i++)
        {
            $suggestion = '';
            $inputWordsCount = 0;
            for ($j = $i; $j < $wordsCount; $j++)
            {
                $suggestion .= $words[$j] . ' ';
                $inputWordsCount++;
                if ($inputWordsCount > $maxSuggestionWords)
                {
                    break;
                }
            }
            $suggestions[] = trim($suggestion);
        }

        return $suggestions;
    }

    protected function createSuggestionsForPersonTitle($words, $wordsCount)
    {
        // Emit a set of suggestions for a person title that will be effective with the most common search which
        // is first name followed by last name. Account for the fact that installations can use either of these forms:
        //  1. <surname> - <first> <middle> <surname> aka <other names>
        //  2. <first> <middle> <surname> aka <other names>
        //
        // For form 1 e.g. "McCaslin Mary Louise McCaslin Mitchell aka Mae", emit:
        //  McCaslin Mary Louise McCaslin Mitchell
        //  Mary Louise McCaslin Mitchell
        //  Louise McCaslin Mitchell
        //  Mary McCaslin Mitchell
        //  Mary Mitchell

        // Determine which form applies. If the first word appears later in the title, assume it's form 1.
        $lastName = $words[0];
        $startsWithLastName = false;
        for ($index = 1; $index < $wordsCount; $index++)
        {
            if ($words[$index] == $lastName)
            {
                $startsWithLastName = true;
                break;
            }
        }

        $suggestions = $this->createSuggestionsForAnyTitle($words, $wordsCount, 'aka');

        // Prepend the first name onto the suggestions where it no longer appears.
        $firstName = $startsWithLastName ? $words[1] : $words[0];
        $firstIndexThatNeedsFirstNameAdded = $startsWithLastName ? 3 : 2;
        foreach ($suggestions as $index => $suggestion)
        {
            if ($index >= $firstIndexThatNeedsFirstNameAdded)
            {
                $suggestions[$index] = "$firstName $suggestion";
            }
        }

        return $suggestions;
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