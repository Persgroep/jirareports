<?php
if ( ! file_exists('jiraconfig.php')) {
	trigger_error('Make sure you create a jiraconfig.php with valid credentials', E_USER_ERROR);
}

include_once('jiraconfig.php');

error_reporting(E_ALL);
ini_set("display_errors", 1);

$maxWidth = 2560;

function calcPixelsX($firstDate, $lastDate, $date1, $date2)
{
	global $maxWidth;

	$span = $lastDate - $firstDate;
	$pos1 = $date1 - $firstDate;
	$pos2 = $date2 - $firstDate;

	$x1 = ($pos1 / $span) * $maxWidth;
	$x2 = ($pos2 / $span) * $maxWidth;

	return array($x1, $x2);
}
ini_set('error_reporting', 1);
define('TIMETRACKING_DAYS_IN_WEEK', 5);
define('TIMETRACKING_HOURS_IN_DAY', 8);

#require_once('../../inc/global.inc');
header('Content-type: text/html; charset=utf-8');

/**
 * Modified functions taken from: http://csl.sublevel3.org/php-secs-to-human-text/
 * Adjusted so that it can also calculate remaining time string the same way as Jira displays it.
 *  (taking worktimes into account).
 *
 * @param $secs
 * @param int $days_per_week
 * @param float $hours_per_day
 * @return array returned values for units in an array
 */
function seconds_to_units($secs, $days_per_week = 5, $hours_per_day = 8)
{
	$units = array(
		"weeks"   => $days_per_week*$hours_per_day*3600,
		"days"    => $hours_per_day*3600,
		"hours"   => 3600,
		"minutes" => 60,
		"seconds" => 1);

	foreach ($units as &$unit )
	{
		$quot  = intval($secs / $unit);
		$secs -= $quot * $unit;
		$unit  = $quot;
	}

	return $units;
}

/**
 * Modified functions taken from: http://csl.sublevel3.org/php-secs-to-human-text/
 * Adjusted so that it can also calculate remaining time string the same way as Jira displays it.
 *  (taking worktimes into account). Function returns a string.
 *
 * @see seconds_to_units
 * @param $secs
 * @param int $days_per_week
 * @param float $hours_per_day
 * @return string human readable string
 */
function seconds_to_humanreadable($secs, $days_per_week = 5, $hours_per_day = 8)
{
	$s = "";
	foreach ( seconds_to_units($secs, $days_per_week, $hours_per_day) as $k => $v ) {
		if ( $v ) $s .= $v." ".($v==1? substr($k,0,-1) : $k).", ";
	}
	return substr($s, 0, -2);
}

/**
 * Convert seconds into number of ours. Returns empty string if zero, and in case of decimals max. 2 decimals.
 * @param $seconds
 * @return float|string
 */
function seconds_to_readable_hours($seconds)
{
	if ($seconds == 0)
		return '';

	$hours = $seconds / 3600;

	if (($hours - ((int)$hours)) === 0)
	{
		return $hours;
	}

	return sprintf('%.2f', $hours);
}

/**
 * JiraRestApi interface for implementations
 */
interface IJiraRestImplApi
{
	public function __construct($restApiUrl, $username, $password);
	public function call($request);
}

/**
 * Implementation for JIRA Rest API using curl.
 */
class JiraRestApiCurlImpl implements IJiraRestImplApi
{
	public function __construct($restApiUrl, $username, $password)
	{
		$this->username = $username;
		$this->password = $password;
		$this->restApiUrl = $restApiUrl;
	}

	public function call($request)
	{
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_URL, "{$this->restApiUrl}{$request}");
		curl_setopt($ch, CURLOPT_USERPWD, $this->username . ":" . $this->password);
		$result = curl_exec($ch);
		curl_close($ch);

		$json = json_decode($result);
		if ($json === null)
			throw new RuntimeException($result);

		return $json;
	}

	private $username = null;
	private $password = null;

	private $restApiUrl = null;
}

