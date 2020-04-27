#!/usr/bin/env php
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
if ($cmd) {
	$app->command($cmd);
}
else {
	$app->cmd_help();
	$app->listen();
}

// === //

class CommandException extends Exception {}

class App {

	/**
	 * COMMANDS
	 */

	/**
	 * grant
	 */
	public function cmd_grant() {
		$db = $this->read('Which database?');
		if (empty($db)) {
			throw new CommandException('Database is required');
		}

		$host = $this->read('Access from which host? [localhost]') ?: 'localhost';
		if (empty($host)) {
			throw new CommandException('Host is required');
		}

		$name = $this->read('User name?');
		if (empty($name)) {
			throw new CommandException('User name is required');
		}

		$users = $this->getUsers();
		if (!isset($users[$name], $users[$name][$host])) {
			throw new CommandException("User doesn't exist");
		}

		$privileges = $this->read('Which privileges? [ALL]') ?: 'ALL';

		$user = $this->getIdentity($name, $host);

		$this->execute("GRANT $privileges ON $db.* TO $user");
		$this->success('Access granted!');
		$this->cmd_user_info($user);
	}

	/**
	 * revoke
	 */
	public function cmd_revoke() {
		$user = $this->read('user@host?');
		if (empty($user)) {
			throw new CommandException('User is required');
		}

		$db = $this->read('Which database? [*]') ?: '*';

		if ($db == '*') {
			$this->execute("REVOKE ALL PRIVILEGES ON *.* FROM $user");
		}
		else {
			$this->execute("REVOKE ALL PRIVILEGES ON $db.* FROM $user");
		}

		$this->success('Access revoked!');
		$this->cmd_user_info($user);
	}

	/**
	 * create-db <database>
	 */
	public function cmd_create_db($database) {
		$this->execute("CREATE DATABASE `$database`");
		$this->success('Database created!');
	}

	/**
	 * create-user
	 */
	public function cmd_create_user() {
		$host = $this->read('Access from which host? [localhost]') ?: 'localhost';
		$name = $this->read('User name?');

		if (empty($name)) {
			throw new CommandException('User name is required');
		}

		$users = $this->getUsers();
		if (isset($users[$name], $users[$name][$host])) {
			throw new CommandException('User already exists');
		}

		$user = $this->getIdentity($name, $host);
		$pass = $this->read('Password? [random]') ?: $this->randomPassword();

		$this->execute("CREATE USER $user IDENTIFIED BY '$pass'");

		$this->success("User created! Password = '" . $this->yellow($pass) . "'.");
	}

	/**
	 * drop-user <user@host>
	 */
	public function cmd_drop_user($user) {
		list($name, $host) = $this->getUser($user);
		$user = $this->getIdentity($name, $host);

		$this->execute("DROP USER $user");

		$this->success("User dropped!");
	}

	/**
	 * dbs
	 */
	public function cmd_dbs() {
		$dbs = $this->queryAll('SHOW DATABASES');
		$dbs = array_map('reset', $dbs);
		natcasesort($dbs);

		foreach ($dbs as $db) {
			echo "- $db\n";
		}
	}

	/**
	 * db-info <database>
	 */
	public function cmd_db_info($db) {
		$users = $this->getUsers();

		$table = [];
		foreach ($users as $name => $hosts) {
			foreach ($hosts as $host) {
				$user = $this->getIdentity($name, $host);

				$grants = $this->getParsedUserGrants($user);
				foreach ($grants as $grant) {
					list($what, $where_db, , , , $admin) = $grant;
					if ($where_db == $db || $where_db == '*') {
						$table[] = [$host, $name, $what];
					}
				}
			}
		}

		$this->sortTable($table);
		$this->table($table, ['HOST', 'USER', 'PRIVILEGES']);
	}

	/**
	 * drop-db <database>
	 */
	public function cmd_drop_db($db) {
		$this->execute("DROP DATABASE `$db`");

		$this->success('Database dropped!');
	}

