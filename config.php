<?php
	// *******************************************
	// *** Database configuration (important!) ***
	// *******************************************

	putenv('TTRSS_DB_TYPE=mysql');
	putenv('TTRSS_DB_HOST=mysql');
	putenv('TTRSS_DB_USER=ttrss');
	putenv('TTRSS_DB_NAME=ttrss');
	putenv('TTRSS_DB_PASS=ttrss');
	putenv('TTRSS_DB_PORT=3306');

	putenv('TTRSS_MYSQL_CHARSET=UTF8');
	// Connection charset for MySQL. If you have a legacy database and/or experience
	// garbage unicode characters with this option, try setting it to a blank string.

	// ***********************************
	// *** Basic settings (important!) ***
	// ***********************************

	putenv('TTRSS_SELF_URL_PATH=https://rss.seward.im/');
	// Full URL of your tt-rss installation. This should be set to the
	// location of tt-rss directory, e.g. http://example.org/tt-rss/
	// You need to set this option correctly otherwise several features
	// including PUSH, bookmarklets and browser integration will not work properly.

	putenv('TTRSS_FEED_CRYPT_KEY=');
	// WARNING: mcrypt is deprecated in php 7.1. This directive exists for backwards
	// compatibility with existing installs, new passwords are NOT going to be encrypted.
	// Use update.php --decrypt-feeds to decrypt existing passwords in the database while
	// mcrypt is still available.

	// Key used for encryption of passwords for password-protected feeds
	// in the database. A string of 24 random characters. If left blank, encryption
	// is not used. Requires mcrypt functions.
	// Warning: changing this key will make your stored feed passwords impossible
	// to decrypt.
	
	putenv('TTRSS_SINGLE_USER_MODE=true');
	// Operate in single user mode, disables all functionality related to
	// multiple users and authentication. Enabling this assumes you have
	// your tt-rss directory protected by other means (e.g. http auth).

	putenv('TTRSS_SIMPLE_UPDATE_MODE=false');
	// Enables fallback update mode where tt-rss tries to update feeds in
	// background while tt-rss is open in your browser. 
	// If you don't have a lot of feeds and don't want to or can't run 
	// background processes while not running tt-rss, this method is generally 
	// viable to keep your feeds up to date.
	// Still, there are more robust (and recommended) updating methods 
	// available, you can read about them here: http://tt-rss.org/wiki/UpdatingFeeds

	// *****************************
	// *** Files and directories ***
	// *****************************

	putenv('TTRSS_PHP_EXECUTABLE=/usr/local/bin/php');
	// Path to PHP *COMMAND LINE* executable, used for various command-line tt-rss 
	// programs and update daemon. Do not try to use CGI binary here, it won't work. 
	// If you see HTTP headers being displayed while running tt-rss scripts, 
	// then most probably you are using the CGI binary. If you are unsure what to 
	// put in here, ask your hosting provider.

	putenv('TTRSS_LOCK_DIRECTORY=/lock');
	// Directory for lockfiles, must be writable to the user you run
	// daemon process or cronjobs under.

	putenv('TTRSS_DAEMON_SLEEP_INTERVAL=60');

	putenv('TTRSS_CACHE_DIR=/cache');
	// Local cache directory for RSS feed content.

	putenv('TTRSS_ICONS_DIR=feed-icons');
	putenv('TTRSS_ICONS_URL=feed-icons');
	// Local and URL path to the directory, where feed favicons are stored.
	// Unless you really know what you're doing, please keep those relative
	// to tt-rss main directory.

	// **********************
	// *** Authentication ***
	// **********************

	// Please see PLUGINS below to configure various authentication modules.

	putenv('TTRSS_AUTH_AUTO_CREATE=false');
	// Allow authentication modules to auto-create users in tt-rss internal
	// database when authenticated successfully.

	putenv('TTRSS_AUTH_AUTO_LOGIN=false');
	// Automatically login user on remote or other kind of externally supplied
	// authentication, otherwise redirect to login form as normal.
	// If set to true, users won't be able to set application language
	// and settings profile.

	// *********************
	// *** Feed settings ***
	// *********************

	putenv('TTRSS_FORCE_ARTICLE_PURGE=0');
	// When this option is not 0, users ability to control feed purging
	// intervals is disabled and all articles (which are not starred) 
	// older than this amount of days are purged.

	// **********************************
	// *** Cookies and login sessions ***
	// **********************************
	
	putenv('TTRSS_SESSION_COOKIE_LIFETIME=31536000');
	// Default lifetime of a session (e.g. login) cookie. In seconds, 
	// 0 means cookie will be deleted when browser closes.

	// ***************************************
	// *** Other settings (less important) ***
	// ***************************************

	putenv('TTRSS_CHECK_FOR_UPDATES=true');
	// Check for updates automatically if running Git version
 
	putenv('TTRSS_ENABLE_GZIP_OUTPUT=false');
	// Selectively gzip output to improve wire performance. This requires
	// PHP Zlib extension on the server.
	// Enabling this can break tt-rss in several httpd/php configurations,
	// if you experience weird errors and tt-rss failing to start, blank pages
	// after login, or content encoding errors, disable it.

	putenv('TTRSS_PLUGINS=auth_internal, af_proxy_http, api_newsplus, powerivq, pusher');
	// Comma-separated list of plugins to load automatically for all users.
	// System plugins have to be specified here. Please enable at least one
	// authentication plugin here (auth_*).
	// Users may enable other user plugins from Preferences/Plugins but may not
	// disable plugins specified in this list.
	// Disabling auth_internal in this list would automatically disable
	// reset password link on the login form.
	
	putenv('TTRSS_LOG_DESTINATION=sql');
	// Error log destination to use. Possible values: sql (uses internal logging
	// you can read in Preferences -> System), syslog - logs to system log.
	// Setting this to blank uses PHP logging (usually to http server 
	// error.log).
	// Note that feed updating daemons don't use this logging facility
	// for normal output.

	// vim:ft=php
