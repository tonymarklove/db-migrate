db-migrate
----

db-migrate is a simple database migration script for MySQL and PHP.

By maintaining a set of forward and reverse SQL migrations db-migrate allows
you to safely upgrade a databse schema along-side the related code commits.


Requirements
---

1. MySQL
2. PHP + mysqli extension


Getting Started
---

Clone or extra db-migrate to somewhere you can access it with a web browser.

  Warning: It would be a good idea to make sure that you restrict access as
	required. Anyone who can gain access to db-migrate can potentially alter your
	database.
	
You are now able to visit db-migrate.php in your browser to view and manage
your current migration state.

Please read the following section on migrations to get your first migrations
working.


Migrations
---

Migrations are a collection of SQL scripts. Each script corresponds to a single
migration, but has both forward and backwards directions separated by a
dividing line with the following contents: <<<<<MIGRATE-UNDO>>>>>

SQL scripts are by default loaded from a directory named 'db' in the same
directory as db-migrate.php, but this directory can be changed.

Each file is dated, and should have a name with the following format:

	YYYY-MM-DD-mm-ss-Dash-separated-description.sql

Dates are used to maintain order even when multiple contributors are merging
commits with database changes.

Here is an example of a complete valid script:

	| CREATE TABLE IF NOT EXISTS `example` (
	|   `column` VARCHAR(32) NOT NULL
	| );
	|
	| <<<<<MIGRATE-UNDO>>>>>
	|
	| DROP TABLE `example`;

	
Configuration
---

You may create an optional configuration file to save you having to log in each
time you use db-migrate.

Create a file named config.php and add one or more of the following PHP
variables:

$GLOBALS['DB_HOST'] = "localhost";
$GLOBALS['DB_NAME'] = "example";
$GLOBALS['DB_USERNAME'] = "root";
$GLOBALS['DB_PASSWORD'] = "password";
