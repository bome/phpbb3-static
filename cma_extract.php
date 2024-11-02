<?php

require_once('config.php');
require_once('cma_common.php');

// set to 0 to retrieve all threads
$MAX_THREADS = 0;
$debug = true;
$more_debug = true;

$FORUM_NAME="Bome Forum Archive 2016-2020";
$CATEGORY_ID = 1;
$FORUM_ID = 21;

// CMA tables:
// wp_cma_logs
// wp_cma_logs_meta

// wp_posts:
// | ID    | post_author | post_date | post_date_gmt       |
// post_content| post_title | post_excerpt | post_status | comment_status | ping_status | post_password | post_name |
//  to_ping | pinged | post_modified | post_modified_gmt | post_content_filtered | post_parent | guid | menu_order |
// post_type  | post_mime_type  | comment_count |
//
// ->post_type = 'cma_thread'
// ->post_type = 'attachment': post_title is the filename, guid is the physical filename


// wp_postmeta:
// | meta_id | post_id | meta_key | meta_value |
// meta_key = '_attachment"
//  post_id: the ID of the post to which the attachment is attached
//  meta_value: the ID of the attachment in wp_posts
// meta_key = '_marked_as_spam'
//  meta_value = 1 if the post is marked as spam
// meta_key = '_sticky_post'
//  meta_value = 1 if the post is sticky


// wp_comments:
// | comment_ID | comment_post_ID | comment_author | comment_author_email | comment_author_url | comment_author_IP | 
// comment_date | comment_date_gmt | comment_content | comment_karma | comment_approved | comment_agent | comment_type |
// comment_parent | user_id |
//
// ->comment_type = 'cma_answer'
// ->comment_type = 'cma_comment'
// comment_ID,comment_post_ID,comment_author,comment_date,comment_content,comment_type,comment_parent
// comment_parent is the comment_ID of the parent answer

// wp_commentmeta:
// | meta_id | comment_id | meta_key | meta_value |

// ->meta_key = 'CMA_private_answer': if meta_value is 1, the answer is private
// ->meta_key = 'CMA_marked_as_spam': if meta_value is 1, the answer is marked as spam
// ->meta_key = 'CMA_answer_attachment': attachment. meta_value is the ID of the attachment in wp_posts

// PHPBB: category: CMA: nothing
// PHPBB: forum: CMA: "Forum"
// PHPBB: topic: CMA: thread
// PHPBB: post: CMA: answer or comment

// real filename: the filename to be displayed
// physical filename: the filename on disk

log_info("Extracting forum topics and posts:\n");

$forum_url = trim($forum_url, '/');

$global_post_count = 0;

// A category is a group of forums. CMA does not have categories.
function get_categories($db, $db_prefix) {
  global $CATEGORY_ID;
  global $FORUM_NAME;

  $categories = array();

  $categories[$CATEGORY_ID] = array(
    'title'  => $FORUM_NAME,
    'forums' => array(),
  );

  return $categories;
}

