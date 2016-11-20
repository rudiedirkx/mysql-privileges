<?php

if (empty($_SERVER['argv'])) {
	exit(1);
}

try {
	list($host, $user, $pass, $cmd) = App::parseInit(array_slice($_SERVER['argv'], 1));
}
catch (Exception $ex) {
	echo $ex->getMessage() . "\n";
	exit(1);
}

$app = new App($host, $user, $pass);
$cmd ? $app->command($cmd) : $app->cmd_help();

// === //

class App {
	function __construct($host, $user, $pass) {
		$this->db = @new mysqli($host, $user, $pass);
		if ($this->db->connect_errno) {
			exit("Connection error: [{$this->db->connect_errno}] {$this->db->connect_error}\n");
		}
	}

	function cmd_raw_user($user) {
		list($name, $host) = $this->getUser($user);

		$grants = $this->queryAll("
			SHOW GRANTS FOR '$name'@'$host'
		");

		$table = [];
		foreach ($grants as $grant) {
			$table[] = [current($grant)];
		}

		$this->table($table);

		return $this->index();
	}

	function cmd_user($user) {
		list($name, $host) = $this->getUser($user);

		$grants = $this->queryAll("
			SHOW GRANTS FOR '$name'@'$host'
		");

		$table = [];
		foreach ($grants as $grant) {
			list($what, $where, $admin) = $this->parseGrant(current($grant));
			if ($what == 'PROXY') continue;

			if (strpos($where, '.') !== false) {
				list($db, $tbl) = explode('.', $where, 2);
			}
			else {
				$db = $where;
				$tbl = '*';
			}
			$table[] = [$db, $tbl, $what, $admin ? 'Y' : ''];
		}

		$this->sortTable($table);
		$this->table($table, ['DATABASE', 'TABLE', 'PRIVILEGES', 'GRANT?']);

		return $this->index();
	}

	function cmd_users() {
		$users = $this->getUsers();
		$identities = $this->getIdentities($users);

		$table = [];
		foreach ($identities as $user) {
			$grants = $this->queryAll("
				SHOW GRANTS FOR $user
			");

			list($name, $host) = explode('@', str_replace("'", '', $user));

			$row = [];

			$row[] = $host;
			$row[] = $name;
			$row[] = count($grants);

			$table[] = $row;
		}

		$this->sortTable($table);
		$this->table($table, ['HOST', 'NAME', 'PRIVILEGES']);

		return $this->index();
	}

	function cmd_help() {
		echo "Available commands:\n";
		echo "- help\n";
		echo "- users\n";
		echo "- user <user>\n";
		// echo "- database <database>\n";
		echo "- raw-user <user>\n";

		return $this->index();
	}

	function getUser($user) {
		$users = $this->getUsers();

		if (strpos($user, '@') !== false) {
			list($name, $host) = explode('@', str_replace("'", '', $user));
			if (!isset($users[$name][$host])) {
				throw new Exception('Invalid user');
			}
		}
		else {
			if (!isset($users[$user]) || count($users[$user]) != 1) {
				throw new Exception('Invalid user');
			}

			$name = $user;
			$host = current($users[$user]);
		}

		return [$name, $host];
	}

	function parseGrant($grant) {
		if (preg_match('#^GRANT (.+?) ON (.+?) TO (.+?)( WITH GRANT OPTION)?$#', $grant, $match)) {
			$what = trim($match[1]);
			$where = str_replace('`', '', preg_replace('#\.\*$#', '', trim($match[2])));
			$admin = (bool) trim(@$match[4]);
			return [$what, $where, $admin];
		}
	}

	function getUsers() {
		$raw = $this->queryAll('
			SELECT GRANTEE
			FROM information_schema.USER_PRIVILEGES
			GROUP BY GRANTEE
			ORDER BY GRANTEE
		');
		$users = array();
		foreach ($raw as $user) {
			list($name, $host) = explode('@', str_replace("'", '', current($user)));
			$users[$name][$host] = $host;
		}

		return $users;
	}

	function getIdentities($users) {
		$identities = [];
		foreach ($users as $user => $hosts) {
			foreach ($hosts as $host) {
				$identities[] = "'$user'@'$host'";
			}
		}

		return $identities;
	}

	function index() {
		$cmd = App::read('What do you want to do?');

		$result = $this->command(self::cliToArray($cmd));
		if ($result !== -1) {
			return $result;
		}

		if ($cmd) {
			echo "\nUNKNOWN COMMAND\n";
		}

		return $this->index();
	}

	function command($words) {
		$cmd = str_replace('-', '_', array_shift($words));
		$args = $words;

		try {
			$_method = new ReflectionMethod($this, "cmd_$cmd");
			$_args = $_method->getParameters();
			$_required = 0;
			foreach ($_args as $i => $_arg) {
				if (!$_arg->isOptional()) {
					$_required = $i + 1;
				}
			}

			if ($_required > count($args)) {
				return -1;
			}

			return $_method->invokeArgs($this, $args);
		}
		catch (ReflectionException $ex) {
			return -1;
		}
	}

	function sortTable(array &$table) {
		usort($table, function($a, $b) {
			// Same col 1, compare col 2
			if ($a[0] == $b[0]) {
				return strnatcasecmp($a[1], $b[1]);
			}

			// Compare col 1
			return strnatcasecmp($a[0], $b[0]);
		});
	}

	function table(array $table, array $header = []) {
		if ($header) {
			array_unshift($table, $header);
		}

		$sizes = [];
		foreach ($table as $y => $row) {
			foreach ($row as $x => $col) {
				$sizes[$x] = max((int) @$sizes[$x], strlen((string) $col));
			}
		}

		$hr = '+';
		foreach ($sizes as $size) {
			$hr .= str_repeat('-', $size + 2) . '+';
		}

		echo "$hr\n";
		foreach ($table as $y => $row) {
			foreach ($row as $x => $col) {
				$padding = $sizes[$x] - strlen((string) $col);
				$before = is_int($col) || is_float($col);

				echo ($x > 0 ? ' ' : '') . '| ';

				if ($padding && $before) {
					echo str_repeat(' ', $padding);
				}

				echo $col;

				if ($padding && !$before) {
					echo str_repeat(' ', $padding);
				}
			}
			echo " |\n";
			echo "$hr\n";
		}
	}

	function read($question) {
		echo "\n$question\n> ";
		$handle = fopen('php://stdin', 'r');
		$line = trim(fgets($handle));
		return $line;
	}

	function queryAll($query) {
		return iterator_to_array($this->db->query($query));
	}

	function query($query) {
		$all = $this->queryAll($query);
		return reset($all);
	}

	static function cliToArray($cli) {
		return preg_split('#\s+#', trim($cli));
	}

	static function parseInit($words) {
		@list($host, $user, $pass) = array_splice($words, 0, 3);
		if (!$host || !$user || !$pass) {
			throw new Exception('Need host + user + pass');
		}

		$cmd = [];
		if ($words) {
			if ($words[0] != '--') {
				throw new Exception('Initial command must be separated by --');
			}

			$cmd = array_slice($words, 1);
		}

		return [$host, $user, $pass, $cmd];
	}
}
