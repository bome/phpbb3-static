<?php

require_once('config.php');
require_once('common.php');

log_info("Extracting forum topics and posts:\n");

$forum_url = trim($forum_url, '/');

// A category is a group of forums.
function get_categories($phpbb_version, $db, $db_prefix) {
  $categories = array();
  if ($phpbb_version == PHPBB2) {
    $res = $db->query("SELECT cat_id, cat_title FROM {$db_prefix}categories " .
                      "ORDER BY cat_order;");
  }
  else if ($phpbb_version == PHPBB3) {
    //FIXME: fix ordering
    $res = $db->query("SELECT forum_id AS cat_id, forum_name AS cat_title " .
                      "FROM {$db_prefix}forums WHERE parent_id=0 ORDER BY left_id;");
  }

  foreach ($res as $row) {
    $categories[$row['cat_id']] = array(
      'title'  => $row['cat_title'],
      'forums' => array(),
    );
  }
  return $categories;
}

function get_forums_tree($phpbb_version, $db, $db_prefix) {
  $forums_tree = array();

  if ($phpbb_version == PHPBB2) {
    $res = $db->query("SELECT forum_id, cat_id FROM {$db_prefix}forums;");

    foreach ($res as $row) {
      $forums_tree[$row['forum_id']] = array(
        'parent_id' => -1,
        'cat_id'    => $row['cat_id'],
      );
    }
  }
  else if ($phpbb_version == PHPBB3) {
    $res = $db->query("SELECT forum_id, parent_id FROM {$db_prefix}forums;");

    foreach ($res as $row) {
      $forums_tree[$row['forum_id']] = array(
        'parent_id' => $row['parent_id'],
      );
    }

    foreach ($forums_tree as $fid => $forum) {
      $parent_id = $forum['parent_id'];

      if ($parent_id != 0) {

        while ($parent_id != 0) {
          $cat_id = $parent_id;
          $parent_id = $forums_tree[$parent_id]['parent_id'];
        }

        $forums_tree[$fid]['cat_id'] = $cat_id;

      }

    }

  }
  return $forums_tree;
}

// return FALSE if not found, the attachment object otherwise
function get_attachment_object_by_id($attachment_id, $all_topic_attachments) {
	$res = FALSE;
	foreach ($all_topic_attachments as $attachment) {
		if ($attachment['id'] == $attachment_id) {
			$res = $attachment;
			break;
		}
	}
	return $res;
}

// return TRUE if found
function has_attachment_real_filename($real_filename, $all_topic_attachments) {
	$res = FALSE;
	foreach ($all_topic_attachments as $attachment) {
		if ($attachment['real_filename'] == $real_filename) {
			$res = TRUE;
			break;
		}
	}
	return $res;
}

function get_attachment_object_from_db($row, &$all_topic_attachments) {

  // if we have already used this attachment, use it
  $attachment = get_attachment_object_by_id($row['attach_id'], $all_topic_attachments);
  if ($attachment !== FALSE) {
	return $attachment;
  }

  $physical_filename = $row['physical_filename'];
  $real_filename = $row['real_filename'];
  
  if (empty($real_filename)) {
    // use physical filename
    $real_filename = $physical_filename;
  } else {
    // fix some problems with filenames
    $real_filename = str_replace("/", "_", $real_filename);
    $real_filename = str_replace("\"", "_", $real_filename);
    $real_filename = str_replace("?", "_", $real_filename);
    $real_filename = str_replace(":", "_", $real_filename);
    $real_filename = str_replace(";", "_", $real_filename);
  }
  
 
  // do we have a name clash?
  $added_index = 1;
  if (has_attachment_real_filename($real_filename, $all_topic_attachments)) {
    $ext = $ext = strrchr($real_filename, '.');
	$base = basename($real_filename, $ext);
	$new_real_filename = $real_filename;
    while (has_attachment_real_filename($new_real_filename, $all_topic_attachments)) {
	  $new_real_filename = $base . "-" . $added_index . $ext;
	  $added_index++;
	}
	//log_info("\nAttachment name clash: $real_filename --> $new_real_filename\n");
	$real_filename = $new_real_filename;
  }

  $attachment = array(
    'id' => $row['attach_id'],
    'physical_filename' => $physical_filename,
    'real_filename' => $real_filename,
    'filetime' => $row['filetime'],
  );
  $all_topic_attachments[] = $attachment;
  
  return $attachment;
}

