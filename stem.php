<?php
namespace HW3\Stem;

use seekquarry\yioop\library\PhraseParser;

require_once "vendor/autoload.php";

$input_string = $argv[1];
//split input string into words
$string_components = explode(" ",$input_string);
$output_array = [];
$output_string = "";

//foreac word call the stemmer
foreach ($string_components as $term)
{
    $output_array = array_merge($output_array, PhraseParser::stemTerms($term, 'en-US'));
}
//combine the terms in the output array to a single string
$output_string = implode(' ',$output_array);
print("{$output_string}\n");