/**
 * Interface to JIRA Rest api.
 */
class JiraRestApi
{
	public function __construct($restApiUrl, $username, $password)
	{
		$this->impl = new JiraRestApiCurlImpl($restApiUrl, $username, $password);
	}

	public function getIssue($issueId)
	{
		return $this->impl->call("issue/{$issueId}");
	}

	public function search($jql, $params = '')
	{
		$jql = urlencode($jql);
		return $this->impl->call("search?jql={$jql}&startAt=0&maxResults=10000&");
	}

	public function getVersions($projectKey)
	{
		return $this->impl->call("project/{$projectKey}/versions");
	}

	/**
	 * @var JiraRestApiCurlImpl contains an instance that implements IJiraRestImplApi interface.
	 */
	private $impl = null;
}

function get_group_by($issue)
{
	$groupIndex = $issue->fields->status->name;
	if (isTicketDone($groupIndex)) {
		$groupIndex .= ':' . $issue->fields->resolution->name;
	}
	return $groupIndex;
}


$jiraApi = new JiraRestApi('https://persgroep.atlassian.net/rest/api/latest/', JiraConfig::Username, JiraConfig::Password);


// Get available versions / iterations
if (file_exists('jira/versions.txt')) {
	$versions = unserialize(file_get_contents('jira/versions.txt'));
}
else {
	$versions = $jiraApi->getVersions('ATONE');
	file_put_contents('jira/versions.txt', serialize($versions));
}

$versionIndexes = array();
$firstDate = false;
$lastDate = false;
$stopOnNext = false;
foreach ($versions as $version) {
	$startDate = strtotime($version->startDate);
	$releaseDate = strtotime($version->releaseDate);

	if ($stopOnNext) {
		//continue;
	}


	if ($startDate > strtotime(date('r'))) {
		//$stopOnNext = true;
	}


	if (!$firstDate) {
		$firstDate = $startDate;
		$lastDate = $releaseDate;
	}
	else if ($startDate < $firstDate) {
		$firstDate = $startDate;
	}
	else if ($releaseDate > $lastDate) {
		$lastDate = $releaseDate;
	}
	$versionIndexes[] = $version->name;
}

//$firstDate = 1390217647;
//$lastDate = 1390217759 + 5;

// Get current iteration
//$iteration = \util\input\InputUtil::getValueFromList($_GET, 'iteration', $versionIndexes);
$iteration = $_GET['iteration']; //\util\input\InputUtil::getValueFromList($_GET, 'iteration', $versionIndexes);
if (!in_array($iteration, $versionIndexes))
	$iteration = '';

// Get all issues from selected fixVersion
// Note: This is not directly user input, if someone messes with Jira an injection might occur
$orderby = '';
if (isset($_GET['orderby']))
{
	// I know JML injection - But I don't care! :P
	$orderby = $_GET['orderby'] . ', ';
}
#$issues = $jiraApi->search('project = ' . 'ATONE' . ' AND fixVersion = "' . $iteration . '" ORDER BY '.$orderby.' component, subtasks ASC, Rank');
$fixversion = 'fixVersion IS EMPTY';
if (!empty($iteration))
	$fixversion = 'fixVersion = "' . $iteration .'"';

//$issues = $jiraApi->search('project = ' . 'ATONE' . ' AND '.$fixversion.' ORDER BY '.$orderby.' component, subtasks ASC, Rank');

// Fetch all the jira keys in the woooooooooorld 
//$issues = $jiraApi->search('', '');
//$keys = array();
//foreach ($issues->issues as $issue) {
//	$keys[] = $issue->key;
//}
//var_dump(file_put_contents('jira_keys.txt', serialize($keys)));
$jiraKeys = unserialize(file_get_contents('jira_keys.txt'));
$jiraKeys = array_filter($jiraKeys, function ($s) { return strpos($s, 'ATONE') === 0; });

