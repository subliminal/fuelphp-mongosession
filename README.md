MongoSession
=========

MongoSession is a session driver for FuelPHP, which uses Mongo as
the database backend. It is effectively a drop-in replacement for
the DB session driver included with FuelPHP.

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

This is still VERY much in Beta. I haven't even tested all of the methods yet, 
let alone get to UnitTesting. Don't use this in production but PLEASE use 
it in development, and let me know if there are issues (or, PR if you are
awesome).
