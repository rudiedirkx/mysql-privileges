MySQL Privileges CLI
====

Start app:

	php privileges.php host user password

Use commands:

* `help` - lists commands
* `users` - lists users
* `user` <name> - lists grants for a user
* `raw-user` <name> - lists raw grants for a user
* `db` <database> - lists user grants to this database
* `create-user` - creates a user
* `create-db <database>` - creates a database

Or start it with a command:

	php privileges.php host user password -- user root
