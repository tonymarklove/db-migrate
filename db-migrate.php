<?php

// Set up variables just so they exist.
$GLOBALS['DB_HOST'] = '';
$GLOBALS['DB_NAME'] = '';
$GLOBALS['DB_USERNAME'] = '';
$GLOBALS['DB_PASSWORD'] = '';

if (file_exists(dirname(__FILE__) . '/config.php')) {
	require(dirname(__FILE__) . '/config.php');
}

error_reporting(E_ALL);
set_time_limit(120);
date_default_timezone_set('UTC');

// Start up the mysqli connection.
function db_connect($host, $user, $pass, $dbName, $create) {
	$db = new mysqli($host, $user, $pass);
	if ($errNo = mysqli_connect_errno()) {
		printf("Connect failed: %s\n", mysqli_connect_error());
		return;
	}
	
	if (!$db->select_db($dbName)) {
		if ($create) {
			printf("Creating database: %s\n", $dbName);
			$db->query("create database {$dbName}");
			$db->select_db($dbName);
		} else {
			printf("Connect failed: %s\n", $db->error);
		}
	}

	// Set utf8 character set.
	if(method_exists($db, 'set_charset') && !$db->set_charset("utf8")) {
	    printf("Error loading character set utf8: %s\n", $db->error);
		exit;
	} else {
	    printf("Current character set: %s\n", $db->character_set_name());
	}

	return $db;
}

// Some small helper functions.
function param($name, $default=null) {
	return isset($_REQUEST[$name]) ? $_REQUEST[$name] : $default;
}

function timestampToSQL($timestamp) {
	return date('Y-m-d H:i:s', $timestamp);
}

function gzUncompressedSize($file) {
	$handle = fopen($file, 'r');
	fseek($handle, -4, SEEK_END);
	$buf = fread($handle, 4);
	$size = end(unpack("V", $buf));
	fclose($handle);
	return $size;
}


function do_query($sql) {
	global $db;
	$results = array();

	if ($result = $db->query($sql)) {
		// Success, but is it a result set?
		if ($result !== true) {
			while ($row = $result->fetch_assoc()) {
				$results[] = $row;
			}

			$result->close();
		}

	}

	return $results;
}

function do_multi_query($sql) {
	global $db;
	if (!trim($sql)) {
		return true;
	}

	/* execute multi query */
	if ($db->multi_query($sql)) {
			for (;;) {
				/* store first result set */
				$results = $db->store_result();
				if ($results) {
					$results->free();
				}

				if (!$db->next_result()) {
					if ($db->error != '') {
						echo $db->error . "\n";
						return false;
					}
					break;
				}
			}
	} else {
		echo $db->error . "\n";;
		return false;
	}

	return true;
}


// We use the table schema_info in the database to keep track of which versions
// have been applied. This function creates this table if it doesn't already
// exist.
function createTable() {
	do_query(<<<SQL
CREATE TABLE IF NOT EXISTS `schema_info` (
`version_date` DATETIME NOT NULL ,
PRIMARY KEY ( `version_date` )
) ENGINE = MYISAM ;
SQL
	);
}

function getAppliedVersions() {
	$results = do_query(<<<SQL
SELECT version_date
FROM schema_info
SQL
	);

	$versions = array();
	foreach ($results as $row) {
		$versions[strtotime($row['version_date'])] = true;
	}

	return $versions;
}

function getMigrations() {
	$migrations = array();
	$dirName = param('mig', 'db');
	$dir = opendir($dirName);

	while ($file = readdir($dir)) {
		if ($file[0] !== '.' ){
			$migration = parseFilename($file);

			// Read file contents.
			$contents = readMigrationFile($dirName . '/' . $file);

			$migrations[$migration['date']]['sql'] = parseFile($contents);
			$migrations[$migration['date']]['desc'] = $migration['desc'];
		}
	}
	// Files are not necessarily read in ascending order so the array must
	// be sorted to guarantee that the files will be in the correct order.
	ksort($migrations);
	closedir($dir);
	return $migrations;
}

function applyVersion($date) {
	do_query(<<<SQL
INSERT INTO `schema_info` (version_date)
VALUES ('{$date}')
SQL
	);
}

function removeVersion($date) {
	do_query(<<<SQL
DELETE FROM `schema_info`
WHERE `version_date` = '{$date}'
LIMIT 1
SQL
	);
}

function readMigrationFile($file) {
	$info = pathinfo($file);

	if ($info['extension'] == 'gz') {
		$handle = gzopen($file, "r");
		$contents = gzread($handle, gzUncompressedSize($file));
		gzclose($handle);
	} else {
		$handle = fopen($file, "r");
		$contents = fread($handle, filesize($file));
		fclose($handle);
	}

	return $contents;
}

function parseFile($content) {
	// Parse the file into do and undo migrations.
	$results = array();
	$split = explode('<<<<<MIGRATE-UNDO>>>>>', $content);
	$results['do'] = $split[0];
	$results['undo'] = $split[1];
	return $results;
}

