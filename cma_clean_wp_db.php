<?php

require_once('config.php');
require_once('cma_common.php');

// set to 0 to retrieve all threads
$MAX_THREADS = 0;
$testmode = true;
$debug = true;

// CMA tables:
// wp_cma_logs
// wp_cma_logs_meta

//
// list all attachment posts:
// select COUNT(*) from wp_posts where guid LIKE "%cma_attachments%";
// 1982

// list all thread posts:
// select COUNT(*) from wp_posts where post_type = 'cma_thread';
// 1050

// list all thread meta entries:
// select COUNT(*) from wp_postmeta, wp_posts P where post_id = P.ID and P.post_type = 'cma_thread';
// 10683
// select meta_id, post_id, meta_key, meta_value from wp_postmeta, wp_posts P where post_id = P.ID and P.post_type = 'cma_thread' LIMIT 100;

// list all answer+comments posts:
// select COUNT(*) from wp_comments where (comment_type = 'cma_answer') OR (comment_type = 'cma_comment');
// 6684

// list all comment meta:
// select COUNT(*) from wp_commentmeta where meta_key like 'CMA%';
// 5380

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

log_info("Removing CMA Q&A threads, answers, comments, and attachments.\n");

$attachmentCount = 0;
$attachmentFileCount = 0;

// $row is a row in wp_posts with type attachment
function del_attachment_object_from_db($row, $db, $db_prefix, $type) {
  global $debug;
  global $testmode;
  global $attachmentCount;
    global $attachmentFileCount;

  $id = $row['ID'];
  $physical_filename = $row['guid'];

  $err = false;

    // fix attachment path
    $physical_filename = str_replace("/home/bome", "/srv/bome", $physical_filename);

  // if file exists, delete it
  if (file_exists($physical_filename)) {
    if ($debug) {
      log_info("  - $type attachment #$id: " . basename($physical_filename) . "\n");
    }
    if (!$testmode) {
      unlink($physical_filename);
    }
    $attachmentFileCount++;
  } else {
    log_info("  - $type attachment #$id: file not found: " . basename($physical_filename) . "\n");
    $err = true;
  }
  // delete the attachment object from the database
  if ($testmode) {
      log_info("  - would delete $type attachment #$id\n");
      $attachmentCount++;
  } else {
      $res = $db->query('DELETE FROM ' . $db_prefix . "posts WHERE ID = " . $id . ";");
      if ($res === FALSE || $res->rowCount() == 0) {
          log_info("    - could not delete $type attachment #$id\n");
          $err = true;
      } else {
            $attachmentCount++;
      }
  }

  return !$err;
}

// @return attachments array for this thread post
function del_attachments_thread($post_id, $db, $db_prefix) {
  global $debug;

  // now get all attachments which are associated with this post
  $res = $db->query(
    'SELECT post_id, meta_value FROM ' . $db_prefix .
    "postmeta WHERE meta_key = '_attachment' AND post_id = " . $post_id . ";");

  $count = 0;
  if ($res !== FALSE) {
    foreach ($res as $row) {
        $attachID = $row['meta_value'];
        $query = 'SELECT ID, guid FROM ' . $db_prefix . "posts WHERE ID = $attachID;";
        $res2 = $db->query($query);
        if ($res2 !== FALSE) {
            foreach ($res2 as $row2) {
                if (del_attachment_object_from_db($row2, $db, $db_prefix, "thread")) {
                    $count++;
                }
            }
        }
    }
  }
  
  if (!$debug) {
    if ($count > 0) {
      log_info(" - removed $count attachments\n");
    }
  }
}

// @return attachments array for this answer or comment
function del_attachments_answer_or_comments($comment_id, $type, $db, $db_prefix) {
  global $debug;

  // now get all attachments which are associated with this comment, but not found in text
  $query = 'SELECT comment_id, meta_value '
  . 'FROM ' . $db_prefix . "commentmeta "
  . "WHERE meta_key = 'CMA_answer_attachment' AND comment_id = " . $comment_id . ";";

  $res = $db->query($query);
  $count = 0;
  if ($res !== FALSE) {
    foreach ($res as $row) {
      $attachID = $row['meta_value'];
      $res2 = $db->query('SELECT ID, guid FROM ' . $db_prefix . "posts WHERE ID = $attachID;");
      if ($res2 !== FALSE) {
        foreach ($res2 as $row2) {
          if (del_attachment_object_from_db($row2, $db, $db_prefix, $type)) {
            $count++;
          }
        }
      }
    }
  }

  if (!$debug) {
    if ($count > 0) {
      log_info("  - removed $count $type attachments\n");
    }
  }
}