// @return attachments array for this post
function fix_attachments($tid, $post_id, &$post_text, $db, $db_prefix, $download_urls, &$all_topic_attachments) {
  $debug = false;
  // the returned array
  $attachments = array();

  foreach($download_urls as $download_url) {
    $dl_index = strpos($post_text, "\"" . $download_url);
    while ($dl_index !== FALSE) {
      if ($debug) log_info("\nTopic $tid: attachments:\n");
      //extract text until end of link
      $end_quote_index = strpos($post_text, "\"", $dl_index + 1);
      if ($end_quote_index === FALSE) {
        // no end quote found, bail out
        if ($debug) log_info("--no end quote found.\n");
        break;
      }
      // link length without quotes
      $link_len = $end_quote_index - $dl_index - 1;
      $link_text = substr($post_text, $dl_index + 1, $link_len);
      if ($debug) log_info("--link: $link_text\n");
      $id_index = strpos($link_text, "id=");
      if ($id_index !== FALSE) {
        $link_id = intval(substr($link_text, $id_index + 3));
        if ($debug) log_info("--attachment ID: $link_id\n");

        $res = $db->query(
          'SELECT attach_id, physical_filename, real_filename, filetime FROM ' . $db_prefix .
          "attachments WHERE attach_id = '" . $link_id . "';");
        if ($res !== FALSE) {
          $row = $res->fetch(PDO::FETCH_ASSOC);
          if ($row !== FALSE) {
			  $attachment = get_attachment_object_from_db($row, $all_topic_attachments);

              if ($debug) log_info("--physical file: " . $attachment['physical_filename'] . "\n");
              if ($debug) log_info("--original file: " . $attachment['real_filename'] . "\n\n");
              if (!$debug) log_info("*");

              // the new link is just pointing to the real filename in the current directory
              $post_text = substr_replace($post_text, rawurlencode($attachment['real_filename']), $dl_index + 1, $link_len);

              if (!in_array($attachment, $attachments)) {
			    $attachments[] = $attachment;
			  }
          }
        }
      } else {
        if ($debug) log_info("--id not found in link.\n");
	  }

      $dl_index = strpos($post_text, "\"" . $download_url, $dl_index + 1);
    }
  }
  
  // now get all attachments which are associated with this post, but not found in text
  $res = $db->query(
    'SELECT attach_id, topic_id, physical_filename, real_filename, filetime FROM ' . $db_prefix .
    "attachments WHERE post_msg_id = '" . $post_id . "';");
  if ($res !== FALSE) {
    foreach ($res as $row) {
      if (!get_attachment_object_by_id($row['attach_id'], $all_topic_attachments)) {
	    $attachment = get_attachment_object_from_db($row, $all_topic_attachments);
		log_info("\nNOTE: orphaned attachment for topic " . $row['topic_id'] . " / post $post_id:\n");
        log_info("--physical: " . $attachment['physical_filename'] . "\n");
        log_info("--original: " . $attachment['real_filename'] . "\n\n");
		//TODO: manually add a link in $post_text to that attachment?
		$attachments[] = $attachment;
	  }
	}
  }
  
  return $attachments;
}


