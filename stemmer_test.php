<?php
namespace HW3\stemmertest;

use seekquarry\yioop\library\FetchUrl;
use seekquarry\yioop\library\CrawlConstants;
use seekquarry\yioop\library\PhraseParser;
use seekquarry\yioop\library\processors\HtmlProcessor;

require_once "vendor/autoload.php";
/*Read the urls from a file and call getPages to download the content from each
of them */
$parser = new PhraseParser();
$url_list = [];
if($fh = fopen($argv[1],'r'))
{
	while(!feof($fh))
  {
		$cc_url = [CrawlConstants::URL => trim(fgets($fh),"\r\n")];
		array_push($url_list,$cc_url);
	}
	fclose($fh);
}
$page_info = FetchUrl::getPages($url_list);

/*get the description from downloaded content of each page*/
$max_description_len = 20000;
for($i=0;$i<count($url_list);$i++)
{
	$page[$i] = $page_info[$i][CrawlConstants::PAGE];
}

$result = [];
/* send the content from the description of every page to the HtmlProcessor
to extract terms from the HTML content*/
$h = new HtmlProcessor([], $max_description_len, CrawlConstants::CENTROID_SUMMARIZER);
for($i=0;$i<count($url_list);$i++)
{
	$words[$i] = $h->process($page[$i], $url_list[$i][CrawlConstants::URL]);
	$result[$i] = [($words[$i][CrawlConstants::DESCRIPTION])];
	array_push($result[$i],$words[$i][CrawlConstants::LANG]);
}

if($argv[2] == "true")
{
	$should_stem = true;
}
else if($argv[2] == "false")
{
	$should_stem = false;
}

$index_info = CreatePositionalIndex($result,$should_stem);
$num_of_docs = $index_info[0];
$index = $index_info[1];
ksort($index);
printIndexInfo($index);

/*call cosine ranking with the desired query and the page descriptions as the
corpus and an indicator to stem or not stem*/
//cosineRanking("Flying Fish body",$result,$num_of_docs,$index,$should_stem);


class WordEncode
{
    /*
    This class for every word contains a
    list doc that contains a list of tuples (doc_id,position of a word in the doc)
    The word itself
    the count variable contains the number of times a word is repeated across all the documents
    doc_count consists of the number of documents in which the given word is present
    current_doc tracks the presence of the word in a document
		doc_freq keeps track of how many times the word is repeated in every document
    The object of this class is stored as a value of dictionary word_dict
    */

    public $count;
    public $doc_count;
    public $current_doc;
    public $doc = array();
    public $word;
    public $doc_word_freq = array();

    // increment the count of the word across all documents
    function increment_count()
    {
        $this->count = $this->count + 1;
    }

    // increment the number of documents in which the word is present
    function increment_doc_count()
    {
        $this->doc_count = $this->doc_count + 1;
    }

    //For every word, add the (docid,wordpos)
    function add_doc_positions($doc_id,$pos)
    {
        for ($i = 0; $i < count($pos); $i++)
        {
          array_push($this->doc,array($doc_id,$pos[$i]));
        }
    }

    //For every word, add (docid=>freq_of_word)
    function add_doc_word_freq($doc_id)
    {
      if (array_key_exists($doc_id,$this->doc_word_freq))
      {
        $this->doc_word_freq[$doc_id]++;
      }
      else
      {
        $this->doc_word_freq[$doc_id] = 1;
      }
    }

    // for every word increment the word count, doc count and find the index of
		// the word in every document it is present in
    function add_doc($doc_id, $pos)
    {
        $this->increment_count();
        $this->add_doc_word_freq($doc_id);
        if ($doc_id != $this->current_doc)
        {
            $this->increment_doc_count();
            $this->current_doc = $doc_id;
            $this->add_doc_positions($doc_id,$pos);
        }
    }

		//constructor
    function __construct($word, $current_doc)
    {
        $this->word = $word;
        $this->count = 0;
        $this->doc_count = 1;
        $this->current_doc = $current_doc;
    }

    // printing the doc count, word count and index of every word
    function printme()
    {
        print ("{$this->doc_count},{$this->count}");
        foreach($this->doc as $docpos)
        {
          $doc_num = $docpos[0];
          $doc_pos = $docpos[1];
          print(",({$doc_num},{$doc_pos})");
        }
    }
}

