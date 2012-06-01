<?php

/*
log_format  main  '$remote_addr - [$time_local] "$host" "$request" '
				  '$status ($bytes_sent) "$http_referer" '
				  '"$uri $args" [$request_time] "$http_user_agent"';
*/

class NginxLogParser
{
	static private $_summaryTemplate = array(
		'rows' => 0,
		'rowsFailed' => 0,
		'rowsSuccess' => 0,
		'failedRows' => array(),
		
		'timeFrom' => 9999999999999,
		'timeTo' => 0,
		
		'remoteAddress' => array(),
		'host' => array(),
		'requestProtocol' => array(),
		
		'timeMin' => 0,
		'timeMax' => 0,
		'timeAvg' => 0,
	);
	
	private $_handler;
	
	private $_summary;
	private $_time = array();
	
	public function getSummary()
	{
		return $this->_summary;
	}
	
	static public function parse($source)
	{
		$instance = new self();
		
		$instance->openHandler($source);
		
		$instance->readSource();
		
		return $instance->getSummary();
	}
	
	private function openHandler($source)
	{
		$handler = fopen($source, 'r');
		if (!$handler)
		{
			throw new Exception("Can't open handler for `$source`\n");
		}
		
		return $this->_handler = $handler;
	}
	
	private function readSource()
	{
		$this->_summary = self::$_summaryTemplate;
		$this->_time = array();
		
		while (false !== ($row = fgets($this->_handler, 8192)))
		{
			$rowArray = self::parseRow($row);
			if (!is_null($rowArray))
			{
				$this->processRow($rowArray);
				
				$this->_summary['rowsSuccess']++;
			}
			else
			{
				$this->_summary['rowsFailed']++;
				$this->_summary['failedRows'][] = $row;
			}
			$this->_summary['rows']++;
		}
		if (!feof($this->_handler))
		{
			throw new Exception("Unexpected fgets() fail");
		}
		
		$this->finalizeResults();
	}
	
	static private function parseRow($row)
	{
		$matches = null;
		$found = preg_match('#^(.*) - \[(.*)\] "(.*)" "(.{1,8}) (.*)" (\d{3}) \((\d+)\) "(.*)" "(.*) (.*)" \[(\d+\.\d+)\] "(.*)"$#isU', trim($row), $matches);
		
		if ($found > 0)
		{
			$result = array(
				'time' => $matches[2],
				'remoteAddress' => $matches[1],
				'host' => $matches[3],
				'requestProtocol' => $matches[4],
				'requestHeader' => $matches[4] . ' ' . $matches[5],
				'requestUri' => $matches[9],
				'requestQueryString' => $matches[10],
				'requestTime' => $matches[11],
				'requestUserAgent' => $matches[12],
				'referer' => $matches[8],
				'responseCode' => $matches[6],
				'responseSize' => $matches[7],
			);
			
			return $result;
		}
		
		return null;
	}
	
	private function finalizeResults()
	{
		arsort($this->_summary['remoteAddress']);
		arsort($this->_summary['host']);
		arsort($this->_summary['protocol']);
		
		uasort($this->_summary['uri'], function($a, $b){return $a['cnt'] == $b['cnt'] ? 0 : (($b['cnt'] - $a['cnt'])/abs($b['cnt'] - $a['cnt']));});
		foreach ($this->_summary['uri'] as &$value)
		{
			$value['timeMin'] = min($value['time']);
			$value['timeMax'] = max($value['time']);
			$value['timeAvg'] = self::average($value['time']);
		}
		
		$this->_summary['timeMin'] = min($this->_time);
		$this->_summary['timeMax'] = max($this->_time);
		$this->_summary['timeAvg'] = self::average($this->_time);
	}
	
	private function processRow(array $rowArray)
	{
		// Time
		$this->updateSummaryInterval($rowArray['time']);
		
		// Remote address
		$this->incrementSummaryCounter('remoteAddress', $rowArray['remoteAddress']);
		
		// Host
		$this->incrementSummaryCounter('host', $rowArray['host']);
		
		// Protocol
		$this->incrementSummaryCounter('protocol', $rowArray['requestProtocol']);
		
		// Request URI
		$uri = $rowArray['requestUri'];
		$rowArray['requestQueryString'] != '-' && $uri .= "?{$rowArray['requestQueryString']}";
		$this->incrementSummaryUriCounter('uri', $uri, $rowArray['requestTime']);
		
		// Request time
		$this->_time[] = $rowArray['requestTime'];
	}
	
	private function updateSummaryInterval($time)
	{
		$time = date_parse($time);
		$timeUnix = mktime($time["hour"], $time["minute"], $time["second"], $time["month"], $time["day"], $time["year"]);
		
		if ($this->_summary['timeFrom'] > $timeUnix)
		{
			$this->_summary['timeFrom'] = $timeUnix;
		}
		if ($this->_summary['timeTo'] < $timeUnix)
		{
			$this->_summary['timeTo'] = $timeUnix;
		}
	}
	
