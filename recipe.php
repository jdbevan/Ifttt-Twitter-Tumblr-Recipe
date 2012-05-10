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
function parse_tweet($content, $tweet_id = "") {

	$full_urls = array();
	
	// Resolve hyperlinks to originals (for image processing etc)
	// https://dev.twitter.com/docs/api/1/get/statuses/show/%3Aid
	if ($tweet_id !== "" and preg_match("/^[0-9]+$/", $tweet_id)) {
		$tweet_info = simplexml_load_file("https://api.twitter.com/1/statuses/show.xml?id=$tweet_id&include_entities=true");
		
		foreach ($tweet_info->entities->urls as $url_data) {
		
			$short_url = (string)$url_data->url;
			$full_url = (string)$url_data->expanded_url;
			
			if (preg_match("/^http:\/\/(bit\.ly|j\.mp)/", $full_url) and function_exists("bitly_v3_expand")) {
				$bitly_response = bitly_v3_expand($full_url);
				if (isset($bitly_response['long_url'])) {
					$full_url = $bitly_response['long_url']; 
				}
			}
		
			$full_urls[] = array($short_url, $long_url);
		}
	}
	
	// Autolink hyperlinks
	$hyperlinked_content = preg_replace("/((f|ht)tps?:\/\/([^\s]+))/", "<a href=\"$1\">$3</a>", $content);
	
	// Used for removing hashtags
	$contains_hyperlink = ($content !== $hyperlinked_content);

	// Check for hashtags
	// Prev non-working regex: /(?![\s\.,;:\?!])#[a-zA-Z][a-zA-Z0-9_]*/
	$contains_hashtags = preg_match_all("/(^|[^a-zA-Z0-9_])#([a-zA-Z][a-zA-Z0-9_]*)/", $hyperlinked_content, $hashtags);
	
	if ($contains_hashtags > 0) {
		/* 3 matching arrays:
		 * 0 => full match string
		 * 1 => start of string/non-word char
		 * 2 => hashtag contents
		 */
		// Remove tags? What if it's like: "I love jam and #Google #fail #drink";
		/*
		 * If hyperlink followed by just tags, remove tags
		 * If no hyperlink, remove tags after last punctuation: .!?
		 */
		foreach($hashtags[2] as $hashtag) {
		
			if ($contains_hyperlink) {
			
				$last_hyperlink_end_pos = strrpos($hyperlinked_content, "</a>") + 4;

				if (($replace_offset = strrpos($hyperlinked_content, "#$hashtag", $last_hyperlink_end_pos)) !== false) {
					$hyperlinked_content = str_replace("#$hashtag", "", $hyperlinked_content, $replace_offset);
				}
			
			} else {
			
				$hyperlinked_content = preg_replace("/([\.\?!])\s*(#[a-zA-Z0-9_]+\s*)+$/", "$1", $hyperlinked_content);
			
			}
		}
	}

	// Autolink mentions
	$mention_linked_content = preg_replace("/(^|[^a-zA-Z0-9_])@([a-zA-Z0-9_]+)/", "$1<a href=\"http://twitter.com/$2\">@$2</a>", $hyperlinked_content);
	$hashtag_linked_content = preg_replace("/(^|[^a-zA-Z0-9_])(#[a-zA-Z][a-zA-Z0-9_]*)/", "$1<a href=\"http://twitter.com/$2\">$2</a>", $mention_linked_content);
	
	return array("hashtags" => $hashtags[2],
				"html" => trim($hashtag_linked_content),
				"has_links" => $contains_hyperlink,
				"has_mentions" => ($hashtag_linked_content !== $hyperlinked_content));
}


/*
$tweets = array(
	"@alijadams Sup g? #win",
	"@lol thanks for signing up with http://siteversion.com - we'll let you know when we're ready to backup your website!",
	"#win #loose #tweet",
	"Nothing doing here",
	"@atta @boy @james",
	"I love jam and #Google #fail #drink",
	"http://jonbevan.me.uk",
	"Are you a #Freelancer? In #Salford? Tomorrow is @SalfordJelly Day!",
	"Women in Architecture is out! http://bit.ly/HtrO6S ▸ Top stories today via @pcmcreative @j_a_fitzgerald @lauracinnamond @younglondon",
	"I know we took our sweet time but as soon as I get back from outer space, we can finish this thing once and for all http://pic.twitter.com/86NNNKPP",
	"Join @sdtuck and @joyent for a Champagne reception, tomorrow, 4-25, The Next Web Conference, Amsterdam. http://thenextweb.com/conference/agenda #TNWConference",
	"We're glad @wdtuts are on the ball - are you? http://webdesign.tutsplus.com/articles/workflow/backing-up-your-website-the-ultimate-guide/ #website #backup"
);
foreach($tweets as $content) {
	$parsed_response = parse_tweet($content);

	echo implode(", ", $parsed_response['hashtags']), "\n";
	echo "{$parsed_response['html']}\n";
	if (preg_match("/^<a href=\"[^\s]+\">[^\s]+<\/a>$/", $parsed_response['html'])) {
		echo "LINK format";
	} else if (!$parsed_response['has_links'] and !$parsed_response['has_mentions']) {
		echo "QUOTE format";
	} else {
		echo "TEXT format";
	}
	echo "\n\n";
}
exit;
*/
 
$twitter_username = "jdbevan";
$twitter_rss_api = "http://api.twitter.com/1/statuses/user_timeline.rss?screen_name=$twitter_username";

include 'bitly.php';

include 'oauth_config.php';
include 'tumblr-api/autoloader.php';
$tumblr_params = array("type" => "text",
						"tags" => "Twitter");


$rss = simplexml_load_file($twitter_rss_api);
if ($rss !== false) {
	
	$tweets = $rss->channel->item;
	foreach ($tweets as $tweet) {
		
		$content = (string)$tweet->title;
		$pubDate = (string)$tweet->pubDate;
		$link = (string)$tweet->link;
		$last_slash = strrpos($link, "/");
		$tweet_id = substr($link, $last_slash+1);
		
		$content = trim(str_replace("$twitter_username: ", "", $content));
		
		// Ignore replies and retweets
		if (!preg_match("/^(RT|\.?@)/", $content) and gmdate("Y-m-d H:i:s", strtotime($pubDate)) < gmdate("Y-m-d H:i:s", strtotime("-15 minutes"))) {
			
			$parsed_response = parse_tweet($content, $tweet_id);
			if (count($parsed_response['hashtags']) > 0) echo "TAGS: ", implode(", ", $parsed_response['hashtags']), "\n";
			echo "{$parsed_response['html']}\n";
			
			$tumblr_params['tags'] .= ", " . implode(", ", $parsed_response['hashtags']);
			if (preg_match("/^<a href=\"[^\s]+\">[^\s]+<\/a>$/", $parsed_response['html'])) {
				//echo "LINK format";
				
				/*
				 * Links can have a title... surely then tweets with text and then a link at
				 * the end (before hashtags) should be titled links, not text posts... text would have multiple
				 * hyperlinks or mentions or hashtags
				 */
				$tumblr_params['type'] = "link";
				$tumblr_params['link'] = '';
			} else if (!$parsed_response['has_links'] and !$parsed_response['has_mentions']) {
				//echo "QUOTE format";
				$tumblr_params['type'] = "quote";
				$tumblr_params['source'] = $link;
			} else {
				//echo "TEXT format";
				$tumblr_params['type'] = "text";
				$tumblr_params['body'] = $parsed_response['html'];
			}
			
			echo "------\n";
		}
	}	
}

?>