/* Creates the positional index for every term in the corpus. The index is
an array with key as the term and value as the WordEncode object*/
function CreatePositionalIndex($corpus,$shouldstem_terms)
{
  $results = array();
  $word_dict = array();


  $num_of_docs = count($corpus);
  array_push($results,$num_of_docs);
	//for every url in the list, we go through the terms in the downloaded page
  foreach($corpus as $corpus_info)
  {
    $doc_idx = array_search($corpus_info,$corpus);
    $words = $corpus_info[0];
    $words = preg_split('/[\s.,]+/', $words,NULL, PREG_SPLIT_NO_EMPTY);
		$words = array_filter($words,'strlen');

    foreach($words as $key=>$term){
      $words[$key] = strtolower($term);
    }
		//Stem the terms if the command line argument should_stem is true
    if($shouldstem_terms)
    {
      foreach($words as $key=>$term){
        $words[$key] = stemTerm($term,$corpus_info[1]);
      }
    }
		//For every term, create/update the word encode object.
    foreach ($words as $word)
    {
      $orig_word = $word;
      $word_without_punct = RemovePunctuation($word);

      if(array_key_exists($word_without_punct,$word_dict))
      {
        $word_dict[$word_without_punct]->add_doc($doc_idx,array_keys($words,$orig_word));
      }
      else if($word_without_punct != "")
      {
        $word_encode_obj = new WordEncode($word_without_punct,$doc_idx);
        $word_dict[$word_without_punct] = $word_encode_obj;
        //$word_dict[$word_without_punct]->add_doc($doc_idx,array_search($word,$words));
        $key_positions = array_keys($words,$orig_word);
        $word_dict[$word_without_punct]->add_doc($doc_idx,$key_positions);
        $word_dict[$word_without_punct]->add_doc_positions($doc_idx,$key_positions);
      }
    }
  }
	//return the result with total number of documents in the corpus,index
  array_push($results,$word_dict);
	//$keys = array_keys($results[1]);
	//print_r($keys);
	//exit();
  return $results;
}

/* Find the next document that contains the $query_term.
Used for cosine ranking. Uses galloping search*/
function nextDoc($index,$query_term,$pos)
{
  $index = array_key_exists($query_term,$index);
  $low = 0;
  $jump = 1;
  $high = $low+$jump;
  if(!$index)
  {
    return INF;
  }
  $term_obj = $index[$query_term];
  if($pos == -INF)
  {
    return array_keys($term_obj->doc_word_freq)[0];
  }
  $len_doc_word_freq = count($index[$query_term]->doc_word_freq);
  if(array_keys($term_obj->doc_word_freq)[$len_doc_word_freq-1] <= $pos)
  {
    return INF;
  }
  else
  {
    while($high < $len_doc_word_freq-1 &&
                  array_keys($term_obj->doc_word_freq)[$high] <= $pos)
    {
        $low = $high;
        $jump = 2* $jump;
        $high = $low + $jump;
    }

    if ($high > $len_doc_word_freq-1)
    {
        $high = $len_doc_word_freq-1;
    }

    return binarySearch(array_keys($term_obj->doc_word_freq),$pos,$low,$high);
  }
}

/*Used for performing binary search on the array of doc_ids.*/
function binarySearch($doc_array,$pos,$low,$high)
{
  if ($low == $high){
    return $doc_array[$low];
  }
  if (($high - $low) == 1)
  {
    if ($doc_array[$low] > $pos)
    {
      return $doc_array[$low];
    }
    else if ($doc_array[$high] > $pos){
      return $doc_array[$high];
    }
  }

  while($low < $high)
  {
    $mid = ($low + $high )/2;
    $mid = (int)$mid;

    if ($doc_array[$mid] < $pos){
      return binarySearch($doc_array,$pos,$mid,$high);
    }
    else if ($doc_array[$mid] > $pos){
      return binarySearch($doc_array,$pos,$low,$mid);
    }
    else if ($doc_array[$mid] == $pos){
      return $doc_array[$mid+1];
    }
  }
}

/*Calculates the TFIDF scores over the terms of a document
$index - index of the terms; $doc_id - id of the doc whose TFIDF values should
be calculated ; $num_of_docs - total number of document in the corpus;
$doc - contains the terms of the document; $should_stem - if we should stemTerm
or not ; $locale - locale to be used for stemming ; returns a document vector
with TFIDF scores of the terms in the document*/
function calcTFIDF_doc($index,$doc_id,$num_of_docs,$doc,$should_stem,
														$locale)
{
  $words = preg_split('/[\s.,]+/', $doc);
	$words = array_filter($words,'strlen');
  $doc_vector = array();
  foreach ($words as $key=>$word)
  {
    $words[$key] = strtolower($word);
  }
  foreach ($words as $word)
  {
    if($should_stem){
      $word = stemTerm($word,$locale);
    }
    $word_without_punct = RemovePunctuation($word);
    if(array_key_exists($word_without_punct,$index));
    {
      //print("{$word_without_punct} \n");
      $doc_word_count = $index[$word_without_punct]->doc_count;
      $doc_freq_count = $index[$word_without_punct]->doc_word_freq[$doc_id];
      $IDF = log(($num_of_docs/$doc_word_count),2);
      $TF = log($doc_freq_count,2)+1;
      $doc_vector[$word_without_punct] = $IDF * $TF;
    }
  }
  $magnitude = calcMagnitude($doc_vector);
  foreach ($doc_vector as &$doc_vector_component)
  {
    $doc_vector_component = $doc_vector_component / $magnitude;
  }
  return $doc_vector;
}

