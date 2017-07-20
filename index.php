<?php
include('header.htm');
include('intro.htm');
include('concepts.htm');
include('other.htm');

include('parse.php');

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