//$jiraKeys = array('ATONE-7', 'ATONE-27', 'ATONE-62', 'ATONE-254');
//$jiraKeys = array('ATONE-254');
//$jiraKeys = array('ATONE-180');
//$jiraKeys = array('ATONE-169');

include_once("krumo/class.krumo.php");

//print_r($keys);

////$issue = $jiraApi->getIssue('ATONE-7?expand=changelog');
//krumo($issues);die;
//foreach ($issues->issues->changelog->histories as $historyItem) {
//	//printf("history: %s<br/>\n", print_r($historyItem,1));
//	printf("history: %s<br/>\n", print_r($historyItem,1));
//}
//

$focusIter = '3.31';


$urenGelogdOpIteratieTickets = 0;
$urenGelogdBinnenIteratie = 0;
$urenAanTicketsBinnenIteratie = 0;


$canvasCodeForTickets = array();
$workLogsForTickets = array();
$estimatesForTickets = array();
$fixedInIter = array();

?>
<html>
<head>
<script src="ocanvas-2.5.1.js" type="text/javascript"></script>
<style type="text/css">
</style>
</head>

<body>
Date from <?php print date('r', $firstDate) ?> to <?php print date('r', $lastDate) ?>. 
Diff = <?php print $lastDate - $firstDate; ?>.
<canvas id="canvas" width="2560" height="25000">
</canvas>

<script type="text/javascript">
var canvas = oCanvas.create({
	canvas: "#canvas"
});

var padding = 20;

canvas.addChild(canvas.display.line({
	start: { x: 0 + padding, y: 0 + padding },
	end: { x: <?php print $maxWidth; ?> + padding, y: 0 + padding },
	stroke: "10px red"
	//, cap: "round"
}), false);

<?php 
$first = true;
foreach ($versions as $version):
	$startDate = strtotime($version->startDate);
	$releaseDate = strtotime($version->releaseDate);

	//if ($startDate > strtotime(date('r'))) continue;

	list($x1, $x2) = calcPixelsX($firstDate, $lastDate, $startDate, $releaseDate);
?>

canvas.addChild(canvas.display.text({
	x: <?php print $x1; ?> + padding,
	y: 10 + padding,
	origin: { x: "left", y: "top" },
	font: "bold 30px sans-serif",
	text: "<?php print $version->name; ?>",
	fill: "red"
}), false);


<?php 
if ($first):
	$first = false;
?>
canvas.addChild(canvas.display.line({
	start: { x: <?php print $x1; ?> + padding, y: 0 + padding },
	end: { x: <?php print $x1; ?> + padding, y: 25000 + padding },
	stroke: "1px grey"
	// ,cap: "round"
}), false);

<?php 
endif; ?>

canvas.addChild(canvas.display.line({
	start: { x: <?php print $x2; ?> + padding, y: 0 + padding },
	end: { x: <?php print $x2; ?> + padding, y: 25000 + padding },
	stroke: "1px grey"
	// ,cap: "round"
}), false);
<?php endforeach; ?>

var y = 50;
<?php
//$jiraKeys = array('ATONE-2');
foreach (array_reverse($jiraKeys) as $key):

	static $counter = 0;
