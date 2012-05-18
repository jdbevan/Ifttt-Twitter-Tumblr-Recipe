<?php
error_reporting(E_ALL);
ini_set("display_errors", true);
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
		
		foreach ($tweet_info->entities->urls->url as $url_data) {
		
			$short_url = (string)$url_data->url;
			$long_url = (string)$url_data->expanded_url;
			
			if (preg_match("/^http:\/\/(bit\.ly|j\.mp)/", $long_url) and function_exists("bitly_v3_expand")) {
				$bitly_response = bitly_v3_expand($long_url);
				if (isset($bitly_response[0]) and isset($bitly_response[0]['long_url'])) {
					$long_url = $bitly_response[0]['long_url']; 
				}
			}
		
			//echo $short_url, " => ", $long_url, "\n";
			$full_urls[$short_url] = $long_url;
		}
	}
	
	// Autolink hyperlinks
	$hyperlinked_content = preg_replace("/((f|ht)tps?:\/\/([^\s]+))/", "<a href=\"$1\">$3</a>", $content);

	// Swap in long URLs if there are any
	if (preg_match("/((f|ht)tps?:\/\/([^\"]+))/", $hyperlinked_content, $url_matches)) {
		foreach($url_matches as $url) {
			if (isset($full_urls[$url])) {
				// Replace the href contents
				$hyperlinked_content = str_replace($url, $full_urls[$url], $hyperlinked_content);
				
				// Replace the a tag contents
				$hyperlinked_content = str_replace(
											str_replace(
												array("https://", "http://", "ftps://", "ftp://"), "", $url
											),
											str_replace(
												array("https://", "http://", "ftps://", "ftp://"), "", $full_urls[$url]
											),
											$hyperlinked_content
										);
			}
		}
	}

	
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
Tumblr\API::configure(BASE_HOSTNAME, API_KEY, API_SECRET);
Tumblr\API::authenticate(null, null); //TOKEN, TOKEN_SECRET);

echo "starting...\n";

$rss = simplexml_load_file($twitter_rss_api);
echo "got twitter...\n";
if ($rss !== false) {
	
	$tweets = $rss->channel->item;
	foreach ($tweets as $tweet) {
		
		$tumblr_params = array("type" => "text",
								"tags" => "Twitter");

		$content = (string)$tweet->title;
		$pubDate = (string)$tweet->pubDate;
		$link = (string)$tweet->link;
		$last_slash = strrpos($link, "/");
		$tweet_id = substr($link, $last_slash+1);
		
		$content = trim(str_replace("$twitter_username: ", "", $content));
		
		// Ignore replies and retweets
		if (!preg_match("/^(RT|\.?@)/", $content) and gmdate("Y-m-d H:i:s", strtotime($pubDate)) > gmdate("Y-m-d H:i:s", strtotime("-30 minutes"))) {
			
			//echo $tweet_id, "\n";
			$parsed_response = parse_tweet($content, $tweet_id);
			if (count($parsed_response['hashtags']) > 0) echo "TAGS: ", implode(", ", $parsed_response['hashtags']), "\n";
			echo "{$parsed_response['html']}\n";
			
			array_push($parsed_response['hashtags'], $tumblr_params['tags']);
			$tumblr_params['tags'] = implode(", ", $parsed_response['hashtags']);

			if (preg_match("/^<a href=\"([^\s]+)\">([^\s]+)<\/a>$/", $parsed_response['html'], $link_match)) {
				$tumblr_params['type'] = "link";
				$tumblr_params['url'] = $link_match[1];
				$tumblr_params['title'] = $link_match[2];
				
			} else if (preg_match("/^([^<]+)<a href=\"([^\s]+)\">[^\s]+<\/a>$/", $parsed_response['html'], $link_match)) {
				/*
				 * Links can have a title... surely then tweets with text and then a link at
				 * the end (before hashtags) should be titled links, not text posts... text would have multiple
				 * hyperlinks or mentions or hashtags
				 */
				$tumblr_params['type'] = "link";
				$tumblr_params['url'] = $link_match[2];
				$tumblr_params['title'] = trim($link_match[1]);
				
				$cls = new Tumblr\Post\Link();
				$cls->tags = $parsed_response['hashtags'];
				$cls->date = "now";
				$cls->title = trim($link_match[1]);
				$cls->url = $link_match[2];
				$s = $cls->serialize();
				var_export($s);
				Tumblr\API::submitPost($cls);
				exit;

			} else if (!$parsed_response['has_links'] and !$parsed_response['has_mentions']) {
				//echo "QUOTE format";
				$tumblr_params['type'] = "quote";
				$tumblr_params['source'] = $link;
			} else {
				//echo "TEXT format";
				$tumblr_params['type'] = "text";
				$tumblr_params['body'] = $parsed_response['html'];
			}
			
			var_export($tumblr_params);			
			
			echo "\n------\n";
			sleep(10);
		}
	}	
}

?>
