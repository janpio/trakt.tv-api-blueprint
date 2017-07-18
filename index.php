<?php
$content = file_get_contents('trakt.apib');

// Parse "Markdown" to HTML
include('Parsedown.php');
$Parsedown = new Parsedown();
$text = $Parsedown->text($content); 

// Parse HTML to DOM
$doc = new DOMDocument();
@$doc->loadHTML($text);

// Transform DOM to array of tags and values
function showDOMNode(DOMNode $domNode) {
	global $array;
    foreach ($domNode->childNodes as $node)
    {
        if($node->nodeName != 'html' && $node->nodeName != 'body') {
			//print $node->nodeName.':'.$node->nodeValue;
			$array[] = array('node' => $node->nodeName, 'value' => $node->nodeValue);
		}
		
        if($node->hasChildNodes()) {
            showDOMNode($node);
        }
    }
}
$array = array();
showDOMNode($doc, $array);
#echo "<pre>"; print_r($array);
#exit;

// filter array of all "nodes" to entries we are interested in (h1 = Group + h2,h3,h4,p)
$results = array();
$inside_wanted_h1 = false;
$inside_h2 = false;
$inside_h3 = false;
$inside_h4 = false;
$take_next_p = false;
foreach($array as $element) {
	// h1 with ("Group" and !"Group Authentication")
	if($element['node'] == 'h1') {
		if(
			substr($element['value'], 0, 5) == "Group" 
			&& substr($element['value'], 0, 20) != "Group Authentication"
		) {
			$results[] = $element;
			$inside_wanted_h1 = true;
			$inside_h2 = false;
			$inside_h3 = false;
			$inside_h4 = false;
		} else {
			$inside_wanted_h1 = false;
			$inside_h2 = false;
			$inside_h3 = false;
			$inside_h4 = false;
		}
	}
	// h2
	if($element['node'] == 'h2') {
		// after h1
		if($inside_wanted_h1) {
			$results[] = $element;
			$inside_h2 = true;
			$inside_h3 = false;
			$inside_h4 = false;
		} else {
			$inside_h2 = false;
			$inside_h3 = false;
			$inside_h4 = false;
		}
	}
	// h3
	if($element['node'] == 'h3') {
		// after h1 + h2
		if($inside_wanted_h1 && $inside_h2) {
			$results[] = $element;
			$inside_h3 = true;
			$inside_h4 = false;
		} else {
			$inside_h3 = false;
			$inside_h4 = false;
		}
	}
	// h4
	if($element['node'] == 'h4') {
		// after h1 + h2 + h3
		if($inside_wanted_h1 && $inside_h2 && $inside_h3) {
			// if value one of (Extended Info|OAuth|Pagination)
			if(
				strpos($element['value'], 'Extended Info') !== false 
				|| strpos($element['value'], 'OAuth') !== false
				|| strpos($element['value'], 'Pagination') !== false
			) {
				$results[] = $element;
				$inside_h4 = true;
			} else {
				$inside_h3 = false;
				$inside_h4 = false;
				continue; // skip further checks
			}
		} else {
			$inside_h4 = false;
			continue;
		}
	}	
	// after h1 + h2 + (h3|h4)
	if( $inside_wanted_h1
		&& $inside_h2
		&& (
			$element['node'] == 'h3' 
			|| $element['node'] == 'h4'
		)
	) {
		$take_next_p = true;
	}
	// p that should be taken
	if($element['node'] == 'p' && $take_next_p) {
		$take_next_p = false;
		$results[] = $element;
	}
}
#echo "<pre>";
#print_r($results);
#exit;

/*
foreach($results as $element) {
	echo "<".$element['node'].">".$element['value']."</".$element['node']."><br>";
}
*/

// transform array([node,value]) into array([h1, h2, h3, h4, p])
$data = array();
$results2 = array();
foreach($results as $element) {
	// reset $data if we go to next h1
	if($element['node'] == 'h1' && $data['h1'] != $element['value']) {
		$data = array();
	};
	$data[$element['node']] = $element['value'];
	// remove h4+p from $data if p was added
	if($element['node'] == 'p') {
		$results2[] = $data;
		unset($data['p'], $data['h4']);
	}
}
#echo "<pre>";
#print_r($results2);
#print_r($results2);
#exit;

// mangle data
foreach($results2 as $k => $v) {
	// h1
	$results2[$k]['h1'] = str_replace('Group ', '', $v['h1']);
	// h2
	$p = explode(" [", $v['h2']);
	$results2[$k]['h2'] = $p[0];
	$results2[$k]['endpoint'] = str_replace(']', '', $p[1]);
	// h3
	$p = explode(" [", $v['h3']);
	$results2[$k]['h3'] = $p[0];
	$results2[$k]['method'] = str_replace(']', '', $p[1]);
	// h4
	$h4 = $v['h4'];
	if($h4) {
		$emoji = array('oauth' => false, 'pagination' => false, 'extended' => false, 'filters' => false);
		if(strpos($h4, 'Extended Info') !== false) { $emoji['extended'] = true; $h4 = str_replace("Extended Info", '', $h4); }
		if(strpos($h4, 'OAuth Required') !== false) { $emoji['oauth'] = 'required'; $h4 = str_replace("OAuth Required", '', $h4); }
		if(strpos($h4, 'OAuth Optional') !== false) { $emoji['oauth'] = 'optional'; $h4 = str_replace("OAuth Optional", '', $h4); }
		if(strpos($h4, 'Pagination Optional') !== false) { $emoji['pagination'] = 'optional'; $h4 = str_replace("Pagination Optional", '', $h4); }
		if(strpos($h4, 'Pagination') !== false) { $emoji['pagination'] = true; $h4 = str_replace("Pagination", '', $h4); }
		if(strpos($h4, 'Filters') !== false) { $emoji['filters'] = true; $h4 = str_replace("Filters", '', $h4); }
		$results2[$k]['h4'] = $h4;
		$results2[$k]['emoji'] = $emoji;
		unset($results2[$k]['h4']);
	}
	// p
	$p = explode(". ", $v['p']);
	$results2[$k]['intro'] = $p[0];
	if($p[1]) $results2[$k]['intro'] .= '.'; // add dot at the end if it was a split
	
}
#echo "<pre>";
#print_r($results2);
#exit;

// transform into multi-dimensional array
$results3 = array('data' => array(), 'user' => array(), 'master-data' => array());
foreach($results2 as $_data) {
	$details = array('endpoint' => $_data['endpoint'], 'method' => $_data['method'], 'emoji' => $_data['emoji'], 'intro' => $_data['intro'], 'p' => $_data['p']);
	if(
		$_data['h1'] == 'Certifications' 
		|| $_data['h1'] == 'Genres' 
		|| $_data['h1'] == 'Networks'
	) {
		$results3['master-data'][$_data['h1']][$_data['h2']][$_data['h3']] = $details;
	}
	else if($_data['emoji']['oauth'] == 'required') {
		$results3['user'][$_data['h1']][$_data['h2']][$_data['h3']] = $details;
		// empty n/a entry 
		if(!$results3['data'][$_data['h1']]) $results3['data'][$_data['h1']] = array();
	}
	else if($_data['emoji']['oauth'] == 'optional') {
		$results3['user'][$_data['h1']][$_data['h2']][$_data['h3']] = $details;
		// $results3['data'][$_data['h1']][$_data['h2']][$_data['h3']] = $details;
	}
	else {
		$results3['data'][$_data['h1']][$_data['h2']][$_data['h3']] = $details;
		// emtpy n/a entry
		if(!$results3['user'][$_data['h1']]) $results3['user'][$_data['h1']] = array();
	}
}
#echo "<pre>";
#print_r($results3);
#exit;