// Returns updated $topics.
function get_posts($phpbb_version, $db, $db_prefix, $extracted) {
  global $forum_url;
  global $bb;

  $res = $db->query(
    'SELECT config_value FROM ' . $db_prefix .
    "config WHERE config_name = 'smilies_path';");
  $smilies_path = $res->fetch()['config_value'];

  // gather possible download paths used in the forum (also when cross linking)
  $download_urls = array();
  $download_urls[] = "./download/file.php?";
  $this_dl_url = $forum_url. "/download/file.php?";
  $download_urls[] = $this_dl_url;
  $index = strpos($this_dl_url, "https://");
  if ($index !== FALSE) {
    // also test for http version
    $this_dl_url = str_replace("https://", "http://", $this_dl_url);
    $download_urls[] = $this_dl_url;
  }
  // remove protocol
  $this_dl_url = str_replace("http://", "", $this_dl_url);
  // next slash is the absolute URL path
  $index = strpos($this_dl_url, "/");
  if ($index !== FALSE) {
    $download_urls[] = substr($this_dl_url, $index);
  }
  //log_info("\nAttachment paths: " . implode(",", $download_urls) . "\n");

  // This variable will be returned later.
  $topics = $extracted['topics'];

  // Cache of posts
  $dba_id = dba_open('forum-data_download_cache.dbm', 'c');

  // For each previously identified topic, fetch the corresponding posts.
  log_info("Extracting topics...");
  foreach ($topics as $tid => $topic) {
    log_info(" $tid");
    if ($phpbb_version == PHPBB2) {
      $res = $db->query('SELECT p.post_id, p.poster_id, p.post_username, u.username, p.post_time, pt.post_subject, pt.post_text, pt.bbcode_uid FROM '.$db_prefix.'posts p LEFT JOIN '.$db_prefix.'users u ON p.poster_id=u.user_id LEFT JOIN '.$db_prefix.'posts_text pt ON p.post_id=pt.post_id WHERE p.topic_id=' . $tid . ' ORDER BY p.post_time ASC');
    }
    else if ($phpbb_version == PHPBB3) {
      $res = $db->query(<<<SQL
SELECT
  p.post_id,
  p.poster_id,
  p.post_username,
  u.username,
  p.post_time,
  p.post_subject,
  p.post_text,
  p.bbcode_uid
FROM
  {$db_prefix}posts p
  LEFT JOIN {$db_prefix}users u ON p.poster_id=u.user_id
WHERE
  p.topic_id={$tid}
ORDER BY p.post_time ASC
;
SQL
);
    }

    $topics[$tid]['posts'] = array();
    $all_topic_attachments = array();

    foreach ($res as $row) {
      $post_id = $row['post_id'];
      $got_text = false;
      if (dba_exists($post_id, $dba_id)) {
        $post_text = dba_fetch($post_id, $dba_id);
        $got_text = true;
      } else {
        $url = $forum_url . '/viewtopic.php?t=' . $tid . '&p=' . $row['post_id'];
        $html = file_get_contents($url);
        if ($html !== false) {
          $doc = new DOMDocument();
          $caller = new ErrorTrap(array($doc, 'loadHTML'));
          $caller->call($html);
          // We could output these errors if we wanted. They could help
          // debugging HTML parsing issues.
          // if (!$caller->ok()) {
          //   var_dump($caller->errors());
          // }
          $xpath = new DOMXpath($doc);
          foreach($xpath->query("//div[contains(@class, 'post') and contains(@id, 'p')]") as $div) {
            $id = $div->getAttribute('id');
            $textNodes = $xpath->query("//div[@id='{$id}']//div[@class='content']");
            // What if it wasn't found?
            $textNode = $textNodes[0];
            $text = $doc->saveHTML($textNode);
			// attachments?
            $attachmentNodes = $xpath->query("//div[@id='{$id}']//dl[@class='attachbox']");
			if (!empty($attachmentNodes)) {
              foreach ($attachmentNodes as $attachmentNode) {
			    $text .= "\n" . $doc->saveHTML($attachmentNode);
				log_info("+");
			  }
			}
			
            $dbm_key = substr($id, 1);
            dba_insert($dbm_key, $text, $dba_id);
          }
          // Maybe the above succeeded, maybe it didn't.
          if (dba_exists($post_id, $dba_id)) {
            $post_text = dba_fetch($post_id, $dba_id);
            $got_text = true;
          }
        }
      }

      if (!$got_text) {
        error_log("Warning: Could not fetch post id {$row['post_id']}.");
        // We got a zero-length file. Let's try to parse the database
        // representation that we've retrieved from the database. If there are
        // links in it, they will be broken, but it's better than nothing.
        $post_text = $row['post_text'];
        $post_text = str_replace(':' . $row['bbcode_uid'], '', $post_text);
        $post_text = preg_replace('/\[(\/?)code:\d*\]/', '[\1code]', $post_text);
        $post_text = nl2br($bb->qParse($post_text));
      }

      // Fix the smilies paths. In an phpBB installation, links to smilies start
      // from the top level. In the case of the archive, topics are 3 levels down,
      // when you count slashes. So if images are in the same place as previously,
      // we need to go 3 levels up to find them.
      $post_text = str_replace('src="./' . $smilies_path,
                               'src="../../../' . $smilies_path, $post_text);

      //extract attachments for this post
      $attachments = fix_attachments($tid, $row['post_id'], /*IN OUT*/$post_text, $db, $db_prefix, $download_urls, $all_topic_attachments);

      $topics[$tid]['posts'][] = array(
        'username'   => $row['username'],
        'post_text'  => $post_text,
        'post_time'  => $row['post_time'],
        'bbcode_uid'  => $row['bbcode_uid'],
        'post_id'  => $row['post_id'],
        'attachments'  => $attachments,
      );
    }
  }  // each $topics
  log_info("\n");
  dba_close($dba_id);
  return $topics;
}

