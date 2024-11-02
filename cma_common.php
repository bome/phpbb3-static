<?php

function slug($text, string $divider = '-')
{
  // replace non letter or digits by divider
  $text = preg_replace('~[^\pL\d]+~u', $divider, $text);

  // transliterate
  $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

  // remove unwanted characters
  $text = preg_replace('~[^-\w]+~', '', $text);

  // trim
  $text = trim($text, $divider);

  // remove duplicate divider
  $text = preg_replace('~-+~', $divider, $text);

  // lowercase
  $text = strtolower($text);

  if (empty($text)) {
    return 'n-a';
  }

  return $text;
}


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

$logfilename = "";

function setLogfile($logfile) {
	global $logfilename;
	$logfilename = $logfile;
	file_put_contents($logfilename, "");
}

function log_info($str) {
	print($str);
	if (!empty($logfilename)) {
        file_put_contents($logfilename, $str , FILE_APPEND | LOCK_EX);
    }
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