// define areas
$areas = array(
	'data' => array('Data Endpoints', 'These return the nitty-gritty of Trakt: Lots of data about shows and movies and all the things related to them:'),
	'user' => array('User Endpoints', 'If it matters which user is logged in via OAuth, these endpoints are collected here:'),
	'master-data' => array('Master Data Endpoints', 'These endpoints offer mostly static data that should be retrieved once and then be cached for further use:')
);

// find and mark duplicates
# pre-sort
$nameintro = array();
foreach($areas as $key => $area) {
	foreach($results3[$key] as $group => $things) {
		foreach($things as $thing => $calls) {
			foreach($calls as $name => $details) {
				$nameintro[$name."|".$details['intro']][] = array($key, $group, $thing, $name, $details);
			}
		}
	}
}
# only get duplicates
$duplicates = array();
foreach($nameintro as $foo => $bar) {
	if(count($bar) > 1) {
		$duplicates[] = $bar;
	}
}
#print_r($duplicates);
#exit;
# mark duplicates
foreach($duplicates as $foo) {
	$one = $foo[0];
	$two = $foo[1];
	/*
	print_r($results3[$one[0]][$one[1]][$one[2]][$one[3]]);
	echo "<hr>";
	print_r($two[4]);
	exit;
	*/
	$results3[$one[0]][$one[1]][$one[2]][$one[3]]['duplicate'] = $two;
	$results3[$two[0]][$two[1]][$two[2]][$two[3]]['duplicate'] = $one;
}


