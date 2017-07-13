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
#print_r($array);
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
	$p = explode(".", $v['p']);
	$results2[$k]['intro'] = $p[0].".";
	
}
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
		$results3['data'][$_data['h1']][$_data['h2']][$_data['h3']] = $details;
	}
	else {
		$results3['data'][$_data['h1']][$_data['h2']][$_data['h3']] = $details;
		// emtpy n/a entry
		if(!$results3['user'][$_data['h1']]) $results3['user'][$_data['h1']] = array();
	}
}
#print_r($results3);
#exit;

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
	if($class == 'updates') $class = '';
	if($class == 'text query') $class = '';
	if($class == 'id lookup') $class = '';
	if($class == 'start') $class = '';
	if($class == 'pause') $class = '';
	if($class == 'stop') $class = '';
	
	return $class;
}
function getLi($group) {
	$classname = getClassstring($group);
	return '<li class="'.$classname.'"><a title="class = '.$classname.'" href="http://docs.trakt.apiary.io/#reference/'.getUrlString($group).'">'.$group.'</a>';
}
function getMiddleLi($group, $thing) {
	$classname = getClassstring($thing);
	echo '<li class="'.$classname.'"><a title="class = '.$classname.'" href="http://docs.trakt.apiary.io/#reference/'.getUrlString($group).'/'.getUrlString($thing).'">'.$thing.'</a>';
}
function getInnerLi($group, $thing, $name, $details) {
	#$classname = getClassstring($name);
	echo '<li>';
	echo '<a href="http://docs.trakt.apiary.io/#reference/'.getUrlString($group).'/'.getUrlString($thing).'/'.getUrlString($name).'">'.$name.'</a> '.getEmoji($details['emoji']).'<br>';
	echo '<em title="'.$details['intro'].'">'.$details['method'].' '.$details['endpoint'].'</em><br>';
	echo $details['intro'];
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
$areas = array(
	'data' => array('Data Endpoints', 'These return the nitty-gritty of Trakt: Lots of data about shows and movies and all the things related to them:'),
	'user' => array('User Endpoints', 'If it matters which user is logged in via OAuth, these endpoints are collected here:'),
	'master-data' => array('Master Data Endpoints', 'These endpoints offer mostly static data that should be retrieved once and then be cached for further use:')
);
foreach($areas as $key => $area) {
	echo '<h2 id="'.$key.'">'.$area[0].'</h2><p>'.$area[1].'</p>';
	echo "<ul>";
	foreach($results3[$key] as $group => $things) {
		echo getLi($group);
		echo "<ul>";
		foreach($things as $thing => $calls) {
			echo getMiddleLi($group, $thing);
			echo "<ul>";
			foreach($calls as $name => $details) {			
				echo getInnerLi($group, $thing, $name, $details);
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