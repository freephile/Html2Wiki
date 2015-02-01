<?php

/**
 * Import/Upload SpecialPage for Html2Wiki extension
 *
 * @see https://www.mediawiki.org/wiki/Manual:Special_pages
 * @file
 * @ingroup Extensions
 * @package Html2Wiki
 * 
 * @todo implement style guide https://www.mediawiki.org/wiki/Design/Living_style_guide
 */
class SpecialHtml2Wiki extends SpecialPage {
    
    /** @var array of original file upload attributes
     * [filename]
     * [mimetype]
     * [filesize]
     * []
     * 
     */
    private $mOriginal;

    /** @var string The HTML we want to turn into wiki text */
    private $mContent;
    private $mContentRaw;  // the exact contents which were uploaded
    private $mContentTidy; // Raw afer it's passed through tidy
    private $mLocalFile;   // unused except in doLocalFile()
    /** @var string The (original) name of the uploaded file */
    private $mFilename; // supplied
    public $mArticleTitle; // created by mArticleSavePath and mFilename or unWrap

    /** @var int The size, in bytes, of the uploaded file. */
    private $mFilesize;
    private $mSummary;
    private $mIsTidy;      // @var bool true once passed through tidy
    private $mTidyErrors;  // the error output of tidy
    private $mTidyConfig;  // the configuration we want to use for tidy.
    private $mMimeType;  // the detected or inferred mime-type of the upload
    private $mDataDir; // where we want to temporarily store and retrieve data from
    /** name for the collection we're importing e.g. UVM-1.1d
     *
     * @var string a specific identifier for a set of documents imported into 
     * the wiki.  Gathered from the form, if the value has any slashes in it
     * then Collection name is the first element and the full supplied value is 
     * used as $mArticleSavePath
     */
    private $mCollectionName;
    protected $mArticleSavePath;
    public $mIsVIP; // we're purpose built to detect and handle two types of zipped content
    public $mIsUVM;
    public $mImages;  // an array of image files that should be imported
    public $mFilesAreProcessed; // boolean, whether we've (uploaded and) processed content files
    public $mResults; // the HTML-formatted result report of an import
    public $mFileCountExpected; // the number of files we expect to process
    public $mFileCountProcessed; // the number of files we've actually processed

    /* Protected Static Members */

    /** @var array List of common image files extensions and MIME-types
     * and HTML MIME-type 
     * as well as the compressed archive types we will allow
     */
    protected static $mimeTypes = array(
        'gif' => 'image/gif',
        'jpe' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'xbm' => 'image/x-xbitmap',
        'svg' => 'image/svg+xml',
        // compression only files, disallow
        //'tar' => 'application/x-tar',
        //'bz2' => 'application/x-bzip2', // bzip2
        //'gz'  => 'application/x-gzip',
        // HTML content
        'htm' => 'text/html',
        'html' => 'text/html',
        // archives
        'gz' => 'application/x-gzip',
        'gzip' => 'application/x-gzip',
        'tar.gz' => 'application/x-gtar',
        'tgz' => 'application/x-gtar',
        'zip' => 'application/zip',
    );

    /** @var array List of common Tidy options that we want to use in order
     * to clean up incoming HTML
     */
    protected static $tidyOpts = array(
        "drop-empty-paras" => 1,
        "drop-font-tags" => 1,
        "enclose-block-text" => 1,
        "enclose-text" => 1,
        "fix-backslash" => 1,
        "fix-bad-comments" => 1,
        "fix-uri" => 1,
        "hide-comments" => 1,
        "merge-divs" => 1,
        "merge-spans" => 1,
        "repeated-attributes" => "keep-first",
        "show-body-only" => 1,
        "show-errors" => 0,
        "show-warnings" => 0,
        "indent" => 0,
        "wrap" => 120,
        "tidy-mark" => 0,
        "write-back" => 0
    );



    /** @todo review and cull the properties that we use here
     * These properties were copied from Special:Upload assuming they'd be 
     * applicable to our use case.
     */

    /** @var WebRequest|FauxRequest The request this form is supposed to handle */
    public $mRequest;
    public $mSourceType;

    /** @var UploadBase */
    public $mUpload;
    public $mUploadClicked;

    /** User input variables from the "description" section * */

    /** @var string The requested target file name */
    public $mDesiredDestName;
    public $mComment;
    public $mLicense; // don't know if we'll bother with this

    /** User input variables from the root section * */
    public $mIgnoreWarning;
    public $mWatchthis;
    public $mCopyrightStatus;
    public $mCopyrightSource;

    /** Hidden variables * */
    public $mDestWarningAck;

    /** @var bool The user followed an "overwrite this file" link */
    public $mForReUpload;

    /** @var bool The user clicked "Cancel and return to upload form" button */
    public $mCancelUpload;
    public $mTokenOk;

    /** @var bool Subclasses can use this to determine whether a file was uploaded */
    public $mUploadSuccessful = false;

    /** Text injection points for hooks not using HTMLForm * */
    public $uploadFormTextTop;
    public $uploadFormTextAfterSummary;

    protected function loadRequest() {
        $this->mRequest = $request = $this->getRequest();
        // get the value from the form, or use the default defined in the language messages
        //$commentDefault = wfMessage( 'html2wiki-comment' )->inContentLanguage()->plain();
        $commentDefault = wfMessage('html2wiki-comment')->inContentLanguage()->parse();
        $this->mComment = $request->getText('log-comment', $commentDefault);

        // use the mCollectionName for tagging content.
        // mArticleSavePath equals the value of mCollectionName (if any) plus any intermediate path elements NOT including the file name
        // mArticleTitle will be the full value of mCollectionName (if any) plus mArticleSavePath plus the file name MINUS any extension
        $this->mCollectionName = (string) $request->getText('collection-name');
        if (!empty($this->mCollectionName)) {
            // make sure that any collection name is at least two characters
            if (strlen($this->mCollectionName) < 2) {
                $this->showForm("Invalid Collection Name; must be greater than a single character");
            }
            // if it's not naked, split it into a name and a path (and fix up 
            // the path to ensure there is a trailing slash)
            // We do this here to handle single files, but also later in Upload
            // to handle zips
            if ( stristr($this->mCollectionName, '/') ) {
                $parts = explode('/', $this->mCollectionName);
                $this->mCollectionName = array_shift($parts); // take out the first part
                $this->mArticleSavePath = implode('/', $parts); // back to a string
                $this->mArticleSavePath = ( substr( $this->mArticleSavePath, -1) == '/' )? $this->mArticleSavePath : "{$this->mArticleSavePath}/";
            }
            // later if we detect a zip, we'll build a different filename
            // for now, assume a single HTML upload to replace an existing collection title
            $this->mArticleTitle = $this->mFilename = $this->mCollectionName . '/' . $this->mArticleSavePath . $_FILES['userfile']['name'];
        } else {
            $this->mArticleTitle = $this->mFilename = $_FILES['userfile']['name'];
        }
        // We'll make the title without the extension
        $path = $this->mArticleTitle;
        $this->mArticleTitle = pathinfo($path, PATHINFO_DIRNAME) . "/" . pathinfo($path, PATHINFO_FILENAME);
    }

    public static function getTidyOpts() {
        return self::$tidyOpts;
    }

