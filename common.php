<?php

// don't show warnings in BBCodeParser2
$old_error_reporting = error_reporting(E_ERROR);
include('HTML/BBCodeParser2.php');
$bbopt = array(
	'filters' => array('Basic', 'Email', 'Extended', 'Images', 'Links', 'Lists')
);
$bb = new HTML_BBCodeParser2($bbopt);
error_reporting($old_error_reporting);

function template_print($var, $template) {
	extract($var);
	include($template);
}

function template_get($var, $template) {
	global $template_dir;

	$template = $template_dir . '/' . $template;

	ob_start();
	template_print($var, $template);
	$res = ob_get_contents();
	ob_end_clean();

	return $res;
}

function mkdir_p($dir) {
	if (!is_dir($dir) && !is_link($dir)) {
		mkdir($dir, 0755);
	}
}


function write_content($file, $content) {
	global $target_dir;

	$target = $target_dir . '/' . $file;
	$dir = dirname($target);

	$one_up_dir = dirname($dir);
	mkdir_p($one_up_dir);
	mkdir_p($dir);

	$f = fopen($target, 'w');
	fputs($f, $content);
	fclose($f);
}

function log_info($str) {
	print($str);
}

function slug($str) {
		$str = transliterator_transliterate(
			"Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; [:Punctuation:] Remove; Lower();",
			$str);
		$str = str_replace(' ', '-', $str);
		$str = str_replace('/', '-', $str);
		$str = str_replace('--', '-', $str);
		$str = trim($str, '-');
		return $str;
}

function get_topic_title_with_type($topic_title, $topic_type) {
  global $topic_title_prefix_sticky, $topic_title_prefix_announcement, $topic_title_prefix_global;

	if ($topic_type == 1) {
		// sticky
		return $topic_title_prefix_sticky . $topic_title;
	} else if ($topic_type == 2) {
		// announcement
		return $topic_title_prefix_announcement . $topic_title;
	} else if ($topic_type == 3) {
		// global
		return $topic_title_prefix_global . $topic_title;
	}
	return $topic_title;
}


// from: https://stackoverflow.com/questions/2050859/copy-entire-contents-of-a-directory-to-another-using-php
function recurse_copy($src, $dst) { 
    $dir = opendir($src); 
    mkdir_p($dst);
    while(false !== ( $file = readdir($dir)) ) { 
        if (( $file != '.' ) && ( $file != '..' )) { 
            if ( is_dir($src . '/' . $file) ) { 
                recurse_copy($src . '/' . $file,$dst . '/' . $file); 
            } 
            else { 
                copy($src . '/' . $file,$dst . '/' . $file); 
            } 
        } 
    } 
    closedir($dir); 
} 

