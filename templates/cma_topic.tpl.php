<?php

global $topics_append_html;

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"
"http://www.w3.org/TR/html4/loose.dtd">
<html><head>
<meta charset="utf-8" />
<meta http-equiv="X-UA-Compatible" content="IE=edge"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title><?=$title;?> - <?=$forum_name;?></title>
<link rel="stylesheet" type="text/css" href="../../../topic.css"/>
</head><body>

<div class="breadcrumb">
	<p><a href="../../../"><?=$forum_name;?></a> &raquo; <a href="../../"><?=$forum_title;?></a></p>
</div>

<h1><?=$title;?></h1>

<!-- Original URL of this topic:
	<a href="<?=$url;?>"><?=$url;?></a>
-->

<?php

	foreach ($posts as $post) {

		$user = $post['username'];
		$html = $post['post_text'];
		$time = $post['post_time'];
		if ($time instanceof int) {
			$dt = date('Y-m-d H:i:s', $time);
		} else {
			$dt = $time;
		}
		$post_id = $post['post_id'];
        $type = $post['type'];
        if ($type != 'comment') {
            $type = '';
        }
        $attachments = $post['attachments'];
?>
<div class="post" id="p<?=$post_id?>">
	<div class="info">
		<p class="poster"><?=$user;?></p>
		<p class="dt"><?=$dt;?></p>
		<p class="type"><?=$type;?></p>
	</div>
	<div class="msg">
	<!-- BEGIN MESSAGE -->
	<?=$html;?>
	<!-- END MESSAGE -->
	</div>
    <?php
        if (count($attachments) > 0) {
            echo "<hr/><p><strong>Attachments:</strong></p>\n";
            echo '<div class="attachments">';
            foreach ($attachments as $attachment) {
                $url = basename($attachment['physical_filename']);
                $display_name = $attachment['display_name'];

                echo '<a href="' . $url . '">' . $display_name . '</a><br/>';
            }
            echo "</div>\n";
        }
    ?>
</div>
<?php

	}

?>
<div class="breadcrumb">
	<p><a href="../../../"><?=$forum_name;?></a> &raquo; <a href="../../"><?=$forum_title;?></a></p>
</div>
<footer>
<?=$topics_append_html?>
</footer>
<?php
include('google_analytics.php');
?>
</body></html>