// output html
function getEmoji($emoji) {
	if(!$emoji) return;
	#print_r($emoji);
	$definition = array(
		'pagination' => array('1' => '📄', 'optional' => '📄?'), 
		'extended' => array('1' => '✨'),
		'filters' => array('1' => '🎚'),
		'oauth' => array('required' => '🔒!', 'optional' => '🔒?')
	);
	$output = array();
	
	foreach($emoji as $key => $value) {
		if($definition[$key][$value])
			$output[] = $definition[$key][$value];
	}
	return implode($output, ' ');
}
function getClassstring($class) {
	$class = strtolower($class);
	
	// special case
	if($class == 'add to history') $class = 'add-history history';
	
	// preparations
	// => remove
	$class = str_replace(array(' progress', 'get ', 'add to ', 'remove from ', 'add ', 'remove ', ' items'), '', $class);
	// => shows
	$class = str_replace(array('all shows', 'all new shows', 'all season premieres', 'my shows', 'my new shows', 'my season premieres'), 'shows', $class);
	// => movies
	$class = str_replace(array('all movies', 'all dvd', 'my movies', 'my dvd'), 'movies', $class);
	
	// additions
	if($class == 'seasons') $class = 'shows '.$class;
	if($class == 'episodes') $class = 'shows seasons '.$class;
	if($class == 'sync') $class = 'users '.$class;
	if($class == 'followers') $class = 'follow '.$class;
	if($class == 'following') $class = 'follow '.$class;
	if($class == 'friends') $class = 'follow '.$class;
	if($class == 'scrobble') $class = 'watch '.$class;
	if($class == 'history') $class = 'watched '.$class;
	
	if($class == 'movie') $class = 'movies '.$class;
	if($class == 'show') $class = 'shows '.$class;
	if($class == 'season') $class = 'shows seasons '.$class;
	if($class == 'episode') $class = 'shows seasons episodes '.$class;

	// complete replacement
	if($class == 'comment') $class = 'comments';
	if($class == 'trending') $class = 'watch watching';
	if($class == 'popular') $class = 'ratings';
	if($class == 'played') $class = 'watch watched';
	if($class == 'watched') $class = 'watch watched';
	if($class == 'collected') $class = 'collection';
	if($class == 'anticipated') $class = 'lists';
	if($class == 'watching') $class = 'watch watching';
	if($class == 'next episode') $class = 'episodes';
	if($class == 'last episode') $class = 'episodes';
	if($class == 'season') $class = 'episodes';
	if($class == 'list') $class = 'lists';
	if($class == 'list items') $class = 'lists';
	if($class == 'list comments') $class = 'lists comments';
	if($class == 'like') $class = 'likes';
	if($class == 'hide movie') $class = 'hidden movies';
	if($class == 'hide show') $class = 'hidden shows';
	if($class == 'last activities') $class = 'last-activities';
	if($class == 'playback') $class = 'watch scrobble playback';
	if($class == 'remove playback') $class = 'watch scrobble playback';
	if($class == 'follower requests') $class = 'follower-requests follow';
	if($class == 'approve or deny follower requests') $class = 'follower-requests follow';
	if($class == 'list like') $class = 'lists likes';
	
	// remove
	if($class == 'box office') $class = '';
	if($class == 'text query') $class = '';
	if($class == 'id lookup') $class = '';
	if($class == 'start') $class = '';
	if($class == 'pause') $class = '';
	if($class == 'stop') $class = '';
	
	return $class;
}
function getReturnTypes($text) {
	$returns = array();
	$possible = array(
		// show
		'Show' => array('Returns a single shows'),
		'Show[]' => array('Returns the most popular shows', 'Returns related and similar shows', 'Personalized show recommendations'),
		'object[] (includes Episode, Show)' => array('Returns all shows airing', 'Returns all new show premieres', 'Returns all show premieres'),
		'object[] (includes Show)' => array('Returns all shows being', 'Returns the most played (a single user can watch multiple episodes multiple times) shows', 'Returns the most watched (unique users) shows', 'Returns the most collected (unique users) shows', 'Returns the most anticipated shows', 'Returns all shows updated'),
		'Season[]' => array('Returns all seasons'),
		'Episode' => array('Returns the next scheduled to air episode', 'Returns the most recently aired episode', 'Returns a single episode'),
		'Episode[]' => array('Returns all episodes'),
		
		// movie
		'Movie' => array('Returns a single movie'),
		'Movie[]' => array('Returns the most popular movies', 'Returns related and similar movies', 'Personalized movie recommendations'),
		'object[] (includes Movie)' => array('Returns all movies with a', 'Returns all movies being ', 'Returns all movies updated', 'Returns the most played (a single user can watch multiple times) movies', 'Returns the most watched (unique users) movies', 'Returns the most collected (unique users) movies', 'Returns the most anticipated movies', 'Returns the top 10 grossing movies'),
		
		// comment
		'Comment' => array('Returns a single comment', 'Add a new comment', 'Update a single comment', 'Add a new reply'),
		'Comment[]' => array('Returns all top level comments', 'Returns comments', 'Returns all replies'),
		
		// rating
		'Rating[] (object with Movie, Show, Season, or Episode)' => array('Get a user\'s ratings'),
		
		// user
		'User' => array('Get a user\'s profile'),
		'User[]' => array('Returns all users'),
		
		// follow
		'Follow-request[]' => array('List a user\'s pending follow requests'),
		'Follower' => array('Approve a follower', 'If the user has a private profile'),
		'Followers/ing[] (object with User)' => array('Returns all followers', 'Returns all user\'s they follow'), // probably two different responses?
		'Friend[] (object with User)' => array('Returns all friends'),
		
		// list
		'List' => array('Returns a single custom list', 'Create a new custom list', 'Update a custom list'),
		'List[]' => array('Returns all lists', 'Returns all custom lists'),
		'Listed item[] (object with Movie, Show, Episode, Person or List)' => array('Get all items on a custom list'),
		'Collected item[] (object with Movie, Show, Season, or Episode)' => array('Get all collected items'),
		'Watchlisted item[] (object with Movie, Show, Season, or Episode)' => array('Returns all items in a user\'s watchlist'),
		
		// people
		'Person' => array('Returns a single person'),
		'People (object of cast (Person[]) + crew (object of Person[]))' => array('Returns all cast and crew'),
		'Roles (object of cast (Movie[]) + crew (object of Movie[]))' => array('Returns all movies where this person'),
		'Roles (object of cast (Show[]) + crew (object of Show[]))' => array('Returns all shows where this person'),
		
		// hidden
		'Hidden item[] (object with Movie, Show, Episode)' => array('Get hidden items'),
		'Liked item[] (object with Comment, List)' => array('Get items a user likes'),
	
		// scrobble
		'Scrobble (object with Movie or Episode)' => array('Use this method when the video'),
		'Playback[] (object with Movie | object with Episode, Show' => array('Whenever a scrobble is paused'),
		
		// watch
		'Watching (object with Movie, Episode)' => array('Returns a movie or episode if the user is currently watching '),
		'Watched[] (object with Movie | object with Show, Season[])' => array('Returns all movies or shows a user has watched'), 
		'History (object with Movie or Episode)' => array('Check into a movie or episode'),
		'History[] (object with Movie or Episode)' => array('Returns movies and episodes that a user has watched'),
		'Activity (object with timestamps)' => array('Returns a list of dates when the user'),
		
		// progress
		'Progress (object with Season[], Episode (next_episode))' => array('Returns collection progress', 'Returns watched progress'),
				
		// search
		'Result[] (object with Movie, Show, Episode, Person or List)' => array('Search', 'Lookup item'),

				
		// master-data
		'Certifications' => array('Get a list of all certifications'),
		'Genre[]' => array('Get a list of all genres'),
		'Network[]' => array('Get a list of all TV'),
		
		// other
		'Alias[]' => array('Returns all title aliases'),
		'Release[]' => array('Returns all releases'),
		'Translation[]' => array('Returns all translations'),
		'Ratings (calculated)' => array('Returns rating (between 0 and 10) and distribution'),
		'Stats' => array('Returns lots of'),
	
		'Userstats' => array('Returns stats about the movies, shows, and episodes a user'),
		'Settings' => array('Get the user\'s settings'),
		
		'Operation result (object with added, updated, existing, deleted, not_found)' => array('Add items to a user\'s collection', 'Remove one or more items from a user\'s collection.', 'Add items to a user\'s watch history.', 'Remove items from a user\'s watch history', 'Rate one or more items', 'Remove ratings', 'Add one of more items to a user\'s watchlist.', 'Remove one or more items from a user\'s watchlist.', 'Hide items for a specific section', 'Unhide items for a specific section', 'Add one or more items to a custom list', 'Remove one or more items from a custom list'),
		'-' => array('Votes help determine'),
		
		# check again in output if all "Returns all movies or shows" are correct!
	);
	foreach($possible as $key => $value) {
		if(_string_includes_from_array($text, $value)) $returns[] = $key ;
	}
	array_unique($returns);
	if(count($returns) == 0) return "unkown";
	return implode(", ", $returns);
}
function _string_includes_from_array($string, $array) {
	foreach ($array as $search) {
		if (strpos($string, $search) !== FALSE) {
			return true;
		}
	}
	return false;
}
function getIdString($key, $param1, $param2 = null, $param3 = null) {
	$foo = getUrlString($key).'-'.getUrlString($param1);
	if($param2) $foo .= '-'.getUrlString($param2);
	if($param3) $foo .= '-'.getUrlString($param3);
	return $foo;
}
function getLi($key, $group) {
	$classname = getClassstring($group);
	$idstring = getIdString($key, $group);
	return '<li id="'.$idstring.'" class="'.$classname.'"><a title="class = '.$classname.'" href="http://docs.trakt.apiary.io/#reference/'.getUrlString($group).'">'.$group.'</a><a class="anchor" href="#'.$idstring.'"></a>';
}
function getMiddleLi($key, $group, $thing) {
	$classname = getClassstring($thing);
	$idstring = getIdString($key, $group, $thing);
	echo '<li id="'.getIdString($key, $group, $thing).'" class="'.$classname.'"><a title="class = '.$classname.'" href="http://docs.trakt.apiary.io/#reference/'.getUrlString($group).'/'.getUrlString($thing).'">'.$thing.'</a><a class="anchor" href="#'.$idstring.'"></a>';
}
function getInnerLi($key, $group, $thing, $name, $details) {
	global $areas;
	#$classname = getClassstring($name);
	$idstring = getIdString($key, $group, $thing, $name);
	echo '<li id="'.getIdString($key, $group, $thing, $name).'" onclick="toggleUsed(event);"';
	if($details['duplicate']) { echo ' data-duplicate="'.getIdString($details['duplicate'][0], $details['duplicate'][1], $details['duplicate'][2], $details['duplicate'][3]).'"'; }
	echo '>';
	echo '<a href="http://docs.trakt.apiary.io/#reference/'.getUrlString($group).'/'.getUrlString($thing).'/'.getUrlString($name).'">'.$name.'</a> '.getEmoji($details['emoji']).'<a class="anchor" href="#'.$idstring.'"></a><br>';
	echo '<em title="'.$details['intro'].'">'.$details['method'].' '.$details['endpoint'].'</em><br>';
	echo $details['intro'].'<br>';
	if($details['method'] != 'DELETE') echo '└ '.getReturnTypes($details['intro']).'<br>';
	if($details['duplicate']) {
		echo '⚠ Duplicate at <a href="#'.getIdString($details['duplicate'][0], $details['duplicate'][1], $details['duplicate'][2], $details['duplicate'][3]).'">'.$areas[$details['duplicate'][0]][0].' > '.$details['duplicate'][1].' > '.$details['duplicate'][2].' > '.$details['duplicate'][3].'</a><br>';
	}
	echo '</li>';
}
function getUrlString($string) {
	$string = strtolower($string);
	$string = str_replace(' ', '-', $string);
	return $string;
}