    /**
     * You can get the mimetype of an arbitrary file in bash with 
     * file --mime-type $file
     * 
     * @param $file string
     * @return bool|string
     */
    public static function getMimeType($file) {
        $realpath = realpath($file);
        if (
                $realpath && function_exists('finfo_file') && function_exists('finfo_open') && defined('FILEINFO_MIME_TYPE')
        ) {
            $mimeType = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $realpath);
            if (array_search($mimeType, self::$mimeTypes)) {
                return $mimeType;
            } else {
                return false; // not allowed type
            }
        } else {
            // Infer the MIME-type from the file extension
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (isset(self::$mimeTypes[$ext])) {
                return self::$mimeTypes[$ext];
            }
        }
        // neither approach worked, or mimeType not allowed
        return false;
    }

    /**
     * Initialize instance variables from request and create an Upload handler
     * @todo review and cull the methods that we use here
     * This method was copied from Special:Upload assuming it would be 
     * applicable to our use case.

      protected function loadRequest() {
      $this->mRequest = $request = $this->getRequest();
      $this->mSourceType = $request->getVal( 'wpSourceType', 'file' );
      // @todo What can we use besides UploadBase because we don't want to store file types: HTML and zip?
      //
      $this->mUpload = UploadBase::createFromRequest( $request );
      $this->mUploadClicked = $request->wasPosted()
      && ( $request->getCheck( 'wpUpload' )
      || $request->getCheck( 'wpUploadIgnoreWarning' ) );

      // Guess the desired name from the filename if not provided
      $this->mDesiredDestName = $request->getText( 'wpDestFile' );
      if ( !$this->mDesiredDestName && $request->getFileName( 'wpUploadFile' ) !== null ) {
      $this->mDesiredDestName = $request->getFileName( 'wpUploadFile' );
      }
      $this->mLicense = $request->getText( 'wpLicense' );

      $this->mDestWarningAck = $request->getText( 'wpDestFileWarningAck' );
      $this->mIgnoreWarning = $request->getCheck( 'wpIgnoreWarning' )
      || $request->getCheck( 'wpUploadIgnoreWarning' );
      $this->mWatchthis = $request->getBool( 'wpWatchthis' ) && $this->getUser()->isLoggedIn();
      $this->mCopyrightStatus = $request->getText( 'wpUploadCopyStatus' );
      $this->mCopyrightSource = $request->getText( 'wpUploadSource' );

      $this->mForReUpload = $request->getBool( 'wpForReUpload' ); // updating a file

      $commentDefault = '';
      $commentMsg = wfMessage( 'upload-default-description' )->inContentLanguage();
      if ( !$this->mForReUpload && !$commentMsg->isDisabled() ) {
      $commentDefault = $commentMsg->plain();
      }
      $this->mComment = $request->getText( 'wpUploadDescription', $commentDefault );

      $this->mCancelUpload = $request->getCheck( 'wpCancelUpload' )
      || $request->getCheck( 'wpReUpload' ); // b/w compat

      // If it was posted check for the token (no remote POST'ing with user credentials)
      $token = $request->getVal( 'wpEditToken' );
      $this->mTokenOk = $this->getUser()->matchEditToken( $token );

      $this->uploadFormTextTop = '';
      $this->uploadFormTextAfterSummary = '';
      }
     */

    /**
     * Constructor : initialise object
     * Get data POSTed through the form and assign them to the object
     * @param WebRequest $request Data posted.
     * We'll use the parent's constructor to instantiate the name but not perms
     * 
     * @todo We need to check for $wgEnableWriteAPI=true (default) because if it's not
     * then our extension will not be able to do it's work.  There are two possible
     * errors:
     * noapiwrite: Editing of this wiki through the API is disabled. Make sure the $wgEnableWriteAPI=true; statement is included in the wiki's LocalSettings.php file
     * writeapidenied: You're not allowed to edit this wiki through the API
     * 
     * The first is clear enough to the user what they have to do to fix it.
     * The second is that they are not configured properly in the correct group
     */
    public function __construct($request = null) {
        $name = 'Html2Wiki';
        parent::__construct($name);
        // we might want to add rights here, or else do it in a method called in exectute
        //parent::__construct('Import Html', array('upload', 'reupload');
    }

    /**
     * Override the parent to set where the special page appears on Special:SpecialPages
     * 'other' is the default, so you do not need to override if that's what you want.
     * Specify 'media' to use the specialpages-group-media system interface 
     * message, which translates to 'Media reports and uploads' in English.
     * 
     * @return string
     */
    function getGroupName() {
        return 'media';
    }

    /**
     * Shows the page to the user.
     * @param string $sub: The subpage string argument (if any).
     *  [[Special:HelloWorld/subpage]].
     */
    public function execute($sub) {
        /**
         * @since 1.23 we can create our own Config object
         * @link https://www.mediawiki.org/wiki/Manual:Configuration_for_developers

          $wgConfigRegistry['html2wiki'] = 'GlobalVarConfig::newInstance';
          // Now, whenever you want your config object
          // $conf = ConfigFactory::getDefaultInstance()->makeConfig( 'html2wiki' );
         */
        $out = $this->getOutput();

        $out->setPageTitle($this->msg('html2wiki-title'));

        /**
         * Restrict access to the importing of content.
         * Use the same approach as Special:Import since if you're
         * allowed to import wiki content, we'll also allow you to import
         * HTML content.
         */
        $user = $this->getUser();
        /** @todo turn on this permission check
          if ( !$user->isAllowedAny( 'import', 'importupload' ) ) {
          throw new PermissionsError( 'import' );
          }
         */
        // Even without the isAllowsedAny check, the anonymous user sees
        // 'No transwiki import sources have been defined and direct history uploads are disabled.'
        # @todo Allow Title::getUserPermissionsErrors() to take an array
        # @todo FIXME: Title::checkSpecialsAndNSPermissions() has a very wierd expectation of what
        # getUserPermissionsErrors() might actually be used for, hence the 'ns-specialprotected'
        $errors = wfMergeErrorArrays(
                $this->getPageTitle()->getUserPermissionsErrors(
                        'import', $user, true, array('ns-specialprotected', 'badaccess-group0', 'badaccess-groups')
                ), $this->getPageTitle()->getUserPermissionsErrors(
                        'importupload', $user, true, array('ns-specialprotected', 'badaccess-group0', 'badaccess-groups')
                )
        );

        if ($errors) {
            throw new PermissionsError('import', $errors);
        }
        // from parent, throw an error if the wiki is in read-only mode
        $this->checkReadOnly();
        $request = $this->getRequest();
        if ($request->wasPosted() && $request->getVal('action') == 'submit') {
            $this->loadRequest();
            $this->doImport();
        } else {
            $this->showForm();
        }
    }

    /**
     * Upload user nominated file.
     * 
     * The user may nominate either a single HTML file, or a zip/tar archive of
     * HTML and images etc.
     * 
     * Since we don't know which, we'll check the mimetype, and then either 
     * process a single file, or else unwrap the archive and process each entry
     * Populate 
     * $mFilename
     * $mMimeType
     * $mFilesize
     */
    private function doUpload() {
        $this->mFilesAreProcessed = false;
        $out = $this->getOutput();
        global $wgMaxUploadSize;
        try {
            if (
                    !isset($_FILES['userfile']['error']) ||
                    is_array($_FILES['userfile']['error'])
            ) {
                throw new RuntimeException('Multiple or missing error during upload.');
            }
            // Check $_FILES['userfile']['error'] value.
            switch ($_FILES['userfile']['error']) {
                case UPLOAD_ERR_OK: // Value: 0; There is no error, the file uploaded with success.
                    break;
                case UPLOAD_ERR_INI_SIZE:
                    // Value: 1; The uploaded file exceeds the upload_max_filesize directive in php.ini.
                    throw new RuntimeException('Exceeded filesize limit. Check upload_max_filesize in php.ini.');
                case UPLOAD_ERR_FORM_SIZE:
                    // Value: 2; The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.
                    throw new RuntimeException('Exceeded filesize limit in form.');
                case UPLOAD_ERR_PARTIAL:
                    throw new RuntimeException('The uploaded file was only partially uploaded.');
                case UPLOAD_ERR_NO_FILE: // Value: 4; No file was uploaded.
                    throw new RuntimeException('No file sent.');
                default:
                    throw new RuntimeException('Unhandled error: ' . $_FILES['userfile']['error'] . ' in ' . __METHOD__);
            }
            // You should also check filesize here. 
            if ($_FILES['userfile']['size'] > $wgMaxUploadSize['*']) {
                throw new RuntimeException('Exceeded filesize limit, check $wgMaxUploadSize[\'*\'].');
            }

            // we do not trust $_FILES['userfile']['type'] 
            $this->mMimeType = $this->mOriginal['mimetype'] =  self::getMimeType($_FILES['userfile']['tmp_name']);
            if (false === $this->mMimeType) {
                throw new RuntimeException('Invalid file format.');
            }

            // You should name it uniquely.
            // DO NOT USE $_FILES['userfile']['name'] WITHOUT ANY VALIDATION !!
            // On this example, obtain safe unique name from its binary data.
            /* We don't even need to move the file.  We WANT the upload to be discarded
             * and it will be after upload if we don't move it from the tmp_name
             * So, we can process it, and only use the result, while PHP destroys
             * the original source
             * 
              if (!move_uploaded_file(
              $_FILES['userfile']['tmp_name'],
              sprintf('./uploads/%s.%s',
              sha1_file($_FILES['userfile']['tmp_name']),
              $ext
              )
              )) {
              throw new RuntimeException('Failed to move uploaded file.');
              }
             */
            if (!is_uploaded_file($_FILES['userfile']['tmp_name'])) {
                throw new RuntimeException('There was a failure in the file upload');
            }
        } catch (RuntimeException $e) {
            $out->wrapWikiMsg(
                    "<p class=\"error\">\n$1\n</p>", array('html2wiki_uploaderror', $e->getMessage())
            );
            return false;
        }
        // Don't assign the tmp_name to another variable because the file goes away
        //$this->mFile = $_FILES['userfile']['tmp_name'];
        $this->mFilename = $this->mOriginal['filename'] =  $_FILES['userfile']['name'];
        $this->mFilesize = $this->mOriginal['filesize'] = $_FILES['userfile']['size'];

        switch ($this->mOriginal['mimetype']) {
            case 'application/x-gzip':
            case 'application/x-gtar':
            case 'application/zip':
                // @todo Do we disallow uploading a zip file without a collection name?
                if( empty ($this->mCollectionName) ) {
                    // warn that you can't do that
                }

                // unwrap the file
                $this->unwrapZipFile();
                $this->saveImages();
                break;

            case 'text/html':
                //process single file
                $this->processFile();
                $this->mFilesAreProcessed = true;
                break;

            default:
                return false;
                break;
        }
        // we know about all the images that are referenced, make sure they are
        // in the wiki
        return $this->mFilesAreProcessed;
    }

    
    /**
     * This method was used in testing/development to work on a file that is local
     * to the server.  We could re-implement this if working on local files is desired
     * @param type $file
     * @return boolean
     */
    private function doLocalFile($file) {
        $out = $this->getOutput();
        if (!file_exists($file)) {
            $out->wrapWikiMsg(
                    "<p class=\"error\">\n$1\n</p>", array('html2wiki_filenotfound', $file)
            );
            return false;
        }
        $this->mLocalFile = $file;
        $this->mFilename = basename($file);
        $this->mContentRaw = file_get_contents($file);
        $this->mFilesize = filesize($file);
        return true;
    }

    /**
     * During the upload process, we detect the mimetype, and if it's an archive
     * we work with the contents of the archive, processing each entry.
     * We have to loop through the archive twice... 
     * On the first pass we detect the "brand" of archive.
     * The brand is currently one of 'VIP' or 'UVM' which are collections we
     * are familiar with.
     * Based on the brand, we build up a list of HTML files and image files that
     * match our expectations.
     * On the second pass, we process each entry (filtered by the first pass).
     */
    private function unwrapZipFile() {
        // blank out our per-file data
        $this->mMimeType = $this->mFilename = $this->mFilesize = '';
        $this->IsVIP = false;
        $this->IsUVM = false;
        $out = $this->getOutput();
        $zipfile = $_FILES['userfile']['tmp_name'];
// $zipfile = __DIR__ . '/data/vip-info-hub-docs-for-wiki-import.zip';
// $zipfile = realpath($zipfile);
// The "Questa Verification IP User Guide" -> "Retargeting a Normal Sequence Item" (./docs/htmldocs/questa_vip_user/advanced_topics07.html)
// content file references the ./docs/htmldocs/questa_vip_user/images/message_sequence_chart__normal_mvc_sequence.jpg image.
// $eoi = 'docs/htmldocs/questa_vip_user/advanced_topics07.html';

        $availableFiles = array();
        $availableImages = array();

        // we need to loop through twice, once to determine if this zip matches expectations (and build a bill of laden)
        $zipHandle = zip_open($zipfile);
        if (is_resource($zipHandle)) {
            while ($zip_entry = zip_read($zipHandle)) {
                $entry = zip_entry_name($zip_entry);
                switch ($entry) {

                    // The html/ folder adds almost 1,000% more files
                    // case ( preg_match('#(?:htmldocs|html)/.*\.html?$#i', $entry)? $entry : '' ): #10,865
                    case ( preg_match('#htmldocs/.*\.html?$#i', $entry) ? $entry : '' ): #1,112
                        // a temporary filter to process a small number of files
                        if ( preg_match('#advanced_topics#', $entry) ) {
                            $availableFiles[] = $entry;
                            $this->mIsVIP = true;
                        }
                        break;

                    // The html/ folder adds 40% more images
                    // case ( preg_match('#(?:htmldocs|html)/.*/images/.*\.(?:jpe?g|png|gif)$#i', $entry)? $entry : '' ): #715
                    case ( preg_match('#htmldocs/.*/images/.*\.(?:jpe?g|png|gif)$#i', $entry) ? $entry : '' ):  #511
                        // a temporary filter to process a small number of files
                        if ( preg_match('#advanced_topics#', $entry) ) {
                            $availableImages[] = $entry;
                        }
                        break;

                    case 'docs/htmldocs/':
                        $this->mIsVIP = true;
                        break;

                    default:
                        // if a simple print doesn't work, then your switch has bad syntax
                        // print "$entry\n";
                        break;
                }
            }
            zip_close($zipHandle);
        }
        if ($this->mIsVIP) {
            $imageCount = 0;
            $this->mImages = array();
            // echo "Now we're processing a VIP Info Hubs zip\n";
            $zipHandle = zip_open($zipfile);
            if (is_resource($zipHandle)) {
                while ($zip_entry = zip_read($zipHandle)) {
                    $entry = zip_entry_name($zip_entry);
                    if ( in_array($entry, $availableFiles) ) {
                        $this->mFileCountExpected += 1;

                        $this->mFilename = zip_entry_name($zip_entry);
                        $this->mFilesize = zip_entry_filesize($zip_entry);
                        $this->mMimeType = 'text/html'; // we'll spoof it for now.

                        if (zip_entry_open($zipHandle, $zip_entry, "r")) {
                            $this->mContent = $this->mContentRaw = 
                                    zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));
                            zip_entry_close($zip_entry);
                        }
                        
                        // mArticleSavePath equals the value of mCollectionName (if any) plus any intermediate path elements NOT including the file name
                        // The mCollectionName will get added in during the call to qpMakeLinksAbsolute()
                        $this->mArticleSavePath = pathinfo($this->mFilename, PATHINFO_DIRNAME);
                        // mArticleTitle will be the full value of mCollectionName (if any) plus mArticleSavePath plus the file name MINUS any extension
                        $this->mArticleTitle = ($this->mCollectionName)? "{$this->mCollectionName}/{$this->mFilename}" : $this->mFilename;
                        $path = $this->mArticleTitle;
                        $this->mArticleTitle = pathinfo($path, PATHINFO_DIRNAME) . "/" . pathinfo($path, PATHINFO_FILENAME);

                        $this->processFile();
                    }
                    // We'll take all the images we find and create an array of 
                    // necessary info that we can pass to saveImages();
                    if ( in_array($entry, $availableImages) ) {
                        // remove 'images/' from the path because we don't want that in our final title
                        $this->mFilename = str_replace( 'images/', '', zip_entry_name($zip_entry) );
                        $this->mFilesize = zip_entry_filesize($zip_entry);
                        // $this->mMimeType = 'image/jpg'; // we'll spoof it for now.
                        $this->mImages[$imageCount]['title'] = ($this->mCollectionName)? "{$this->mCollectionName}/{$this->mFilename}" : $this->mFilename;
                        $this->mImages[$imageCount]['filesize'] = $this->mFilesize;
                        if (zip_entry_open($zipHandle, $zip_entry, "r")) {
                            $buf = zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));
                            zip_entry_close($zip_entry);
                        }
                        $this->mImages[$imageCount]['tmpfile'] = $this->makeTempFile($buf);
                        unset($buf);
                        $imageCount++;
                    }
                }
                // check if mFileCountExpected = mFileCountProcessed and close the handle
                zip_close($zipHandle);
            }
        } else {
            $out->addHTML('<div><pre>');
            $out->addHTML('not VIP');
            $out->addHTML(print_r($availableFiles, true));
            $out->addHTML('</pre></div>');
        }
    }

    function formatValue($value) {
        return htmlspecialchars($this->getLanguage()->formatSize($value));
    }

    /**
     * Not sure when we'll use this, but the intent was to create
     * an ajax interface to manipulate the file like wikEd
     */
    private function showRaw() {
        $out = $this->getOutput();
        $out->addModules(array('ext.Html2Wiki')); // add our javascript and css
        //$this->mContent = $this->findBody();
        $out->addHTML('<div class="mw-ui-button-group">'
                . '<button class="mw-ui-button mw-ui-progressive" '
                . 'form="html2wiki-form" formmethod="post" id="h2wWand" name="h2wWand">'
                . '<img src="/w/extensions/Html2Wiki/modules/images/icons/wand.png" '
                . 'alt="wand"/></button>'
                . '</div><div style="clear:both"></div>');
        $out->addHTML('<div id="h2wContent">' . $this->mContentRaw . '</div>');
    }

    /**
     * displays $this->mContent (in a <source> block)
     * optionally passed through htmlentities() and nl2br()
     */
    private function showContent($showEscaped = false) {
        $out = $this->getOutput();
        $out->addModules(array('ext.Html2Wiki')); // add our javascript and css
        // @todo Error or warn here if not $mIsTidy or $mIsPure

        if ($showEscaped) {
            $escapedContent = $this->escapeContent();
            $out->addHTML('<div id="h2wLabel">Escaped File Contents:</div>');
            $out->addHTML('<div id="h2wContent">' . $escapedContent . '</div>');
        } else {
            $out->addHTML('<div id="h2wLabel">Original File Contents:</div>');
            // putting the original source into GeSHi makes it "safe" from the parser
            // but destroys any ability to act on the 'source' by manipulating this 
            // elements innerHTML
            $out->addWikiText('<source id="h2wContent" lang="html4strict">' . $this->mContent . '</source>');
        }
    }

    private function showResults() {
        $out = $this->getOutput();
        $out->addHTML('<div id="h2wContent"><ul class="mw-ext-Html2Wiki">' . $this->mResults . '</ul></div>');
    }
    private function addFileToResults() {
        $this->mFileCountProcessed += 1;
        $size = $this->formatValue($this->mFilesize);
        $this->mResults .= "<li>{$this->mFilename} ({$size}) {$this->mMimeType}</li>\n";
    }

    private function listFile() {
        $out = $this->getOutput();
        $out->addModules(array('ext.Html2Wiki')); // add our javascript and css
        $size = $this->formatValue($this->mFilesize);
        $out->addHTML(<<<HERE
                <ul class="mw-ext-Html2Wiki">
                    <li>{$this->mFilename} ({$size}) {$this->mMimeType}</li>
                </ul>
HERE
        );
    }

    /** Don't really need this because we're doing it with Tidy
     * Tidy will correct errors AND give us the body
     * @return type
     */
    private function findBody() {
        $out = $this->getOutput();
        $content = $this->mContentRaw;
        $pattern = '#<body[^>]*>(.*)</body>#';
        $foundBody = preg_match_all($pattern, $content, $matches);
        if ($foundBody && count($matches) === 2) {
            return $matches[1];
        } else {
            $out->wrapWikiMsg(
                    "<p class=\"error\">\n$1\n</p>", array('html2wiki_multiple_body', $content)
            );
        }
    }

    private function escapeContent() {
        return nl2br(htmlentities($this->mContent, ENT_QUOTES | ENT_IGNORE, "UTF-8"));
    }

    /**
     * Do the import which consists of three phases:
     * 1) Select and/or Upload user nominated files 
     * 2) pre-process, filter, and convert them to wikitext 
     * 3) Create the articles, and images
     */
    private function doImport() {

        global $wgOut;
        $wgOut->addModules('ext.Html2Wiki');

        if ( $this->doUpload() ) {
            // if($this->doLocalFile("/vagrant/mediawiki/extensions/Html2Wiki/data/uvm-1.1d/docs/html/files/base/uvm_printer-svh.html")) {
            // if($this->doLocalFile("/vagrant/mediawiki/extensions/Html2Wiki/data/docs/htmldocs/mgc_html_help/overview04.html")) {
            
            $this->mFilesAreProcessed = ($this->mFileCountExpected == $this->mFileCountProcessed)? true : false;
            if ( $this->mFilesAreProcessed ) {
                $this->showResults();
            } else {
                $this->showForm('Some files were not processed');
            }
        } else {
            // @todo This should be an error report, not a form.
            $this->showForm();
        }
        return true;
    }

    /**
     * $this->mArticleSavePath, $this->mArticleTitle already set
     * @return boolean
     */
    private function processFile() {
        // when only a single file is uploaded, we can populate content from tmp_name
        if ($this->mOriginal['mimetype'] == 'text/html') {
            $this->mContent = $this->mContentRaw = file_get_contents($_FILES['userfile']['tmp_name']);
            $this->mFileCountExpected = 1;
        }
        
        // Tidy now works on mContent even when in fallback mode for MediaWiki-Vagrant
        $this->tidyup(self::getTidyOpts());
        // $this->tidyup();// with nothing, it should default to reading the config file
        $this->mContent = self::qpCleanVIP($this->mContent);
        
        // CleanLinks is a stronger version of RemoveMouseOvers
        //$this->qpCleanLinks();
        // Remove mouseovers and onclick handlers in links
        $this->qpRemoveMouseOvers();
        // turn <a name="foo"></a> to <span name="foo"></span> for intradocument links
        $this->mContent = self::qpLinkToSpan($this->mContent);
        // fix up relative links
        $this->qpMakeLinksAbsolute();
        // fix up the image links
        $this->qpAlterImageLinks();
        // cleanUVM is actually good for both sets, and gets rid of scripts etc.
        $this->cleanUVMFile();
        
        $this->eliminateCruft();
        // example of using a static method that would be available without
        // an Html2Wiki object (outside the class)
        $this->mContent = self::qpItalics($this->mContent, 'span.cItalic' );
        // functional version is commented out, but works just as well
        // $this->qpItalics('span.cItalic');
        // panDoc apparently lets double single quotes ''italics'' pass through
        // so we can qpItalics before running panDoc2Wiki()
        $this->panDoc2Wiki();
        // $this->qpRewriteImages();
        // $this->substituteTemplates();
        // $this->autoCategorize();
        // $this->showRaw();
        // $this->showContent();
        $this->saveArticle();
        // self::saveCat($this->mFilename, 'Html2Wiki Imports');
        $this->addFileToResults();

        return true;
    }
    
    /**
     * afer processing (all) file(s), we have a list of images to import
     */
    public function saveImages() {
        global $wgH2WProcessImages;
        if ( $wgH2WProcessImages === false ) {
            return true;
        }
        
        $out = $this->getOutput();
        $user = $this->getUser();
        
        $images = $this->mImages;
        if (!is_array($images)) {
            die('No images to save');
        }
        foreach ($images as $key => $img) {
            $title = $img[$key]['title']->makeTitle(NS_FILE);
            $image = wfLocalFile( $title );
            wfDebug( __METHOD__ . ": processing {$image[$key]['title']}" ); // @todo remove this later or set it only to log
            $result = $image->upload($img[$key]['tmpfile']);
            if ($result) {
                $out->addWikiText('<div class="success">' . $title . ' was ' . $actionverb . '. See [[' . $title . ']]</div>');
                $logEntry = new ManualLogEntry('html2wiki', 'import'); // Log action 'import' in the Special:Log for 'html2wiki'
                $logEntry->setPerformer($user); // User object, the user who performed this action
                $logEntry->setTarget($title); // The page that this log entry affects, a Title object
                $logEntry->setComment($this->mComment);
                $logid = $logEntry->insert();
                // optionally publish the log item to recent changes
                $logEntry->publish( $logid );
            } else {
                die("failed to upload $img[$key]['tmpfile']");
            }
            unlink($img[$key]['tmpfile']);
        }
        // images saved
    }

    private function makeTitle($namespace = NS_MAIN) {
        $desiredTitleObj = Title::makeTitleSafe($namespace, $this->mArticleTitle);
        if (!is_null($desiredTitleObj)) {
            return $desiredTitleObj;
        } else {
            die($this->mArticleTitle . " is an invalid filename");
        }
    }

    private function saveArticle() {
        $out = $this->getOutput();
        $user = $this->getUser();
        $token = $user->getEditToken();
        $title = $this->makeTitle(NS_MAIN);
        $existing = $title->exists();
        $actionverb = $existing ? 'edited' : 'created';
        $action = 'edit';
        $api = new ApiMain(
                new DerivativeRequest(
                $this->getRequest(), // Fallback upon $wgRequest if you can't access context
                array(
            'action' => $action,
            'title' => $title,
            'text' => $this->mContent, // can only use one of 'text' or 'appendtext'
            'summary' => $this->mComment,
            'notminor' => true,
            'token' => $token
                ), true // was posted?
                ), true // enable write?
        );
        $api->execute(); // actually save the article.
        // @todo convert this to a message with parameters to go in en.json
        $out->addWikiText('<div class="success">' . $title . ' was ' . $actionverb . '. See [[' . $title . ']]</div>');
        $logEntry = new ManualLogEntry('html2wiki', 'import'); // Log action 'import' in the Special:Log for 'html2wiki'
        $logEntry->setPerformer($user); // User object, the user who performed this action
        $logEntry->setTarget($title); // The page that this log entry affects, a Title object
        $logEntry->setComment($this->mComment);
        $logid = $logEntry->insert();
        // optionally publish the log item to recent changes
        // $logEntry->publish( $logid );
    }

    /**
     * Function borrowed from msUpload extension.  Not sure if this will
     * be easier to use with AJAX, or if our own saveArticle serves as a better
     * model to do this.  
     * 
     * @global type $wgContLang
     * @global type $wgUser
     * @param type $title
     * @param type $category
     */
    static function saveCat($title, $category) {
        global $wgContLang, $wgUser;
        $text = "\n[[Category:" . $category . "]]";
        $wgEnableWriteAPI = true;
        $params = new FauxRequest(array(
            'action' => 'edit',
            'nocreate' => 'true', // throw an error if the page doesn't exist
            'section' => 'new',
            'title' => $title,
            'appendtext' => $text,
            'token' => $wgUser->getEditToken(), //$token."%2B%5C",
                ), true, $_SESSION);
        $enableWrite = true; // This is set to false by default, in the ApiMain constructor
        $api = new ApiMain($params, $enableWrite);
        $api->execute();
        $data = &$api->getResultData();
    }

    /**
     * We don't have to worry about access restrictions here, because the whole
     * SpecialPage is restricted to users with "import" privs.
     * @var string $message is an optional error message that gets displayed 
     * on form re-display
     * 
     */
    private function showForm($message = null) {
        $action = $this->getPageTitle()->getLocalURL(array('action' => 'submit'));
        $user = $this->getUser();
        $out = $this->getOutput();
        $out->addModules(array('ext.Html2Wiki')); // add our javascript and css
        $out->addWikiMsg('html2wiki-intro');
        // display an error message if any
        if ($message) {
            $out->addHTML('<div class="error">' . $message . "</div>\n");
        }

        if ($user->isAllowed('importupload')) {
            $out->addHTML(
                    Xml::fieldset($this->msg('html2wiki-fieldset-legend')->text()) . // ->plain() or ->escaped()
                    Xml::openElement(
                            'form', array(
                        'enctype' => 'multipart/form-data',
                        'method' => 'post',
                        'action' => $action,
                        'id' => 'html2wiki-form'           // new id
                            )
                    ) .
                    $this->msg('html2wiki-text')->parseAsBlock() .
                    Html::hidden('action', 'submit') .
                    Html::hidden('source', 'upload') .
                    Xml::openElement('table', array('id' => 'html2wiki-table')) . // new id
                    "<tr>
					<td class='mw-label'>" .
                    Xml::label($this->msg('html2wiki-filename')->text(), 'userfile') .
                    "</td>
					<td class='mw-input'>" .
                    Html::input('userfile', '', 'file', array('id' => 'userfile')) . ' ' .
                    "</td>
				</tr>
				<tr>
					<td class='mw-label'>" .
                    Xml::label($this->msg('html2wiki-collection-name')->text(), 'mw-collection-name') .
                    "</td>
					<td class='mw-input'>" .
                    Xml::input('collection-name', 50, ( $this->mRequest ? $this->mCollectionName : ''), // value
                            array('id' => 'mw-collection-name', 'type' => 'text')) . ' ' . // attribs
                    "</td>
				</tr>
				<tr>
					<td class='mw-label'>" .
                    Xml::label($this->msg('import-comment')->text(), 'mw-import-comment') .
                    "</td>
					<td class='mw-input'>" .
                    Xml::input('log-comment', 50, ( $this->mRequest ? $this->mComment : ''), // value
                            array('id' => 'mw-import-comment', 'type' => 'text')) . ' ' . // attribs
                    "</td>
				</tr>
				<tr>
					<td></td>
					<td class='mw-submit'>" .
                    Xml::submitButton($this->msg('html2wiki-importbtn')->text()) .
                    "</td>
				</tr>" .
                    Xml::closeElement('table') .
                    Html::hidden('editToken', $user->getEditToken()) .
                    Xml::closeElement('form') .
                    Xml::closeElement('fieldset')
            );
        } else {
            $out->addWikiMsg('html2wiki-not-allowed');
        }
    }

    /**
     * The Tidy class can easily use an array.
     * To REUSE that same array in a command line environment, we reformat it
     * @param array $tidyOpts
     * @return string
     */
    private function makeConfigStringForTidy($tidyOpts) {
        $tidyOptsString = '';
        foreach ($tidyOpts as $k => $v) {
            $tidyOptsString .= " --$k $v";
        }
        return $tidyOptsString;
    }

    public static function tidyErrorsToArray ($errors) {
        preg_match_all(
                '/^(?:line (\d+) column (\d+) - )?(\S+): (?:\[((?:\d+\.?){4})]:) ?(.*?)$/m', 
                $errors, $matches, PREG_SET_ORDER);
        return $matches;
    }
    /**
     * Tidyup will populate both mContent and mContentTidy 
     * and store errors in mTidyErrors
     * 
     * Since we support ZipArchives, it doesn't make sense to read from a file.
     * Instead we need to read from a PHP Variable.  This isn't a problem when
     * the Tidy module is available (like it is with PHP5).  However, in a strange
     * twist of fate, the HHVM that comes with MediaWiki Vagrant does not yet
     * have a Tidy extension built in.  So, we have to fall back to using Tidy 
     * on the command line, and pass our PHP variables to STDIN / STDERR  
     * The way to do this with PHP is to use proc_open().
     * 
     * You can test Tidy online at sites like http://infohound.net/tidy/tidy.pl
     * 
     * @param string | array $tidyConfig
     * if passed as an array like so
      $tidyConfig = array(
      'indent'        => false,
      'output-xhtml'  => true,
      'wrap'          => 80
      );
     * then tidy will use the supplied configuration
     * Or, if passed as a string, tidy will use the configuration file
     * Left alone, it will load the config file supplied with Html2Wiki
     * 
     * On success, populate $this->mIsTidy = true
     * @return boolean
     * 
     * In testing tidy from the command line, it would NOT use a configuration file
     * If you have an existing tidy.conf you want to use, you can convert a config
     * awk '{ printf " --"$0 }' /vagrant/mediawiki/extensions/Html2Wiki/tidy.conf
     * 
     * @todo capture Tidy errors for logging or display?
     * see http://stackoverflow.com/questions/6472102/redirecting-i-o-in-php
     * @todo figure out if we need to use "force" option in Tidy?
     */
    public function tidyup($tidyConfig = NULL) {
        if ($this->mMimeType !== 'text/html') {
            // @todo wrap this in a wiki message
            echo "wrong type of file for Tidy\n";
            $this->isTidy = false;
            return false;
        }

        $this->mTidyConfig = realpath(__DIR__ . "/../tidy.conf");
        if (is_null($tidyConfig)) {
            $tidyConfig = $this->mTidyConfig;
            $shellConfig = " -config $tidyConfig";
        } elseif (is_array($tidyConfig)) {
            $shellConfig = $this->makeConfigStringForTidy($tidyConfig);
        } elseif (is_string($tidyConfig)) {
            $shellConfig = " -config $tidyConfig";
            if (!is_readable($tidyConfig)) {
                // echo "Tidy's config not found, or not readable\n";
            }
        }
        if (class_exists('Tidy')) {
            $encoding = 'utf8';
            $tidy = new Tidy;
            $tidy->parseString($this->mContent, $tidyConfig, $encoding);
            $tidy->cleanRepair();
            // @todo need to do something else with this error list?
            // regex converts string error into a two dimensional array 
            // foreach line spit out by Tidy
            // It will match Error, Warning, Info and Access error types.
            if (!empty($tidy->errorBuffer)) {
                $this->mTidyErrors = self::tidyErrorsToArray($tidy->errorBuffer);
            }
            // just focus on the body of the document
            $this->mContentTidy = $this->mContent = (string) $tidy->body();
        } else {
            // using Tidy on the command line expects input from STDIN
            // We'll use printf which is more consistent than echo which varies
            // We want something like the following
            //$result =  shell_exec('printf "$content" | tidy');
            $html = $this->mContent;
            // don't need this escaping since we're not using shell_exec
            // $html = str_replace(array('\\', '%'), array('\\\\', '%%'), $html);
            
            $tidy = "/usr/bin/tidy";
            // only by passing the options as a long string worked.
            // also, $this->mTidyErrors will never populate unless we explicitly 
            // trap STDERR
            // 2>&1 1> /dev/null
            $cmd = "$tidy -quiet -indent -ashtml $shellConfig";
$tidy = '/usr/bin/tidy -quiet -indent -ashtml  --drop-empty-paras 1 --drop-font-tags 1 --enclose-block-text 1 --enclose-text 1 --fix-backslash 1 --fix-bad-comments 1 --fix-uri 1 --hide-comments 1 --merge-divs 1 --merge-spans 1 --repeated-attributes keep-first --show-body-only 1 --show-errors 0 --show-warnings 0 --indent 0 --wrap 120 --tidy-mark 0 --write-back 0';
            // $escaped_command = escapeshellcmd($cmd);
            // echo "executing $escaped_command";
            // $this->mContentTidy = $this->mContent = shell_exec("printf '$html' | $escaped_command");
            $descriptorspec = array(
                0 => array("pipe", "r"), // stdin is a pipe that the child will read from
                1 => array("pipe", "w"), // stdout is a pipe that the child will write to
                2 => array("pipe", "w")  // stderr is a pipe that the child will write to
                // 2 => array("file", "/tmp/error-output.txt", "a") // stderr is a file to write to
            );
            $process = proc_open($tidy, $descriptorspec, $pipes);
            if (is_resource($process)) {
                // $pipes now looks like this:
                // 0 => writeable handle connected to child stdin
                // 1 => readable handle connected to child stdout
                // 2 => readable handle connected to child stderr
                // Any error output will be appended to /tmp/error-output.txt

                fwrite($pipes[0], $html);
                fclose($pipes[0]);

                $this->mContentTidy = $this->mContent = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                
                $this->mTidyErrors = self::tidyErrorsToArray(stream_get_contents($pipes[2]));
                fclose($pipes[2]);
                
                // It is important that you close any pipes before calling
                // proc_close in order to avoid a deadlock
                $return_value = proc_close($process);
            } else {
                die('could not get process open');
            }

        }
        $this->isTidy = true;
        return true;
    }

    /**
     * Using the QueryPath library to manipulate our source document.
     * 
     * QueryPath is a jQuery-like library for working with XML and HTML 
     * documents in PHP.
     * 
     * Doing this with xPath directly like explained at
     * http://schlitt.info/opensource/blog/0704_xpath.html 
     * is like so
     * $nodes = $xpath->query('//a/@href');
      foreach($nodes as $href) {
      echo $href->nodeValue;                       // echo current attribute value
      $href->nodeValue = 'new value';              // set new attribute value
      $href->parentNode->removeAttribute('href');  // remove attribute
      }
     * but QueryPath offers a CSS parser to more easily specify document objects
     * 
     * This function "fixes" relative links so that we know the full path of 
     * document links and image links.  This way we can rewrite them properly.
     * 
     * This function is not recursive, so it will need to be called successively
     * for each time an attribute contains '../'
     */
    public function qpAddParentToLink($parent) {
        $qp = qp($this->mContent);
        $anchors = $qp->find("a");
        foreach ($anchors as $anchor) {
            $href = $anchor->attr('href');
            if (substr($href, 0, 1) == '#') {
                // leave intra document links alone
            } else {
                $anchor->attr('href', preg_replace('#\.\./([^.]*)#', "$parent/$1", $href));
            }
            // print( $anchor->attr('href') . "<br />" . PHP_EOL );
        }
        // now go back to the top and fix relative image sources
        $ea = $qp->top()->find('img');
        foreach ($ea as $image) {
            $src = $image->attr('src');
            $image->attr( 'src', preg_replace('#\.\./([^.]*)#', "$parent/$1", $src) ); 
         }
        $qp->top();
        ob_start();
        $qp->writeHTML();
        $this->mContent = ob_get_clean();
    }
    
    /**
     * Function to convert relative links to full absolute links to preserve
     * the Collection hierarchy in the wiki for imported Collections.
     * 
     * @return boolean true on completion
     */
    public function qpMakeLinksAbsolute () {
        $fullpath = ($this->mCollectionName)? "/{$this->mCollectionName}/{$this->mArticleSavePath}" : "/{$this->mArticleSavePath}";
        $qp = htmlqp($this->mContent, 'a:link');
        foreach ($qp as $anchor) {
            $href = $anchor->attr('href');
            $absolutePath = "$fullpath/{$href}";
            // NO need to get rid of any ../ because it will still work
            $anchor->attr('href', $absolutePath);
        }
        ob_start();
        $qp->writeHTML();
        $this->mContent = ob_get_clean();
        return true;

    }
    /**
     * A function to change the image tags in imported documents 
     * to reflect the path that those images will be found at in the wiki.
     * 
     * We also want to dress up the tag so that it can be useful to Pandoc
     * 
     * Our source content looks like this (blank lines removed):
     * @source
       <a name="wp80923"></a><p class="pFigureTitle" id="MGC80923">
        Figure 4-1. <a name="CRefID61031"></a>
        Message Sequence Chart for a Normal mvc_sequence<a name='Graphic80921'></a><img src="images/message_sequence_chart__normal_mvc_sequence.jpg"  style='width:6.36667in;'  class="Aligncenter" id='wp80921' border='0' hspace='0' vspace='0'/>
        </p>
     * @source
     * 
     * Pandoc will recognize and condense "title" and "alt" attributes, producing 
     * [[Image: source txt ]]
     * 
     * Also, Pandoc has special handling if the Title attribute starts with 
     * the word 'Figure', producing something like
     * [[Image: src |frame|none txt ]]
     * 
     * Our conversion currently looks like 
     * @source
       <span id="wp80923"></span>
       Figure 4-1. <span id="CRefID61031"></span> 
       Message Sequence Chart for a Normal mvc_sequence<span id="Graphic80921"></span>[[Image:images/message_sequence_chart__normal_mvc_sequence.jpg]]
      @source
     * 
     * This is because we already preserve "named anchors" by converting them to spans
     * 
     * What we'll do in this function is grab the "text" portion of the parent
     * paragraph element and create a title attribute for our image.
     * 
     * We'll also fix up the src attribue to remove the 'images/' component and 
     * replace it with a full path of the article-- which will be the equivalent
     * location of the image that is saved into the wiki's file namespace
     * 
     */
    public function qpAlterImageLinks () {
        $fullpath = ($this->mCollectionName)? "/{$this->mCollectionName}/{$this->mArticleSavePath}" : "/{$this->mArticleSavePath}";
        $qp = htmlqp ($this->mContent, 'img');
        foreach ($qp as $img) {
            $title = $img->parent('.pFigureTitle')->text();
            $title = str_replace(array("\r", "\r\n", "\n"), ' ', $title);
            $title = preg_replace("/^\s+/", '', $title);
            $src = $img->attr('src');
            $src = str_replace('images/', '', $src);
            $img->attr('src', "$fullpath{$src}");
            $img->attr('title', $title);
        }
        ob_start();
        $qp->writeHTML();
        $this->mContent = ob_get_clean();
        return true;
    }
    
    /**
     * Provide a selector to transform HTML to wiki text markup for italics 
     * e.g. <div class="foo">something</div>  ----->  ''something''
     * 
     * Html2Wiki::qpItalics("div.foo");
     */

    public static function qpItalics($content, $selector = NULL, $options = array('ignore_parser_warnings'=> true) ) {
        $qp = htmlqp($content, $selector, $options);
        $items = $qp->find($selector);
        foreach ($items as $item) {
            $text = $item->text();
            $newtag = "''$text''";
            $item->replaceWith($newtag);
        }
        $qp->top();
        ob_start();
        $qp->writeHTML();
        $return = ob_get_clean();
        return $return;
    }
    
    /**
     * A function that will strip the head, and remove certain document elements
     * which are found in VIP collections and are not desired for conversion to
     * wikitext and so they should be filtered out.
     * @param string $content
     * @return string the cleaned content
     */
    public static function qpCleanVIP($content) {
        $qp = htmlqp($content);
        $ea = $qp->top()->find('head');
        foreach ($ea as $head) {
            $head->remove();
        }
        $qp->find('#BodyPopup')->remove();
        $qp->find('#HideBody')->remove();
        $qp->find('#BodyContent')->unwrap();
        // $qp->top()->find('#BodyContent')->html();
        ob_start();
        $qp->writeHTML();
        $return = ob_get_clean();
        return $return;
    }
    
    /**
     * A method to remove HTML elements according to their ID
     * @param string $content a document fragment
     * @param string $selector, an element id like '#foo', or .class but that is more destructive
     * @return string
     */
    public static function qpRemoveIds($content, $selector) {
        $qp = htmlqp($content, $selector)->remove();
        ob_start();
        $qp->writeHTML();
        $return = ob_get_clean();
        return $return;        
    }
   
    
    /**
     * Provide a selector to transform HTML to wiki text markup for italics 
     * e.g. <div class="foo">something</div>  ----->  ''something''
     * 
     * Html2Wiki::qpItalics("div.foo");

    public function qpItalics($selector = NULL) {
        $qp = htmlqp($this->mContent);
        $items = $qp->find($selector);
        foreach ($items as $item) {
            $text = $item->text();
            $newtag = "''$text''";
            $item->replaceWith($newtag);
        }
        $qp->top();
        ob_start();
        $qp->writeHTML();
        $this->mContent = ob_get_clean();
}
     */
    
    
    /**
     * Remove all attributes on anchor tags except for the href
     */
    public function qpCleanLinks () {
        $options = array('ignore_parser_warnings'=> true);
        $qp = htmlqp($this->mContent, null, $options);
        // first ditch all the other attributes of true anchor tags
        $anchors = $qp->find('a:link');
        foreach ($anchors as $anchor) {
            $href = $anchor->attr('href');
            $linktext = $anchor->text();
            $newtag = "<a href=\"$href\">$linktext</a>";
            $anchor->replaceWith($newtag);
        }
        // now go back to the top and ditch empty anchors
        $ea = $qp->top()->find('a');
        foreach ($ea as $anchor) {
            if ($anchor->hasAttr('href')) {
                if (substr($href, 0, 1) == '#') {
                    $anchor->remove(); // remove intra-document links
                }
            } else { 
                // no href, it's a target
                // remove all id'd anchor targets
                $anchor->remove();
            }
         }
         $qp->top();
         ob_start();
         $qp->writeHTML();
         $this->mContent = ob_get_clean();
    }
    
    public static function qpLinkToSpan($content) {
        $qp = htmlqp($content, 'a');
        foreach ($qp as $anchor) {
            if ($anchor->hasAttr('href')) {
                // in VIP anchors with href's don't have names and vice versa
                // but we still want to be extra cautious not to turn a link
                // with an href into a span tag
                continue; 
            }
            if ($anchor->hasAttr('name')) {
                $name = $anchor->attr('name');
                $linktext = $anchor->text(); // normally no link text, but just in case
                $newtag = "<span id=\"$name\">$linktext</span>";
                $anchor->replaceWith($newtag);
            }
        }
        ob_start();
        $qp->writeHTML();
        $return = ob_get_clean();
        return $return;        
    }
    
    public function qpRemoveMouseOvers() {
        $qp = qp($this->mContent, 'body')->find("a:link");
        foreach ($qp as $anchor) {
            $anchor->removeAttr('onclick'); // vip
            $anchor->removeAttr('onmouseover'); // uvm
            $anchor->removeAttr('onmouseout'); // uvm
        }
        $qp->top();
        ob_start();
        $qp->writeHTML();
        $this->mContent = ob_get_clean(); 
    }
    

    /**
     * Before we rewrite the image tags, we'll have to ensure that any relative 
     * links like src="../images/foo.jpg" are converted to full paths that can be 
     * translated to a wiki file path.  qpAddParentToLink() should take care of that.
     * @param type $content
     * @return type
     */
    public function qpRewriteImages($content=null) {
        
        global $wgH2WEliminateDuplicateImages;
                
        if ( is_null($content) ) {
            $content = $this->mContent;
        }
        $qp = htmlqp($content)->find('img');

        foreach ($qp as $item) {
            $src = $item->attr('src');
            $src = ($wgH2WEliminateDuplicateImages)? basename($src) : $src;
            $src = ( !empty($this->mCollectionName) )? "{$this->mCollectionName}/$src" : $src;
            $newtag = "[[File::$src|center|frame]]";
            $item->replaceWith($newtag);
        }
        $qp->top();
        ob_start();
        $qp->writeHTML();
        $this->mContent = ob_get_clean();
    }
            
    public function cleanUVMFile() {
        // delete content tags
        $reHead = "#<head>.*?</head>#is";
        $reBody = "#<body\b[^>]*>(.*?)</body>#is";
        $reScript = "#<script\b[^>]*>(.*?)</script>#is"; // caseless dot-all
        $reNoscript = "#<noscript\b[^>]*>(.*?)</noscript>#is"; // caseless dot-all
        $reComment = "#<!--(.*?)-->#s";
        $reEmptyAnchor = "#<a\b[^>]*></a>#is"; // empty anchor tags
        $reCollapsePre = '#</pre>\s*?<pre class="pCode">#s'; // sibling pre tags
        $reBlankLine = "#^\s?$\n#m";
        // simulate Tidy by ditching the <head> and getting only the <body>
        // but the source HTML could still be in a hurtful mess
        if (!$this->isTidy) {
            $this->mContent = preg_replace($reHead, '', $this->mContent);
            $this->mContent = preg_filter($reBody, "$1", $this->mContent);
        }
        $this->mContent = preg_replace($reScript, '', $this->mContent);
        $this->mContent = preg_replace($reNoscript, '', $this->mContent);
        $this->mContent = preg_replace($reEmptyAnchor, '', $this->mContent);
        $this->mContent = preg_replace($reCollapsePre, '', $this->mContent);

        $this->mContent = preg_replace($reComment, '', $this->mContent);
        // keep this last, because there will be a lot of blank lines generated
        $this->mContent = preg_replace($reBlankLine, '', $this->mContent);
        /*
          $mouseAnchors = '#<a (?:name|href)=[^>]*?(?:onMouseOver|onMouseOut)[^>]*?>(.*?)</a>#ms';
          $this->mContent = preg_replace($mouseAnchors, "$1", $this->mContent);
         */
    }

    /**
     * A method to find certain HTML fragments that you wish to replace with 
     * Templates that you've designed in your wiki.
     * 
     * For example, you're importing a large set of pages and every page contains 
     * a common footer.
     * 
     * You can define those strings in this method, and swap in the wiki
     * templates.  Corresponding wiki templates must be made by hand. 
     * 
     * For example, we'll replace 
     *     <div class="BlankFooter" id="BlankFooter">&nbsp;</div>
     *     <div class="Footer" id="Footer">&nbsp;</div>
     * with 
     *     {{BlankFooter}}
     *     {{Footer}}
     * 
     * Note: this function uses string replacement, not regular expressions
     * Also, we use a case insensitive match because <div and <Div are the same
     * in HTML.  Although the strings /should/ be consistent throughout your source
     * we'll assume that you might still be hand editing HTML which introduces
     * such inconsistencies.
     */
    public function substituteTemplates() {
        $myReplacements = array(
            '<div class="BlankFooter" id="BlankFooter">&nbsp;</div>'
            => '{{BlankFooter}}',
            '<div class="Footer" id="Footer">&nbsp;</div>'
            => '{{Footer}}'
        );
        $this->mContent = str_ireplace(array_keys($myReplacements), array_values($myReplacements), $this->mContent);
    }

    /**
     * Similar to substituteTemplates() but this function is for removing 
     * leftover presentational or functional HTML that is not suitable content
     * for the wiki-fied version.
     * 
     * E.g.  <div id="BodyPopup" class="BodyPopup"></div>
     *       <div class="HideBody" id="HideBody">&nbsp;</div>
     * should be removed from the source HTML
     */
    public function eliminateCruft() {
        $myNeedles = array(
            '<div id="BodyPopup" class="BodyPopup"></div>',
            '<div class="HideBody" id="HideBody">&nbsp;</div>'
        );
        $this->mContent = str_ireplace($myNeedles, '', $this->mContent);
    }

    /**
     * We need to eliminate or replace mouseover links because they cause problems with 
     * DOM manipipluation as libxml can NOT read this content... it seems that Tidy 
     * leaves multiple IDs in place, or just that libxml auto-assigns ids to named elements
     * 
     * Function currently unused. We can do this with QueryParse
     */
    public function preProcess() {
        // <a href="#uvm_line_printer" id=link6 onMouseOver="ShowTip(event, 'tt5', 'link6')" onMouseOut="HideTip('tt5')">
        $mouseAnchors = '#< href=[^>]*??(onMouseOver|onMouseOut)[^>]*?(.*?)</a>#ms';
        $this->mContent = preg_replace($mouseAnchors, $this->mContent);
    }

    /**
     * Automatically categorize the content based on what's found in the original
     * source.
     */
    private function autoCategorize() {
        $categoryTags = array();
        $categories = array(
            'uvm' => '[[Category:UVM]]',
            'vip' => '[[Category:VIP Info Hub]]'
        );
        $needles = array(
            'uvm' => 'Generated by Natural Docs',
            'vip' => 'Quadralay WebWorks AutoMap 2003 for FrameMaker'
        );
        foreach ($needles as $k => $v) {
            if (stristr($this->mContentRaw, $v)) {
                $categoryTags[] = $categories[$k];
            }
        }
        $this->mContent .= "\n" . implode(" ", $categoryTags);
    }
    
    /**
     * A convenience function to temporarily store contents so that we can use
     * other tools or methods that expect to work on a file rather than a stream
     * @param type $content
     * @return type
     */
    public function makeTempFile ($content) {
        if( !empty($this->mDataDir) ) {
            $stage = realpath($this->mDataDir);
        } else {
            $stage = sys_get_temp_dir();
        }
        $prefix = 'h2w';
        $tempfilename = tempnam($stage, $prefix);
        $handle = fopen($tempfilename, "w");
        fwrite($handle, $content);
        fclose($handle);
        return $tempfilename;
    }

    /**
     * Use Pandoc to convert our content to mediawiki markup
     */
    public function panDoc2Wiki() {
        $tempfilename = $this->makeTempFile($this->mContent);
        // file_put_contents($stage/$tempfilename, $this->mContent);
        $this->mContent = shell_exec("pandoc -f html -t mediawiki $tempfilename");
        unlink($tempfilename);
    }

}


