<?php
/**
 * Html2Wiki extension - enables you to import HTML content into your wiki.
 *
 * For more info see http://mediawiki.org/wiki/Extension:Html2Wiki (when available)
 *
 * @file
 * @ingroup Extensions
 * @author Greg Rundlett, 2014
 * @license GNU General Public Licence 2.0 or later
 * @todo publish the extension upstream. first with the http://www.mediawiki.org/wiki/Template:Extension
 */

$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'Html2Wiki',
	'author' => array(
		'Greg Rundlett',
	),
	'version'  => '0.1.0',
	'url' => 'https://www.mediawiki.org/wiki/Extension:Html2Wiki',
	'descriptionmsg' => 'html2wiki-desc',
);

/* Setup */

// Register files
$wgAutoloadClasses['Html2WikiHooks'] = __DIR__ . '/Html2Wiki.hooks.php';
$wgAutoloadClasses['SpecialHelloWorld'] = __DIR__ . '/specials/SpecialHelloWorld.php';
$wgMessagesDirs['Html2Wiki'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['Html2WikiAlias'] = __DIR__ . '/Html2Wiki.i18n.alias.php';

// Register hooks
#$wgHooks['NameOfHook'][] = 'Html2WikiHooks::onNameOfHook';

// Register special pages
$wgSpecialPages['HelloWorld'] = 'SpecialHelloWorld';
$wgSpecialPageGroups['HelloWorld'] = 'other';

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