include('header.htm');
include('intro.htm');
include('concepts.htm');
foreach($areas as $key => $area) {
	echo '<h2 id="'.$key.'">'.$area[0].'</h2><p>'.$area[1].'</p>';
	echo "<ul>";
	foreach($results3[$key] as $group => $things) {
		echo getLi($key, $group);
		echo "<ul>";
		foreach($things as $thing => $calls) {
			echo getMiddleLi($key, $group, $thing);
			echo "<ul>";
			foreach($calls as $name => $details) {			
				echo getInnerLi($key, $group, $thing, $name, $details);
			}
			echo "</ul>";
			echo "</li>";
		}
		echo "</ul>";
		echo "</li>";
	}
	echo "</ul>";
}


/*
$items = $doc->getElementsByTagName('h1');
$headlines = array();

foreach($items as $item) {
	$headline = array();
	print_r($item);
	print_r($item->nextSibling);
	print_r($item->nextSibling->nextSibling);
	print_r($item->nextSibling->nextSibling->nextSibling);
	print_r($item->nextSibling->nextSibling->nextSibling->nextSibling);
	print_r($item->nextSibling->nextSibling->nextSibling->nextSibling->nextSibling);

	if($item->childNodes->length) {
		foreach($item->childNodes as $i) {
			$headline[$i->nodeName] = $i->nodeValue;
		}
	}
   
	$headlines[] = $headline;
} 
print_r($headlines);
*/

/*
$dom = new DOMDocument();
@$dom->loadHTML($text);

$h1s = array();
$h1 = $dom->getElementsByTagName('h1');
foreach ($h1 as $_h1) {
    echo $_h1->nodeValue, PHP_EOL;
	$h1s[] = $dom->saveHTML($_h1);
}



echo "<hr>LALA<pre>";
print_r($h1s);
*/
/*
echo "<pre>";
print_r($doc);
*/