This extension officially lives at <https://www.mediawiki.org/wiki/Extension:Html2Wiki> This extension to MediaWiki is used to import HTML content (including images) into the wiki.

Imagine having dozens, hundreds, maybe thousands of pages of HTML. And you want to get that into your wiki. Maybe you've got a website, or perhaps a documentation system that is in HTML format. You'd love to be able to use your wiki platform to edit, annotate, organize, and publish this content. That's where the **Html2Wiki** extension comes into play. You simply install the extension in your wiki, and then you are able to import entire zip files containing all the HTML + image content. Instead of months of work, you could be done in minutes.

Installation
------------

Configuration parameters
------------------------

COMING SOON

User rights
-----------

Right now the extension is restricted to Admins

Requirements or Dependencies
----------------------------

This extension was built on MediaWiki version 1.25alpha. It may not be compatible with earlier releases since there are a number of external libraries such as [jQuery](https://www.mediawiki.org/wiki/JQuery) which have changed over time. Contact Us if you have version compatibility issues.

Since parsing the DOM is problematic when using PHP's native DOM manipulation (which is itself based on libxml), we use the [QueryPath project](http://querypath.org/) to provide a more flexible parsing platform. The best tutorial on QueryPath is this [IBM DeveloperWorks article](http://www.ibm.com/developerworks/opensource/library/os-php-querypath/index.html) The most recent list of documentation for QueryPath is at this bug: <https://github.com/technosophos/querypath/issues/151> The API docs contain a [CSS selector reference](http://api.querypath.org/docs/_c_s_s_reference.html)

Html2Wiki can import entire document sets and maintain a hierarchy of those documents. The [<http://www.mediawiki.org/wiki/Manual>:\$wgNamespacesWithSubpages \$wgNamespacesWithSubpages] variable will allow you to create a hierarchy in your wiki's 'main' namespace; and even automatically create navigation links to parent article content. Taking this further, the [SubPageList](https://www.mediawiki.org/wiki/Extension:SubPageList) extension creates navigation blocks for subpages.

The document sets we were importing were based on generated source code documentation (coming from an open source documentation generator called [Natural Docs](http://naturaldocs.org/)) which creates DHTML "mouseovers" for glossary terms. To create similar functionality in the wiki environment, we will rely on the [Lingo](https://www.mediawiki.org/wiki/Extension:Lingo) extension to create a Glossary of terms.

Usage
-----

### System Elements

Once installed, the Html2Wiki extension makes a new form available to Administrators of your wiki. Simply choose a file, click **import** and watch as your HTML is magically transformed into Wiki text.

You access the import HTML form at the `Special:Html2Wiki` page (similar to `Special:Upload` for regular media). The Html2Wiki extension also adds a convenient **Import HTML** link to the **Tools** panel of your wiki for quick easy access to the importer.

### Single File

Enter a comment in the Comment field, which is logged in the 'Recent Changes' content as well as the Special:Log area.

The upload is automatically categorized according to the content provided.

You can optionally specify a "Collection Name" for your content. The Collection Name represents where this content is coming from (e.g. The book, or website). Any unique identifier will do. The "Collection Name" is used to tag (categorize) all content that is part of that collection. And, all content that is part of a Collection will be organized "under" that Collection Name in a hierarchy. This lets you have 2 or more articles in your wiki named "Introduction" if they belong to separate Collections. Specifying an existing Collection Name + article title will update the existing content. In fact, to reimport a single file and maintain it's 'position' in a collection, you would specify the full path to the file.

### Zip File

Choose a zip file to import. The Zip file can contain any type of file, but only html and image files will be processed.

Mechanics
---------

Importing a file works like this

                         Select
                           |
                           v
                        Upload
                           |
                           v
           Tidy ----->  Normalize
                           |
                           v
       QueryParse----->  Clean
                           |
                           v
           Pandoc ----> Convert
                           |
                           v
                         Save

Zip archive handling
--------------------

In order to handle the zip upload, we'll have to traverse all files and index hrefs as they exist. We'll need to map those to safe titles and rewrite the source to use those safe URLs. This has to be done for both anchors and images.

Practically speaking MW is probably more flexible than we need; but we'll want to check

    [legaltitlechars] =>  %!"$&'()*,\-.\/0-9:;=?@A-Z\\^_`a-z~\x80-\xFF+

Since MediaWiki uses (by default) First-letter capitals, you would normally need to account for that in rewriting all hrefs within the source. However, in practice, we use a Collection Name as the first path element, and MediaWiki will seamlessly redirect foo to Foo.

Styles and Scripts
------------------

Cascading Style Sheets (CSS) as well as JavaScript (js) are not kept as part of the transformation. Although, we are working on including CSS

Wiki Text markup
----------------

The fundamental requirement for this extension is to transform input (HTML) into Wiki Text (see [<http://www.mediawiki.org/wiki/Help:Formatting>](http://www.mediawiki.org/wiki/Help:Formatting)) because that is the format stored by the MediaWiki system. Originally, it was envisioned that we would make API calls to the Parsoid service which is used by the Visual Editor extension. However, Parsoid is not very flexible in the HTML that it will handle. To get a more flexible converter, we use the [Pandoc](https://github.com/jgm/pandoc) project which is able to (read and) [write to MediaWiki Text format](https://github.com/jgm/pandoc/blob/master/src/Text/Pandoc/Writers/MediaWiki.hs).

For each source type we will need to survey the content to identify the essential content, and remove navigation, JavaScript, presentational graphics, etc. We should have a "fingerprint" that we can use to sniff out the type of document set that the user is uploading to the wiki. Actually, work is underway to allow the user to create special "recipe" articles in the wiki that would instruct Html2Wiki on how to transform content. The user will be able to interatively run a recipe in test "dry-run" mode to see the results on a sampling of content in order to perfect the recipe and then use it on a larger Collection.

As a result of sniffing the source type, we can properly index and import content only, while discarding the dross. We can likewise apply the correct transformation to the source.

Form file content is saved to server (tmp), and that triggers conversion attempt. A Title is proposed from text (checked in the db), and user can override naming HTML is converted to wiki text for the content of the article.

Image references are either assumed to be relative e.g. `src="../images/foo.jpg"` and contained in the zip file, or absolute e.g. `src="http://example.com/images/foo.jpg"` in which case they are not local to the wiki.

Want to check your source for a list of image files?

~~~~ {.bash}
grep -P -r --only-matching '(?<=<img src=["'\''])([^"'\'']*)' ./my/html/files/
~~~~

For each of the image files (png, jpg, gif) contained in the zip archive, the image asset is saved into the wiki with automatic file naming based on the "Collection Name" + path in the zip file.

Also, each image is tagged with the collection name for easier identification.

Image references in the HTML source are automatically updated to reference the in-wiki images.

@todo document the \$wgEliminateDuplicateImages option

Database
--------

The extension currently does not make any schema changes to the MediaWiki system.

What, if any, additional tables could we want in the database? [1]

We may need to store checksums for zip uploads, because we don't want to store the zip itself, but we may want to recognize a re-upload attempt?

Logging
-------

Logging is provided at <Special:Log/html2wiki> The facility for logging will tap into `LogEntry` as outlined at <https://www.mediawiki.org/wiki/Manual:Logging_to_Special:Log>

Interestingly, SpecialUpload must call `LogEntry` from it's hooks SpecialImport calls `LogPage` which itself invokes `LogEntry` (see includes/logging).

Use Parsoid?
------------

In order to use Parsoid at all, we need to have the content conform to the MediaWikiDOMspec, which is based on HTML5 and RDFa <https://www.mediawiki.org/wiki/Parsoid/MediaWiki_DOM_spec#Ref_and_References>.

We would need to parse the incoming content, validate and possibly transform the document type to HTML5 (`<!DOCTYPE html>`) and then transform the HTML5 to MediaWiki DOMspec <http://www.w3.org/TR/html5/syntax.html#html-parser>

[Parsoid offers an API](https://www.mediawiki.org/wiki/Parsoid/API) with basically two actions: POST and GET You can test the API at [<http://parsoid-lb.eqiad.wikimedia.org/_html/>](http://parsoid-lb.eqiad.wikimedia.org/_html/)

You can also test it locally on the vm through port 8000

Variables we care about
-----------------------

1.  We probably want a variable that can interact with the max upload size
2.  [<https://www.mediawiki.org/wiki/Manual>:\$wgMaxUploadSize \$wgMaxUploadSize][\*] = 104857600 bytes (100 MB)
3.  [<https://www.mediawiki.org/wiki/Manual>:\$wgFileBlacklist \$wgFileBlacklist] we don't care about because we use our own file upload and mime detection
4.  [\$wgVisualEditorParsoidURL](https://www.mediawiki.org/wiki/Extension:VisualEditor) we can use for API requests to Parsoid
5.  [<https://www.mediawiki.org/wiki/Manual>:\$wgLegalTitleChars \$wgLegalTitleChars] we use to check for valid file naming
6.  [<https://www.mediawiki.org/wiki/Manual>:\$wgMaxArticleSize \$wgMaxArticleSize] default is 2048 KB, which may be too small?
7.  [<https://www.mediawiki.org/wiki/Manual>:\$wgMimeInfoFile \$wgMimeInfoFile] we don't yet use
8.  Also, how do imagelimits come into play? <http://localhost:8080/w/api.php?action=query&meta=siteinfo&format=txt>

Features
--------

Add a link to the sidebar for our extension. \$wgUploadNavigationUrl is for overriding the regular 'upload' link (not what we want).

Instead, we have to edit MediaWiki:Common.js see [<https://www.mediawiki.org/wiki/Manual:Interface/Sidebar>](https://www.mediawiki.org/wiki/Manual:Interface/Sidebar)

Internationalization
--------------------

`Special:Html2Wiki?uselang=qqx` shows the interface messages You can see most of the messages in Special:AllMessages if you filter by the prefix 'Html2Wiki'

Error handling
--------------

1.  submitting the form with no file <span style="color:red;">There was an error handling the file upload: No file sent.</span>
2.  choosing a file that is too big: limit is set to 100 MB
3.  choosing a file of the wrong type <span style="color:red;">There was an error handling the file upload: Invalid file format.</span>
4.  choosing a file that has completely broken HTML: You could end up with no wiki markup, but it tries hard to be generous.

Developing
----------

This extension was originally written by and is maintained by Greg Rundlett of [eQuality Technology](http://eQuality-Tech.com). Additional developers, testers, documentation helpers, and translators welcome!

The project code is hosted on both [GitHub](https://github.com/freephile/Html2Wiki) and WikiMedia Foundation servers on the [Html2Wiki Extension page](https://www.mediawiki.org/wiki/Extension:Html2Wiki). You should use git to clone the project and submit pull requests. The code is simultaneously updated on MediaWiki servers and GitHub, so feel free to fork, or pull it from either location.

~~~~ {.bash}
git clone https://gerrit.wikimedia.org/r/p/mediawiki/
~~~~

or (with gerrit auth)

~~~~ {.bash}
git clone ssh://USERNAME@gerrit.wikimedia.org:29418/mediawiki/services/parsoid
~~~~

The best way to setup a full development environment is to use [MediaWiki Vagrant](https://www.mediawiki.org/wiki/MediaWiki-Vagrant). This handy bit of wizardry will create a full LAMP stack for you and package it into a VirtualBox container (among others).

See also
--------

-   <Extension:UploadLocal>
-   <Extension:MsUpload>

<References>

[1] <https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates>