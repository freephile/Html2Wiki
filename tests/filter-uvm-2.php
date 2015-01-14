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
 * Unfortunately, the libxml based PHP DOMDocument
 * @link http://docs.php.net/manual/en/domdocument.loadhtml.php PHP DOMDocument 
 * fails to parse even our first test due to improperly nested <DIV> tags
 * 
 * @link http://htmlpurifier.org/comparison HTMLPurifier 
 * is that solution.
 * 
 * @file
 */



// $file = "uvm_printer-svh.html";
// $file = __DIR__ . "/../data/uvm-1.1d/docs/html/files/base/$file";

// VIP Info Hubs
$file = "overview04.html"; // Documentation Directory Structure
$file = __DIR__ . "/../data/docs/htmldocs/mgc_html_help/$file";


list( $dirname, $basename, $extension, $filename ) = array_values( pathinfo($file) );
if (empty($filename)) { // handle files without extension
    $filename = $extension;
    $extension = "";
}
$raw = file_get_contents($file);
/*
$pureFile="$dirname/$basename.pure.$extension";
$domFile="$dirname/$basename.dom.$extension";
$tidyFile="$dirname/$basename.tidy.$extension";
*/

/**
 * Tidy our input 
 * http://tidy.sourceforge.net/docs/tidy_man.html
 * 
 * usage in Parser.php
   if ( ( $wgUseTidy && $this->mOptions->getTidy() ) || $wgAlwaysUseTidy ) {
     $text = MWTidy::tidy( $text );

 * For testing, we'll use the Tidy extension bundled with PHP5
 * @link http://php.net/manual/en/book.tidy.php
 * @link http://php.net/manual/en/tidy.installation.php
 * Note that the class has methods like tidy::body() that we can use to get just
 * the <body> without writing our own method, or prior to loading a
 * PHPDom Document
 * Also, tidy::parseFile() will handle a URI
 * 
 * and if that doesn't exist (it doesn't in mw-vagrant), then we'll fall back
 * to /usr/bin/tidy
 */
if (class_exists('Tidy')) {
    // cleanup output
    $config = array(
        'indent'        => false,
        'output-xhtml'  => true,
        //'wrap'          => 80,
        'show-body-only' => true,
        'merge-divs'     => true,
    );
    $encoding = 'utf8';
    $tidy = new Tidy;
    $tidy->parseFile($file, $config, $encoding);
    $tidy->cleanRepair();
    if(!empty($tidy->errorBuffer)) {
//     echo "The following errors or warnings occurred:\n";
//     echo $tidy->errorBuffer;
    }
    // convert the object to string
    // $tidyout = (string) $tidy;
    // focus just on the <body> tag
    // this is equivalent to the --show-body-only option
    $tidyout = (string) $tidy->body();
    // file_put_contents($tidyFile, $tidyout);
} else {
    echo ("Tidy is not bundled as an extension.  Falling back to command-line.");
    // echo "processing $file";
    // echo "contents:\n";
    // echo $raw;
    $tidy = "/usr/bin/tidy";
    $cmd= "$tidy -indent -asxhtml --show-body-only 1 --merge-divs 1";
    echo "piping to $cmd\n";
    $tidyout = exec("cat $file | $tidy 2>/dev/null");
    echo $tidyout;
    
    // $tidyout = $raw;
    // die ("Tidy is not bundled as an extension.");
}

// echo $tidyout;

/**
 * Use HTML Purifier
 */
require_once __DIR__ . "/../lib/htmlpurifier/library/HTMLPurifier.auto.php";

$purifier = new HTMLPurifier();
// $clean_html = $purifier->purify($raw);
$clean_html = $purifier->purify($tidyout);
// echo $clean_html;
// echo "writing to $pureFile\n";
// file_put_contents($pureFile, $clean_html);
// @link http://bit.ly/1vrlTVl





$reScript = "#<script\b[^>]*>(.*?)</script>#is"; // caseless dot-all

$reEmptyAnchor = "#<a>.*?</a>#is"; // empty anchor tags
$clean_html = preg_replace($reEmptyAnchor, '', $clean_html);

$reCollapsePre = '#</pre>.*?<pre class="pCode">#s'; // sibling pre tags
$clean_html = preg_replace($reCollapsePre, '', $clean_html);

$reBlankLine = "#^\s?$\n#m";
$clean_html = preg_replace($reBlankLine, '', $clean_html);
        
echo $clean_html;

/*
// calling parsoid directly doesn't work because we run out of memory and other issues
$tmpfile = '/tmp/output.html';
file_put_contents($tmpfile, $clean_html);
$parsoid = "/srv/parsoid/src/tests/parse.js";
$wikiText = `$parsoid --html2wt --inputfile $tmpfile`;
echo $wikiText;
*/

/**
 * instead we'll use the API as intended
 * https://www.mediawiki.org/wiki/Parsoid/API
 */

/*
$url = 'http://localhost:8000/localhost/';
$page = 'Main_Page';
$data = array('html'=>$clean_html);
// $data = array('html'=>"<p>This is a paragraph</p>");
// use key 'http' even if you send the request to https://...
$options = array(
    'http' => array(
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'method'  => 'POST',
        'content' => http_build_query($data),
    ),
);
$context  = stream_context_create($options);
// file_get_contents() only works here if only if allow_url_fopen = true (default)
$result = file_get_contents($url, false, $context);

var_dump($result);

  */  
