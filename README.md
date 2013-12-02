MongoSession
=========

MongoSession is a session driver for FuelPHP, which uses Mongo as
the database backend. It is effectively a drop-in replacement for
the DB session driver included with FuelPHP.
_NOTE_: It would appear the parent project has been abandoned, so
until my pull request is addressed, I'll happily take on
maintenance of the project.


License
-------

Just like FuelPHP, this package is released under the MIT license.

Installation
------------

MongoSession is released as a FuelPHP package, so installation is the same as 
any other package:

1. Download the package (or clone it) into APP/packages/mongosession
2. Copy the included config.php file to APP/config/session.php

3. Edit your APP/fuel/config/config.php file and add mongosession to your 
always_load.

	```php
	'packages' => array(
		'mongosession'
	);
	```

Warning
-------
It's not beta or alpha anymore and is being used in production, but in my own project. Please test extensivly on your own system, and if you have any issues, patches, or changes, fixes and bug reports are welcome!