// Retrieve answers and comments for all topic id's in $topics.
function del_posts($db, $db_prefix, $topics) {
  global $debug;
  global $testmode;

  $threadCount = 0;

  // For each previously identified CMA thread (aka topic), del the corresponding posts.
  foreach ($topics as $tid) {
    if ($debug) {
      log_info("Thread #$tid\n");
    }

    // first, del attachments for the thread itself
    del_attachments_thread($tid, $db, $db_prefix);

    $res = $db->query(<<<SQL
SELECT
  comment_ID,
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

    $count = 0;
    foreach ($res as $row) {
      $id = $row['comment_ID'];
			
      $type = $row['comment_type'] == 'cma_answer' ? 'answer' : 'comment'; 

      if ($debug) {
        log_info("  - $type #$id\n");
      }
  
      //1. DELETE attachments for this comment
      del_attachments_answer_or_comments($id, $type, $db, $db_prefix);

      //2. DELETE comment meta
      if ($testmode) {
        $res_meta = $db->query(<<<SQL
        SELECT
            COUNT(*) AS count
        FROM
            {$db_prefix}commentmeta
        WHERE
            (comment_id = $id)
        ;
        SQL
        );
        foreach ($res_meta as $rowmeta) {
          $count = $rowmeta['count'];
          if ($count > 0) {
                if ($debug) {
                    log_info("    - would delete $count meta entries\n");
                }
          }
          break;
        }

        $count++;

      } else {
        $res_meta = $db->query(<<<SQL
        DELETE FROM
            {$db_prefix}commentmeta
        WHERE
            (comment_id = $id)
        ;
        SQL
        );
        // log the number of affected rows
        if ($res_meta !== FALSE) {
            if ($debug && $res_meta->rowCount() > 0) {
                log_info("  - deleted " . $res_meta->rowCount() . " meta entries\n");
            }
        }

        //3. DELETE the comment itself
        $res = $db->query(<<<SQL
        DELETE FROM
            {$db_prefix}comments
        WHERE
            comment_ID = $id
        ;
        SQL
        );
        if ($res === FALSE || $res->rowCount() == 0) {
            log_info("    - could not delete $type #$id\n");
        } else {
            $count++;
        }
      }

    } // comment or answer

    if ($debug) {
      log_info("  -> deleted $count comments and answers from thread #$tid.\n");
    }

    //4. DELETE the thread itself
    if ($testmode) {
      log_info("-> would delete thread #$tid.\n");
      $threadCount++;
    } else {
      $res = $db->query(<<<SQL
      DELETE FROM
          {$db_prefix}posts
      WHERE
          ID = $tid
      ;
      SQL
      );
        if ($res === FALSE || $res->rowCount() == 0) {
            log_info("  - could not delete thread #$tid\n");
        } else {
            $threadCount++;
        }
    }

    //5. DELETE thread meta
    if ($testmode) {
      $res_meta = $db->query(<<<SQL
      SELECT
          COUNT(*) AS count
      FROM
          {$db_prefix}postmeta
      WHERE
          (post_id = $tid)
      ;
      SQL
      );
      foreach ($res_meta as $rowmeta) {
        $count = $rowmeta['count'];
        if ($count > 0) {
          if ($debug) {
              log_info(" - would delete $count thread meta entries\n");
          }
        }
      }
    } else {
      $res_meta = $db->query(<<<SQL
      DELETE FROM
          {$db_prefix}postmeta
      WHERE
          (post_id = $tid)
      ;
      SQL
      );
      // log the number of affected rows
      if ($res_meta !== FALSE) {
          if ($debug && $res_meta->rowCount() > 0) {
              log_info(" - deleted " . $res_meta->rowCount() . " thread meta entries\n");
          }
      }
    }
  }  // each topic

    if ($debug) {
        log_info("- deleted $threadCount threads.\n");
    }
  return $topics;
}


// Get CMA threads (aka topics)
function get_topic_ids($db, $db_prefix) {
  global $MAX_THREADS;

  $topics = array();

  $res = $db->query(<<<SQL
  SELECT
      ID
  FROM
      {$db_prefix}posts
  WHERE
      post_type = 'cma_thread'
  ;
  SQL
  );

  if ($res === FALSE) {
    log_info("Could not fetch forum threads.\n");
    return $topics;
  }

  foreach ($res as $row) {
    $tid = $row['ID'];
    $topics[] = $tid;

    if ($MAX_THREADS > 0 && count($topics) >= $MAX_THREADS) {
      log_info("Stopping after $MAX_THREADS threads.\n");
      break;
    }
  }

  $count = count($topics);
  log_info("Found $count forum threads.\n");

  return $topics;
}

setLogfile('cma_clean_wp_db_log.txt');

$db_port_statement = '';
if (!empty($db_port)) {
  $db_port_statement = ";port=$db_port";
}

try {
  $db = new PDO(
    'mysql:host=' . $db_host . $db_port_statement . ';dbname=' . $db_name . ';charset=utf8mb4',
    $db_user, $db_pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
  
  log_info("Connected to database.\n");
  
  $topics = get_topic_ids($db, $db_prefix);
  del_posts($db, $db_prefix, $topics);

  if (false && $MAX_THREADS == 0) {
        if (!$testmode) {
        // drop table wp_cma_logs
        $res = $db->query('DROP TABLE ' . $db_prefix . 'cma_logs;');
        if ($res === FALSE) {
        log_info("Could not drop table " . $db_prefix . "cma_logs.\n");
        } else {
        log_info("Dropped table " . $db_prefix . "cma_logs.\n");
        }
    } else {
        log_info("Would drop table " . $db_prefix . "cma_logs.\n");
    }
  }

  if (false && $MAX_THREADS == 0) {
    if (!$testmode) {
        // drop table wp_cma_logs_meta
        $res = $db->query('DROP TABLE ' . $db_prefix . 'cma_logs_meta;');
        if ($res === FALSE) {
          log_info("Could not drop table " . $db_prefix . "cma_logs_meta.\n");
        } else {
          log_info("Dropped table " . $db_prefix . "cma_logs_meta.\n");
        }
    } else {
        log_info("Would drop table " . $db_prefix . "cma_logs_meta.\n");
    }
  }

  log_info("Deleted $attachmentCount attachments and $attachmentFileCount attached files.\n");

  log_info("Done\n");

  log_info("Now drop tables wp_cma_logs and wp_cma_logs_meta manually.\n");
  log_info("Also, remove all attachments like this:\n");
  log_info("  rm -rf /srv/bome/htdocs/wp-content/uploads/cma_attachments\n");
  log_info(" SQL: DELETE from wp_posts where guid LIKE '%cma_attachments%';\n");
} catch(PDOException $ex) {
  echo "An Error occured! " . $ex->getMessage();
  throw $ex;
}
