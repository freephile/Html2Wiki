<?php

/**
 * We need to cleanup our input before we can begin to import it to MediaWiki
 * 
 * The original content is frames based, and heavily interspersed with 
 * JavaScript.  We need to filter out the JavaScript, as well as CSS styles
 * and we should hope that the content is sanitized from XSS attacks.
 * 
 * Rather than try some never-ending (and imperfect) long-tail chasing with 
 * regex based solutions like <TAG\b[^>]*>(.*?)</TAG>, we need a solution that
 * incorporates an actual HTML parser.
 * 
 * @link http://htmlpurifier.org/comparison HTMLPurifier 
 * is that solution.
 * 
 * @file
 */


$filename = "uvm-1.1d-docs-html-files-overviews-intro-txt.html";
$raw = file_get_contents(__DIR__ . "/$filename");


// The regex approach fails on a simple test.  
// It strips the closing </body></html> tags when it shouldn't
// a pattern to remove <script> tags
$reScript = "#<script\b[^>]*>(.*?)</script>#ius"; // lazy dot-all
// echo $html = preg_replace($reScript, '', $raw);

// @link http://docs.php.net/manual/en/domdocument.loadhtml.php PHP DOMDocument 
// which is based on libxml
// As the documentation points out, you should handle the many errors and warnings
// this tool will generate.
$doc = new DOMDocument();
// suppress the frailty of libxml
libxml_use_internal_errors(true);
$doc->loadHTML($raw);
$scriptTags = $doc->getElementsByTagName('script');
$length = $scriptTags->length;
// for each tag, remove it from the DOM
for ($i = 0; $i < $length; $i++) {
  $scriptTags->item($i)->parentNode->removeChild($scriptTags->item($i));
}
// get the HTML string back
$html = $doc->saveHTML();
echo $html;