/*Calculates the TFIDF values of the query terms. The IDF score of query term is
assumed to be 1*/
function calcTFIDF_query($query,$should_stem)
{
  $query_terms = explode(" ",$query);
  $query_vector = array();
  foreach($query_terms as $query_term)
  {
    $num_query_term_occurence = count(array_keys($query_terms, $query_term));
    if($should_stem)
    {
      $query_term = stemTerm($query_term,'en-US');
    }
    $query_term = RemovePunctuation($query_term);
    $query_vector[$query_term] = 1 * (log($num_query_term_occurence) + 1);
  }
  $magnitude = calcMagnitude($query_vector);
  foreach ($query_vector as $query_vector_component)
  {
    $query_vector_component /= $magnitude;
  }
  return $query_vector;
}

/*Calculate the cosine scores between the document vector and the query vector */
function calcScore($doc_vector,$query_vector,$query,$should_stem,$locale)
{
  $query_terms = explode(" ",$query);
  $score = 0;
  foreach ($query_terms as $query_term)
  {
    if($should_stem)
    {
      $query_term = stemTerm($query_term,$locale);
    }
    $query_term = RemovePunctuation($query_term);
    if(array_key_exists($query_term,$doc_vector))
    {
      $score += $doc_vector[$query_term] * $query_vector[$query_term];
    }
  }
  return $score;
}

/*Calculates the magnitude of a vector */
function calcMagnitude($vector)
{
    $magnitude = 0;
    foreach($vector as $vector_component)
    {
      $magnitude += pow($vector_component,2);
    }
    return pow($magnitude,0.5);
}

/*Remove puntuations from a string */
function RemovePunctuation($word)
{
  //return strtolower(preg_replace("#[[:punct:]]#", "", $word));
	$word = trim( preg_replace( "/[^0-9a-z]+/i", "", $word ) );
	return strtolower($word);
}

/*Call the PhraseParser::stemTerms over the $term using the $locale*/
function stemTerm($term,$locale)
{
  $term = PhraseParser::stemTerms($term,$locale);
  $term = implode("",$term);
  return $term;
}

/*Print the index for every term in the index*/
function printIndexInfo($positional_index)
{
  $terms = array_keys($positional_index);

  foreach($terms as $term)
  {
    print("\n{$term} \n");
    $positional_index[$term]->printme();
  }
}

/*Calculates cosine ranking for $query using the $index*/
function cosineRanking($query,$corpus,$num_of_docs,$index,$should_stem)
{
  $doc_locale = [];
  for($i = 0; $i < count($corpus); $i++)
  {
    $doc_locale[$i] = $corpus[$i][1];
  }

  $cosine_results = array();
	$doc_nums = array();
  $query_terms = explode(' ',$query);
  $pos = -INF;
	//Calculate the query vector
  $query_vector = calcTFIDF_query($query,$should_stem);

	//Find the min document in which atleast one of the query term is present
  while($pos < INF)
  {
    foreach($query_terms as $query_term)
    {
      if($should_stem){
        $query_term = stemTerm($query_term,'en-US');
      }
      $query_term = RemovePunctuation($query_term);
      array_push($doc_nums,nextDoc($index,$query_term,$pos));
    }
    $next_pos = min($doc_nums);
    $doc_nums = array();
    if ($next_pos == INF)
    {
      break 1;
    }
		//for the min doc found, calculate the TFIDF scores
    $doc_vector = calcTFIDF_doc($index,$next_pos,$num_of_docs,
                                $corpus[$next_pos][0],$should_stem,
                                        $corpus[$next_pos][1]);
		//Calculate the cosine similarity between doc vector and cosine vector
    $cosine_results[$next_pos] = calcScore($doc_vector,$query_vector,$query,
                                            $should_stem,$corpus[$next_pos][1]);
    $pos = $next_pos;
  }
	arsort($cosine_results);
  print("\ndoc_id\tscore \n");
  foreach ($cosine_results as $key => $value)
	{
  	print("{$key}\t{$value}\n");
  }
}
