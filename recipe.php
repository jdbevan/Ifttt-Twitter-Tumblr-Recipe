<?php

/*
 * Essentially a cron job script to be run every 15 minutes
 *
 * 1. Connect to Twitter API using: http://api.twitter.com/1/statuses/user_timeline.rss?screen_name=twitter
 *    replacing twitter with users screen name
 *
 * 2. Store tweets from last 15 minutes
 *
 * 3. Parse tweets as follows:
 *    (http://blog.jdbevan.com/post/21323233154/so-i-use-ifttt-to-mirror-my-tweets-onto-tumblr)
 *
 *		Match & store hashtags.
 *		Remove hashtags at the end of tweet (unless whole tweet is hashtags)
 *
 *		Autolink hyperlinks /^https?\:\/\/(a-zA-Z0-9_\?\#!\/-)+$/
 *
 *		If just one hyperlink and optional hashtags and no other text, then just a link post (perhaps get target title?)
 *		If text and hyperlinks, then text format post
 *		If just text, then quote format post
 *		If a hyperlink resolves to picture on Twitter, embed/link to that image?
 */
$twitter_username = "jdbevan";
$twitter_rss_api = "http://api.twitter.com/1/statuses/user_timeline.rss?screen_name=$twitter_username";

$rss = simplexml_load_file($twitter_rss_api);
if ($rss !== false) {
	
	$tweets = $rss->channel->item;
	foreach ($tweets as $tweet) {
		
		$content = (string)$tweet->title;
		$pubDate = (string)$tweet->pubDate;
		$link = (string)$tweet->link;
		
		$content = str_replace("$twitter_username: ", "", $content);
		
		if (gmdate("Y-m-d H:i:s", strtotime($pubDate)) < gmdate("Y-m-d H:i:s", strtotime("-15 minutes"))) {
			
			//regex 1: /(#[a-zA-Z][a-zA-Z0-9_]*)/
			$contains_hashtags = preg_match_all("/(?![\s\.,;:\?!])#[a-zA-Z][a-zA-Z0-9_]*/", $content, $hashtags);
			
			// Remove tags? What if it's like: "I love jam and #Google #fail #drink";
			
			$hyperlinked_content = preg_replace("/((f|ht)tps?:\/\/[^\s]+)/", "<a href='$1'>$1</a>", $content);
			
			
		}
	}	
}

?>
