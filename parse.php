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
	
	// hard replace
	$class = str_replace(array(' progress', 'get ', 'add to ', 'remove from ', 'add ', 'remove ', ' items'), '', $class);
	$class = str_replace(array('all shows', 'all new shows', 'all season premieres', 'my shows', 'my new shows', 'my season premieres'), 'shows', $class);
	$class = str_replace(array('all movies', 'all dvd', 'my movies', 'my dvd'), 'movies', $class);
	$search = array('');
	$replace = array('');
	$class = str_replace($search, $replace, $class);
	
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
	$low = strtolower($group);
	return '<li class="'.getClassstring($low).'">'.getClassstring($low).' # <a href="http://docs.trakt.apiary.io/#reference/'.$low.'">'.$group.'</a>';
}
function getInnerLi($thing, $name, $details) {
	$low = strtolower($thing);
	echo '<li class="'.getClassstring($low).'">'.getClassstring($low).' # <a href="http://docs.trakt.apiary.io/#reference/calendars/'.$low.'/'.$name.'">'.$name.'</a> '.getEmoji($details['emoji']).'<br>
	<em>'.$details['method'].' '.$details['endpoint'].'</em><br>
	'.$details['intro'].'</li>';
}
?>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<style>
.light { background-color:grey; color:#ccc; }
.deactivated {
	transition: opacity 1s;
	opacity: 0.55;
	border: 1px solid red;
}
.debug {
	background:red;
	color:white;
	padding:3px;
	padding-bottom:1px;
}
.hide {
	display: none;
}
</style>
<script>
// Cookies
function setCookie(cname, cvalue, exdays) {
	console.log('setCookie', cname, cvalue, exdays);
    var d = new Date();
    d.setTime(d.getTime() + (exdays*24*60*60*1000));
    var expires = "expires="+ d.toUTCString();
    document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
}
function getCookie(cname) {
    var name = cname + "=";
    var decodedCookie = decodeURIComponent(document.cookie);
    var ca = decodedCookie.split(';');
    for(var i = 0; i <ca.length; i++) {
        var c = ca[i];
        while (c.charAt(0) == ' ') {
            c = c.substring(1);
        }
        if (c.indexOf(name) == 0) {
			console.log('getCookie', cname, '=', c.substring(name.length, c.length));
            return c.substring(name.length, c.length);
        }
    }
    return "";
}


function addInputFields() {
	var els = document.querySelectorAll('[data-class]');
	for (var i = 0; i < els.length; i++) {
		var el = els[i];
		var name = el.dataset['class'];
		
		if(name == "scrobble" || name == "scrobble") { 
			console.log(el.parentNode.parentNode.innerHTML); 
		}
		
		var id = 'checkbox-' + name;
		// wrap label around element
		const wrapper = document.createElement('label');
		wrapper.htmlFor = id;
		el.parentNode.insertBefore(wrapper, el);
		el.parentNode.removeChild(el);
		wrapper.appendChild(el);
		
		if(name == "scrobble" ) { console.log(el.parentNode.parentNode.innerHTML); }
		
		// read the current/previous setting
		var cookie = getCookie(id);
		
		// add checkbox after element
		const input = document.createElement('input');
		input.setAttribute("type", "checkbox");
		input.setAttribute("checked", "checked");
		input.setAttribute("onchange", "handleChange(event);");
		input.id = id;
		if(cookie == "unchecked") {
			input.removeAttribute('checked');
		} else {
			input.setAttribute('checked', 'checked');
		}
		el.parentNode.insertBefore(input, el.nextSibling);			
		
		if(name == "scrobble" || name == "scrobble") { console.log(el.parentNode.parentNode.innerHTML); }
		
		// make changes!
		if(cookie == "unchecked") {
			hide(name);
		} else {
			show(name);
		}
		
	}
}

function handleChange(e) {
	var el = e.target;
	var targetValue = "unchecked"
	var name = el.id;
	
	var value = getCookie(name);
	if(value == 'unchecked') targetValue = "checked";
	
	console.log('old =', value, '; new =', targetValue);
	setCookie(name, targetValue, 365);
	
	if(targetValue == "unchecked") { 
		hide(name); 
	} else { 
		show(name); 
	}
}

function hide(name) {
	var classname = name.replace("checkbox-", "");
	console.log('hide', classname);
	els = document.getElementsByClassName(classname);
	
	for (var i = 0; i < els.length; i++) {
		var el = els[i];
		
		// real hide
		//el.style.display = 'none';

		// add class
		el.classList.add('deactivated');
		
		// add debug element
		var newNode = document.createElement("span");
		newNode.classList.add('debug');
		newNode.innerHTML = "removed by '"+classname+"'";
		el.parentNode.insertBefore(newNode, el.nextSibling);				
	}
}

function show(name) {
	var classname = name.replace("checkbox-", "");
	console.log('show', classname);
	els = document.getElementsByClassName(classname);
	for (var i = 0; i < els.length; i++) {
		var el = els[i];
		
		// real show
		//el.style.display = ''|'inline'|'inline-block'|'inline-table'|'block';
		
		// add class
		el.classList.remove('deactivated');
		
		// remove debug element
		if(el.nextSibling && el.nextSibling.innerHTML == "removed by '"+classname+"'") {
			el.nextSibling.parentNode.removeChild(el.nextSibling);
		}
	}
}



// $(document).ready(function(){
function ready(fn) {
  if (document.attachEvent ? document.readyState === "complete" : document.readyState !== "loading"){
    fn();
  } else {
    document.addEventListener('DOMContentLoaded', fn);
  }
}


ready(addInputFields);



function toggleHide(e) {
	var box = e.target;
	els = document.getElementsByClassName('deactivated');
	for (var i = 0; i < els.length; i++) {
		var el = els[i];
		if (box.checked) {
			el.classList.add('hide');
		} else {
			el.classList.remove('hide');
		}
	}
	
	els = document.getElementsByClassName('debug');
	for (var i = 0; i < els.length; i++) {
		var el = els[i];
		if (box.checked) {
			el.classList.add('hide');
		} else {
			el.classList.remove('hide');
		}
	}
	
}
</script>
<ul>
	<li><a href="#concepts">Concepts</a></li>
	<li><a href="#emojis">Emojis Legend</a></li>
	<li>Endpoints
		<ul>
			<li><a href="#data">Data Endpoints</a></li>
			<li><a href="#user">User Endpoints</a></li>
			<li><a href="#master-data">Master Data Endpoints</a></li>
		</ul>
	</li>
</ul>
<h3 id="concepts">Concepts</h3>
<p>The API only <a href="http://docs.trakt.apiary.io/#introduction/terminology">defines some terms in „Terminology“</a>, but there is a lot more to understand what there is and how it is all connected:</p>
<ul>
	<li>There are <strong data-class="users">Users</strong> and objects: <strong data-class="movies">Movies</strong>, <strong data-class="shows">Shows</strong> with <strong data-class="seasons">Seasons</strong> and <strong data-class="episodes">Episodes</strong>.</li>
	<li class="users">Users
		<ul>
			<li>Users can <strong data-class="watch">watch</strong> things:
				<ul>
					<li class="watch"><em>Users</em> can <strong data-class="checkin">checkin</strong> to or <strong data-class="scrobble">scrobble</strong> <em>movies </em>and <em>episodes.</em> These are then first marked as <em data-class="watching">watching</em>, later as <em>watched</em> after the runtime (complicated, read details in docs).</li>
					<li class="watch"><em>Users</em> can additionally <strong data-class="add-history">add to history</strong> for <em>movies</em>, <em>shows</em>, <em>seasons</em> and <em>episodes</em> to mark them as <em data-class="watched">watched</em> instantly.</li>
				</ul>
			</li>
			<li><em>Users </em>can create multiple <strong data-class="lists">Lists</strong> and have one <strong data-class="collection">Collection</strong> and one<strong data-class="watchlist"> Watchlist</strong> (of <em>movies</em>, <em>shows</em>, <em>seasons</em>, and <em>episodes</em>).</li>
			<li><em>Users</em> can write <strong data-class="comments">Comments</strong> on <em>movies</em>, <em>shows</em>, <em>seasons</em>, <em>episodes</em>, or <em>lists</em>.
				<ul class="comments">
					<li><em>Users </em>can create <strong data-class="replies">Replies</strong> and <strong data-class="likes">Likes</strong> <em>on comments </em>and<em> lists.</em><strong><br>
</strong></li>
				</ul></li>
			<li><em>Users</em> can create <strong data-class="ratings">Ratings</strong> on <em>movies</em>, <em>shows</em>,<em> seasons</em> and <em>episode. </em></li>
			<li><em>Users</em> have <strong data-class="settings">Settings</strong> and a<strong data-class="profile"> Profile</strong></li>
			<li><em>Users</em> can <strong data-class="follow">follow</strong> other <em>users</em> to create <strong data-class="follower-requests">Follower Requests</strong> and eventually become <strong data-class="followers">Followers</strong>, <strong data-class="following">Following</strong> and <strong data-class="friends">Friends</strong></li>
		</ul>
	</li>
	<li>Objects
		<ul>
			<li>There is additional information for some objects:
				<ul>
					<li><strong data-class="summary">Summary</strong> for <em>movies</em>, <em>people</em>, <em>shows</em>, <em>seasons</em> and <em>episodes</em> collect all known information about them</li>
					<li><strong data-class="aliases">Aliases</strong> of <em>movies</em> and <em>shows</em></li>
					<li class="movies"><strong data-class="releases">Releases</strong> of <em>movies</em></li>
					<li><strong data-class="translations">Translations</strong> of <em>movies</em>, <em>shows</em> and <em>episodes </em></li>
					<li><strong data-class="people">People</strong> are cast and crew of <em>shows</em> and <em>movies</em></li>
				</ul>
			</li>
			<li><strong data-class="search">Search</strong> can look for <em>movies</em>, <em>shows</em>, <em>episodes</em>, <em>people</em>, and <em>lists</em> by text or ID.</li>
			<li><strong data-class="related">Related</strong> objects are calculated for <em>movies </em>and <em>shows</em>.</li>
			<li><strong data-class="stats">Stats</strong> are calculated for <em>movies</em>, <em>shows</em>, <em>seasons</em>, <em>episodes</em> and <em>users</em>.</li>
		</ul>
	</li>
	<li class="users">Users and Objects
		<ul>
			<li><strong data-class="history">History</strong> <span class="scrobble">and <strong data-class="playback">Playback</strong></span> are calculated based on <em>checkins</em> and <em>scrobbles</em> (=<em> movies </em>and <em>episodes </em>watched) and manual <em>„add to history"</em>.</li>
			<li><strong data-class="last-activities">Last Activities</strong> are collected from <em>history</em> and all other <em>user</em> activities (comments, replies, likes, ratings).</li>
			<li>Based on the <em>releases</em> of objects and the <em>history</em> of the <em>user</em> there are <strong data-class="calendars">Calendars</strong> for <em>shows </em>and <em>episodes</em>.</li>
			<li><strong data-class="recommendations">Recommendations</strong> are given to <em>users</em> for <em>movies</em> and <em>shows.</em></li>
			<li><strong>Collection Progress</strong> and <strong>Watch Progress </strong>is calculated for <em>users</em> for <em>shows</em> and their<em> episodes.</em></li>
			<li><em>Users</em> can create<strong data-class="hidden"> Hidden Items</strong> to hide things (<em>movies, shows </em>or<em> episodes</em>) in <em>calendars</em>, <em>collection progress</em>, <em>watch progress</em> <em>and recommendations.</em></li>
		</ul>
	</li>
</ul>
<p>Use the checkboxes next to the terms to deactivate them in the list below. You can also set if they only should be deactivated or completely hidden. <label><i>Hide deactivated items:</i> <input type="checkbox" onchange="toggleHide(event);"></label> </p>
<?php
$areas = array(
	'data' => array('Data Endpoints', 'These return the nitty-gritty of Trakt: Lots of data about shows and movies and all the things related to them:'),
	'user' => array('User Endpoints', 'If it matters which user is logged in via OAuth, these endpoints are collected here:'),
	'master-data' => array('Master Data Endpoints', 'These endpoints offer mostly static data that should be retrieved once and then be cached for further use:')
);
foreach($areas as $key => $area) {
	echo '<h2 id="'.$key.'">'.$area[0].'</h2><p>'.$area[1].'</p>';
	echo "<ul>";
	foreach($results3[$key] as $group => $things) {
		echo getLi($group); //'<li class="calendars"><a href="http://docs.trakt.apiary.io/#reference/calendars">'.$group.'</a>';
		echo "<ul>";
		foreach($things as $thing => $calls) {
			echo '<li>'.$thing.'';
			echo "<ul>";
			foreach($calls as $name => $details) {			
				echo getInnerLi($thing, $name, $details);
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