//	if ($counter++ > 15) break;
	if (file_exists('jira/' . $key .'.dat')) {
		$issue = unserialize(file_get_contents('jira/' . $key .'.dat'));
	}
	else {
		$issue = $jiraApi->getIssue($key . '?expand=changelog');
		var_dump(file_put_contents('jira/' . $key .'.dat', serialize($issue)));
	}

	$inIter = false;
	foreach ($issue->fields->fixVersions as $fv) {
		if ($fv->name == $focusIter) {
			$inIter = true;
			break;
		}
	}
	if (!$inIter) continue;


	$created = strtotime($issue->fields->created);
	$updated = strtotime($issue->fields->updated);

	$canvasCodeForTickets[$key] = "y += 30;\n";

	list($x1, $x2) = calcPixelsX($firstDate, $lastDate, $created, $updated);

	$canvasCodeForTickets[$key] .= "
		canvas.addChild(canvas.display.line({
			start: { x: " .  $x1 . " + padding, y: y + padding },
			end: { x: " .  $x2 . " + padding, y: y + padding },
			stroke: \"5px blue\"
		}), false);
	";

	$fixver = array();
	foreach ($issue->fields->fixVersions as $fixVersion) {
		$fixver[] = $fixVersion->name;
	}
	$fixVersions = array();
	$fixVersions[] = array(NULL, $updated, implode(':', $fixver));
	$canvasCodeForTickets[$key] .= "
		canvas.addChild(canvas.display.text({
			x: " .  $x1 . " + padding,
			y: y - 20 + padding,
			origin: { x: \"left\", y: \"top\" },
			font: \"14px sans-serif\",
			text: '" . $issue->key . ' - ' . str_replace("'", "\"", $issue->fields->summary) . "',
			fill: \"black\"
		}), false);
		canvas.addChild(canvas.display.text({
			x: " .  $x2 . " + padding,
			y: y - 20 + padding,
			origin: { x: \"left\", y: \"top\" },
			font: \"14px sans-serif\",
			text: \"" . implode(':', $fixver) . "\",
			fill: \"red\"
		}), false);
	";

	$lastStatusChange = false;

	//### HISTORIES #
	foreach (array_reverse($issue->changelog->histories) as $historyItem): 
		$historyCreated = strtotime($historyItem->created);  
		list($histX1, $histX2) = calcPixelsX($firstDate, $lastDate, $historyCreated, $historyCreated);  
		foreach ($historyItem->items as $item): 

			$color = 'yellow';
			if ($item->field === 'Fix Version') {
				$color = 'red';
				$fixver = array($item->toString);

				$fixver = array($item->fromString . '|' . $item->toString);
				$fixver = array($item->fromString);
				$fixVersions[] = array($historyCreated, NULL, implode(':', $fixver));
			} 
			else if ($item->field === 'status' && $lastStatusChange === false /*note that we iterate backwards*/) {
				$lastStatusChange = array(strtotime($historyItem->created), $item);
				continue;
			}
			else { continue; }

			$canvasCodeForTickets[$key] .= "
				canvas.addChild(canvas.display.text({
					x: " . $histX1 . " + padding,
					y: y + 5 + padding,
					origin: { x: \"left\", y: \"top\" },
					font: \"12px sans-serif\",
					text: \"" . implode(':', $fixver) . "\",
					fill: \"black\"
				}), false);

				canvas.addChild(canvas.display.ellipse({
					x: " . $histX1 . " + padding,
					y: y + padding,
					radius: 3,
					fill: \"" . $color . "\"
				}), false); 
			";

		endforeach;
	endforeach;

?>
	<?php 
	$originalEstimate = $issue->fields->timetracking->originalEstimateSeconds;
	$estimates = array();
	$estimates[] = array(NULL, $updated, $originalEstimate);
	$canvasCodeForTickets[$key] .= "
		canvas.addChild(canvas.display.text({
			x: " . $x2 . " + padding,
			y: y + 30 + padding,
			origin: { x: \"left\", y: \"top\" },
			font: \"12px sans-serif\",
			text: \"" . $originalEstimate . "!!\",
			fill: \"green\"
		}), false);
	";

	foreach (array_reverse($issue->changelog->histories) as $historyItem): 
		$historyCreated = strtotime($historyItem->created);  
		list($x1, $x2) = calcPixelsX($firstDate, $lastDate, $historyCreated, $historyCreated);  

		foreach ($historyItem->items as $item): 

			$color = 'yellow';
			if ($item->field === 'timeoriginalestimate') {
				$color = 'green';
				$estim = $item->fromString;// . ':' . $item->toString;
				$estimates[] = array($historyCreated, NULL, $estim);
			} else { continue; }

			$canvasCodeForTickets[$key] .= "
				canvas.addChild(canvas.display.text({
					x: " . $x1 . " + padding,
					y: y + padding,
					origin: { x: \"left\", y: \"top\" },
					font: \"12px sans-serif\",
					text: \"" . $estim . "\",
					fill: \"green\"
				}), false);

				canvas.addChild(canvas.display.ellipse({
					x: " . $x1 . " + padding,
					y: y + padding,
					radius: 3,
					fill: \"" . $color . "\"
				}), false); 
			";
		endforeach;
	endforeach;

foreach ($fixVersions as $idx => $val) {
	//$fixVersions[$idx][0] = date('r', $fixVersions[$idx][0]);
	if ($fixVersions[$idx][1]){
		//$fixVersions[$idx][1] = date('r', $fixVersions[$idx][1]);
	}
}
foreach ($fixVersions as $idx => $val) {

	if (isset($fixVersions[$idx-1])) {
		$fixVersions[$idx-1][1] = $fixVersions[$idx][0];
	}
	
}
//fix edges
if (isset($fixVersions[0])) {
	$fixVersions[0][0] = $updated;//date('r', $updated);
}
$fixVersions[$idx][1] = $created;//date('r', $created);




//IsTicketDone!!?!
	list($statusChangeTime, $statusChange) = $lastStatusChange;
	$resolution = $issue->fields->resolution->name;
	$isTicketDone = $statusChange->toString === 'Done';

	if ($isTicketDone && $resolution == 'Fixed') {
		//print_r($statusChangeTime);
		//print_r($statusChange);
		$matched = false;
		foreach ($fixVersions as $fixv) {
			list ($to, $from, $fixver) = $fixv;

			//print "@@ $statusChangeTime\n";
			//print_r($fixv);
			if ($statusChangeTime >= $from && $statusChangeTime <= $to) {
				$matched = $fixver;
			}
		}
		if ($matched === $focusIter) {
			// Fixed in this iteration!
			$fixedInIter[] = $key;
		}
	}







$canvasCodeForTickets[$key] .= "y += 20;\n";
foreach (array_reverse($fixVersions) as $fixVersion):

	list ($begin, $end, $fixversion) = $fixVersion;

	list($x2, $x1) = calcPixelsX($firstDate, $lastDate, $begin, $end);

	$canvasCodeForTickets[$key] .= "
		canvas.addChild(canvas.display.ellipse({
			x: " .  $x1  . " + padding,
			y: y + padding,
			radius: 2,
			fill: \"red\"
		}), false); 
		canvas.addChild(canvas.display.ellipse({
			x: " .  $x2  . " + padding,
			y: y + padding,
			radius: 2,
			fill: \"red\"
		}), false); 
		canvas.addChild(canvas.display.line({
			start: { x: " .  $x1 . " + padding, y: y + padding },
			end: { x: " .  $x2 . " + padding, y: y + padding },
			stroke: \"2px red\"
			//,cap: \"round\"
		}), false);
		canvas.addChild(canvas.display.text({
			x: " .  $x1 . " + padding,
			y: y + 5 + padding,
			origin: { x: \"left\", y: \"top\" },
			font: \"12px sans-serif\",
			text: \"" .  (empty($fixversion) ? '(none)' : $fixversion)  . "\",
			fill: \"red\"
		}), false);
		y += 20;
	";
?>

<?php endforeach; ?>

<?php
/////
foreach ($estimates as $idx => $val) {
	//$estimates[$idx][0] = date('r', $estimates[$idx][0]);
	if ($estimates[$idx][1]){
		//$estimates[$idx][1] = date('r', $estimates[$idx][1]);
	}
}
foreach ($estimates as $idx => $val) {

	if (isset($estimates[$idx-1])) {
		$estimates[$idx-1][1] = $estimates[$idx][0];
	}
	
}
//fix edges
if (isset($estimates[0])) {
	$estimates[0][0] = $updated;//date('r', $updated);
}
$estimates[$idx][1] = $created;//date('r', $created);
foreach (array_reverse($estimates) as $originalEstimate_):

	list ($begin, $end, $fixversion) = $originalEstimate_;

	list($x2, $x1) = calcPixelsX($firstDate, $lastDate, $begin, $end);


	$canvasCodeForTickets[$key] .= "

	canvas.addChild(canvas.display.ellipse({
		x: " .  $x1  . " + padding,
		y: y + padding,
		radius: 2,
		fill: \"blue\"
	}), false); 
	canvas.addChild(canvas.display.ellipse({
		x: " .  $x2  . " + padding,
		y: y + padding,
		radius: 2,
		fill: \"blue\"
	}), false); 
	canvas.addChild(canvas.display.line({
		start: { x: " .  $x1  . " + padding, y: y + padding },
		end: { x: " .  $x2  . " + padding, y: y + padding },
		stroke: \"2px blue\"
		//,cap: \"round\"
	}), false);
	canvas.addChild(canvas.display.text({
		x: " .  $x1  . " + padding,
		y: y + 5 + padding,
		origin: { x: \"left\", y: \"top\" },
		font: \"12px sans-serif\",
		text: \"" .  (empty($fixversion) ? '(none)' : $fixversion) . "\",
		fill: \"blue\"
	}), false);
	y += 20;
	";
?>

<?php endforeach; ?>

<?php /*--------------------------*/ ?>
<?php 
	$worklogged = 0;
	$worklogs = array();
	foreach (array_reverse($issue->fields->worklog->worklogs) as $workLogItem): 
		$historyCreated = strtotime($workLogItem->created);  
		$historyUpdated = strtotime($workLogItem->updated);  
		list($x1, $x2) = calcPixelsX($firstDate, $lastDate, $historyCreated, $historyUpdated);  

		// is timespent
		$estim = $workLogItem->timeSpentSeconds;

		$matched = false;
		foreach ($estimates as $estimate) {
			list ($to, $from, $estim_) = $estimate;

			if ($historyUpdated >= $from && $historyUpdated <= $to) {
				$matched = $estim_;
			}
		}
		if ($matched === false) {
			print_r($issue);
			var_dump($historyCreated);
			var_dump($historyUpdated);
			print_r($workLogItem);
			print_r($estimates);
			die("could not match the worklog to estimation");
		}

		$matched2 = false;
		foreach ($fixVersions as $fixv) {
			list ($to, $from, $fixver) = $fixv;
			if ($historyUpdated >= $from && $historyUpdated <= $to) {
				$matched2 = $fixver;
			}
		}
		if ($matched2 === $focusIter) {
			// Fixed in this iteration!
			$estimatesForTickets[$key][] = array($estim, $matched, $matched2);
		}


		$worklogged += $estim;
		$color = 'green';

		$canvasCodeForTickets[$key] .= "

			canvas.addChild(canvas.display.text({
				x: " .  $x1  . " + padding,
				y: y + padding,
				origin: { x: \"left\", y: \"top\" },
				font: \"12px sans-serif\",
				text: \"" .  $estim  . "\",
				fill: \"green\"
			}), false);

			canvas.addChild(canvas.display.ellipse({
				x: " .  $x1  . " + padding,
				y: y + padding,
				radius: 3,
				fill: \"" .  $color  . "\"
			}), false); 

			canvas.addChild(canvas.display.line({
				start: { x: " .  $x1  . " + padding, y: y + padding },
				end: { x: " .  $x2  . " + padding, y: y + padding },
				stroke: \"2px " .  $color  . "\"
				//,cap: \"round\"
			}), false);
		";
?>

		<?php 
	endforeach;

	$canvasCodeForTickets[$key] .= "
		/*
		ESTIM " .  $worklogged . ' ' . $originalEstimate  . "
		*/
		canvas.addChild(canvas.display.text({
			x: " .  $x1  . " + padding,
			y: y + 50 + padding,
			origin: { x: \"left\", y: \"top\" },
			font: \"12px sans-serif\",
			text: \"" .  $originalEstimate . ' @ ' . $worklogged  . "\",
			fill: \"green\"
		}), false);

	";
/////
foreach ($worklogs as $idx => $val) {
	//$worklogs[$idx][0] = date('r', $worklogs[$idx][0]);
	if ($worklogs[$idx][1]){
		//$worklogs[$idx][1] = date('r', $worklogs[$idx][1]);
	}
}
foreach ($worklogs as $idx => $val) {

	if (isset($worklogs[$idx-1])) {
		$worklogs[$idx-1][1] = $worklogs[$idx][0];
	}
	
}
//fix edges
if (isset($worklogs[0])) {
	$worklogs[0][0] = $updated;//date('r', $updated);
}
$worklogs[$idx][1] = $created;//date('r', $created);
foreach (array_reverse($worklogs) as $originalEstimate):

	list ($begin, $end, $fixversion) = $originalEstimate;

	list($x2, $x1) = calcPixelsX($firstDate, $lastDate, $begin, $end);


	$canvasCodeForTickets[$key] .= "
		canvas.addChild(canvas.display.ellipse({
			x: " .  $x1  . " + padding,
			y: y + padding,
			radius: 2,
			fill: \"blue\"
		}), false); 
		canvas.addChild(canvas.display.ellipse({
			x: " .  $x2  . " + padding,
			y: y + padding,
			radius: 2,
			fill: \"blue\"
		}), false); 
		canvas.addChild(canvas.display.text({
			x: " .  $x1  . " + padding,
			y: y + 5 + padding,
			origin: { x: \"left\", y: \"top\" },
			font: \"12px sans-serif\",
			text: \"" .  (empty($fixversion) ? '(none)' : $fixversion) . "\",
			fill: \"blue\"
		}), false);
		y += 20;
	";
?>

<?php endforeach; ?>
<?php endforeach; ?>

<?php 

$tickets = array_intersect_key($canvasCodeForTickets, array_flip($fixedInIter));

foreach ($tickets as $js) {

print $js;

}

print "/*";

$sorted = array();
foreach ($estimatesForTickets as $key => $data) {
	if (!isset($sorted[$key]))
		$sorted[$key] = array('spent' => array(), 'estimated' => array());

	foreach ($data as $data2) {
		list ($timeSpent, $estimation, $iteration) = $data2;

		$sorted[$key]['spent'][] = $timeSpent;
		$sorted[$key]['estimated'][] = $estimation;
	}

}

$spentAll = 0;
$estimateAll = 0;
foreach ($sorted as $key => $data) {
	$spent = array_sum($data['spent']);
	$estimate = array_pop($data['estimated']);
	printf("spent %s of %s\n", seconds_to_humanreadable($spent), seconds_to_humanreadable($estimate));
	$spentAll += $spent;
	$estimateAll += $estimate;
}

	$ratio = ($spentAll / $estimateAll) * 100;
	printf("In iteration %s spent total is %s with tickets at done with estimation of %s (ratio = %.2f)\n", $focusIter, seconds_to_readable_hours($spentAll), seconds_to_readable_hours($estimateAll), $ratio);
	printf("In iteration %s spent total is %s with tickets at done with estimation of %s (ratio = %.2f)\n", $focusIter, seconds_to_humanreadable($spentAll), seconds_to_humanreadable($estimateAll), $ratio);

print "*/";
 ?>

</script>

<h2>Result: </h2>
Uren gelogd op tickets binnen de iteratie: <?= $urenGelogdOpIteratieTickets ?> <br>
Uren gelogd binnen de iteratie:            <?= $urenGelogdBinnenIteratie ?> <br>
Uren aan tickets binnen de iteratie:       <?= $urenAanTicketsBinnenIteratie ?> <br>
</body>
</html>
