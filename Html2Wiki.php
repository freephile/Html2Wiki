<?php
/**
 * Html2Wiki extension - enables you to import HTML content into your wiki.
 *
 * For more info see http://mediawiki.org/wiki/Extension:Html2Wiki (when available)
 *
 * @file
 * @ingroup Extensions
 * @author Greg Rundlett
 * @license GNU General Public Licence 2.0 or later
 * @todo publish the extension upstream. first with the http://www.mediawiki.org/wiki/Template:Extension
 *
 * This file is part of the Html2Wiki Extension to MediaWiki
 * https://www.mediawiki.org/wiki/Extension:Html2Wiki
 *
 * @section LICENSE
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 * 
 * @file
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	echo <<<EOT 
This file is an extension to the MediaWiki software and cannot be used on its own.

To install this extension, put the following line in LocalSettings.php:
require_once( "\$IP/extensions/Html2Wiki/Html2Wiki.php" );

EOT;
	die( 1 );
}

/** 
 * Add in a version compatibility check
 * I'm unsure what version we will require, but this is how you do it
 */
if ( version_compare( $wgVersion, '1.21', '<' ) ) {
	die( "This extension requires MediaWiki 1.21+\n" );
}

$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'Html2Wiki',
	'author' => array(
		'Greg Rundlett',
	),
	'version'  => '0.1.0',
	// 'url' => 'https://www.mediawiki.org/wiki/Extension:Html2Wiki',
	'descriptionmsg' => 'html2wiki-desc',
);

/* Setup */

// Register files
$wgAutoloadClasses['Html2WikiHooks'] = __DIR__ . '/Html2Wiki.hooks.php';
$wgAutoloadClasses['SpecialHtml2Wiki'] = __DIR__ . '/specials/SpecialHtml2Wiki.php';

$wgMessagesDirs['Html2Wiki'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['Html2WikiAlias'] = __DIR__ . '/Html2Wiki.i18n.alias.php';

// Register hooks
#$wgHooks['NameOfHook'][] = 'Html2WikiHooks::onNameOfHook';

// Register special pages
$wgSpecialPages['Html2Wiki'] = 'SpecialHtml2Wiki'; // the name of the subclass
// Set the group for our Special Page(s) 
// deprecated but still works See getGroupName()
// $wgSpecialPageGroups['Html2Wiki'] = 'media';

// Register modules
$wgResourceModules['ext.Html2Wiki.foo'] = array(
	'scripts' => array(
		'modules/ext.Html2Wiki.foo.js',
	),
	'styles' => array(
		'modules/ext.Html2Wiki.foo.css',
	),
	'messages' => array(
	),
	'dependencies' => array(
	),

	'localBasePath' => __DIR__,
	'remoteExtPath' => 'examples/Html2Wiki',
);


/* Configuration */

// Enable Foo
#$wgHtml2WikiEnableFoo = true;
