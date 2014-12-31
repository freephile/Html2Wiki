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

$filename = "uvm-1.1d-docs-html-files-overviews-intro-txt";
$raw = file_get_contents($filename);

// define a pattern to remove <script> tags
$reScript = "#<script\b[^>]*>(.*?)</script>#is"; // lazy dot-all

$html = preg_replace($reScript, '', $raw);

echo $html;