function parseFilename($name) {
	// Current format is yyyy-mm-dd-hh-mm followed by the description with
	// words separated by hypens (-).
	$time = str_replace('-', ':', substr($name, 11, 5));
	$date = strtotime(substr($name, 0, 10) . ' ' . $time);
	$desc = str_replace('-', ' ', substr($name, 17, -3));

	return array('name'=>$name, 'date'=>$date, 'desc'=>$desc);
}

function rebaseBase($versions, $migrations) {
	$base = 0;
	foreach ($migrations as $date=>$migration) {
		if (!isset($versions[$date])) {
			break;
		}
		$base = $date;
	}
	return $base;
}

function migrate($versions, $migrations, $limit=0, $fake=false) {
	$i = 0;

	foreach ($migrations as $date=>$migration) {
		if (isset($versions[$date])) {
			// Already applied.
			$i++;
			continue;
		}

		if ($limit && $i >= $limit) {
			break;
		}

		if (!$fake) {
			$sql = $migration['sql']['do'];

			if (!do_multi_query($sql)) {
				$failed = $i+1;
				echo "Stopping due to error in migration #{$failed}\n";
				break;
			}
		}

		// Register this version in the database.
		applyVersion(timestampToSQL($date));

		$i++;
	}
}

// Undo $number from the latest version. This is not latest applied version,
// it's from the entire list of migrations.
function undo($versions, $migrations, $number, $fake=false) {
	$keys = array_keys($migrations);
	$numMig = count($migrations);

	if (!is_numeric($number)) {
		return;
	}

	if ($number > $numMig) {
		$number = $numMig;
	}

	for ($i=0; $i < $number; $i++) {
		$date = $keys[$numMig-1-$i];

		if (!isset($versions[$date])) {
			// This migration was not applied.
			continue;
		}

		if (!$fake) {
			$sql = $migrations[$date]['sql']['undo'];

			if (!do_multi_query($sql)) {
				break;
			}
		}

		// Unregister from the database.
		removeVersion(timestampToSQL($date));
	}
}

function calcUndo($versions, $migrations, $earliest=1) {
	$keys = array_keys($migrations);

	if (!is_numeric($earliest)) {
		$earliest = strtotime($earliest);
	}

	$back = 0;
	$undos = 0;

	for ($i=count($migrations)-1; $i>=0; $i--) {
		$date = $keys[$i];

		if ($date < $earliest) {
			break;
		}

		$back++;
		if (isset($versions[$date])) {
			$undos++;
		}
	}

	return array('back'=>$back, 'undos'=>$undos);
}


// Strategies.
function strat_rebase($versions, $migrations) {
	$base = rebaseBase($versions, $migrations);
	undo($versions, $migrations, $base);
	$versions = getAppliedVersions();
	migrate($versions, $migrations);
}

function strat_merge($versions, $migrations) {
	migrate($versions, $migrations);
}

function strat_linear($versions, $migrations) {
	migrate($versions, $migrations);
}

function strat_undo($versions, $migrations, $number) {
	undo($versions, $migrations, $number);
}

function strat_fakeapply($versions, $migrations, $limit) {
	undo($versions, $migrations, count($migrations)-$limit, true);
	migrate($versions, $migrations, $limit, true);
}

function strat_apply($versions, $migrations, $limit) {
	undo($versions, $migrations, count($migrations)-$limit);
	migrate($versions, $migrations, $limit);
}


function login() {
	if (param('host') && param('login') && param('db')) {
		return array('host'=>param('host'), 'login'=>param('login'), 'pass'=>param('pass'), 'db'=>param('db'));
	} elseif($GLOBALS['DB_HOST'] && $GLOBALS['DB_NAME'] && isset($GLOBALS['DB_USERNAME'])){
		return array('host' => $GLOBALS['DB_HOST'], 'login' => $GLOBALS['DB_USERNAME'], 'pass' => $GLOBALS['DB_PASSWORD'], 'db' => $GLOBALS['DB_NAME']);
	}
	return false;
}


//----
// Above this point is the actual library. Below is just the user interface.

$versions = array();
$migrations = array();
$user = false;
$db = null;


