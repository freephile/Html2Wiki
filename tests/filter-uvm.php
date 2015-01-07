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

$tidy = "/usr/bin/tidy";

        /* usage in Parser.php
		if ( ( $wgUseTidy && $this->mOptions->getTidy() ) || $wgAlwaysUseTidy ) {
			$text = MWTidy::tidy( $text );
*/


$file = "uvm-1.1d-docs-html-files-overviews-intro-txt.html";
$file = __DIR__ . "/$file";
list( $dirname, $basename, $extension, $filename ) = array_values( pathinfo($file) );
if (empty($filename)) { // handle files without extension
    $filename = $extension;
    $extension = "";
}
$raw = file_get_contents($file);

$pureFile="$dirname/$basename.pure.$extension";
$domFile="$dirname/$basename.dom.$extension";
$tidyFile="$dirname/$basename.tidy.$extension";


/**
 * Tidy our input 
 * Use the Tidy extension bundled with PHP5
 * @link http://php.net/manual/en/book.tidy.php
 * @link http://php.net/manual/en/tidy.installation.php
 * Note that the class has methods like tidy::body() that we can use to get just
 * the <body> without writing our own method, or prior to loading a
 * PHPDom Document
 * Also, tidy::parseFile() will handle a URI
 */
if (class_exists('Tidy')) {
    // cleanup output
    $config = array(
        'indent'        => false,
        'output-xhtml'  => true,
        //'wrap'          => 80
    );
    $encoding = 'utf8';
    $tidy = new Tidy;
    $tidy->parseFile($file, $config, $encoding);
    $tidy->cleanRepair();
    if(!empty($tidy->errorBuffer)) {
      echo "The following errors or warnings occurred:\n";
      echo $tidy->errorBuffer;
    }
    // convert the object to string
    $tidy = (string) $tidy;
    file_put_contents($tidyFile, $tidy);
} else {
    die ("Tidy is not bundled as an extension.");
}



/**
 * Use HTML Purifier
 */
require_once __DIR__ . "/../lib/htmlpurifier/library/HTMLPurifier.auto.php";

$purifier = new HTMLPurifier();
// $clean_html = $purifier->purify($raw);
$clean_html = $purifier->purify($tidy);
// echo $clean_html;
echo "writing to $pureFile\n";
file_put_contents($pureFile, $clean_html);
// @link http://bit.ly/1vrlTVl


/** 
 * There are problematic entities used in this source.  I don't have a problem
 * per se with &rdquo; but when &rsquo; is used instead of ' for an apostrophe,
 * then it really breaks readability of the source, and these characters don't 
 * exist in older encodings like ISO-8859-1.
 * 
 * compare 
 * php -r 'echo html_entity_decode("&rsquo;", ENT_HTML401, "ISO-8859-1");'
 * php -r 'echo html_entity_decode("&rsquo;", ENT_HTML401, "UTF-8");'
 * 
 * We should use html_entity_decode() while specifying our source
 * to be ENT_HTML401 and ensure that we
 * specify the UTF-8 encoding to be sure that we're feeding good code downstream.
 * 
 * @link http://php.net/manual/en/function.html-entity-decode.php
 * 
     html_entity_decode("&rsquo;", ENT_HTML401, "UTF-8");
 * 
 * 
$substitutions = array(
  '&rsquo;' => ',
  '&ldquo;' => ", # “
  '&rdquo;' => ", # ”
);
foreach ($substitutions as $k => $v) {
  $out = str_replace($k, $v, $out);
}

 * 
 * 
 *  */
function decode ($input) {
    return html_entity_decode($input, ENT_HTML401, "UTF-8");
}

/*
// The regex approach fails on a simple test.  
// It strips the closing </body></html> tags when it shouldn't
// a pattern to remove <script> tags
 */
$reScript = "#<script\b[^>]*>(.*?)</script>#is"; // caseless dot-all
$html = preg_replace($reScript, '', $raw);

// @link http://docs.php.net/manual/en/domdocument.loadhtml.php PHP DOMDocument 
// which is based on libxml
// As the documentation points out, you should handle the many errors and warnings
// this tool will generate.
$doc = new DOMDocument();
// We get PHP Warning:  DOMDocument::loadHTML(): Unexpected end tag : div in Entity, line: 64
// We can suppress the frailty of libxml before loading the HTML
// libxml_use_internal_errors(true);
// however, this just moves the error downstream.
$doc->loadHTML($html);
// use Tidy output instead of the "regex-treated" html
// $doc->loadHTML($tidy);
// $doc->loadHTML($raw); // can't do this because the script tags are causing problem
$scriptTags = $doc->getElementsByTagName('script');
$length = $scriptTags->length;
// for each tag, remove it from the DOM
for ($i = 0; $i < $length; $i++) {
  $scriptTags->item($i)->parentNode->removeChild($scriptTags->item($i));
}
// get the HTML string back
$domhtml = $doc->saveHTML();
echo "writing to $domFile\n";
file_put_contents($domFile, $domhtml);



/**
 * Work on images
 * find . -type f | egrep  '\.(gif|jpg)$'|sort
./docs/html/images/bg_column_green.gif
./docs/html/images/bg_column_green_grey.gif
./docs/html/images/bg_feature.jpg
./docs/html/images/bg_h3_roundcorners.gif
./docs/html/images/bg_main.gif
./docs/html/images/bg_masthead.jpg
./docs/html/images/bg_navbar.gif
./docs/html/images/bg_roundcorners2.gif
./docs/html/images/bg_tableheader.gif
./docs/html/images/bg_thick_grey_bar.gif
./docs/html/images/bullet_GreenOnGrey.gif
./docs/html/images/uvm_ref_base.gif
./docs/html/images/uvm_ref_comparators.gif
./docs/html/images/uvm_ref_components.gif
./docs/html/images/uvm_ref_factory.gif
./docs/html/images/uvm_ref_phases_uml.gif
./docs/html/images/uvm_ref_reg_class_map.gif
./docs/html/images/uvm_ref_reporting.gif
./docs/html/images/uvm_ref_root.gif
./docs/html/images/uvm_ref_seq_item_ports.gif
./docs/html/images/uvm_ref_sequence.gif
./docs/html/images/uvm_ref_sequencer.gif
./docs/html/images/uvm_ref_sync.gif
./docs/html/images/uvm_ref_tlm_analysis_if.gif
./docs/html/images/uvm_ref_tlm_bidir_ports.gif
./docs/html/images/uvm_ref_tlm_get_peek_ifs.gif
./docs/html/images/uvm_ref_tlm_hierarchy.gif
./docs/html/images/uvm_ref_tlm_master_slave_ifs.gif
./docs/html/images/uvm_ref_tlm_put_ifs.gif
./docs/html/images/uvm_ref_tlm_transport_ifs.gif
./docs/html/images/uvm_ref_tlm_uni_ports.gif
 * 
 */