// loadHTML is spewing warnings that are of no interest to me, and silencing
// them is excitingly complicated.
// Solution copied from:
// http://stackoverflow.com/questions/1148928/disable-warnings-when-loading-non-well-formed-html-by-domdocument-php
class ErrorTrap {
  protected $callback;
  protected $errors = array();
  function __construct($callback) {
    $this->callback = $callback;
  }
  function call() {
    $result = null;
    set_error_handler(array($this, 'onError'));
    try {
      $result = call_user_func_array($this->callback, func_get_args());
    } catch (Exception $ex) {
      restore_error_handler();
      throw $ex;
    }
    restore_error_handler();
    return $result;
  }
  function onError($errno, $errstr, $errfile, $errline) {
    $this->errors[] = array($errno, $errstr, $errfile, $errline);
  }
  function ok() {
    return count($this->errors) === 0;
  }
  function errors() {
    return $this->errors;
  }
}

// List of topics
function get_forums_and_topics($phpbb_version, $db, $db_prefix, $extracted) {
  global $filter_forum;
  global $phpbb3_minor_version;

  $topics = array();
  $forums = array();

  // Get details of each forum.
  if ($phpbb_version == PHPBB2) {
    $res = $db->query("SELECT forum_id, forum_name, forum_posts, forum_topics FROM {$db_prefix}forums ORDER BY forum_order;");
  }
  else if ($phpbb_version == PHPBB3) {
    //FIXME: fix ordering
    if($phpbb3_minor_version == 0) {
        $res = $db->query("SELECT forum_id, forum_name, forum_posts, forum_topics FROM {$db_prefix}forums WHERE parent_id<>0 ORDER BY left_id;");
    } elseif ($phpbb3_minor_version == 1 || $phpbb3_minor_version == 2) {
        $res = $db->query("SELECT forum_id, forum_name, forum_posts_approved, forum_topics_approved FROM {$db_prefix}forums WHERE parent_id<>0 ORDER BY left_id;");
    } else {
        die('Unknown PHPBB minor version');
    }
}

  $categories = $extracted['categories'];
  foreach ($res as $row) {
    $fid = $row['forum_id'];

    if (in_array($fid, $filter_forum)) {
      continue;
    }

    $forums_tree = $extracted['forums_tree'];
    $cat_id = $forums_tree[$fid]['cat_id'];

    if($phpbb3_minor_version == 0) {
        $forums[$fid] = array(
          'title'   => $row['forum_name'],
          'nposts'  => $row['forum_posts'],
          'ntopics' => $row['forum_topics'],
          'topics'  => array()
        );
    } elseif ($phpbb3_minor_version == 1 || $phpbb3_minor_version == 2) {
        $forums[$fid] = array(
          'title'   => $row['forum_name'],
          'nposts'  => $row['forum_posts_approved'],
          'ntopics' => $row['forum_topics_approved'],
          'topics'  => array()
        );
    } else {
        die('Unknown PHPBB minor version');
    }

    $categories[$cat_id]['forums'][] = $fid;
  }

  // Get topics
  if($phpbb3_minor_version == 0) {
    $res = $db->query(<<<SQL
    SELECT
      t.forum_id,
      t.topic_id,
      t.topic_title,
      t.topic_time,
	  t.topic_type,
      t.topic_replies,
      u.username
    FROM
      {$db_prefix}topics t
      LEFT JOIN {$db_prefix}users u ON t.topic_poster=u.user_id
    WHERE
      t.topic_moved_id = 0
    ORDER BY
	  t.topic_type DESC,
      t.topic_time DESC
    -- LIMIT 100 -- uncomment in development for faster runs
    ;
SQL
    );
  } elseif ($phpbb3_minor_version == 1 || $phpbb3_minor_version == 2) {
    $res = $db->query(<<<SQL
    SELECT
      t.forum_id,
      t.topic_id,
      t.topic_title,
      t.topic_time,
	  t.topic_type,
      t.topic_posts_approved,
      u.username
    FROM
      {$db_prefix}topics t
      LEFT JOIN {$db_prefix}users u ON t.topic_poster=u.user_id
    WHERE
      t.topic_moved_id = 0
    ORDER BY
	  t.topic_type DESC,
      t.topic_time DESC
    -- LIMIT 100 -- uncomment in development for faster runs
    ;
SQL
    );
  } else {
    die('Unknown PHPBB minor version');
  }

  foreach ($res as $row) {
    $fid = $row['forum_id'];

    if (in_array($fid, $filter_forum)) {
      continue;
    }
	
    if($phpbb3_minor_version == 0) {
        $topics[$row['topic_id']] = array(
          'fid'     => $fid,
          'title'   => $row['topic_title'],
          'time'    => $row['topic_time'],
          'type'    => $row['topic_type'],
          'replies' => $row['topic_replies'],
          'author'  => $row['username'],
          'lastmod' => gmdate('Y-m-d\TH:i:s\Z', $row['topic_time']),
        );
    } elseif ($phpbb3_minor_version == 1 || $phpbb3_minor_version == 2) {
        $topics[$row['topic_id']] = array(
          'fid'     => $fid,
          'title'   => $row['topic_title'],
          'type'    => $row['topic_type'],
          'time'    => $row['topic_time'],
          'replies' => $row['topic_posts_approved'],
          'author'  => $row['username'],
          'lastmod' => gmdate('Y-m-d\TH:i:s\Z', $row['topic_time']),
        );
    }  else {
        die('Unknown PHPBB minor version');
    }
    $forums[$fid]['topics'][] = $row['topic_id'];
  }

  return array($categories, $forums, $topics);
}