// CMA does not have multiple forums.
function get_forums_tree($db, $db_prefix) {
  global $FORUM_ID;
  global $CATEGORY_ID;

    $forums_tree = array();

    $forums_tree[$FORUM_ID] = array(
    'parent_id' => 0,
    'cat_id' => $CATEGORY_ID
    );

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

// $row is a row in wp_posts with type attachment
function get_attachment_object_from_db($row, &$all_topic_attachments) {

  global $more_debug;

  // if we have already used this attachment, use it
  $attachment = get_attachment_object_by_id($row['ID'], $all_topic_attachments);
  if ($attachment !== FALSE) {
	  return $attachment;
  }

  $physical_filename = $row['guid'];
  $display_name = $row['post_title'];
  
  if (empty($real_filename)) {
    // use physical filename
    $real_filename = basename($physical_filename);
  }
  
  $attachment = array(
    'id' => $row['ID'],
    'physical_filename' => $physical_filename,
    'display_name' => $display_name,
    'filetime' => $row['post_modified'],
  );
  $all_topic_attachments[] = $attachment;

  if ($more_debug) {
    log_info("a" . $row['ID']);
  }
  
  return $attachment;
}

// @return attachments array for this thread post
function get_attachments_thread($post_id, $db, $db_prefix, &$all_topic_attachments) {
  global $debug;
  global $more_debug;

  // the returned array
  $attachments = array();

  // now get all attachments which are associated with this post, but not found in text
  $res = $db->query(
    'SELECT post_id, meta_value FROM ' . $db_prefix .
    "postmeta WHERE meta_key = '_attachment' AND post_id = " . $post_id . ";");
  if ($res !== FALSE) {
    foreach ($res as $row) {
      $query = 'SELECT ID, post_title, guid, post_modified FROM ' . $db_prefix . "posts WHERE ID = " . $row['meta_value'] . ";";
      $res2 = $db->query($query);
            
        if ($res2 !== FALSE) {
          foreach ($res2 as $row2) {
      
          if (!get_attachment_object_by_id($row2['ID'], $all_topic_attachments)) {
              $attachment = get_attachment_object_from_db($row2, $all_topic_attachments);
              //log_info("--physical: " . $attachment['physical_filename'] . "\n");
              //log_info("--original: " . $attachment['real_filename'] . "\n\n");
              $attachments[] = $attachment;
          }
        }
      }
    }
  }
  
  if ($debug && !$more_debug) {
    $count = count($attachments);
    if ($count > 0) {
      log_info(", thread #$post_id with $count attachments");
    }
  }
  return $attachments;
}

// @return attachments array for this answer or comment
function get_attachments_answer_or_comments($comment_id, $type, $db, $db_prefix, &$all_topic_attachments) {
  global $debug;
  global $more_debug;

  // the returned array
  $attachments = array();

  // now get all attachments which are associated with this comment, but not found in text
  $query = 'SELECT comment_id, meta_value '
  . 'FROM ' . $db_prefix . "commentmeta "
  . "WHERE meta_key = 'CMA_answer_attachment' AND comment_id = " . $comment_id . ";";

  //log_info("\n   query: $query\n");
  $res = $db->query($query);
  if ($res !== FALSE) {
    foreach ($res as $row) {
      $res2 = $db->query(
            'SELECT ID, post_title, guid, post_modified FROM ' . $db_prefix .
            "posts WHERE ID = " . $row['meta_value'] . ";");
          if ($res2 !== FALSE) {
            foreach ($res2 as $row2) {
            if (!get_attachment_object_by_id($row2['ID'], $all_topic_attachments)) {
                $attachment = get_attachment_object_from_db($row2, $all_topic_attachments);
                $attachments[] = $attachment;
            }
          }
      }
    }
  }

  if ($debug && !$more_debug) {
    $count = count($attachments);
    if ($count > 0) {
      log_info(", $type #$comment_id with $count attachments");
    }
  }

  return $attachments;
}


// Retrieve answers and comments for all topics in $extracted['topics'].
function get_posts($db, $db_prefix, &$extracted) {
  global $debug;
  global $more_debug;
  global $global_post_count;

  // This variable will be returned later.
  $topics = $extracted['topics'];

  // For each previously identified CMA thread (aka topic), fetch the corresponding posts.
  log_info("Retrieving posts.\n");
  foreach ($topics as $tid => $topic) {
    if ($debug) {
      log_info("- thread #$tid");
    }

    $topics[$tid]['posts'] = array();
    $all_topic_attachments = array();

    // first, add the thread itself as question
    $attachments = get_attachments_thread($tid, $db, $db_prefix, $all_topic_attachments);

    $topics[$tid]['posts'][] = array(
      'username'   => $topic['author'],
      'post_text'  => $topic['content'],
      'post_time'  => $topic['time'],
      'post_id'  => 0,
      'attachments'  => $attachments,
      'type'    => 'question'
    );

    // remove redundant content
    $topics[$tid]['content'] = '';

    // then, get the answers and comments
    // note: we lose the connection of comments to answers, but we order by date, so should be fine. Somehow...

    // wp_comments:
    // | comment_ID | comment_post_ID | comment_author | comment_author_email | comment_author_url | comment_author_IP | 
    // comment_date | comment_date_gmt | comment_content | comment_karma | comment_approved | comment_agent | comment_type |
    // comment_parent | user_id |
    //
    // ->comment_type = 'cma_answer'
    // ->comment_type = 'cma_comment'
    // comment_ID,comment_post_ID,comment_author,comment_date,comment_content,comment_type,comment_parent
    // comment_parent is the comment_ID of the parent answer

    $res = $db->query(<<<SQL
SELECT
  comment_ID,
  comment_author,
  comment_date,
  comment_content,
  comment_type
FROM
  {$db_prefix}comments
WHERE
  comment_post_id={$tid}
  AND
  (comment_type='cma_answer' OR comment_type='cma_comment')
ORDER BY comment_date ASC
;
SQL
);

    foreach ($res as $row) {
      $id = $row['comment_ID'];
			
      $type = $row['comment_type'] == 'cma_answer' ? 'reply' : 'comment'; 

      // do not include private posts or spam
      // | meta_id | comment_id | meta_key | meta_value |
      $res_meta = $db->query(<<<SQL
      SELECT
          meta_id,
          meta_key
      FROM
          {$db_prefix}commentmeta
      WHERE
          (comment_id = $id)
          AND
          ((meta_key = 'CMA_private_answer' AND meta_value = 1)
            OR
            (meta_key = 'CMA_marked_as_spam' AND meta_value = 1))
      ;
      SQL
      );

      $found_spam_or_private = false;
      foreach ($res_meta as $row_meta) {
          if ($row_meta['meta_key'] == 'CMA_private_answer') {
              log_info(" -private $type comment_id=$id");
              $found_spam_or_private = true;
              break;
          }
          else if ($row_meta['meta_key'] == 'CMA_marked_as_spam') {
              log_info(" -spam $type comment_id=$id");
              $found_spam_or_private = true;
              break;
          }
      }
      if ($found_spam_or_private) {
        continue;
      }

      //extract attachments for this post
      $attachments = get_attachments_answer_or_comments($id, $type, $db, $db_prefix, $all_topic_attachments);

      $topics[$tid]['posts'][] = array(
        'username'   => $row['comment_author'],
        'post_text'  => $row['comment_content'],
        'post_time'  => $row['comment_date'],
        'bbcode_uid'  => 0,
        'post_id'  => $id,
        'attachments'  => $attachments,
        'type'    => $type
      );

      if ($more_debug) {
        if ($type == 'reply') {
          $typeCode = 'r';
        } else {
          $typeCode = 'c';
        }
        log_info($typeCode . $id);
      }
    }
    $count = count($topics[$tid]['posts']);
    $global_post_count += $count;

    if ($debug && !$more_debug) {
      log_info(": got $count posts.\n");
    }
    else if ($more_debug) {
      log_info("\n");
    }
  }  // each $topics
  return $topics;
}


// Get CMA threads (aka topics)
function get_forums_and_topics($db, $db_prefix, $extracted) {
  global $MAX_THREADS;
  global $FORUM_ID;
  global $CATEGORY_ID;
  global $FORUM_NAME;

  $topics = array();
  $forums = array();

  // we only have one forum and one category

  $categories = $extracted['categories'];
  $forums[$FORUM_ID] = array(
          'title'   => $FORUM_NAME,
          'nposts'  => 0,
          'ntopics' => 0,
          'topics'  => array()
        );

  $categories[$CATEGORY_ID]['forums'][] = $FORUM_ID;

  // | ID    | post_author | post_date | post_date_gmt       |
  // post_content| post_title | post_excerpt | post_status | comment_status | ping_status | post_password | post_name |
  //  to_ping | pinged | post_modified | post_modified_gmt | post_content_filtered | post_parent | guid | menu_order |
  // post_type  | post_mime_type  | comment_count |

  $res = $db->query(<<<SQL
  SELECT
      p.ID,
      p.post_author,
      u.display_name,
      p.post_date,
      p.post_modified,
      p.post_title,
      p.post_content
  FROM
      {$db_prefix}posts p
  LEFT JOIN {$db_prefix}users u ON p.post_author=u.ID
  WHERE
      p.post_type = 'cma_thread'
  ORDER BY p.post_date ASC
  ;
  SQL
  );

  if ($res === FALSE) {
    log_info("Could not fetch forum threads.\n");
    return array($categories, $forums, $topics);
  }

  $topic_type = 0;
  $topic_posts_approved = 0;

  foreach ($res as $row) {
    $tid = $row['ID'];
    $fid = $FORUM_ID;

    // do not include private posts or spam
    // | meta_id | post_id | meta_key | meta_value |
    $res_meta = $db->query(<<<SQL
    SELECT
        meta_id,
        meta_key
    FROM
        {$db_prefix}postmeta
    WHERE
        (post_id = $tid)
        AND
        ((meta_key = '_marked_as_spam' AND meta_value = 1)
          OR
          (meta_key = '_sticky_post' AND meta_value = 1))
    ;
    SQL
    );

    $found_spam_or_private = false;
    foreach ($res_meta as $row_meta) {
        if ($row_meta['meta_key'] == '_marked_as_spam') {
            log_info("Skipping spam thread with post_id=$tid\n");
            $found_spam_or_private = true;
            break;
        }
        else if ($row_meta['meta_key'] == '_sticky_post') {
            log_info("Found sticky thread with post_id=$tid\n");
            $topic_type = 1;
        }
    }
    if ($found_spam_or_private) {
      continue;
    }

    $topics[$tid] = array(
        'fid'     => $fid,
        'title'   => $row['post_title'],
        'type'    => $topic_type,
        'time'    => $row['post_date'],
        'replies' => $topic_posts_approved,
        'author'  => $row['display_name'],
        'content'  => $row['post_content'],
        //'lastmod' => gmdate('Y-m-d\TH:i:s\Z', $row['post_date']),
        'lastmod' => $row['post_modified'],
    );
    $forums[$fid]['topics'][] = $tid;

    if ($MAX_THREADS > 0 && count($topics) >= $MAX_THREADS) {
      log_info("Stopping after $MAX_THREADS threads.\n");
      break;
    }
  }  

  $count = count($forums[$FORUM_ID]['topics']);
  log_info("Found $count forum threads.\n");
  $forums[$FORUM_ID]['ntopics'] = $count;

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


setLogfile('cma_extract_log.txt');

$db_port_statement = '';
if (!empty($db_port)) {
  $db_port_statement = ";port=$db_port";
}

try {
  $db = new PDO(
    'mysql:host=' . $db_host . $db_port_statement . ';dbname=' . $db_name . ';charset=utf8mb4',
    $db_user, $db_pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
  
  log_info("Connected to database.\n");
  
  $extracted = array();
  $extracted['categories'] = get_categories($db, $db_prefix);
  $extracted['forums_tree'] = get_forums_tree($db, $db_prefix);
  list($extracted['categories'],
    $extracted['forums'],
    $extracted['topics']) = get_forums_and_topics($db, $db_prefix, $extracted);
  $extracted['topics'] = get_posts($db, $db_prefix, $extracted);
  unset($forums_and_topics);
  
  $extracted['forums'][$FORUM_ID]['nposts'] = $global_post_count;

  save_data_in_json($extracted, 'cma_forum-data.json');
  log_info("\n\nWrote " . count($extracted['topics']) . " topics to cma_forum-data.json\n");
} catch(PDOException $ex) {
  echo "An Error occured! " . $ex->getMessage();
  throw $ex;
}
