<?php
include('header.htm');
include('intro2.htm');
include('concepts.htm');

include('parse.php');

function getInnerLi2($key, $group, $thing, $name, $details) {
	global $areas;
	#$classname = getClassstring($name);
	$idstring = getIdString($key, $group, $thing, $name);
	echo '<li id="'.getIdString($key, $group, $thing, $name).'">';
	echo '<a href="http://docs.trakt.apiary.io/#reference/'.getUrlString($group).'/'.getUrlString($thing).'/'.getUrlString($name).'">'.$name.'</a> '.getEmoji($details['emoji']).'<a class="anchor" href="#'.$idstring.'"></a><br>';
	echo '<em title="'.$details['intro'].'">'.$details['method'].' '.$details['endpoint'].'</em><br>';
	echo $details['intro'].'<br>';
	if($details['method'] != 'DELETE') {
		echo '└ '.getReturnTypes($details['intro']).' - '.count($details['pre']).' response examples:';
		if(count($details['pre']) > 0) {
			echo '<ol>';
			foreach($details['pre'] as $pre) {
				echo '<li><pre style="border:3px solid yellow;">'.$pre.'</pre></li>';
			}
			echo '</ol>';
		} else {
			echo "<br>";
		}
	}
	if($details['duplicate']) {
		echo '⚠ Duplicate at <a href="#'.getIdString($details['duplicate'][0], $details['duplicate'][1], $details['duplicate'][2], $details['duplicate'][3]).'">'.$areas[$details['duplicate'][0]][0].' > '.$details['duplicate'][1].' > '.$details['duplicate'][2].' > '.$details['duplicate'][3].'</a><br>';
	}
	echo '</li>';
}

echo '<h2 id="payloads">Payloads</h2>';
foreach($areas as $key => $area) {
	echo '<h3 id="payloads-'.$key.'">'.$area[0].'</h3><p>'.$area[1].'</p>';
	echo "<ul>";
	foreach($results3[$key] as $group => $things) {
		echo getLi($key, $group);
		echo "<ul>";
		foreach($things as $thing => $calls) {
			echo getMiddleLi($key, $group, $thing);
			echo "<ul>";
			foreach($calls as $name => $details) {			
				echo getInnerLi2($key, $group, $thing, $name, $details);
			}
			echo "</ul>";
			echo "</li>";
		}
		echo "</ul>";
		echo "</li>";
	}
	echo "</ul>";
}