function run() {
	global $versions, $migrations, $user, $db;

	$user = login();

	if ($user) {
		$db = db_connect($user['host'], $user['login'], $user['pass'], $user['db'], param('create'));
		createTable();
		$versions = getAppliedVersions();
	}
	
	$migrations = getMigrations();

	// Run a strategy selected by the user.
	$strat = param('strat');
	if ($strat) {
		if ($strat == 'undo') {
			$earliest = param('earliest');
			$revert = param('revert');

			if (is_numeric($revert)) {
				if (param('confirm') == 'true') {
					strat_undo($versions, $migrations, $revert);
				} else {
					echo "You are about to rollback to {$revert} versions from the latest. <a href=\"?strat=undo&revert={$revert}&confirm=true\">Continue</a>";
				}
			} else {
				if ($earliest === 'all') {
					$calc = calcUndo($versions, $migrations);
				} elseif ($earliest) {
					$calc = calcUndo($versions, $migrations, strtotime($earliest));
				} else {
					$calc = array('back'=>0, 'undos'=>0);
				}

				echo "This undo will rollback to {$calc['back']} versions from the latest, reverting {$calc['undos']} applied migrations. <a href=\"?strat=undo&revert={$calc['back']}&confirm=true\">Continue</a>";
			}
		} elseif ($strat == 'apply') {
			$limit = param('limit');
			if (is_numeric($limit)) {
				if (param('fake')) {
					strat_fakeapply($versions, $migrations, $limit);
				} else {
					strat_apply($versions, $migrations, $limit);
				}
			}
		} else {
			$func = "strat_{$strat}";
			if (function_exists($func)) {
				$func($versions, $migrations);
			}
		}

		$versions = getAppliedVersions();
	}
}



?>
<!DOCTYPE HTML>

<html>
	<head>
		<title>Database Migration</title>

		<style>
			ol {
				list-style-position: inside;
				clear: left;
				margin: 0;
				padding: 0;
			}

			li {
				cursor: hand;
				cursor: pointer;
				padding: 5px;
			}

			.dbmsg {
				border: 1px solid #212121;
				line-height: 1.6;
				padding: 5px;
			}

			.padv {
				padding: 20px 0;
			}

			form {
				margin-top: 20px;
			}

			div#keys {
				float: left;
				margin: 20px;
			}

			div.key {
				float: left;
				width: 100px;
				text-align: center;
			}

			.applied {
				background: #00ff00;
			}

			.missing {
				background: #ffffff;
			}

			.addition {
				background: #ffff00;
			}

			.deletion {
				background: #ff0000;
			}
		</style>

		<script>
			window.onload = function() {
				var list = document.getElementsByTagName("ol")[0];
				var items = document.getElementsByTagName("li");

				var limitField = document.forms[0].limit;

				var markingTbl = {
					applied: "applied",
					missing: "addition",
					addition: "addition",
					deletion: "applied"
				};

				var unmarkingTbl = {
					applied: "deletion",
					missing: "missing",
					addition: "missing",
					deletion: "deletion"
				};

				list.onclick = function(e) {
					var e = window.event || e;
					var i = 0;
					var marking = true;

					for (i=0; i<items.length; i++) {
						var item = items[i];

						if (marking) {
							item.className = markingTbl[item.className];
						} else {
							item.className = unmarkingTbl[item.className];
						}
						
						target = e.srcElement || e.target;
						if (item === target) {
							limitField.value = i+1;
							marking = false;
						}
					}
				};
			};
		</script>
	</head>

	<body>
		<pre class="dbmsg"><?php run() ?></pre>

		<form method="post">
			<div id="login">
				<label for="host">Host</label>
				<input type="text" name="host" value="<?php echo param('host', $GLOBALS['DB_HOST']); ?>" />
				<label for="login">Login</label>
				<input type="text" name="login" value="<?php echo param('login', $GLOBALS['DB_USERNAME']); ?>" />
				<label for="pass">Password</label>
				<input type="password" name="pass" value="<?php echo param('pass', $GLOBALS['DB_PASSWORD']); ?>" />
				<label for="db">Database</label>
				<input type="text" name="db" value="<?php echo param('db', $GLOBALS['DB_NAME']); ?>" />
				<label for="db">Migrations</label>
				<input type="text" name="mig" value="<?php echo param('mig', 'db'); ?>" />
			</div>

			<?php if ($user) { ?>
			<div id="keys">
				<div class="key applied">Applied</div>
				<div class="key missing">Not Applied</div>
				<div class="key addition">Addition</div>
				<div class="key deletion">Deletion</div>
			</div>

			<br style="clear: left;" />

			<input type="hidden" name="strat" value="apply" />
			<ol>
				<?php foreach ($migrations as $date=>$migration) { ?>
				<li class="<?php echo isset($versions[$date]) ? 'applied' : 'missing'; ?>" data-date="<?php echo date('Y-m-d H:i', $date); ?>"><?php echo date('Y-m-d H:i', $date); ?> &mdash; <?php echo $migration['desc']; ?></li>
				<?php } ?>
			</ol>
			<?php } ?>

			<div class="padv">
				<?php if ($user) { ?>
				<label for="limit">Apply to:</label> <input type="text" name="limit" value="" />
				<label for="fake">Fake:</label> <input type="checkbox" name="fake" id="fake" <?php echo param('fake') ? 'checked' : '' ?> />
				<label for="create">Create Database:</label> <input type="checkbox" name="create" id="create" <?php echo param('create') ? 'checked' : '' ?> />
				<input type="submit" value="Apply" />
				<?php } ?>
			</div>
		</form>
	</body>
</html>