	private function incrementSummaryCounter($key, $value)
	{
		if (!isset($this->_summary[$key][$value]))
		{
			$this->_summary[$key][$value] = 1;
		}
		else
		{
			$this->_summary[$key][$value]++;
		}
	}
	
	private function incrementSummaryUriCounter($key, $value, $time)
	{
		if (!isset($this->_summary[$key][$value]))
		{
			$this->_summary[$key][$value] = array('cnt' => 1, 'time' => array($time));
		}
		else
		{
			$this->_summary[$key][$value]['cnt']++;
			$this->_summary[$key][$value]['time'][] = $time;
		}
	}
	
	private static function average($a)
	{
		return array_sum($a)/count($a) ;
	}
}

$summary = NginxLogParser::parse('php://stdin');

?>

<h1>Stat</h1>
<p><b><?php echo $summary['rows']; ?> lines processed.</b><?php if ($summary['rowsFailed']) { ?> Failed rows: <?php echo $summary['rowsFailed']; ?>.
	<?php } ?> Interval <?php echo $seconds = $summary['timeTo'] - $summary['timeFrom']; ?> seconds.
	From <?php echo date('Y-m-d H:i:s', $summary['timeFrom']); ?> to <?php echo date('Y-m-d H:i:s', $summary['timeTo']); ?>.</p>
<p>Average stream: <?php echo sprintf('%.3f', $summary['rows'] / $seconds); ?> rps.</p>

<h2>Request processing time stat</h2>
<table border="1" cellspacing="0" cellpadding="3">
	<tr><td>1</td><td>Min. time</td><td><?php echo $summary['timeMin']; ?></td></tr>
	<tr><td>1</td><td>Avg. time</td><td><?php echo $summary['timeAvg']; ?></td></tr>
	<tr><td>1</td><td>Max. time</td><td><?php echo $summary['timeMax']; ?></td></tr>
</table>

<h2>Remote address stat (<?php echo count($summary['remoteAddress']); ?>)</h2>
<table border="1" cellspacing="0" cellpadding="3">
<?php $i = 0; foreach ($summary['remoteAddress'] as $addr => $cnt) { $i++; ?>
	<tr><td><?php echo $i; ?></td><td><?php echo htmlspecialchars($addr); ?></td><td><?php echo $cnt ?></td></tr>
<?php } ?>
</table>

<h2>Hosts stat (<?php echo count($summary['host']); ?>)</h2>
<table border="1" cellspacing="0" cellpadding="3">
<?php $i = 0; foreach ($summary['host'] as $host => $cnt) { $i++; ?>
	<tr><td><?php echo $i; ?></td><td><?php echo htmlspecialchars($host); ?></td><td><?php echo $cnt ?></td></tr>
<?php } ?>
</table>

<h2>Protocols stat (<?php echo count($summary['protocol']); ?>)</h2>
<table border="1" cellspacing="0" cellpadding="3">
<?php $i = 0; foreach ($summary['protocol'] as $proto => $cnt) { $i++; ?>
	<tr><td><?php echo $i; ?></td><td><?php echo htmlspecialchars($proto); ?></td><td><?php echo $cnt ?></td></tr>
<?php } ?>
</table>

<h2>URI stat (<?php echo count($summary['uri']); ?>)</h2>
<table border="1" cellspacing="0" cellpadding="3">
<tr><th rowspan="2">#</th><th rowspan="2">URI</th><th rowspan="2">Cnt</th><th colspan="3">Time</th></tr>
<tr><th>Min</th><th>Avg</th><th>Max</th></tr>
<?php $i = 0; foreach ($summary['uri'] as $uri => $value) { $i++; ?>
	<tr><td><?php echo $i; ?></td><td><?php echo htmlspecialchars(wordwrap($uri, 100, ' ', true)); ?></td><td><?php echo $value['cnt'] ?></td><td><?php echo $value['timeMin'] ?></td><td<?php if ($value['timeAvg'] > 1.0) { ?> style="background-color:red;"<?php }?>><?php echo sprintf('%.3f', $value['timeAvg']) ?></td><td<?php if ($value['timeMax'] > 1.0) { ?> style="background-color:pink;"<?php }?>><?php echo $value['timeMax'] ?></td></tr>
<?php } ?>
</table>

<?php if (($c = count($summary['failedRows'])) > 0) { ?>
<h2>Failed rows (<?php echo $c ?>):</h2>
<ul>
	<?php foreach ($summary['failedRows'] as $row) { ?>
	<li><?php echo $row; ?></li>
	<?php } ?>
</ul>
<?php } ?>