	/**
	 * raw-user-info <user@host>
	 */
	public function cmd_raw_user_info($user) {
		$grants = $this->queryAll("
			SHOW GRANTS FOR $user
		");

		$table = [];
		foreach ($grants as $grant) {
			$table[] = [current($grant)];
		}

		$this->table($table, ['GRANT']);
	}

	/**
	 * user-info <user@host>
	 */
	public function cmd_user_info($user) {
		list($name, $host) = $this->getUser($user);

		$grants = $this->getParsedUserGrants("'$name'@'$host'");

		$table = [];
		foreach ($grants as $grant) {
			list($what, $where_db, $where_tbl, , , $admin) = $grant;

			$table[] = [$where_db, $where_tbl, $what, $admin ? 'Y' : ''];
		}

		$this->sortTable($table);
		$this->table($table, ['DATABASE', 'TABLE', 'PRIVILEGES', 'GRANT?']);
	}

	/**
	 * users
	 */
	public function cmd_users() {
		$users = $this->getUsers();
		$identities = $this->getIdentities($users);

		$table = [];
		foreach ($identities as $user) {
			$grants = $this->getParsedUserGrants($user);

			list($name, $host) = explode('@', str_replace("'", '', $user));

			$row = [];

			$row[] = $host;
			$row[] = $name;
			$row[] = count($grants);

			$table[] = $row;
		}

		$this->sortTable($table);
		$this->table($table, ['HOST', 'NAME', 'PRIVILEGES']);
	}

	/**
	 * help
	 */
	public function cmd_help() {
		echo "Available commands:\n";
		echo "- help\n";

		echo "- users\n";
		echo "- user-info " . $this->hilite('<user@host>') . "\n";
		echo "- create-user\n";
		echo "- drop-user " . $this->hilite('<user@host>') . "\n";

		echo "- dbs\n";
		echo "- db-info " . $this->hilite('<database>') . "\n";
		echo "- create-db " . $this->hilite('<database>') . "\n";
		echo "- drop-db " . $this->hilite('<database>') . "\n";
		echo "- grant\n";
		echo "- revoke\n";
		echo "- raw-user-info " . $this->hilite('<user@host>') . "\n";
	}

	/**
	 * SUPPORT
	 */

	public function __construct($host, $user, $pass) {
		$this->db = @new mysqli($host, $user, $pass);
		if ($this->db->connect_errno) {
			exit("Connection error: [{$this->db->connect_errno}] {$this->db->connect_error}\n");
		}
	}

	protected function getUser($user) {
		$users = $this->getUsers();

		if (strpos($user, '@') !== false) {
			list($name, $host) = explode('@', str_replace("'", '', $user));
			if (!isset($users[$name][$host])) {
				throw new CommandException('Invalid user');
			}
		}
		else {
			if (!isset($users[$user]) || count($users[$user]) != 1) {
				throw new CommandException('Invalid user');
			}

			$name = $user;
			$host = current($users[$user]);
		}

		return [$name, $host];
	}

	protected function getParsedUserGrants($user) {
		$raw = $this->queryAll("SHOW GRANTS FOR $user");

		$grants = [];
		foreach ($raw as $grant) {
			if ($grant = $this->parseGrant(current($grant))) {
				$grants[] = $grant;
			}
		}

		return $grants;
	}

	protected function parseGrant($grant) {
		if (preg_match("#^GRANT (.+?) ON `?(.+?)`?\.`?(.+?)`? TO '(.+?)'@'(.+?)'( WITH GRANT OPTION)?$#", $grant, $match)) {
			$what = trim($match[1]);
			if ($what == 'USAGE') {
				return;
			}

			$where_db = trim($match[2]);
			$where_tbl = trim($match[3]);
			$who_name = trim($match[4]);
			$who_host = trim($match[5]);
			$admin = (bool) trim(@$match[6]);
			return [$what, $where_db, $where_tbl, $who_name, $who_host, $admin];
		}
	}

	protected function getUsers() {
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

	protected function getIdentity($name, $host) {
		return "'$name'@'$host'";
	}

	protected function getIdentities($users) {
		$identities = [];
		foreach ($users as $user => $hosts) {
			foreach ($hosts as $host) {
				$identities[] = $this->getIdentity($user, $host);
			}
		}

		return $identities;
	}

	protected function queryAll($query) {
		$result = $this->db->query($query);
		if (!$result) {
			throw new CommandException("[{$this->db->errno}] {$this->db->error}");
		}

		return iterator_to_array($result);
	}

	protected function query($query) {
		$all = $this->queryAll($query);
		return reset($all);
	}

	protected function execute($query) {
		$this->info("< $query");

		if ($this->db->query($query) !== true) {
			throw new CommandException("[{$this->db->errno}] {$this->db->error}");
		}

		$this->db->query('FLUSH PRIVILEGES');
	}

	protected function randomPassword() {
		$chars = array_merge(range('A', 'Z'), range('a', 'z'), range(0, 9));

		$pass = '';
		while (strlen($pass) < 12) {
			$pass .= $chars[ array_rand($chars) ];
		}

		return $pass;
	}

	protected function sortTable(array &$table) {
		usort($table, function($a, $b) {
			// Same col 1, compare col 2
			if ($a[0] == $b[0]) {
				return strnatcasecmp($a[1], $b[1]);
			}

			// Compare col 1
			return strnatcasecmp($a[0], $b[0]);
		});
	}

	protected function table(array $table, array $header = []) {
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

	/**
	 * PROCESS
	 */

	protected function index() {
		$cmd = App::read('What do you want to do?');

		$this->command(self::cliToArray($cmd));
	}

	public function listen() {
		while (true) {
			$this->index();
		}
	}

	protected function red($message) {
		// return $message;
		return "\033[0;31m$message\033[0m";
	}

	protected function green($message) {
		// return $message;
		return "\033[0;32m$message\033[0m";
	}

	protected function yellow($message) {
		// return $message;
		return "\033[0;33m$message\033[0m";
	}

	protected function error($message) {
		echo $this->red($message) . "\n";
	}

	protected function success($message) {
		echo $this->green($message) . "\n";
	}

	protected function info($message) {
		echo $this->yellow($message) . "\n";
	}

	protected function hilite($message) {
		return $this->yellow($message);
	}

	public function command($words) {
		$cmd = str_replace('-', '_', array_shift($words));
		$args = $words;

		// App exceptions
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
				return $this->error('Too few arguments');
			}
		}
		catch (ReflectionException $ex) {
			return $cmd ? $this->error('Unknown command') : null;
		}

		// Command exceptions
		try {
			return $_method->invokeArgs($this, $args);
		}
		catch (CommandException $ex) {
			return $this->error($ex->getMessage());
		}
	}

	protected function read($question) {
		echo "\n$question\n> ";
		$handle = fopen('php://stdin', 'r');
		$line = trim(fgets($handle));
		return $line;
	}

	static public function cliToArray($cli) {
		return preg_split('#\s+#', trim($cli));
	}

	static public function parseInit($words) {
		@list($host, $user, $pass) = array_splice($words, 0, 3);
		if (!$host || !$user || $pass === null) {
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
