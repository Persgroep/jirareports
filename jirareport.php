<?php
if ( ! file_exists('jiraconfig.php')) {
	trigger_error('Make sure you create a jiraconfig.php with valid credentials', E_USER_ERROR);
}

include_once('jiraconfig.php');

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

	public function search($jql)
	{
		$jql = urlencode($jql);
		return $this->impl->call("search?jql={$jql}&startAt=0&maxResults=10000&expand=changelog");
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
$versions = $jiraApi->getVersions('ATONE');
$versionIndexes = array();
foreach ($versions as $version)
	$versionIndexes[] = $version->name;

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
$issues = $jiraApi->search('project = ' . 'ATONE' . ' AND '.$fixversion.' ORDER BY '.$orderby.' component, subtasks ASC, Rank');

/* DEVHOEKJE :-)
$issue = $jiraApi->search('key = ATONE-7');
//$issue = $jiraApi->getIssue('ATONE-7?expand=changelog');
include_once("krumo/class.krumo.php");
krumo($issue);die;
foreach ($issue->issues->changelog->histories as $historyItem) {
	printf("history: %s<br/>\n", print_r($historyItem,1));
}
die;
*/

// Get current iteration name (description field)
$currentIterationName = '';
$currentIterationTotal = 0;
foreach ($versions as $index => $version)
{
	if ($iteration == $version->name)
	{
		$currentIterationName = $version->description;
	}
}

if(isset($_GET['groupby']))
{
	$groupBy = $_GET['groupby'];
}

$groupByField = '';
#// Sort issues by product version
#/// Get some custom field ids from JiraConfig
#$customfieldProductversion = \config\JiraConfig::CUSTOMFIELD_ID_PRODUCT_VERSION;
#$customfieldProjectsection = \config\JiraConfig::CUSTOMFIELD_ID_PROJECT_SECTION;
#
#switch ($groupBy) {
#	case 'version':
#		$groupByField = \config\JiraConfig::CUSTOMFIELD_ID_PRODUCT_VERSION;
#		break;
#	case 'section':
#		$groupByField = \config\JiraConfig::CUSTOMFIELD_ID_PROJECT_SECTION;
#		break;
#	default:
#		$groupByField = \config\JiraConfig::CUSTOMFIELD_ID_PRODUCT_VERSION;
#		$groupBy = 'version';
#		break;
#}
#
#$customfieldProject = \config\JiraConfig::CUSTOMFIELD_ID_PROJECT;


$subtaskByParentIdMapping = array();
$allSubtasksArray = array();
$allParentsArray = array();
$parentToChildMapping = array();

$groupedIssues = array();
$groups = array('version', 'section');

function isTicketDone($ticketStatus)
{
	return $ticketStatus == 'Done';
}

if (is_array($issues->issues))
{
	foreach ($issues->issues as $issue)
	{
		if ( ! is_array($groupedIssues[get_group_by($issue)]))
			$groupedIssues[get_group_by($issue)] = array();

		$groupedIssues[get_group_by($issue)][] = $issue;

		if( ! empty($issue->fields->parent))
		{
			// Gather components for display as well
			$components = array();
			foreach ($issue->fields->components as $component)
			{
				$components[] = $component->name;
			}

			// Calculate remaining estimate for each issue
			$diff = $issue->fields->timeestimate;
			if ($diff < 0)
				$diff = 0;

			if (/*\config\JiraConfig::*/isTicketDone($issue->fields->status->name))
				$diff = 0;

			$subtaskByParentIdMapping[$issue->fields->parent->key][$issue->key] = array(
				null,
				$issue->key,
				$issue->fields->summary,
				$issue->fields->issuetype->name,
				$issue->fields->priority->iconUrl . '|' . $issue->fields->priority->name,
				seconds_to_readable_hours($issue->fields->timespent),
				seconds_to_readable_hours($issue->fields->timeoriginalestimate),
				seconds_to_readable_hours($diff),
				$issue->fields->status->name . '|' . $issue->fields->assignee->name,
				$issue->fields->duedate,
				implode(', ', $components),
				'',
#				$issue->fields->$customfieldProject->value,
#				$issue->fields->$customfieldProjectsection->value
			);

			$allSubtasksArray[] = $issue->key;
			$parentToChildMapping[$issue->key] = $issue->fields->parent->key;
		}

		if( ! empty($issue->fields->subtasks))
		{
			$allParentsArray[] = $issue->key;
		}
	}
}

// Build results array that will be printed as a table in HTML
$results = array();
$columnsIndexToName = array('#', 'Key', 'Summary', 'Issue', 'Priority', 'Timespent', 'Estimate', 'Remainder', 'Status', 'Due date', 'Component/s', 'Subtasks'/*, 'Project', 'Project section'*/);
$columnsNameToIndex = array_flip($columnsIndexToName);

foreach ($groupedIssues as $group => $issues)
{
	// Calculate total per product version
	$remainder_total = 0;
	foreach ($issues as $issue)
	{
		$diff = $issue->fields->timeestimate;
		if ($diff <= 0)
			continue;

		if ( ! isTicketDone($issue->fields->status->name))
		{
			$remainder_total += $diff;
			$currentIterationTotal += $diff;
		}
	}

	// Create header in table for each product version
	$results[] = array('header', array($group,
		seconds_to_humanreadable($remainder_total, TIMETRACKING_DAYS_IN_WEEK, TIMETRACKING_HOURS_IN_DAY),
		$remainder_total / 3600));

	foreach ($issues as $issue)
	{
		// Calculate remaining estimate for each issue
		$diff = $issue->fields->timeestimate;
		if ($diff < 0)
			$diff = 0;

		if (isTicketDone($issue->fields->status->name))
			$diff = 0;

		if(in_array($issue->key, $allSubtasksArray) && in_array($issue->fields->parent->key, $allParentsArray))
		{
			continue;
		}

		// Gather keys for subtasks for display only
		$subtaskKeys = array();
		foreach ($issue->fields->subtasks as $subtask)
		{
			$subtaskKeys[] = $subtask->key;
		}

		// Gather components for display as well
		$components = array();
		foreach ($issue->fields->components as $component)
		{
			$components[] = $component->name;
		}

		if (isset($_GET['x']) && $issue->key == 'DEV-4837')
			die(dump($issue));

		// Fill the array
		static $counter = 1;
		$results[] = array('value', array(
			$counter++,
			$issue->key,
			$issue->fields->summary,
			$issue->fields->issuetype->name,
			//seconds_to_humanreadable($issue->fields->timespent,
			//	\config\JiraConfig::TIMETRACKING_DAYS_IN_WEEK, \config\JiraConfig::TIMETRACKING_HOURS_IN_DAY),
			//seconds_to_humanreadable($issue->fields->timeoriginalestimate,
			//	\config\JiraConfig::TIMETRACKING_DAYS_IN_WEEK, \config\JiraConfig::TIMETRACKING_HOURS_IN_DAY),
			//seconds_to_humanreadable($diff,
			//	\config\JiraConfig::TIMETRACKING_DAYS_IN_WEEK, \config\JiraConfig::TIMETRACKING_HOURS_IN_DAY),
			$issue->fields->priority->iconUrl . '|' . $issue->fields->priority->name,
			seconds_to_readable_hours($issue->fields->timespent),
			seconds_to_readable_hours($issue->fields->timeoriginalestimate),
			seconds_to_readable_hours($diff),
			$issue->fields->status->name . (isTicketDone($issue->fields->status->name) ? ' : ' . $issue->fields->resolution->name : '') . '|' . $issue->fields->assignee->name,
			$issue->fields->duedate,
			implode(', ', $components),
			implode(', ', $subtaskKeys),
#			$issue->fields->$customfieldProject->value,
#			$issue->fields->$customfieldProjectsection->value
		));
	}
}
//var_dump($allSubtasksArray, $subtaskMapping);die;

function printTableRow($dat, $index, $summaryKey = '', $isSubtask = false)
{
	$classHtml = '';
	$dat = htmlspecialchars($dat);
	$columnsIndexToName = array('#', 'Key', 'Summary', 'Issue', 'Priority', 'Timespent', 'Estimate', 'Remainder', 'Status', 'Due date', 'Component/s', 'Subtasks', 'Project', 'Project section');
	$columnsNameToIndex = array_flip($columnsIndexToName);
	switch ($index)
	{
		case $columnsNameToIndex['Priority']:
			list ($imageUrl, $label) = explode('|', $dat);
			$dat = "<img border=0 src=\"$imageUrl\" title=\"$label\" />";
			break;
		case $columnsNameToIndex['Status']:
			list ($status, $assignee) = explode('|', $dat);
			if ($status == 'Ready for Development')
				$status = 'Rdy for dev';
			if (empty($assignee))
				$dat = "<nobr>$status</nobr>";
			else
				$dat = "<a href=\"javascript:void(0);\" title=\"assignee: $assignee\"><nobr>$status</nobr></a>";
			break;
		case $columnsNameToIndex['Key']:
			$dat = $isSubtask ? 'Subtask': "<a href=\"https://persgroep.atlassian.net/browse/{$dat}\">{$dat}</a>";
			break;
		case $columnsNameToIndex['Remainder']:
			if ($dat != '')
				$classHtml = 'class="highlight"';
			break;
		case $columnsNameToIndex['Subtasks']:
			if( ! $isSubtask)
			{
				$items = explode(', ', $dat);
				array_walk($items, function (&$value) { $value = "<a href=\"https://persgroep.atlassian.net/browse/{$value}\">{$value}</a>"; });
				$dat = implode(', ', $items);
			}
			break;
		case $columnsNameToIndex['Summary']:
			if($isSubtask)
			{
				$dat = "<a href=\"https://persgroep.atlassian.net/browse/{$summaryKey}\">{$summaryKey}</a> " . $dat;
			}
			break;
	}
	if (empty($dat))
		$dat = '&nbsp;';

	return "<td {$classHtml}>{$dat}</td>\n";
}


?>
<script type="text/javascript" src="jquery.js"></script>
<script type="text/javascript">
	$(document).ready(function(){
		$('table tr').hover(
			function () { $(this).data('orig-bg-color', $(this).css('background-color')); $(this).css('background-color', '#CCE6FF'); },
			function () { $(this).css('background-color', $(this).data('orig-bg-color')); });
		$('#select_iteration').change(function () {
			window.location.replace('?iteration=' + encodeURIComponent($(this).val()) + '&groupby=' + encodeURIComponent($('#select_version').val()));
			$(this).prop('disabled', true); });
		$('#select_version').change(function () {
				window.location.replace('?iteration=' + encodeURIComponent($('#select_iteration').val()) + '&groupby=' + encodeURIComponent($(this).val()));
			$(this).prop('disabled', true); });

		$('table tr').each(function () {
			$(this).click(function () {
				if ($(this).attr('class') == 'header_row')
				{

					var $current = $(this).next();
					do {
						if ($current.length != 0 && $current.attr('class') != 'header_row')
						{
							if ($(this).data('is_collapsed'))
							{
								$current.removeClass('hidden_from_nonprint')
							} else {
								$current.addClass('hidden_from_nonprint')
							}

							$current = $current.next();
						}
						else
						{
							break;
						}
					}
					while (true);

					$(this).data('is_collapsed', ! $(this).data('is_collapsed'));
				}
			});
			if ($(this).attr('class') == 'header_row')
			{
				$(this).data('is_collapsed', true);
			}
			else
			{
				$(this).addClass('hidden_from_nonprint');
			}
		});
	});

</script>
<style type="text/css">
	html, body, table, td { font: 9px verdana; }
	th { text-align: left; font-size: 16px; padding-top: 20px; }
	td { border-bottom: solid 1px #c0c0c0; }

	.subheader_row * { font-weight: bold; }
	.header_row { background-color: #c0c0c0; cursor: pointer; }
	.highlight { background-color: #FFFFC1; }
/*	.hidden_from_nonprint { display: none; }*/

</style>
<style media='print' type='text/css'>
	#select_iteration { display: none; }
		/* The following does not appear to work properly. Block elements are not properly renderd before printing.
		  Only when they were actually rendered in the browser window before printing.
		  On second thoughts, this is a more desired feature as it introduces more control w/regards to what and what not to print*/
		/* .hidden_from_nonprint { display: block; } */
</style>

<p><h2>Iteration:</h2></p>
<select id="select_iteration">
	<option value="">-- select iteration --</option>
	<option value="">None</option>
	<?php
	foreach ($versions as $index => $version) {
		$selected = '';
		if ($iteration == $version->name)
			$selected = ' selected="selected" ';
		print '<option '. $selected .' value="' . htmlspecialchars($version->name) . '">' . htmlspecialchars($version->name) . '</option>';
	}
	?>
</select>
<div  style="display:none;">
<p><h2>Group By:</h2></p>

<select id="select_version">
	<option value="">Group by:</option>
	<?php
	foreach ($groups as $group) {
		$selected = '';
		if ($groupBy == $group)
			$selected = ' selected="selected" ';
		print '<option '. $selected .' value="' . htmlspecialchars($group) . '">' . htmlspecialchars($group) . '</option>';
	}
	?>
</select>
</div>

<h1>Current iteration: <?php print htmlspecialchars($currentIterationName); ?></h1>
<h2>Total remaining estimate: <?php print htmlspecialchars(seconds_to_humanreadable($currentIterationTotal, TIMETRACKING_DAYS_IN_WEEK, TIMETRACKING_HOURS_IN_DAY)); ?></h2>
<h2>Total remaining estimate in hours: <?php print htmlspecialchars($currentIterationTotal / 3600); ?></h2>

<table border=0>
	<tbody>
	<?php
	foreach ($results as $result)
	{
		list($type, $data) = $result;
		switch ($type)
		{
			case 'header':
				list($header, $remainder, $remainder_hours) = $data;
				?>
				<tr class="header_row">
					<th colspan="5"><?php print htmlspecialchars($header); ?></th>
					<th colspan="6"><?php print htmlspecialchars($remainder); ?></th>
					<th colspan="3">Remaining hours: <?php print htmlspecialchars($remainder_hours); ?></th>
				</tr>
				<tr class="subheader_row">
					<?php
					foreach ($columnsIndexToName as $col)
					{
						$col = htmlspecialchars($col);
						print "<td>{$col}</td>\n";
					}
					?>
				</tr>
				<?php
				break;
			case 'value':
				?>
				<tr>
					<?php
					foreach ($data as $index => $dat)
					{
						print printTableRow($dat, $index);
					}

					$subtasks = $subtaskByParentIdMapping[$data[1]];

					if(is_array($subtasks) && ! empty($subtasks))
					{
						foreach($subtasks as $singleTaskData)
						{
							print "</tr><tr>";
							foreach($singleTaskData as $index => $cell)
							{
								print printTableRow($cell, $index, $singleTaskData[1], true);
							}
						}
					}
					?>
				</tr>
				<?php
				break;
		}
	}
	?>
	</tbody>
</table>