function save_data_in_json($what, $where_to) {
  // The encoding flags aren't crucial, they are just here because they make it
  // easier for me to review the resulting JSON.
  log_info('Encoding to JSON... ');
  $encoded_data = json_encode($what,
    JSON_PRETTY_PRINT | JSON_HEX_APOS | JSON_HEX_QUOT
    | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
  if ($encoded_data !== false) {
    log_info('saving to disk... ');
    $fp = fopen($where_to, 'w');
    fwrite($fp, $encoded_data);
     // The encoder doesn't add a newline at the end.
    fwrite($fp, "\n");
    fclose($fp);
    log_info("done.\n");
  } else {
    error_log('Could not encode data to JSON.\n');
  }
}

$db_port_statement = '';
if (!empty($db_port)) {
  $db_port_statement = ";port=$db_port";
}

$db = new PDO(
  'mysql:host=' . $db_host . $db_port_statement . ';dbname=' . $db_name . ';charset=utf8mb4',
  $db_user, $db_pass);


try {
  $extracted = array();
  $extracted['categories'] = get_categories($phpbb_version, $db, $db_prefix);
  $extracted['forums_tree'] = get_forums_tree($phpbb_version, $db, $db_prefix);
  list($extracted['categories'],
    $extracted['forums'],
    $extracted['topics']) = get_forums_and_topics($phpbb_version, $db, $db_prefix, $extracted);
  $extracted['topics'] = get_posts($phpbb_version, $db, $db_prefix, $extracted);
  unset($forums_and_topics);
  save_data_in_json($extracted, 'forum-data.json');
  log_info("\n");
} catch(PDOException $ex) {
  echo "An Error occured! " . $ex->getMessage();
  throw $ex;
}
