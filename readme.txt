=== DesktopServer for WordPress ===
Contributors: steveorevo, davejesch
Donate link: http://serverpress.com
Tags: localhost, isp, hosting, deploy, copy, transfer, publish, desktopserver
Requires at least: 3.0
Tested up to: 4.5.2
Stable tag: 1.6.1
License: GPLv2 or later

DesktopServer for WordPress

== Description ==

DesktopServer for WordPress eases localhost to live server deployment by publishing hosting provider server details via a protected XML-RPC feed and API to an authorized administrator only. It also provides assisted deployments to hosting providers that support file system direct.

== Installation ==

Upload the DesktopServer for WordPress plugin and activate it. Then using DesktopServer's export feature, select Export, followed by the "Fetch live hosting server details" checkbox. For more information, please visit http://serverpress.com/.

== Frequently Asked Questions ==

= Do I have to use this plugin in order to use DesktopServer locally? =

No. The DesktopServer plugin is installed on a live site only when you are ready to deploy your local install to the live site. It is not required for local development.

= After deployment, do I need to keep this plugin active? =

No. Since this is only needed during the deploy process, once deployment is complete this plugin is automatically removed and does not need to be reinstalled.

= Is this secure? =

Yes! The DesktopServer plugin provides information to the DesktopServer application about your live site. But it requires a valid admin login. So as long as you are using secure passwords on your live site, you are secure.

== Changelog ==

= 1.6.1 =
* reset value of ds_deploy directory for (old) XMLRPC API calls for better backward compatibility

= 1.6.0 =
* Add custom endpoint API as alternate to XMLRPC.

= 1.5.2 =
* Bump version number.

= 1.5.1 =
* Accommodate servers that are missing DIR magic constants (bluehost).

= 1.5.0 =
* Updated MySQL interface to PDO; resolved "Unexpected Response" pertaining to mysql_connect deprecated warnings.
* Transfers htaccess last to prevent accidental lockout during deployment.
* Error reporting fix provides more feedback.
* Supports hosts running PHP 5.5.X

= 1.4.0 =
* Updated support for GoDaddy's non-standard, ever changing, SUBDOMIN_DOCUMENT_ROOT definition for addon domains.

= 1.3.0 =
* Added support for GoDaddy's REAL_DOCUMENT_ROOT, supports subdomain and addon domains

= 1.2.0 =
* Added temporary session details for processing database thread in chunks.
* Added expanded memory and timeout overrides for limited hosts.

= 1.1.2 =
* Enforce correct file and folder permissions

= 1.1.1 =
* Add fix for deployment from/to Windows based systems

= 1.1.0 =
* Allow only users with core update capability only
* Added deployment functionality
* Re-coded into OOP

== Upgrade Notice ==

= 1.0.0 =
* Initial release
